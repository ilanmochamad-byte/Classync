<?php
session_start();

echo "<h1>Langkah 2: Hasil Pengecekan Session</h1>";

// Kita coba panggil kembali informasi yang tadi disimpan
if (isset($_SESSION['test_berhasil'])) {
    echo "<p style='color:green; font-weight:bold; font-size: 24px;'>✅ TES SESSION BERHASIL!</p>";
    echo "<p>Informasi yang tersimpan adalah: '<b>". $_SESSION['test_berhasil'] ."</b>'</p>";
    echo "<hr>";
    echo "<p>Jika ini muncul, berarti session Anda normal dan masalahnya sangat aneh, kita akan periksa lagi file login_process.php.</p>";
} else {
    echo "<p style='color:red; font-weight:bold; font-size: 24px;'>❌ TES SESSION GAGAL!</p>";
    echo "<p>Informasi session dari halaman pertama tidak ditemukan.</p>";
    echo "<hr>";
    echo "<p><b>Ini adalah 100% akar masalah Anda.</b> Server Anda tidak bisa menyimpan status login. Ini bukan kesalahan kode, melainkan masalah konfigurasi server hosting.</p>";
}
?>