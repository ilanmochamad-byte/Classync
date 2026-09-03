<?php
// Middleware untuk otentikasi
require 'auth_middleware.php';

// Memvalidasi token dan mendapatkan data guru yang sedang login
$guru = authenticate();
$guru_id = $guru['id'];

// Hapus (set NULL) token otentikasi dari database untuk guru ini
$stmt = $conn->prepare("UPDATE guru SET auth_token = NULL WHERE id = ?");
$stmt->bind_param("i", $guru_id);

if ($stmt->execute()) {
    // Jika berhasil, kirim pesan sukses
    http_response_code(200); // OK
    echo json_encode(['status' => 'success', 'message' => 'Logout berhasil.']);
} else {
    // Jika gagal
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'Gagal melakukan logout di server.']);
}

$stmt->close();
$conn->close();
?>