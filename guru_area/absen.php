<?php
// Middleware untuk memeriksa otentikasi
require 'auth_middleware.php';

// Memvalidasi token dan mendapatkan data guru yang sedang login
$guru = authenticate();
$guru_id = $guru['id'];

// --- Logika Proses Absensi ---

// Input datang dari form-data, bukan JSON, karena ada file upload
$tipe_absensi = $_POST['tipe_absensi'] ?? null;
$jadwal_id = $_POST['jadwal_id'] ?? null;

// 1. Validasi input dasar
if (empty($tipe_absensi) || empty($jadwal_id) || !is_numeric($jadwal_id)) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Data absensi tidak lengkap.']);
    exit;
}

// 2. Cek apakah sudah absen sebelumnya untuk jadwal ini hari ini
$sql_cek = "SELECT id FROM absensi WHERE guru_id = ? AND jadwal_id = ? AND tipe_absensi = ? AND DATE(waktu_absensi) = CURDATE()";
$stmt_cek = $conn->prepare($sql_cek);
$stmt_cek->bind_param("iis", $guru_id, $jadwal_id, $tipe_absensi);
$stmt_cek->execute();
if ($stmt_cek->get_result()->num_rows > 0) {
    http_response_code(409); // Conflict
    echo json_encode(['status' => 'error', 'message' => 'Anda sudah melakukan absensi untuk jadwal ini hari ini.']);
    exit;
}
$stmt_cek->close();

// 3. Proses Upload Foto Bukti
$foto_bukti_path = null;
if (isset($_FILES['foto_bukti']) && $_FILES['foto_bukti']['error'] == 0) {
    $target_dir = "../uploads/"; // Simpan foto di folder /uploads/ di root website
    // Buat nama file yang unik untuk mencegah tumpang tindih
    $file_extension = strtolower(pathinfo($_FILES["foto_bukti"]["name"], PATHINFO_EXTENSION));
    $file_name = "foto-" . $guru_id . "-" . time() . "." . $file_extension;
    $target_file = $target_dir . $file_name;

    // Validasi file (gambar, ukuran, format)
    if (getimagesize($_FILES["foto_bukti"]["tmp_name"]) === false) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'File yang diupload bukan gambar.']);
        exit;
    }
    if ($_FILES["foto_bukti"]["size"] > 5000000) { // Batas 5MB
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Ukuran file terlalu besar (maks 5MB).']);
        exit;
    }
    if (!in_array($file_extension, ['jpg', 'jpeg', 'png'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Hanya format JPG, JPEG, PNG yang diizinkan.']);
        exit;
    }

    // Pindahkan file dari temporary ke direktori uploads
    if (move_uploaded_file($_FILES["foto_bukti"]["tmp_name"], $target_file)) {
        $foto_bukti_path = "uploads/" . $file_name;
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan file foto.']);
        exit;
    }
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Foto bukti wajib diupload.']);
    exit;
}

// 4. Simpan data absensi ke database
$waktu_absensi = date('Y-m-d H:i:s');
$sql_insert = "INSERT INTO absensi (guru_id, jadwal_id, tipe_absensi, waktu_absensi, status, foto_bukti) VALUES (?, ?, ?, ?, 'Hadir', ?)";
$stmt_insert = $conn->prepare($sql_insert);
$stmt_insert->bind_param("iisss", $guru_id, $jadwal_id, $tipe_absensi, $waktu_absensi, $foto_bukti_path);

if ($stmt_insert->execute()) {
    http_response_code(201); // Created
    echo json_encode(['status' => 'success', 'message' => 'Absensi berhasil direkam.']);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal merekam absensi ke database.']);
}

$stmt_insert->close();
$conn->close();
?>