<?php
// admin/ekspor_siswa.php
ini_set('display_errors', 0);

require '../includes/db.php';

// Ambil Filter 
$filter_kelas = $_GET['kelas'] ?? '';
$filter_jenis_kelamin = $_GET['jenis_kelamin'] ?? '';

// Pastikan tidak mengekspor data alumni
$sql = "SELECT nisn, nama_siswa, jenis_kelamin, kelas, kontak_ortu 
        FROM siswa WHERE kelas != 'Lulus / Alumni'";

$conditions = [];
$params = [];
$types = '';

if (!empty($filter_kelas)) {
    $sql .= " AND kelas LIKE ?";
    $params[] = "%" . $filter_kelas . "%";
    $types .= 's';
}
if (!empty($filter_jenis_kelamin)) {
    $sql .= " AND jenis_kelamin = ?";
    $params[] = $filter_jenis_kelamin;
    $types .= 's';
}

$sql .= " ORDER BY kelas ASC, nama_siswa ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Persiapan File
$filename = "Data_Siswa_Aktif_" . date('Ymd') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

// Buka output stream
$output = fopen('php://output', 'w');

// Tambahkan BOM untuk kompabilitas Excel agar membaca karakter khusus (UTF-8) dengan benar
fputs($output, "\xEF\xBB\xBF");

// Tulis Header Kolom
fputcsv($output, ['NISN', 'Nama Lengkap Siswa', 'Jenis Kelamin', 'Kelas Saat Ini', 'Kontak WhatsApp Ortu']);

// Tulis Isi Data
while ($row = $result->fetch_assoc()) {
    // Agar Excel tidak mengubah NISN yang diawali nol menjadi angka biasa (misal 0123 menjadi 123)
    $nisn_str = '="' . $row['nisn'] . '"'; 
    $kontak_str = '="' . $row['kontak_ortu'] . '"'; 

    fputcsv($output, [
        $nisn_str,
        $row['nama_siswa'],
        $row['jenis_kelamin'],
        $row['kelas'],
        $kontak_str
    ]);
}

fclose($output);
exit;
?>