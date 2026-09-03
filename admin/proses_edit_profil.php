<?php
session_start();
require '../includes/db.php';

if (!isset($_SESSION['guru_logged_in'])) {
    header('Location: ../login_guru.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $guru_id = $_SESSION['guru_id'];

    // Ambil semua data dari form
    $nama_guru = $_POST['nama_guru'];
    $nip = $_POST['nip'];
    $tempat_lahir = $_POST['tempat_lahir'];
    $tanggal_lahir = $_POST['tanggal_lahir'];
    $kontak = $_POST['kontak'];
    $pendidikan_s1 = $_POST['pendidikan_s1'];
    $pendidikan_s2 = $_POST['pendidikan_s2'];
    $pendidikan_s3 = $_POST['pendidikan_s3'];
    $tugas_tambahan = $_POST['tugas_tambahan'];

    $foto_path_db = $_POST['foto_lama']; // Gunakan foto lama sebagai default

    // Proses upload foto baru jika ada
    if (isset($_FILES['foto_profil']) && $_FILES['foto_profil']['error'] == 0) {
        $target_dir = "../uploads/guru/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
        $file_name = "guru-" . $guru_id . "-" . time() . basename($_FILES["foto_profil"]["name"]);
        $target_file = $target_dir . $file_name;
        
        if (move_uploaded_file($_FILES["foto_profil"]["tmp_name"], $target_file)) {
            // Hapus foto lama jika ada
            if (!empty($foto_path_db) && file_exists("../".$foto_path_db)) {
                unlink("../".$foto_path_db);
            }
            $foto_path_db = "uploads/guru/" . $file_name;
        }
    }

    // Update data ke database
    $stmt = $conn->prepare("UPDATE guru SET nama_guru=?, nip=?, tempat_lahir=?, tanggal_lahir=?, kontak=?, pendidikan_s1=?, pendidikan_s2=?, pendidikan_s3=?, tugas_tambahan=?, foto_profil=? WHERE id=?");
    $stmt->bind_param("ssssssssssi", $nama_guru, $nip, $tempat_lahir, $tanggal_lahir, $kontak, $pendidikan_s1, $pendidikan_s2, $pendidikan_s3, $tugas_tambahan, $foto_path_db, $guru_id);
    
    if ($stmt->execute()) {
        // Jika berhasil, kembali ke halaman profil dengan notifikasi sukses
        header("Location: profil_guru.php?status=sukses");
    } else {
        // Handle error jika perlu
        echo "Gagal menyimpan data: " . $stmt->error;
    }
    exit();
}
?>