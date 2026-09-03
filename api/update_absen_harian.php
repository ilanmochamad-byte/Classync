<?php
// api/update_absen_harian.php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

// Kredensial database dimuat dari luar webroot.
// Berkas itu mendefinisikan $db_host, $db_user, $db_pass, $db_name.
$config_db = '/DATA/k1807225/config/db-classync.php';
if (!is_readable($config_db)) {
    echo json_encode(['success' => false, 'message' => 'Konfigurasi database tidak ditemukan.']);
    exit;
}
require $config_db;

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $data = json_decode(file_get_contents('php://input'), true);

    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $jam_masuk = $data['jam_masuk'] ?? null;
    $jam_pulang = $data['jam_pulang'] ?? null;
    $bonus = isset($data['bonus']) ? (int)$data['bonus'] : 0;

    if ($id === 0 || !$jam_masuk) {
        throw new Exception("ID dan Jam Masuk tidak valid.");
    }

    $sql = "UPDATE absensi_harian SET jam_masuk = ?, jam_pulang = ?, bonus = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssii", $jam_masuk, $jam_pulang, $bonus, $id);
    
    if($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Data diperbarui']);
    } else {
        throw new Exception("Gagal menyimpan ke database.");
    }
    $stmt->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    if(isset($conn)) $conn->close();
}
?>