<?php
// Izinkan header CORS untuk pengembangan
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

// Middleware untuk memeriksa token otentikasi
require 'auth_middleware.php'; 
require 'db.php';

// Fungsi untuk mendapatkan nama hari dalam Bahasa Indonesia
function getNamaHariIndonesia($englishDay) {
    $daftar_hari = array(
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
    );
    return $daftar_hari[$englishDay];
}

// Dapatkan ID guru dari token yang sudah divalidasi oleh middleware
$guru_id = $auth_guru_id; 

// Dapatkan nama guru
$stmt_guru = $conn->prepare("SELECT nama_guru FROM guru WHERE id = ?");
$stmt_guru->bind_param("i", $guru_id);
$stmt_guru->execute();
$nama_guru = $stmt_guru->get_result()->fetch_assoc()['nama_guru'];

// Dapatkan hari ini dalam format Indonesia (e.g., 'Senin')
// Pastikan zona waktu server Anda diatur ke Asia/Jakarta
date_default_timezone_set('Asia/Jakarta');
$hari_ini = getNamaHariIndonesia(date('l'));

// --- LOGIKA PENGAMBILAN JADWAL YANG DIPERBAIKI ---

// 1. Ambil SEMUA jadwal mengajar untuk hari ini
$sql_mengajar = "SELECT id as id_jadwal, jam_mulai, jam_selesai, mata_pelajaran, kelas as nama_kelas 
                 FROM jadwal_mengajar 
                 WHERE guru_id = ? AND hari = ? 
                 ORDER BY jam_mulai ASC";
$stmt_mengajar = $conn->prepare($sql_mengajar);
$stmt_mengajar->bind_param("is", $guru_id, $hari_ini);
$stmt_mengajar->execute();
$jadwal_mengajar = $stmt_mengajar->get_result()->fetch_all(MYSQLI_ASSOC);

// 2. Ambil SEMUA jadwal piket untuk hari ini
$sql_piket = "SELECT id, sesi 
              FROM jadwal_piket 
              WHERE guru_id = ? AND hari = ?";
$stmt_piket = $conn->prepare($sql_piket);
$stmt_piket->bind_param("is", $guru_id, $hari_ini);
$stmt_piket->execute();
$jadwal_piket = $stmt_piket->get_result()->fetch_all(MYSQLI_ASSOC);

// 3. Ambil SEMUA jadwal ekskul untuk hari ini
$sql_ekskul = "SELECT id, nama_ekskul, jam_mulai 
               FROM jadwal_ekskul 
               WHERE guru_id = ? AND hari = ? 
               ORDER BY jam_mulai ASC";
$stmt_ekskul = $conn->prepare($sql_ekskul);
$stmt_ekskul->bind_param("is", $guru_id, $hari_ini);
$stmt_ekskul->execute();
$jadwal_ekskul = $stmt_ekskul->get_result()->fetch_all(MYSQLI_ASSOC);


// --- Menyusun Respons JSON ---
$response_data = [
    'nama_guru' => $nama_guru,
    'jadwal_mengajar' => $jadwal_mengajar,
    'jadwal_piket' => $jadwal_piket,
    'jadwal_ekskul' => $jadwal_ekskul
];

// Kirimkan hasilnya sebagai JSON
http_response_code(200);
echo json_encode(['status' => 'success', 'data' => $response_data]);

$conn->close();
?>