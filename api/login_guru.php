<?php
// Izinkan header CORS untuk pengembangan
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require 'db.php';

// Membaca data dari form-data, bukan raw JSON
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Username dan password wajib diisi.']);
    exit;
}

$stmt = $conn->prepare("SELECT id, nama_guru, password FROM guru WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $guru = $result->fetch_assoc();
    if (password_verify($password, $guru['password'])) {
        // Buat token unik yang aman
        $token = bin2hex(random_bytes(32));

        // Simpan token ke database
        $update_stmt = $conn->prepare("UPDATE guru SET auth_token = ? WHERE id = ?");
        $update_stmt->bind_param("si", $token, $guru['id']);
        $update_stmt->execute();
        
        // Kirim respons sukses
        http_response_code(200); // OK
        echo json_encode([
            'status' => 'success',
            'message' => 'Login berhasil.',
            'token' => $token,
            'guru' => [
                'id' => $guru['id'],
                'nama_guru' => $guru['nama_guru']
            ]
        ]);
    } else {
        http_response_code(401); // Unauthorized
        echo json_encode(['status' => 'error', 'message' => 'Password salah.']);
    }
} else {
    http_response_code(404); // Not Found
    echo json_encode(['status' => 'error', 'message' => 'Username tidak ditemukan.']);
}

$stmt->close();
$conn->close();
?>