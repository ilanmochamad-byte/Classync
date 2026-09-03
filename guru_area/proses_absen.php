<?php
session_start();
require '../includes/db.php';

// Cek apakah guru sudah login
if (!isset($_SESSION['guru_logged_in'])) {
    header('Location: ../login_guru.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $guru_id = $_SESSION['guru_id'];
    $jadwal_id = $_POST['jadwal_id'];
    $tipe_absensi = $_POST['tipe_absensi'];
    $waktu_absensi = date('Y-m-d H:i:s');
    $foto_bukti_path = null;

    // --- LOGIKA UPLOAD FOTO ---
    if (isset($_FILES['foto_bukti']) && $_FILES['foto_bukti']['error'] == 0) {
        $target_dir = "../uploads/";
        $file_name = time() . '-' . basename($_FILES["foto_bukti"]["name"]);
        $target_file = $target_dir . $file_name;
        $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

        // Cek apakah file gambar
        $check = getimagesize($_FILES["foto_bukti"]["tmp_name"]);
        if($check === false) {
            header("Location: index.php?status=gagal&pesan=" . urlencode("File bukan gambar."));
            exit();
        }

        // Cek ukuran file (misal, maks 5MB)
        if ($_FILES["foto_bukti"]["size"] > 5000000) {
            header("Location: index.php?status=gagal&pesan=" . urlencode("Ukuran file terlalu besar."));
            exit();
        }

        // Izinkan format tertentu
        if($imageFileType != "jpg" && $imageFileType != "png" && $imageFileType != "jpeg") {
            header("Location: index.php?status=gagal&pesan=" . urlencode("Hanya format JPG, JPEG, PNG yang diizinkan."));
            exit();
        }

        // Pindahkan file
        if (move_uploaded_file($_FILES["foto_bukti"]["tmp_name"], $target_file)) {
            $foto_bukti_path = "uploads/" . $file_name;
        } else {
            header("Location: index.php?status=gagal&pesan=" . urlencode("Gagal mengupload foto."));
            exit();
        }
    } else {
        header("Location: index.php?status=gagal&pesan=" . urlencode("Foto bukti wajib diupload."));
        exit();
    }
    
    // --- LOGIKA SIMPAN KE DATABASE ---
    $sql = "INSERT INTO absensi (guru_id, jadwal_id, tipe_absensi, waktu_absensi, status, foto_bukti) VALUES (?, ?, ?, ?, 'Hadir', ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iisss", $guru_id, $jadwal_id, $tipe_absensi, $waktu_absensi, $foto_bukti_path);
    
    if($stmt->execute()){
        header("Location: index.php?status=sukses&pesan=" . urlencode("Absensi berhasil direkam."));
    } else {
        header("Location: index.php?status=gagal&pesan=" . urlencode("Gagal merekam absensi ke database."));
    }
    exit();
}