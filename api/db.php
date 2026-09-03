<?php
// Mengatur header agar output selalu JSON
header('Content-Type: application/json');

// Atur zona waktu
date_default_timezone_set('Asia/Jakarta');

// Konfigurasi Database
$db_host = 'localhost';
$db_user = 'k1807225_user_absensi';      // Ganti dengan username database Anda
$db_pass = 'Smktah2017!@#';   // Ganti dengan password Anda
$db_name = 'k1807225_sekolah_absensi'; // Ganti dengan nama database Anda

// Buat Koneksi
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Cek Koneksi
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Koneksi ke database gagal.']);
    exit;
}
?>