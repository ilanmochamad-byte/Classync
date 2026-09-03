<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

// Mengaktifkan laporan error untuk tujuan debugging jika diperlukan
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

// Membungkus seluruh logika di dalam blok try-catch
try {
    // Langkah 1: Hubungkan ke database. Ini membuat variabel $conn.
    require 'db.php'; 

    // Langkah 2: Jalankan middleware. File ini akan melempar Exception jika gagal.
    require 'auth_middleware.php';

    // Jika variabel ini tidak ada setelah middleware, berarti ada masalah
    if (!isset($auth_guru_id) || empty($auth_guru_id)) {
        throw new Exception("Middleware otentikasi gagal menyediakan ID guru.", 500);
    }

    // Fungsi untuk mendapatkan nama hari
    function getNamaHariIndonesia($englishDay) {
        $daftar_hari = [
            'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
        ];
        return $daftar_hari[$englishDay] ?? 'Hari Tidak Valid';
    }

    $guru_id = $auth_guru_id; 

    $stmt_guru = $conn->prepare("SELECT nama_guru FROM guru WHERE id = ?");
    $stmt_guru->bind_param("i", $guru_id);
    $stmt_guru->execute();
    $nama_guru_result = $stmt_guru->get_result();
    $nama_guru = $nama_guru_result->num_rows > 0 ? $nama_guru_result->fetch_assoc()['nama_guru'] : 'Guru Tidak Ditemukan';

    date_default_timezone_set('Asia/Jakarta');
    $hari_ini = getNamaHariIndonesia(date('l'));

    // Query untuk jadwal mengajar
    $sql_mengajar = "SELECT id as id_jadwal, jam_mulai, jam_selesai, mata_pelajaran, kelas as nama_kelas FROM jadwal_mengajar WHERE guru_id = ? AND hari = ? ORDER BY jam_mulai ASC";
    $stmt_mengajar = $conn->prepare($sql_mengajar);
    $stmt_mengajar->bind_param("is", $guru_id, $hari_ini);
    $stmt_mengajar->execute();
    $jadwal_mengajar = $stmt_mengajar->get_result()->fetch_all(MYSQLI_ASSOC);

    // Query untuk jadwal piket
    $sql_piket = "SELECT id, sesi FROM jadwal_piket WHERE guru_id = ? AND hari = ?";
    $stmt_piket = $conn->prepare($sql_piket);
    $stmt_piket->bind_param("is", $guru_id, $hari_ini);
    $stmt_piket->execute();
    $jadwal_piket = $stmt_piket->get_result()->fetch_all(MYSQLI_ASSOC);

    // Query untuk jadwal ekskul
    $sql_ekskul = "SELECT id, nama_ekskul, jam_mulai FROM jadwal_ekskul WHERE guru_id = ? AND hari = ? ORDER BY jam_mulai ASC";
    $stmt_ekskul = $conn->prepare($sql_ekskul);
    $stmt_ekskul->bind_param("is", $guru_id, $hari_ini);
    $stmt_ekskul->execute();
    $jadwal_ekskul = $stmt_ekskul->get_result()->fetch_all(MYSQLI_ASSOC);

    $response_data = [
        'nama_guru' => $nama_guru,
        'jadwal_mengajar' => $jadwal_mengajar,
        'jadwal_piket' => $jadwal_piket,
        'jadwal_ekskul' => $jadwal_ekskul
    ];

    http_response_code(200);
    echo json_encode(['status' => 'success', 'data' => $response_data]);

} catch (Exception $e) {
    // "Sarung tangan" yang akan menangkap semua error
    // Tentukan kode status HTTP dari exception, atau default ke 500
    $statusCode = is_numeric($e->getCode()) && $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($statusCode);
    
    echo json_encode([
        'status' => 'error',
        'message' => 'Terjadi kesalahan pada server.',
        'error_details' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>