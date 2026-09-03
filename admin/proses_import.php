<?php
require 'partials/header.php';
require '../vendor/autoload.php'; // Path ke autoload dari Composer

use PhpOffice\PhpSpreadsheet\IOFactory;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file_excel'])) {
    $tipe_import = $_POST['tipe_import'];
    $file_tmp = $_FILES['file_excel']['tmp_name'];
    $file_name = $_FILES['file_excel']['name'];
    $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);

    if ($file_ext != 'xlsx') {
        header("Location: import.php?status=gagal&pesan=" . urlencode("Hanya file .xlsx yang diizinkan"));
        exit;
    }

    try {
        $spreadsheet = IOFactory::load($file_tmp);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();
        
        $conn->begin_transaction(); // Mulai transaksi
        $imported_count = 0;
        
        // Lewati baris header (baris pertama)
        array_shift($rows);

        if ($tipe_import == 'guru') {
            $stmt = $conn->prepare("INSERT INTO guru (nip, nama_guru, kontak) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE nama_guru=VALUES(nama_guru), kontak=VALUES(kontak)");
            foreach ($rows as $row) {
                if (empty($row[0])) continue; // Lewati baris kosong
                $stmt->bind_param("sss", $row[0], $row[1], $row[2]);
                $stmt->execute();
                $imported_count++;
            }
        } elseif ($tipe_import == 'jadwal_mengajar') {
            $stmt = $conn->prepare("INSERT INTO jadwal_mengajar (guru_id, hari, jam_mulai, jam_selesai, mata_pelajaran, kelas) VALUES ((SELECT id FROM guru WHERE nip = ?), ?, ?, ?, ?, ?)");
            foreach ($rows as $row) {
                if (empty($row[0])) continue;
                $stmt->bind_param("ssssss", $row[0], $row[1], $row[2], $row[3], $row[4], $row[5]);
                $stmt->execute();
                $imported_count++;
            }
        }
        // ... Tambahkan logika untuk tipe import lainnya (piket, ekskul) di sini ...

        $conn->commit(); // Konfirmasi transaksi jika berhasil
        header("Location: import.php?status=sukses&pesan=" . urlencode("$imported_count data berhasil diimpor."));

    } catch (Exception $e) {
        $conn->rollback(); // Batalkan transaksi jika ada error
        header("Location: import.php?status=gagal&pesan=" . urlencode("Terjadi error: " . $e->getMessage()));
    }
} else {
    header('Location: import.php');
}
exit;