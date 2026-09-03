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

// Ambil data JSON
$input_raw = file_get_contents('php://input');
$input = json_decode($input_raw, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    sendResponse('error', 'Format data tidak valid');
}

$siswa_id = isset($input['siswa_id']) ? intval($input['siswa_id']) : 0;
$status_manual = isset($input['status']) ? trim($input['status']) : '';

// Validasi input
if (empty($siswa_id) || empty($status_manual)) {
    sendResponse('error', 'Data tidak lengkap');
}

// PERBAIKAN: Pastikan status sesuai format database
$valid_statuses = ['Sakit', 'Izin', 'Alpha', 'Alpa'];
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

    // 3. Cek apakah sudah absen
    $stmt_cek = $conn->prepare("SELECT id FROM absensi_siswa WHERE siswa_id = ? AND tanggal = ?");
    $stmt_cek->bind_param("is", $siswa_id, $tanggal_hari_ini);
    $stmt_cek->execute();
    
    if ($stmt_cek->get_result()->num_rows > 0) {
        $stmt_cek->close();
        sendResponse('error', 'Siswa sudah melakukan absensi hari ini');
    }
    $stmt_cek->close();

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
    logError('Error: ' . $e->getMessage());
    sendResponse('error', 'Terjadi kesalahan: ' . $e->getMessage());
}
?>