<?php 
include 'partials/header.php';

// Helper: Menembak API FCM yang berada di Server B (api.smkt.alhasan.co.id)
function panggilApiFCMServerB($token, $title, $body, $screenTarget) {
    $url = 'https://api.smkt.alhasan.co.id/send_fcm_api.php';
    
    // Siapkan data, pastikan kunci rahasia sama dengan yang ada di send_fcm_api.php
    $data = [
        'secret' => 'SMKTAH_Classync_2026_Secure!',
        'token' => $token,
        'title' => $title,
        'body' => $body,
        'screen' => $screenTarget
    ];

    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($data)
        ]
    ];
    $context  = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    return $result;
}

// Helper: Konversi Nama Hari (Inggris -> Indonesia)
function getHariIndonesia($date) {
    $days = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
    ];
    return $days[date('l', strtotime($date))];
}

$msg = "";

if (isset($_POST['action'])) {
    $id = $_POST['id'];
    $status = $_POST['action']; // 'Disetujui' atau 'Ditolak'
    
    // 1. Ambil data pengajuan mentah
    $stmt_req = $conn->prepare("SELECT * FROM pengajuan_absensi WHERE id = ?");
    $stmt_req->bind_param("i", $id);
    $stmt_req->execute();
    $req = $stmt_req->get_result()->fetch_assoc();
    $stmt_req->close();
    
    if (!$req) die("Data tidak ditemukan.");

    // Variabel untuk Push Notification
    $notif_title = "";
    $notif_body = "";
    $send_notif = false;
    $guru_id = $req['guru_id'];

    if ($status === 'Disetujui') {
        // --- LOGIKA UTAMA AGAR HONOR CAIR ---
        $tanggal = $req['tanggal'];
        $hari_ini = getHariIndonesia($tanggal);
        $jam_mulai_aju = $req['jam_mulai'];
        
        $berhasil_insert = false;

        if ($req['jenis_absensi'] == 'Mengajar') {
            $stmt_jadwal = $conn->prepare("
                SELECT id 
                FROM jadwal_mengajar 
                WHERE guru_id = ? 
                AND hari = ? 
                AND (? BETWEEN jam_mulai AND jam_selesai)
                LIMIT 1
            ");
            $stmt_jadwal->bind_param("iss", $guru_id, $hari_ini, $jam_mulai_aju);
            $stmt_jadwal->execute();
            $jadwal = $stmt_jadwal->get_result()->fetch_assoc();
            $stmt_jadwal->close();

            if ($jadwal) {
                $jadwal_id = $jadwal['id'];
                $waktu_absensi = $tanggal . ' ' . $jam_mulai_aju;
                $ket = "Susulan: " . $req['keterangan'];
                
                $stmt_ins = $conn->prepare("INSERT INTO absensi (guru_id, jadwal_id, tipe_absensi, waktu_absensi, status, keterangan) VALUES (?, ?, 'mengajar', ?, 'Hadir', ?)");
                $stmt_ins->bind_param("iiss", $guru_id, $jadwal_id, $waktu_absensi, $ket);
                
                if($stmt_ins->execute()) $berhasil_insert = true;
                $stmt_ins->close();
            } else {
                $msg = "Gagal: Tidak ditemukan jadwal mengajar pada hari/jam tersebut.";
            }

        } elseif ($req['jenis_absensi'] == 'Piket' || $req['jenis_absensi'] == 'Ekstrakurikuler') {
            $tipe_db = ($req['jenis_absensi'] == 'Piket') ? 'piket' : 'ekskul';
            $waktu_absensi = $tanggal . ' ' . $jam_mulai_aju;
            $ket = "Susulan: " . $req['keterangan'];

            $stmt_ins = $conn->prepare("INSERT INTO absensi (guru_id, jadwal_id, tipe_absensi, waktu_absensi, status, keterangan) VALUES (?, 0, ?, ?, 'Hadir', ?)");
            $stmt_ins->bind_param("isss", $guru_id, $tipe_db, $waktu_absensi, $ket);
            
            if($stmt_ins->execute()) {
                $berhasil_insert = true;
                if ($tipe_db == 'piket') {
                    $stmt_daily = $conn->prepare("INSERT INTO absensi_harian (guru_id, tanggal, jam_masuk, jam_pulang) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE jam_masuk = VALUES(jam_masuk)");
                    $stmt_daily->bind_param("isss", $guru_id, $tanggal, $req['jam_mulai'], $req['jam_selesai']);
                    $stmt_daily->execute();
                }
            }
            $stmt_ins->close();
        }

        if ($berhasil_insert) {
            $conn->query("UPDATE pengajuan_absensi SET status = 'Disetujui' WHERE id = $id");
            $msg = "Pengajuan disetujui. Honor telah diperbarui.";
            
            // Siapkan data untuk notifikasi
            $notif_title = "✅ Pengajuan Disetujui!";
            $notif_body = "Pengajuan absensi " . $req['jenis_absensi'] . " tanggal " . date('d M Y', strtotime($req['tanggal'])) . " telah disetujui.";
            $send_notif = true;
        } elseif ($msg == "") {
            $msg = "Terjadi kesalahan sistem saat menyimpan data absensi.";
        }

    } else {
        // Jika Ditolak
        $komentar = $_POST['komentar_admin'] ?? '';
        $stmt_reject = $conn->prepare("UPDATE pengajuan_absensi SET status = 'Ditolak', komentar_admin = ? WHERE id = ?");
        $stmt_reject->bind_param("si", $komentar, $id);
    
        if($stmt_reject->execute()) {
            $msg = "Pengajuan ditolak dengan alasan.";

            // Siapkan data untuk notifikasi
            $alasan = !empty($komentar) ? "\nAlasan: " . $komentar : "";
            $notif_title = "❌ Pengajuan Ditolak";
            $notif_body = "Silakan perbaiki pengajuan " . $req['jenis_absensi'] . " Anda." . $alasan;
            $send_notif = true;
        }
        $stmt_reject->close();
    }

    // --- PROSES PENGIRIMAN PUSH NOTIFICATION KE SERVER API ---
    if ($send_notif) {
        try {
            // Ambil token push guru
            $res_token = $conn->query("SELECT push_token FROM guru WHERE id = " . $guru_id);
            if ($res_token && $res_token->num_rows > 0) {
                $token = $res_token->fetch_assoc()['push_token'];
                
                if (!empty($token)) {
                    // Simpan history ke tabel database 'notifikasi' (Sama seperti kirim_notifikasi_harian.php)
                    $stmt_simpan = $conn->prepare("INSERT INTO notifikasi (guru_id, judul, isi) VALUES (?, ?, ?)");
                    $stmt_simpan->bind_param("iss", $guru_id, $notif_title, $notif_body);
                    $stmt_simpan->execute();
                    $stmt_simpan->close();

                    // Panggil API di Server B untuk mengirim FCM
                    $screenTarget = '/pengajuan_absensi';
                    panggilApiFCMServerB($token, $notif_title, $notif_body, $screenTarget);
                }
            }
        } catch (Exception $e) {
            error_log("Gagal mengirim notifikasi absensi: " . $e->getMessage());
        }
    }
}

// Ambil data Pending
$query = $conn->query("SELECT p.*, g.nama_guru FROM pengajuan_absensi p JOIN guru g ON p.guru_id = g.id WHERE p.status = 'Pending' ORDER BY p.tanggal ASC");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Approval Absensi Susulan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-4">
    <div class="card shadow">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Verifikasi Absensi Susulan</h5>
        </div>
        <div class="card-body">
            <?php if(!empty($msg)): ?>
                <div class="alert alert-<?php echo strpos($msg, 'Gagal') !== false ? 'danger' : 'success'; ?>">
                    <?= $msg ?>
                </div>
            <?php endif; ?>
            
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Guru</th>
                            <th>Jenis</th>
                            <th>Tanggal & Waktu</th>
                            <th>Alasan</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($query->num_rows > 0): ?>
                            <?php while($row = $query->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['nama_guru']) ?></td>
                                <td><span class="badge bg-secondary"><?= $row['jenis_absensi'] ?></span></td>
                                <td>
                                    <?= date('d M Y', strtotime($row['tanggal'])) ?><br>
                                    <small class="text-muted"><?= substr($row['jam_mulai'], 0, 5) ?> - <?= substr($row['jam_selesai'], 0, 5) ?></small>
                                </td>
                                <td><?= htmlspecialchars($row['keterangan']) ?></td>
                                <td>
                                    <form method="POST">
                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                        <div class="input-group input-group-sm mb-2">
                                            <input type="text" name="komentar_admin" class="form-control" placeholder="Alasan penolakan (opsional)">
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="submit" name="action" value="Disetujui" class="btn btn-success btn-sm w-50" onclick="return confirm('Yakin setujui? Data akan masuk ke perhitungan honor.')">Setuju</button>
                                            <button type="submit" name="action" value="Ditolak" class="btn btn-danger btn-sm w-50">Tolak</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center text-muted">Tidak ada pengajuan pending.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
<?php
$custom_script = ob_get_clean();
include 'partials/footer.php'; 
?>