<?php
// Memulai sesi untuk mencoba membaca variabel yang sudah dibuat
session_start();

echo "<h1>Langkah 2: Hasil Pengecekan Session</h1>";

// Mengecek apakah variabel session dari halaman pertama ada
if (isset($_SESSION['test_login'])) {
    echo "<p style='color:green; font-weight:bold; font-size: 24px;'>✅ TES BERHASIL!</p>";
    echo "<p>Session Anda berhasil ditemukan.</p>";
    echo "<p>Nilai session yang tersimpan adalah: '<b>". $_SESSION['test_login'] ."</b>'</p>";
    echo "<hr>";
    echo "<p>Ini berarti tidak ada masalah dengan kode atau PHP Session di server Anda. Masalahnya mungkin sangat spesifik dan aneh.</p>";
} else {
    echo "<p style='color:red; font-weight:bold; font-size: 24px;'>❌ TES GAGAL!</p>";
    echo "<p>Session Anda tidak ditemukan di halaman ini.</p>";
    echo "<hr>";
    echo "<p><b>Ini adalah penyebab masalah Anda.</b> Proses login berhasil, tetapi server tidak menyimpan status login Anda. Ini adalah masalah konfigurasi server hosting, bukan masalah kode.</p>";
}
?>