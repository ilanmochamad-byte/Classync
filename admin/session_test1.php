<?php
// Memulai sesi
session_start();

// Membuat sebuah variabel session
$_SESSION['test_login'] = 'sudah_login_jam_'.date('H:i:s');

echo "<h1>Langkah 1: Session Dibuat</h1>";
echo "<p>Sebuah session dengan nilai '<b>". $_SESSION['test_login'] ."</b>' telah dibuat.</p>";
echo "<p>Sekarang, klik link di bawah ini untuk memeriksa apakah session tersebut masih ada di halaman lain.</p>";
echo "<hr>";
echo '<a href="session_test2.php" style="font-size: 20px;">Lanjutkan ke Langkah 2: Periksa Session</a>';
?>