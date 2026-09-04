<?php
// admin_notifikasi.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- KONFIGURASI ---
// Path ke file Kunci Akun Layanan (Service Account Key) JSON Anda
$serviceAccountKeyPath = '/DATA/k1807225/credentials/classyncapp-9a6b6-firebase-adminsdk-fbsvc-a059a16151.json'; // GANTI DENGAN PATH YANG BENAR
// ID Proyek Firebase Anda
$projectId = 'classyncapp-9a6b6'; // GANTI JIKA BERBEDA

// Database — kredensial dimuat dari luar webroot.
// Berkas itu mendefinisikan $db_host, $db_user, $db_pass, $db_name.
$config_db = '/DATA/k1807225/config/db-classync.php';
if (!is_readable($config_db)) {
    die("Koneksi database gagal: konfigurasi tidak ditemukan.");
}
require $config_db;
// --------------------

// Sertakan autoloader dari Composer
require_once __DIR__ . '/vendor/autoload.php';

// --- FUNGSI PENGIRIMAN (Sama seperti skrip harian) ---

function getAccessToken($keyFilePath) {
    $client = new \Google\Client();
    $client->setAuthConfig($keyFilePath);
    $client->addScope('https://www.googleapis.com/auth/cloud-platform');
    $client->fetchAccessTokenWithAssertion();
    $accessToken = $client->getAccessToken();
    if (!isset($accessToken['access_token'])) {
        throw new Exception("Gagal mendapatkan Access Token.");
    }
    return $accessToken['access_token'];
}

function sendPushNotification($token, $title, $body, $accessToken, $projectId) {
    $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
    $data = [
        'message' => [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'android' => ['notification' => ['sound' => 'default', 'channel_id' => 'default']]
        ]
    ];
    $headers = ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}
// --- AKHIR FUNGSI PENGIRIMAN ---


// --- Logika Halaman ---
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

$status_message = '';
$status_type = '';

// 1. TANGANI FORM JIKA DIKIRIM (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $guru_id = $_POST['guru_id'] ?? 0;
    $title = $_POST['title'] ?? 'Pesan dari Admin';
    $message = $_POST['message'] ?? '';

    if (empty($guru_id) || empty($message)) {
        $status_message = "Error: Guru dan Isi Pesan tidak boleh kosong.";
        $status_type = 'danger';
    } else {
        try {
            // Ambil push token guru yang dipilih
            $stmt = $conn->prepare("SELECT push_token, nama_guru FROM guru WHERE id = ?");
            $stmt->bind_param("i", $guru_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $guru = $result->fetch_assoc();
            
            if ($guru && !empty($guru['push_token'])) {
                // Dapatkan Access Token
                $accessToken = getAccessToken($serviceAccountKeyPath);
                // Kirim notifikasi
                sendPushNotification($guru['push_token'], $title, $message, $accessToken, $projectId);
                
                $status_message = "Notifikasi berhasil dikirim ke: " . $guru['nama_guru'];
                $status_type = 'success';
            } else {
                $status_message = "Error: Guru ini tidak memiliki push token terdaftar.";
                $status_type = 'warning';
            }
            $stmt->close();
        } catch (Exception $e) {
            $status_message = "Error: " . $e->getMessage();
            $status_type = 'danger';
        }
    }
}

// 2. AMBIL DAFTAR GURU UNTUK DROPDOWN
$guru_list_result = $conn->query("SELECT id, nama_guru FROM guru WHERE push_token IS NOT NULL AND push_token != '' ORDER BY nama_guru ASC");
$conn->close();

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Kirim Notifikasi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h3>Kirim Notifikasi Manual ke Guru</h3>
                    </div>
                    <div class="card-body">
                        
                        <?php if ($status_message): ?>
                            <div class="alert alert-<?php echo $status_type; ?>" role="alert">
                                <?php echo htmlspecialchars($status_message); ?>
                            </div>
                        <?php endif; ?>

                        <form action="admin_notifikasi.php" method="POST">
                            <div class="mb-3">
                                <label for="guru_id" class="form-label">Kirim Ke:</label>
                                <select name="guru_id" id="guru_id" class="form-select" required>
                                    <option value="">-- Pilih Guru --</option>
                                    <?php while($guru = $guru_list_result->fetch_assoc()): ?>
                                        <option value="<?php echo $guru['id']; ?>">
                                            <?php echo htmlspecialchars($guru['nama_guru']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <small class="form-text text-muted">Hanya guru yang memiliki push token terdaftar yang muncul di sini.</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="title" class="form-label">Judul Notifikasi:</label>
                                <input type="text" name="title" id="title" class="form-control" value="Pesan dari Admin" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="message" class="form-label">Isi Pesan:</label>
                                <textarea name="message" id="message" class="form-control" rows="4" placeholder="Tulis pesan Anda di sini..." required></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">Kirim Notifikasi</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>