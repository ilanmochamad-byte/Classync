<?php
// Mengatur header agar output selalu JSON
header('Content-Type: application/json');

// Atur zona waktu
date_default_timezone_set('Asia/Jakarta');

$config_db = '/DATA/k1807225/config/db-classync.php';
if (!is_readable($config_db)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Koneksi ke database gagal.']);
    exit;
}
require $config_db;

// Buat Koneksi
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Cek Koneksi
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Koneksi ke database gagal.']);
    exit;
}
?>