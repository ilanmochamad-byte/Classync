<?php
// Robust handler for student attendance (masuk / pulang)
// Requires: includes/db.php (provides $conn), includes/wa_sender.php (optional, for notifications)

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Jakarta');

function jsonResponse($status, $message, $data = null) {
    $out = ['status' => $status, 'message' => $message];
    if ($data !== null) $out['data'] = $data;
    $body = json_encode($out);
    // Kirim Content-Length agar client tahu respons sudah selesai
    header('Content-Length: ' . strlen($body));
    echo $body;
    // Flush respons ke client sebelum shutdown function (WA) berjalan
    if (ob_get_level()) { ob_end_flush(); }
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit;
}

function logErr($msg) {
    error_log('[proses_absen_siswa] ' . $msg);
}

// Load dependencies
if (!file_exists(__DIR__ . '/../includes/db.php')) {
    logErr('includes/db.php not found');
    jsonResponse('error', 'Server configuration error (db).');
}
require __DIR__ . '/../includes/db.php';

// optional WA sender
$hasWa = false;
if (file_exists(__DIR__ . '/../includes/wa_sender.php')) {
    require_once __DIR__ . '/../includes/wa_sender.php';
    $hasWa = function_exists('kirimNotifikasiWA') && function_exists('formatNomorWA');
}

// Antrian notifikasi WA — dikirim via shutdown function SETELAH respons dikirim ke client
// agar client tidak menunggu HTTP call ke gateway WA (timeout 30 detik).
$wa_queue = [];
register_shutdown_function(function () use (&$wa_queue) {
    foreach ($wa_queue as $notif) {
        try {
            kirimNotifikasiWA($notif['nomor'], $notif['pesan']);
        } catch (Exception $e) {
            error_log('[proses_absen_siswa] WA shutdown error: ' . $e->getMessage());
        }
    }
});

// Read input
$raw = file_get_contents('php://input');
if (!$raw) {
    jsonResponse('error', 'No input received.');
}
$input = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logErr('JSON decode error: ' . json_last_error_msg() . ' raw=' . substr($raw,0,500));
    jsonResponse('error', 'Invalid JSON input.');
}

$nisn = isset($input['nisn']) ? trim($input['nisn']) : '';
$foto_base64 = isset($input['foto_base64']) ? $input['foto_base64'] : null;
$mode = isset($input['mode']) ? strtolower(trim($input['mode'])) : '';

if ($nisn === '' || !in_array($mode, ['masuk','pulang'])) {
    jsonResponse('error', 'Parameter tidak lengkap atau mode tidak valid.');
}

// Get jam_masuk from pengaturan
$jam_masuk = '07:30:00';
$peng = $conn->query("SELECT nama_pengaturan, nilai_pengaturan FROM pengaturan WHERE nama_pengaturan IN ('jam_masuk')");
while ($r = $peng->fetch_assoc()) {
    if ($r['nama_pengaturan'] === 'jam_masuk' && $r['nilai_pengaturan'] !== '') {
        $jam_masuk = date('H:i:s', strtotime($r['nilai_pengaturan']));
    }
}

// Find student by nisn
$stmt = $conn->prepare("SELECT id, nama_siswa, kelas, kontak_ortu FROM siswa WHERE nisn = ? LIMIT 1");
if (!$stmt) {
    logErr('Prepare siswa failed: ' . $conn->error);
    jsonResponse('error', 'Server error (prepare).');
}
$stmt->bind_param('s', $nisn);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    $stmt->close();
    jsonResponse('error', 'NISN tidak ditemukan.');
}
$siswa = $res->fetch_assoc();
$stmt->close();

$siswa_id = (int)$siswa['id'];
$tanggal = date('Y-m-d');
$waktu_now = date('H:i:s');

// Begin transaction for consistency
$conn->begin_transaction();

