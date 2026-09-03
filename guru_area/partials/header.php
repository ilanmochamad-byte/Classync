<?php
// --- KONFIGURASI HONOR ---
// Tarif honor dibaca dari database (tabel pengaturan) agar admin dapat mengubahnya
// tanpa perlu edit kode. Fallback ke nilai default jika data belum ada di DB.

// --- FUNGSI BANTU ---
// Fungsi ini hanya placeholder, bisa dikembangkan lebih lanjut
function cekAbsenBerturutTurut($conn, $guru_id, $bulan, $tahun) {
    return false; 
}
// --- AKHIR KONFIGURASI ---
session_start();
// Jika tidak ada session guru, tendang ke halaman login
if (!isset($_SESSION['guru_logged_in'])) {
    header('Location: ../login_guru.php');
    exit;
}
require '../includes/db.php';

// Baca tarif dari DB setelah koneksi tersedia
$_honor_query = $conn->query("SELECT nama_pengaturan, nilai_pengaturan FROM pengaturan WHERE nama_pengaturan LIKE 'honor_%'");
$_honor_tarif = [];
if ($_honor_query) {
    while ($_hr = $_honor_query->fetch_assoc()) {
        $_honor_tarif[$_hr['nama_pengaturan']] = (int)$_hr['nilai_pengaturan'];
    }
}
if (!defined('HONOR_PER_JP')) define('HONOR_PER_JP', $_honor_tarif['honor_per_jp'] ?? 10000);
if (!defined('HONOR_EKSKUL')) define('HONOR_EKSKUL', $_honor_tarif['honor_ekskul'] ?? 25000);
if (!defined('HONOR_PIKET'))  define('HONOR_PIKET',  $_honor_tarif['honor_piket'] ?? 25000);
if (!defined('HONOR_BK'))     define('HONOR_BK',     $_honor_tarif['honor_bk'] ?? 25000);
unset($_honor_query, $_honor_tarif, $_hr);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Guru</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-success">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php">Area Guru</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link" href="profil_guru.php">Profil Guru</a>
        </li>
    <div class="ms-auto text-white">
      Selamat datang, <?php echo htmlspecialchars($_SESSION['nama_guru']); ?>
      <a href="../logout_guru.php" class="btn btn-sm btn-outline-light ms-2">Logout</a>
    </div>
  </div>
</div>
</nav>
<div class="container mt-4">