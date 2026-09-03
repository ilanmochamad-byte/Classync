<?php
require 'db.php'; // Menggunakan koneksi DB khusus API

$guru_id = $_GET['guru_id'] ?? 0;
$tipe = $_GET['tipe'] ?? '';

if (empty($guru_id) || empty($tipe)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Parameter tidak lengkap.']);
    exit;
}

$jadwal = [];
$sql = "";

switch ($tipe) {
    case 'mengajar':
        // PERBAIKAN: Menambahkan AND status_jadwal = 'Aktif'
        $sql = "SELECT id, mata_pelajaran, kelas, hari, jam_mulai FROM jadwal_mengajar WHERE guru_id = ? AND status_jadwal = 'Aktif'";
        break;
    case 'piket':
        // PERBAIKAN: Menambahkan AND status_jadwal = 'Aktif'
        $sql = "SELECT id, sesi, hari FROM jadwal_piket WHERE guru_id = ? AND status_jadwal = 'Aktif'";
        break;
    case 'ekskul':
        // PERBAIKAN: Menambahkan AND status_jadwal = 'Aktif'
        $sql = "SELECT id, nama_ekskul, hari, jam_mulai FROM jadwal_ekskul WHERE guru_id = ? AND status_jadwal = 'Aktif'";
        break;
    default:
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Tipe jadwal tidak valid.']);
        exit;
}

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $guru_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $detail = "";
    if ($tipe == 'mengajar') {
        // Format jam_mulai agar menghilangkan detik jika perlu (opsional)
        $jam = date('H:i', strtotime($row['jam_mulai']));
        $detail = "{$row['hari']}, {$jam} - {$row['mata_pelajaran']} (Kelas {$row['kelas']})";
    } elseif ($tipe == 'piket') {
        $detail = "{$row['hari']} - Sesi {$row['sesi']}";
    } elseif ($tipe == 'ekskul') {
        $jam = date('H:i', strtotime($row['jam_mulai']));
        $detail = "{$row['hari']}, {$jam} - {$row['nama_ekskul']}";
    }
    $jadwal[] = ['id' => $row['id'], 'detail' => $detail];
}

echo json_encode(['status' => 'success', 'jadwal' => $jadwal]);
$stmt->close();
$conn->close();
?>