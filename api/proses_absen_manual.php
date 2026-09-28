<?php
// Aktifkan error reporting untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

// Fungsi untuk mengirim response JSON
function sendResponse($status, $message, $data = null) {
    $response = [
        'status' => $status,
        'message' => $message
    ];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit;
}

// Fungsi untuk log error
function logError($message) {
    error_log('[Absen Manual] ' . $message);
}

try {
    require '../includes/db.php';
    require '../includes/wa_sender.php'; // Pastikan path ini benar mengarah ke lokasi wa_sender.php
    
    if (!isset($conn) || !$conn) {
        throw new Exception('Koneksi database gagal');
    }

} catch (Exception $e) {
    logError('Load dependencies error: ' . $e->getMessage());
    sendResponse('error', 'Error sistem: ' . $e->getMessage());
}

// Hanya guru piket hari ini yang sedang masuk (login_piket.php), atau admin
// yang sedang login di panel admin sebagai pengganti guru piket.
// Bentuk respons tetap sendResponse() milik berkas ini.
require_once __DIR__ . '/../includes/sesi_piket.php';
require_once __DIR__ . '/../includes/status_harian.php';
$pencatat = pencatatAbsenManual($conn);
if ($pencatat === null) {
    sendResponse('error', 'Sesi Anda berakhir. Silakan masuk lagi.', ['perlu_masuk' => true]);
}

// Ambil data JSON
$input_raw = file_get_contents('php://input');
$input = json_decode($input_raw, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    sendResponse('error', 'Format data tidak valid');
}

// Token dari formulir absen_manual.php. Tanpa ini, situs lain bisa menyuruh
// peramban guru piket yang sedang masuk mengirim absen atas namanya.
if (!cekCsrfPiket($input['csrf_token'] ?? '')) {
    sendResponse('error', 'Sesi tidak sah. Silakan masuk lagi.', ['perlu_masuk' => true]);
}

$siswa_id = isset($input['siswa_id']) ? intval($input['siswa_id']) : 0;
$status_manual = isset($input['status']) ? trim($input['status']) : '';

// Validasi input
if (empty($siswa_id) || empty($status_manual)) {
    sendResponse('error', 'Data tidak lengkap');
}

// PERBAIKAN: Pastikan status sesuai format database
// 'Izin Pulang' bukan nilai status_masuk; ia punya cabang sendiri di bawah.
$valid_statuses = ['Sakit', 'Izin', 'Alpha', 'Alpa', 'Izin Pulang'];
if (!in_array($status_manual, $valid_statuses)) {
    sendResponse('error', 'Status tidak valid');
}

// Normalisasi Alpha/Alpa
if ($status_manual === 'Alpha') {
    $status_manual = 'Alpa'; // Atau sebaliknya, sesuaikan dengan database Anda
}