try {
    // Lock any existing attendance row for this siswa today
    $stmt_lock = $conn->prepare("SELECT id, waktu_masuk, waktu_pulang, status_masuk FROM absensi_siswa WHERE siswa_id = ? AND tanggal = ? FOR UPDATE");
    if (!$stmt_lock) {
        throw new Exception('Prepare lock failed: ' . $conn->error);
    }
    $stmt_lock->bind_param('is', $siswa_id, $tanggal);
    $stmt_lock->execute();
    $res_lock = $stmt_lock->get_result();
    $row = $res_lock->fetch_assoc();
    $stmt_lock->close();

    // Helper: save photo if provided
    $savePhoto = function($base64, $typePrefix) use ($nisn, $tanggal) {
        if (empty($base64) || strpos($base64, 'data:') !== 0 && !preg_match('/^\/9j/',$base64)) {
            // If it's not a data URI but raw base64, still try
        }
        if (strpos($base64, 'base64,') !== false) {
            $parts = explode('base64,', $base64);
            $base64 = $parts[1];
        }
        $base64 = str_replace(' ', '+', $base64);
        $data = base64_decode($base64);
        if ($data === false) return null;

        $dir = __DIR__ . '/../uploads/absensi/' . $tanggal;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $safeNisn = preg_replace('/[^A-Za-z0-9_-]/','', $nisn);
        $filename = $typePrefix . '_' . $safeNisn . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
        $path = $dir . '/' . $filename;
        if (file_put_contents($path, $data) === false) {
            return null;
        }
        return 'uploads/absensi/' . $tanggal . '/' . $filename;
    };

    if ($mode === 'masuk') {
        if ($row) {
            if (!empty($row['waktu_masuk'])) {
                $conn->rollback();
                jsonResponse('error', 'Siswa sudah melakukan absensi masuk hari ini.');
            } else {
                $foto_path = null;
                if ($foto_base64) {
                    $foto_path = $savePhoto($foto_base64, 'masuk');
                }
                $stmt_up = $conn->prepare("UPDATE absensi_siswa SET waktu_masuk = ?, foto_masuk = ?, status_masuk = ? WHERE id = ?");
                if (!$stmt_up) throw new Exception('Prepare update masuk failed: ' . $conn->error);
                $status_text = (strtotime($waktu_now) <= strtotime($jam_masuk)) ? 'Tepat Waktu' : 'Terlambat';
                $stmt_up->bind_param('sssi', $waktu_now, $foto_path, $status_text, $row['id']);
                if (!$stmt_up->execute()) throw new Exception('Execute update masuk failed: ' . $stmt_up->error);
                $stmt_up->close();
                $conn->commit();

                // Antri notifikasi WA — dikirim setelah respons (lihat register_shutdown_function di atas)
                if ($hasWa && !empty($siswa['kontak_ortu'])) {
                    $nomor = formatNomorWA($siswa['kontak_ortu']);
                    $pesan  = "INFO ABSENSI SMK TERPADU AL HASAN\n\n";
                    $pesan .= "Yth. Bpk/Ibu Wali dari:\n";
                    $pesan .= "Nama: *" . $siswa['nama_siswa'] . "*\n";
                    $pesan .= "Kelas: " . $siswa['kelas'] . "\n\n";
                    $pesan .= "Diberitahukan bahwa hari ini (" . date('d/m/Y') . ") Ananda telah melakukan absensi *MASUK* pada pukul *" . date('H:i', strtotime($waktu_now)) . " WIB*.\n";
                    $pesan .= "Status Kehadiran: *" . $status_text . "*\n\n";
                    $pesan .= "Terima kasih.";
                    $wa_queue[] = ['nomor' => $nomor, 'pesan' => $pesan];
                }

                jsonResponse('success', 'Absensi masuk berhasil diperbarui.', [
                    'nama_siswa' => $siswa['nama_siswa'],
                    'kelas' => $siswa['kelas'],
                    'waktu_masuk' => $waktu_now,
                    'status_masuk' => $status_text,
                    'foto_masuk' => isset($foto_path) ? $foto_path : null
                ]);
            }
        } else {
            $foto_path = null;
            if ($foto_base64) $foto_path = $savePhoto($foto_base64, 'masuk');
            $status_text = (strtotime($waktu_now) <= strtotime($jam_masuk)) ? 'Tepat Waktu' : 'Terlambat';
            $stmt_ins = $conn->prepare("INSERT INTO absensi_siswa (siswa_id, tanggal, waktu_masuk, foto_masuk, status_masuk) VALUES (?, ?, ?, ?, ?)");
            if (!$stmt_ins) throw new Exception('Prepare insert masuk failed: ' . $conn->error);
            $stmt_ins->bind_param('issss', $siswa_id, $tanggal, $waktu_now, $foto_path, $status_text);
            if (!$stmt_ins->execute()) throw new Exception('Execute insert masuk failed: ' . $stmt_ins->error);
            $stmt_ins->close();
            $conn->commit();

            // Antri notifikasi WA — dikirim setelah respons
            if ($hasWa && !empty($siswa['kontak_ortu'])) {
                $nomor  = formatNomorWA($siswa['kontak_ortu']);
                $pesan  = "INFO ABSENSI SMK TERPADU AL HASAN\n\n";
                $pesan .= "Yth. Bpk/Ibu Wali dari:\n";
                $pesan .= "Nama: *" . $siswa['nama_siswa'] . "*\n";
                $pesan .= "Kelas: " . $siswa['kelas'] . "\n\n";
                $pesan .= "Diberitahukan bahwa hari ini (" . date('d/m/Y') . ") Ananda telah melakukan absensi *MASUK* pada pukul *" . date('H:i', strtotime($waktu_now)) . " WIB*.\n";
                $pesan .= "Status Kehadiran: *" . $status_text . "*\n\n";
                $pesan .= "Terima kasih.";
                $wa_queue[] = ['nomor' => $nomor, 'pesan' => $pesan];
            }

            jsonResponse('success', 'Absensi masuk berhasil disimpan.', [
                'nama_siswa' => $siswa['nama_siswa'],
                'kelas' => $siswa['kelas'],
                'waktu_masuk' => $waktu_now,
                'status_masuk' => $status_text,
                'foto_masuk' => isset($foto_path) ? $foto_path : null
            ]);
        }
    } elseif ($mode === 'pulang') {
        if ($row) {
            if (!empty($row['waktu_pulang'])) {
                $conn->rollback();
                jsonResponse('error', 'Absensi pulang sudah tercatat sebelumnya.');
            } else {
                $foto_path = null;
                if ($foto_base64) $foto_path = $savePhoto($foto_base64, 'pulang');
                $stmt_up = $conn->prepare("UPDATE absensi_siswa SET waktu_pulang = ?, foto_pulang = ? WHERE id = ?");
                if (!$stmt_up) throw new Exception('Prepare update pulang failed: ' . $conn->error);
                $stmt_up->bind_param('ssi', $waktu_now, $foto_path, $row['id']);
                if (!$stmt_up->execute()) throw new Exception('Execute update pulang failed: ' . $stmt_up->error);
                $stmt_up->close();
                $conn->commit();

                // Antri notifikasi WA — dikirim setelah respons
                if ($hasWa && !empty($siswa['kontak_ortu'])) {
                    $nomor  = formatNomorWA($siswa['kontak_ortu']);
                    $pesan  = "INFO ABSENSI SMK TERPADU AL HASAN\n\n";
                    $pesan .= "Yth. Bpk/Ibu Wali dari:\n";
                    $pesan .= "Nama: *" . $siswa['nama_siswa'] . "*\n";
                    $pesan .= "Kelas: " . $siswa['kelas'] . "\n\n";
                    $pesan .= "Diberitahukan bahwa hari ini (" . date('d/m/Y') . ") Ananda telah melakukan absensi *PULANG* pada pukul *" . date('H:i', strtotime($waktu_now)) . " WIB*.\n\n";
                    $pesan .= "Terima kasih.";
                    $wa_queue[] = ['nomor' => $nomor, 'pesan' => $pesan];
                }

                jsonResponse('success', 'Absensi pulang berhasil dicatat.', [
                    'nama_siswa' => $siswa['nama_siswa'],
                    'kelas' => $siswa['kelas'],
                    'waktu_pulang' => $waktu_now,
                    'foto_pulang' => isset($foto_path) ? $foto_path : null
                ]);
            }
        } else {
            $foto_path = null;
            if ($foto_base64) $foto_path = $savePhoto($foto_base64, 'pulang');
            $stmt_ins = $conn->prepare("INSERT INTO absensi_siswa (siswa_id, tanggal, waktu_pulang, foto_pulang, status_masuk) VALUES (?, ?, ?, ?, ?)");
            if (!$stmt_ins) throw new Exception('Prepare insert pulang failed: ' . $conn->error);
            $status_val = 'Pulang';
            $stmt_ins->bind_param('issss', $siswa_id, $tanggal, $waktu_now, $foto_path, $status_val);
            if (!$stmt_ins->execute()) throw new Exception('Execute insert pulang failed: ' . $stmt_ins->error);
            $stmt_ins->close();
            $conn->commit();

            // Antri notifikasi WA — dikirim setelah respons
            if ($hasWa && !empty($siswa['kontak_ortu'])) {
                $nomor  = formatNomorWA($siswa['kontak_ortu']);
                $pesan  = "INFO ABSENSI SMK TERPADU AL HASAN\n\n";
                $pesan .= "Yth. Bapak/Ibu Orang Tua/Wali dari:\n";
                $pesan .= "Nama: *" . $siswa['nama_siswa'] . "*\n";
                $pesan .= "Kelas: " . $siswa['kelas'] . "\n\n";
                $pesan .= "Diberitahukan bahwa hari ini (" . date('d/m/Y') . ") Ananda telah melakukan absensi *PULANG* pada pukul *" . date('H:i', strtotime($waktu_now)) . " WIB*.\n\n";
                $pesan .= "Terima kasih.";
                $wa_queue[] = ['nomor' => $nomor, 'pesan' => $pesan];
            }

            jsonResponse('success', 'Absensi pulang berhasil disimpan.', [
                'nama_siswa' => $siswa['nama_siswa'],
                'kelas' => $siswa['kelas'],
                'waktu_pulang' => $waktu_now,
                'foto_pulang' => isset($foto_path) ? $foto_path : null
            ]);
        }
    } else {
        $conn->rollback();
        jsonResponse('error', 'Mode tidak dikenali.');
    }

} catch (Exception $e) {
    $conn->rollback();
    logErr('Exception: ' . $e->getMessage() . ' raw=' . substr($raw,0,500));
    jsonResponse('error', 'Terjadi kesalahan server: ' . $e->getMessage());
}
?>