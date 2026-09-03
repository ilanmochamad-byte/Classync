<?php
// Middleware untuk otentikasi
require 'auth_middleware.php';

// Memvalidasi token dan mendapatkan data guru
$guru = authenticate();
$guru_id = $guru['id'];

// Query untuk mengambil semua riwayat absensi milik guru yang login.
// Kita menggunakan LEFT JOIN dan COALESCE untuk menggabungkan detail dari 
// berbagai jenis jadwal (mengajar, piket, ekskul) menjadi satu kolom 'keterangan'.
$sql = "
    SELECT 
        a.id,
        a.waktu_absensi,
        a.tipe_absensi,
        a.foto_bukti,
        COALESCE(
            CONCAT(jm.mata_pelajaran, ' - Kelas ', jm.kelas), 
            CONCAT('Piket Sesi ', jp.sesi), 
            je.nama_ekskul
        ) as keterangan
    FROM 
        absensi a
    LEFT JOIN jadwal_mengajar jm ON a.jadwal_id = jm.id AND a.tipe_absensi = 'mengajar'
    LEFT JOIN jadwal_piket jp ON a.jadwal_id = jp.id AND a.tipe_absensi = 'piket'
    LEFT JOIN jadwal_ekskul je ON a.jadwal_id = je.id AND a.tipe_absensi = 'ekskul'
    WHERE 
        a.guru_id = ?
    ORDER BY 
        a.waktu_absensi DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $guru_id);
$stmt->execute();
$result = $stmt->get_result();

$riwayat = [];
while ($row = $result->fetch_assoc()) {
    $riwayat[] = $row;
}

// Mengemas data untuk dikirim sebagai JSON
$response = [
    'status' => 'success',
    'data' => $riwayat
];

// Mengirim response
http_response_code(200); // OK
echo json_encode($response);

$stmt->close();
$conn->close();
?>