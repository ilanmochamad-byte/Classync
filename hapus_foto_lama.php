<?php
// Skrip ini dirancang untuk dijalankan oleh Cron Job, bukan diakses dari browser.
// Mengatur batas waktu eksekusi agar tidak berhenti di tengah jalan jika file banyak.
set_time_limit(300); // 300 detik = 5 menit

// Sertakan file koneksi database
// __DIR__ memastikan path file selalu benar, tidak peduli dari mana skrip ini dijalankan.
require __DIR__ . '/includes/db.php';

echo "Memulai proses penghapusan foto absensi yang lebih tua dari 30 hari...\n";

// 1. Tentukan tanggal 30 hari yang lalu
$tanggal_batas = date('Y-m-d H:i:s', strtotime('-30 days'));

// 2. Query untuk mencari semua absensi yang memenuhi kriteria
// Kriteria: Waktu absensi lebih lama dari tanggal batas DAN kolom foto_bukti tidak kosong
$sql_select = "SELECT id, foto_bukti FROM absensi WHERE waktu_absensi < ? AND foto_bukti IS NOT NULL AND foto_bukti != ''";
$stmt_select = $conn->prepare($sql_select);
$stmt_select->bind_param("s", $tanggal_batas);
$stmt_select->execute();
$result = $stmt_select->get_result();

if ($result->num_rows === 0) {
    echo "Tidak ada foto lama yang ditemukan untuk dihapus.\n";
    exit;
}

echo "Ditemukan " . $result->num_rows . " foto untuk diproses.\n\n";

$berhasil_dihapus = 0;
$gagal_dihapus = 0;
$id_untuk_update = [];

// 3. Loop melalui setiap hasil
while ($row = $result->fetch_assoc()) {
    $id_absensi = $row['id'];
    $path_file_relatif = $row['foto_bukti'];
    
    // Tentukan path file absolut di server
    $path_file_absolut = __DIR__ . '/' . $path_file_relatif;
    
    echo "Memproses file: " . $path_file_relatif . " ... ";

    // 4. Cek apakah file benar-benar ada
    if (file_exists($path_file_absolut)) {
        // 5. Hapus file dari server
        if (unlink($path_file_absolut)) {
            echo "FILE DIHAPUS.\n";
            $id_untuk_update[] = $id_absensi; // Kumpulkan ID untuk diupdate di database
            $berhasil_dihapus++;
        } else {
            echo "GAGAL MENGHAPUS FILE (permission error?).\n";
            $gagal_dihapus++;
        }
    } else {
        echo "FILE TIDAK DITEMUKAN (sudah dihapus sebelumnya?).\n";
        $id_untuk_update[] = $id_absensi; // Tetap update DB agar tidak dicek lagi
        $gagal_dihapus++;
    }
}

// 6. Update database untuk membersihkan path file yang sudah dihapus
if (!empty($id_untuk_update)) {
    // Mengubah array ID menjadi string yang dipisahkan koma, contoh: "5, 12, 23"
    $ids_string = implode(',', $id_untuk_update);
    
    $sql_update = "UPDATE absensi SET foto_bukti = NULL WHERE id IN ($ids_string)";
    
    if ($conn->query($sql_update)) {
        echo "\nDatabase berhasil diperbarui. " . count($id_untuk_update) . " baris telah dibersihkan.\n";
    } else {
        echo "\nGagal memperbarui database: " . $conn->error . "\n";
    }
}

echo "\nProses Selesai.\n";
echo "Total Berhasil Dihapus: " . $berhasil_dihapus . "\n";
echo "Total Gagal/Tidak Ditemukan: " . $gagal_dihapus . "\n";

$conn->close();
?>