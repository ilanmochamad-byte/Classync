<?php
// api/delete_absen_harian.php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

$db_host = "localhost";
$db_user = "k1807225_user_absensi";
$db_pass = "Smktah2017!@#";
$db_name = "k1807225_sekolah_absensi";

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $data = json_decode(file_get_contents('php://input'), true);

    $id = isset($data['id']) ? (int)$data['id'] : 0;

    if ($id === 0) throw new Exception("ID tidak valid.");

    $sql = "DELETE FROM absensi_harian WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Data dihapus']);
    } else {
        throw new Exception("Gagal menghapus dari database.");
    }
    $stmt->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    if(isset($conn)) $conn->close();
}
?>