try {
    // 1. Cek struktur kolom status_masuk
    $check_column = $conn->query("SHOW COLUMNS FROM absensi_siswa LIKE 'status_masuk'");
    $column_info = $check_column->fetch_assoc();
    
    // 2. Ambil data siswa
    $stmt_siswa = $conn->prepare("SELECT nama_siswa, kelas, kontak_ortu FROM siswa WHERE id = ?");
    if (!$stmt_siswa) {
        throw new Exception('Prepare statement gagal: ' . $conn->error);
    }
    
    $stmt_siswa->bind_param("i", $siswa_id);
    $stmt_siswa->execute();
    $result_siswa = $stmt_siswa->get_result();

    if ($result_siswa->num_rows == 0) {
        $stmt_siswa->close();
        sendResponse('error', 'Data siswa tidak ditemukan');
    }
    
    $siswa = $result_siswa->fetch_assoc();
    $stmt_siswa->close();
    
    $kontak_ortu = $siswa['kontak_ortu'];
    $tanggal_hari_ini = date('Y-m-d');

    // Izin pulang lebih awal: siswa yang sudah absen masuk hari ini pulang
    // sebelum jam pulang dengan izin sekolah. Barisnya sudah ada, jadi yang
    // diubah hanya status_harian. status_masuk (Tepat Waktu/Terlambat) tetap,
    // karena monitoring_siswa.tsx di ClassyncApp membacanya. Cron sore tidak
    // pernah menimpa Izin pada baris yang punya absen masuk.
    if ($status_manual === 'Izin Pulang') {
        $hari_sekolah = infoHariSekolah($conn, $tanggal_hari_ini);
        if (!$hari_sekolah['masuk_sekolah']) {
            sendResponse('error', 'Hari ini bukan hari sekolah.');
        }
        if (date('H:i:s') >= $hari_sekolah['jam_pulang']) {
            sendResponse('error', 'Sudah lewat jam pulang (' . date('H.i', strtotime($hari_sekolah['jam_pulang'])) . '). Siswa cukup absen pulang di kiosk.');
        }
        if (isset(daftarSiswaPkl($conn)[$siswa_id])) {
            sendResponse('error', 'Siswa ini sedang PKL. Izin pulangnya tidak dicatat di sini.');
        }

        // Satu transaksi dengan catatan pencatatnya, seperti status lain.
        $conn->begin_transaction();
        $stmt_baris = $conn->prepare("SELECT id, waktu_masuk, waktu_pulang, status_harian FROM absensi_siswa WHERE siswa_id = ? AND tanggal = ? FOR UPDATE");
        $stmt_baris->bind_param("is", $siswa_id, $tanggal_hari_ini);
        $stmt_baris->execute();
        $baris = $stmt_baris->get_result()->fetch_assoc();
        $stmt_baris->close();

        if (!$baris || empty($baris['waktu_masuk'])) {
            $conn->rollback();
            sendResponse('error', 'Siswa belum absen masuk hari ini. Izin pulang hanya untuk siswa yang sudah hadir.');
        }
        if (!empty($baris['waktu_pulang'])) {
            $conn->rollback();
            sendResponse('error', 'Siswa sudah absen pulang hari ini.');
        }
        if ($baris['status_harian'] === 'Izin') {
            $conn->rollback();
            sendResponse('error', 'Izin pulang siswa ini sudah dicatat.');
        }

        $baris_id = (int)$baris['id'];
        $stmt_izin = $conn->prepare("UPDATE absensi_siswa SET status_harian = 'Izin' WHERE id = ?");
        $stmt_izin->bind_param("i", $baris_id);
        $stmt_izin->execute();
        $stmt_izin->close();

        $waktu_log    = date('Y-m-d H:i:s');
        $guru_id_log  = $pencatat['jenis'] === 'guru'  ? $pencatat['id'] : null;
        $admin_id_log = $pencatat['jenis'] === 'admin' ? $pencatat['id'] : null;
        $stmt_log = $conn->prepare("INSERT INTO log_absen_manual (waktu, guru_id, admin_id, cara_masuk, siswa_id, tanggal, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt_log->bind_param("siisiss", $waktu_log, $guru_id_log, $admin_id_log, $pencatat['cara'], $siswa_id, $tanggal_hari_ini, $status_manual);
        $stmt_log->execute();
        $stmt_log->close();
        $conn->commit();

        $wa_sent = false;
        if (!empty($kontak_ortu)) {
            try {
                $pesan_wa  = "INFO ABSENSI SMK TERPADU AL HASAN\n\n";
                $pesan_wa .= "Yth. Bapak/Ibu Orang Tua/Wali dari:\n";
                $pesan_wa .= "Nama: *" . $siswa['nama_siswa'] . "*\n";
                $pesan_wa .= "Kelas: " . $siswa['kelas'] . "\n\n";
                $pesan_wa .= "Diberitahukan bahwa hari ini (" . date('d/m/Y') . ") Ananda *IZIN PULANG LEBIH AWAL* pada pukul *" . date('H:i') . " WIB* dengan izin sekolah.\n\n";
                $pesan_wa .= "Terima kasih.";
                $wa_sent = kirimNotifikasiWA(formatNomorWA($kontak_ortu), $pesan_wa);
            } catch (Exception $e) {
                logError('WA error: ' . $e->getMessage());
            }
        }

        $response_message = 'Izin pulang untuk ' . $siswa['nama_siswa'] . ' berhasil dicatat';
        if (!empty($kontak_ortu)) {
            $response_message .= $wa_sent ? ' dan notifikasi WhatsApp telah dikirim' : ' namun notifikasi WhatsApp GAGAL dikirim (Cek Log)';
        }
        sendResponse('success', $response_message);
    }

    // 3. Cek apakah sudah absen
    $stmt_cek = $conn->prepare("SELECT id FROM absensi_siswa WHERE siswa_id = ? AND tanggal = ?");
    $stmt_cek->bind_param("is", $siswa_id, $tanggal_hari_ini);
    $stmt_cek->execute();
    
    if ($stmt_cek->get_result()->num_rows > 0) {
        $stmt_cek->close();
        sendResponse('error', 'Siswa sudah melakukan absensi hari ini');
    }
    $stmt_cek->close();

    // Satu transaksi dengan catatan pencatat di bawah: tidak ada catatan
    // manual tanpa nama pencatatnya.
    $conn->begin_transaction();

    // 4. PERBAIKAN: Cek apakah kolom menggunakan ENUM
    if (strpos($column_info['Type'], 'enum') !== false) {
        // Jika ENUM, ambil nilai yang valid
        preg_match("/^enum\(\'(.*)\'\)$/", $column_info['Type'], $matches);
        $enum_values = explode("','", $matches[1]);
        
        // Cek apakah status_manual ada dalam ENUM
        if (!in_array($status_manual, $enum_values)) {
            // FALLBACK: Gunakan kolom keterangan jika ada
            $stmt_insert = $conn->prepare("INSERT INTO absensi_siswa (siswa_id, tanggal, keterangan) VALUES (?, ?, ?)");
            $keterangan_text = "Tidak Hadir - " . $status_manual;
            $stmt_insert->bind_param("iss", $siswa_id, $tanggal_hari_ini, $keterangan_text);
        } else {
            // Status valid dalam ENUM
            $stmt_insert = $conn->prepare("INSERT INTO absensi_siswa (siswa_id, tanggal, status_masuk) VALUES (?, ?, ?)");
            $stmt_insert->bind_param("iss", $siswa_id, $tanggal_hari_ini, $status_manual);
        }
    } else {
        // Jika VARCHAR atau TEXT, langsung insert
        $stmt_insert = $conn->prepare("INSERT INTO absensi_siswa (siswa_id, tanggal, status_masuk) VALUES (?, ?, ?)");
        $stmt_insert->bind_param("iss", $siswa_id, $tanggal_hari_ini, $status_manual);
    }
    
    if (!$stmt_insert->execute()) {
        throw new Exception('Gagal menyimpan: ' . $stmt_insert->error);
    }
    
    $stmt_insert->close();

    $waktu_log    = date('Y-m-d H:i:s');
    $guru_id_log  = $pencatat['jenis'] === 'guru'  ? $pencatat['id'] : null;
    $admin_id_log = $pencatat['jenis'] === 'admin' ? $pencatat['id'] : null;
    $stmt_log = $conn->prepare("INSERT INTO log_absen_manual (waktu, guru_id, admin_id, cara_masuk, siswa_id, tanggal, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt_log->bind_param("siisiss", $waktu_log, $guru_id_log, $admin_id_log, $pencatat['cara'], $siswa_id, $tanggal_hari_ini, $status_manual);
    if (!$stmt_log->execute()) {
        throw new Exception('Gagal mencatat pencatat: ' . $stmt_log->error);
    }
    $stmt_log->close();
    $conn->commit();
    
    logError("Absensi saved for: " . $siswa['nama_siswa']);

    // 5. Kirim WA
    $wa_sent = false;
    if (!empty($kontak_ortu)) {
        try {
            $nomor_wa_tujuan = formatNomorWA($kontak_ortu);
            
            $pesan_wa = "INFO ABSENSI SMK TERPADU AL HASAN\n\n";
            $pesan_wa .= "Yth. Bapak/Ibu Orang Tua/Wali dari:\n";
            $pesan_wa .= "Nama: *" . $siswa['nama_siswa'] . "*\n";
            $pesan_wa .= "Kelas: " . $siswa['kelas'] . "\n\n";
            $pesan_wa .= "Diberitahukan bahwa hari ini (" . date('d/m/Y') . ") Ananda dinyatakan *TIDAK HADIR* dengan keterangan:\n";
            $pesan_wa .= "Status: *" . $status_manual . "*\n\n";
            
            if($status_manual == 'Alpa' || $status_manual == 'Alpha') {
                $pesan_wa .= "Mohon konfirmasi atau informasi lebih lanjut kepada pihak sekolah.\n\n";
            }
            
            $pesan_wa .= "Terima kasih.";

            // MENGGUNAKAN FUNGSI WA SENDER YANG BARU (Tanpa panggil token manual)
            $wa_sent = kirimNotifikasiWA($nomor_wa_tujuan, $pesan_wa);
            
        } catch (Exception $e) {
            logError('WA error: ' . $e->getMessage());
        }
    }
    
    // 6. Response
    $response_message = 'Absensi ' . $status_manual . ' untuk ' . $siswa['nama_siswa'] . ' berhasil disimpan';
    
    if (!empty($kontak_ortu)) {
        if ($wa_sent) {
            $response_message .= ' dan notifikasi WhatsApp telah dikirim';
        } else {
            $response_message .= ' namun notifikasi WhatsApp GAGAL dikirim (Cek Log)';
        }
    }
    
    sendResponse('success', $response_message);

} catch (Exception $e) {
    $conn->rollback();
    logError('Error: ' . $e->getMessage());
    sendResponse('error', 'Terjadi kesalahan: ' . $e->getMessage());
}
?>