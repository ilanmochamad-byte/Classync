<?php
session_start();
require 'includes/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, nama_guru, password FROM guru WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $guru = $result->fetch_assoc();
        if (password_verify($password, $guru['password'])) {
            $_SESSION['guru_logged_in'] = true;
            $_SESSION['guru_id'] = $guru['id'];
            $_SESSION['nama_guru'] = $guru['nama_guru'];
            header('Location: guru_area/');
            exit;
        }
    }
    
    header('Location: login_guru.php?error=' . urlencode('Username atau password salah'));
    exit;
}