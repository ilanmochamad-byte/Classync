<?php
session_start();
// Jika tidak ada session login, tendang ke halaman login
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}
require '../includes/db.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <link rel="icon" type="image/png" href="../../classync.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="dashboard.php">Admin Absensi</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link" href="dashboard.php">Dashboard</a>
        </li>
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Data</a>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="guru.php">Data Guru</a></li>
                <li><a class="dropdown-item" href="siswa.php">Data Siswa</a></li>
                <li><a class="dropdown-item" href="mutasi_siswa.php">Mutasi & Kelulusan Siswa</a></li>
                <li><a class="dropdown-item" href="alumni.php">Data Alumni</a></li>
            </ul>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Jadwal</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="jadwal_mengajar.php">Jadwal Mengajar</a></li>
            <li><a class="dropdown-item" href="jadwal_piket.php">Jadwal Piket</a></li>
            <li><a class="dropdown-item" href="jadwal_ekskul.php">Jadwal Ekskul</a></li>
          </ul>
        </li>
        <!-- <li class="nav-item">-->
        <!--  <a class="nav-link" href="import.php">Import Data</a>-->
        <!--</li>-->
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Laporan</a>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="laporan.php">Absensi Guru</a></li>
                <li><a class="dropdown-item" href="laporan_absen_harian.php">Absensi Harian Guru</a></li>
                <li><a class="dropdown-item" href="statistik.php">Statistik Guru</a></li>
                <li><a class="dropdown-item" href="laporan_absensi_siswa.php">Absensi Siswa</a></li>
                <li><a class="dropdown-item" href="statistik_siswa.php">Statistik Siswa</a></li>
                <li><a class="dropdown-item" href="pusat_layanan_bk.php">Pusat Layanan BK</a></li>
            </ul>
        </li>
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Input Absen</a>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="absensi_manual.php">Input Manual</a></li>
                <li><a class="dropdown-item" href="approval_absensi.php">Approval Absen</a></li>
            </ul>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="penempatan_pkl.php">Penempatan PKL</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="profil_guru.php">Profil Guru</a>
        </li>
      <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Keuangan</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="pengaturan_honor.php">Pengaturan Honorarium</a></li>
            <li><a class="dropdown-item" href="keuangan_tunjangan.php">Manajemen Tunjangan</a></li>
            <li><a class="dropdown-item" href="keuangan_potongan.php">Input Potongan Bulanan</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="laporan_honor.php">Laporan Honor</a></li>
          </ul>
        </li>
      </ul>
      <a href="pengaturan.php" class="btn btn-outline-secondary me-2">
          <i class="bi bi-gear-fill"></i> Pengaturan</a>
      <a href="ubah_password.php" class="btn btn-outline-warning me-2">
          <i class="bi bi-key-fill"></i> Ubah Password</a>
      <a href="logout.php" class="btn btn-outline-danger">Logout</a>
    </div>
  </div>
</nav>
<div class="container mt-4">