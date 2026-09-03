<?php
session_start();

// Kita mencoba menyimpan informasi sederhana ke dalam session
$_SESSION['test_berhasil'] = "PHP Session berfungsi dengan baik!";

echo "<h1>Langkah 1: Session Dibuat</h1>";
echo "<p>Informasi sudah disimpan ke dalam session.</p>";
echo "<p>Sekarang, klik link di bawah ini untuk memeriksa apakah informasinya masih ada.</p>";
echo '<hr>';
echo '<a href="test_sesi2.php" style="font-size: 20px;">Lanjutkan ke Langkah 2: Periksa Session</a>';
?>