<?php
session_start();
require '../includes/db.php';
require_once __DIR__ . '/../includes/unggah_gambar.php';

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

    // Foto lama dibaca dari basis data, BUKAN dari $_POST['foto_lama']. Dulu
    // nilai kiriman itu dipakai mentah di dua tempat: digabung ke path lalu
    // di-unlink(), dan disimpan ke kolom foto_profil. Guru mana pun yang
    // login bisa mengirim foto_lama=../../../config/db-classync.php dan
    // menghapus konfigurasi basis data — seluruh sistem mati. Lubang yang
    // sama sudah ditutup di repo API lewat commit 188c705. Kolom tersembunyi
    // foto_lama di formulir sengaja dibiarkan; nilainya kini diabaikan.
    $stmt_lama = $conn->prepare("SELECT foto_profil FROM guru WHERE id = ?");
    $stmt_lama->bind_param("i", $guru_id);
    $stmt_lama->execute();
    $baris_lama = $stmt_lama->get_result()->fetch_assoc();
    $stmt_lama->close();
    $foto_lama_db = $baris_lama['foto_profil'] ?? '';
    $foto_path_db = $foto_lama_db;

    // Proses upload foto baru jika ada. Nama berkas kiriman klien tidak
    // lagi masuk ke nama berkas, dan ekstensinya diambil dari tipe yang
    // terdeteksi — lihat includes/unggah_gambar.php.
    if (isset($_FILES['foto_profil']) && $_FILES['foto_profil']['error'] !== UPLOAD_ERR_NO_FILE) {
        $hasil = simpanGambarUnggahan($_FILES['foto_profil'], __DIR__ . '/../uploads/guru/', 'guru-' . (int)$guru_id);
        if (!$hasil['ok']) {
            echo "Gagal menyimpan foto: " . htmlspecialchars($hasil['pesan']);
            exit();
        }
        hapusFotoLamaAman($foto_lama_db);
        $foto_path_db = "uploads/guru/" . $hasil['nama'];
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