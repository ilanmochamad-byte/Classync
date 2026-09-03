<?php
header('Content-Type: application/json');
require '../includes/db.php';
require '../includes/wa_sender.php'; // Panggil file WA Sender

// --- KONFIGURASI DEFAULT (digunakan jika DB belum berisi) ---
define('DEFAULT_JAM_BATAS_TERLAMBAT', '07:35:00'); // fallback jika pengaturan tidak ada
define('WA_GATEWAY_TOKEN', 'uZMYv6MUMwJ9v7QAEpV7');

// Ambil data JSON yang dikirim dari Javascript
$input = json_decode(file_get_contents('php://input'), true);
$nisn = $input['nisn'] ?? '';
$foto_base64 = $input['foto_base64'] ?? '';
$mode = $input['mode'] ?? 'masuk'; // Ambil mode ('masuk' atau 'pulang')

// Validasi input dasar
if (empty($nisn) || empty($foto_base64)) {
    echo json_encode(['status' => 'error', 'message' => 'Data NISN atau foto tidak lengkap.']);
    exit;
}

// --- Ambil pengaturan jam dari DB (jam_masuk / jam_pulang) ---
$jam_masuk_db = null;
$jam_pulang_db = null;
$res = $conn->query("SELECT nama_pengaturan, nilai_pengaturan FROM pengaturan WHERE nama_pengaturan IN ('jam_masuk','jam_pulang')");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        if ($r['nama_pengaturan'] === 'jam_masuk') $jam_masuk_db = $r['nilai_pengaturan'];
        if ($r['nama_pengaturan'] === 'jam_pulang') $jam_pulang_db = $r['nilai_pengaturan'];
    }
}

// Normalisasi format jam_masuk ke H:i:s (fallback ke DEFAULT_JAM_BATAS_TERLAMBAT)
function normalize_time_to_his($time_str, $fallback = DEFAULT_JAM_BATAS_TERLAMBAT) {
    if (empty($time_str)) return $fallback;
    // Terima format 'H:i' atau 'H:i:s' atau lainnya yang strtotime bisa parse
    $ts = strtotime($time_str);
    if ($ts === false) return $fallback;
    return date('H:i:s', $ts);
}

$jam_masuk_str = normalize_time_to_his($jam_masuk_db);
$jam_pulang_str = normalize_time_to_his($jam_pulang_db);

// Buat objek DateTime untuk perbandingan
$now_dt = new DateTime();
$now_str_his = $now_dt->format('H:i:s');

// buat DateTime dari jam_masuk_str
$jam_masuk_dt = DateTime::createFromFormat('H:i:s', $jam_masuk_str);
if (!$jam_masuk_dt) {
    // fallback ke default
    $jam_masuk_dt = DateTime::createFromFormat('H:i:s', DEFAULT_JAM_BATAS_TERLAMBAT);
}

// 1. Cari Siswa
$stmt_siswa = $conn->prepare("SELECT id, nama_siswa, kelas, kontak_ortu FROM siswa WHERE nisn = ?");
$stmt_siswa->bind_param("s", $nisn);
$stmt_siswa->execute();
$result_siswa = $stmt_siswa->get_result();

if ($result_siswa->num_rows == 0) {
    echo json_encode(['status' => 'error', 'message' => 'NISN tidak terdaftar.']);
    exit;
}

$siswa = $result_siswa->fetch_assoc();
$siswa_id = $siswa['id'];
$kontak_ortu = $siswa['kontak_ortu'];
$tanggal_hari_ini = date('Y-m-d');
$waktu_sekarang_full = $now_str_his;
$waktu_sekarang_simple = $now_dt->format('H:i'); // untuk tampilan

// 2. Cek absensi hari ini (ambil juga status_masuk bila sudah ada)
$stmt_cek = $conn->prepare("SELECT id, waktu_masuk, waktu_pulang, status_masuk FROM absensi_siswa WHERE siswa_id = ? AND tanggal = ?");
$stmt_cek->bind_param("is", $siswa_id, $tanggal_hari_ini);
$stmt_cek->execute();
$absensi_hari_ini = $stmt_cek->get_result()->fetch_assoc();


// ---------------------------------
// LOGIKA UTAMA: MASUK vs PULANG
// ---------------------------------

