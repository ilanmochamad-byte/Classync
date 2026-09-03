<?php
// admin/ekspor_jadwal.php
ini_set('display_errors', 0);

require '../includes/db.php';

// Ambil Filter 
$filter_hari = $_GET['hari'] ?? '';
$filter_guru_id = $_GET['guru_id'] ?? '';
$filter_kelas = $_GET['kelas'] ?? '';

$sql = "SELECT jm.hari, jm.jam_mulai, jm.jam_selesai, g.nama_guru, jm.mata_pelajaran, jm.kelas 
        FROM jadwal_mengajar jm 
        JOIN guru g ON jm.guru_id = g.id";

$conditions = [];
$params = [];
$types = '';

if (!empty($filter_hari)) {
    $conditions[] = "jm.hari = ?";
    $params[] = $filter_hari;
    $types .= 's';
}
if (!empty($filter_guru_id)) {
    $conditions[] = "jm.guru_id = ?";
    $params[] = $filter_guru_id;
    $types .= 'i';
}
if (!empty($filter_kelas)) {
    $conditions[] = "jm.kelas LIKE ?";
    $params[] = "%" . $filter_kelas . "%";
    $types .= 's';
}

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}
$sql .= " ORDER BY FIELD(jm.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'), jm.jam_mulai";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Persiapan File
$filename = "Rekap_Jadwal_Mengajar_" . date('Ymd') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

// Buka output stream
$output = fopen('php://output', 'w');

// Tambahkan BOM untuk kompabilitas Excel agar membaca UTF-8 dengan benar
fputs($output, "\xEF\xBB\xBF");

// Tulis Header Kolom
fputcsv($output, ['Hari', 'Jam Mulai', 'Jam Selesai', 'Nama Guru', 'Mata Pelajaran', 'Kelas']);

// Tulis Isi Data
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['hari'],
        date('H:i', strtotime($row['jam_mulai'])),
        date('H:i', strtotime($row['jam_selesai'])),
        $row['nama_guru'],
        $row['mata_pelajaran'],
        $row['kelas']
    ]);
}

fclose($output);
exit;
?>