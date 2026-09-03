<?php
echo "<h1>Tes Koneksi Database</h1>";

// Memanggil file konfigurasi database Anda
require 'includes/db.php';

// Mengecek status koneksi dari file db.php
if ($conn && $conn->ping()) {
    echo "<p style='color:green; font-weight:bold;'>SELAMAT! Koneksi ke database '{$db_name}' berhasil.</p>";
} else {
    echo "<p style='color:red; font-weight:bold;'>GAGAL! Tidak bisa terhubung ke database.</p>";
    echo "<p>Pesan Error: " . $conn->connect_error . "</p>";
    echo "<p>Mohon periksa kembali file 'includes/db.php' Anda.</p>";
}

$conn->close();
?>