if ($mode == 'masuk') {
    
    // --- PROSES ABSEN MASUK ---
    
    if ($absensi_hari_ini) {
        echo json_encode(['status' => 'error', 'message' => 'Anda sudah absen MASUK hari ini.']);
        exit;
    }

    // Tentukan status masuk (Tepat Waktu / Terlambat) berdasarkan jam_masuk dari DB
    // Bandingkan objek DateTime
    $status_masuk = ($now_dt > $jam_masuk_dt) ? 'Terlambat' : 'Tepat Waktu';
    
    // Proses dan simpan foto
    $nama_file_foto = 'uploads/foto_masuk/' . $siswa_id . '_' . $tanggal_hari_ini . '.jpg';
    simpanFoto($foto_base64, '../' . $nama_file_foto);

    // Simpan ke database
    $stmt_insert = $conn->prepare("INSERT INTO absensi_siswa (siswa_id, tanggal, waktu_masuk, foto_masuk, status_masuk) VALUES (?, ?, ?, ?, ?)");
    $stmt_insert->bind_param("issss", $siswa_id, $tanggal_hari_ini, $waktu_sekarang_full, $nama_file_foto, $status_masuk);

    if ($stmt_insert->execute()) {
        // Kirim Notifikasi WA Masuk (server-side)
        kirimNotifikasi($kontak_ortu, $siswa, 'MASUK', $waktu_sekarang_full, $status_masuk);

        // Kirim balasan sukses ke Kiosk
        echo json_encode([
            'status' => 'success',
            'message' => 'Absensi ' . $status_masuk . ' berhasil!',
            'data' => [
                'nama_siswa' => $siswa['nama_siswa'],
                'kelas' => $siswa['kelas'],
                'waktu_masuk' => $waktu_sekarang_full,
                'status_masuk' => $status_masuk,
                'foto_absensi' => $nama_file_foto
            ]
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan ke database.']);
    }

} else {
    
    // --- PROSES ABSEN PULANG ---

    if (!$absensi_hari_ini) {
        echo json_encode(['status' => 'error', 'message' => 'Anda BELUM absen MASUK hari ini.']);
        exit;
    }

    if (!empty($absensi_hari_ini['waktu_pulang'])) {
        echo json_encode(['status' => 'error', 'message' => 'Anda sudah absen PULANG hari ini.']);
        exit;
    }

    // Proses dan simpan foto pulang
    $nama_file_foto = 'uploads/foto_pulang/' . $siswa_id . '_' . $tanggal_hari_ini . '.jpg';
    simpanFoto($foto_base64, '../' . $nama_file_foto);

    // Update database
    $stmt_update = $conn->prepare("UPDATE absensi_siswa SET waktu_pulang = ?, foto_pulang = ? WHERE id = ?");
    $stmt_update->bind_param("ssi", $waktu_sekarang_full, $nama_file_foto, $absensi_hari_ini['id']);
    
    if ($stmt_update->execute()) {
        // Kirim Notifikasi WA Pulang
        kirimNotifikasi($kontak_ortu, $siswa, 'PULANG', $waktu_sekarang_full, null);

        // Ambil status_masuk yang sudah tersimpan (jika ada)
        $status_masuk_lama = $absensi_hari_ini['status_masuk'] ?? '';

        // Kirim balasan sukses ke Kiosk
        echo json_encode([
            'status' => 'success',
            'message' => 'Absensi PULANG berhasil!',
            'data' => [
                'nama_siswa' => $siswa['nama_siswa'],
                'kelas' => $siswa['kelas'],
                'waktu_masuk' => $absensi_hari_ini['waktu_masuk'], // Waktu masuk yang lama
                'waktu_pulang' => $waktu_sekarang_full,           // Waktu pulang yang baru
                'status_masuk' => $status_masuk_lama, // kembalikan status saat masuk (Tepat Waktu/Terlambat)
                'foto_absensi' => $nama_file_foto // Foto pulang
            ]
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Gagal update database.']);
    }
}


// ------------------------------------------------------------------
// FUNGSI BANTUAN
// ------------------------------------------------------------------

/**
 * Menyimpan foto base64 ke file
 */
function simpanFoto($base64_string, $output_file) {
    // Pastikan folder ada
    $folder = dirname($output_file);
    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
    }

    // Hapus prefix data URL bila ada (mendukung jpeg/png)
    $foto_data = preg_replace('#^data:image/\w+;base64,#i', '', $base64_string);
    $foto_data = base64_decode($foto_data);
    file_put_contents($output_file, $foto_data);
}

/**
 * Mengirim notifikasi WA
 */
function kirimNotifikasi($kontak_ortu, $siswa, $mode, $waktu, $status_masuk) {
    if (!empty($kontak_ortu)) {
        try {
            $nomor_wa_tujuan = formatNomorWA($kontak_ortu); // Panggil fungsi dari wa_sender.php
            
            $pesan_wa = "INFO ABSENSI SMK TERPADU AL HASAN\n\n";
            $pesan_wa .= "Yth. Bpk/Ibu Wali dari:\n";
            $pesan_wa .= "Nama: *" . $siswa['nama_siswa'] . "*\n";
            $pesan_wa .= "Kelas: " . $siswa['kelas'] . "\n\n";
            
            if ($mode == 'MASUK') {
                $pesan_wa .= "Telah melakukan absensi *MASUK* pada:\n";
                $pesan_wa .= "Pukul: *" . $waktu . " WIB*\n";
                $pesan_wa .= "Status: *" . $status_masuk . "*\n\n";
            } else {
                $pesan_wa .= "Telah melakukan absensi *PULANG* pada:\n";
                $pesan_wa .= "Pukul: *" . $waktu . " WIB*\n\n";
            }
            
            $pesan_wa .= "Terima kasih.";

            // Panggil fungsi pengirim WA "fire and forget"
            kirimNotifikasiWA(defined('WA_GATEWAY_TOKEN') ? WA_GATEWAY_TOKEN : '', $nomor_wa_tujuan, $pesan_wa);
            
        } catch (Exception $e) {
            // error_log('Gagal kirim WA: ' . $e->getMessage());
        }
    }
}

?>