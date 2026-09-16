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
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['action']; // 'Disetujui' atau 'Ditolak'

    // Transaksi + FOR UPDATE. Permintaan kedua menunggu di sini sampai yang
    // pertama selesai, lalu melihat status sudah bukan 'Pending'. Tanpa ini,
    // klik ganda pada tombol Disetujui memasukkan DUA baris absensi — dan
    // hitungHonorBulan() di keuangan_helper.php menjumlahkan honor PER BARIS,
    // jadi honornya benar-benar terbayar dobel. Sudah pernah terjadi sekali.
    // pengajuan_absensi dan absensi keduanya InnoDB, jadi kuncian ini berlaku.
    $conn->begin_transaction();
    $transaksi_sukses = false;

    // 1. Ambil data pengajuan mentah, sekaligus kunci barisnya
    $stmt_req = $conn->prepare("SELECT * FROM pengajuan_absensi WHERE id = ? FOR UPDATE");
    $stmt_req->bind_param("i", $id);
    $stmt_req->execute();
    $req = $stmt_req->get_result()->fetch_assoc();
    $stmt_req->close();

    if (!$req) { $conn->rollback(); die("Data tidak ditemukan."); }

    $sudah_diproses = ($req['status'] !== 'Pending');
    if ($sudah_diproses) {
        $msg = "Pengajuan ini sudah diproses sebelumnya (status: " . $req['status'] . ").";
    }

    // Variabel untuk Push Notification
    $notif_title = "";
    $notif_body = "";
    $send_notif = false;
    $guru_id = $req['guru_id'];

    if (!$sudah_diproses && $status === 'Disetujui') {
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

                // Satu jadwal mengajar hanya boleh satu baris per tanggal. Guru
                // boleh punya beberapa jadwal di hari yang sama — jadwal_id yang
                // membedakannya. Tanpa penjaga ini, dua pengajuan berbeda untuk
                // slot yang sama menghasilkan dua baris, dan hitungHonorBulan()
                // membayar keduanya.
                $stmt_cek = $conn->prepare("SELECT id FROM absensi WHERE guru_id = ? AND jadwal_id = ? AND tipe_absensi = 'mengajar' AND DATE(waktu_absensi) = ?");
                $stmt_cek->bind_param("iis", $guru_id, $jadwal_id, $tanggal);
                $stmt_cek->execute();
                $is_duplicate = $stmt_cek->get_result()->num_rows > 0;
                $stmt_cek->close();

                if ($is_duplicate) {
                    $msg = "Pengajuan gagal disetujui: Absensi mengajar untuk jadwal dan tanggal tersebut sudah ada.";
                } else {
                    $stmt_ins = $conn->prepare("INSERT INTO absensi (guru_id, jadwal_id, tipe_absensi, waktu_absensi, status, keterangan) VALUES (?, ?, 'mengajar', ?, 'Hadir', ?)");
                    $stmt_ins->bind_param("iiss", $guru_id, $jadwal_id, $waktu_absensi, $ket);

                    if($stmt_ins->execute()) $berhasil_insert = true;
                    $stmt_ins->close();
                }
            } else {
                $msg = "Gagal: Tidak ditemukan jadwal mengajar pada hari/jam tersebut.";
            }

        } elseif ($req['jenis_absensi'] == 'Piket' || $req['jenis_absensi'] == 'Ekstrakurikuler') {
            $tipe_db = ($req['jenis_absensi'] == 'Piket') ? 'piket' : 'ekskul';
            $waktu_absensi = $tanggal . ' ' . $jam_mulai_aju;
            $ket = "Susulan: " . $req['keterangan'];

            // Cari jadwal sungguhan, seperti proses_approval_absensi.php di repo
            // API. Sebelumnya berkas ini menyimpan jadwal_id = 0, sehingga kedua
            // jalur approval tidak bisa saling melihat baris yang sudah ada.
            $jadwal_id = 0;
            if ($tipe_db == 'piket') {
                $stmt_jadwal = $conn->prepare("SELECT id FROM jadwal_piket WHERE guru_id = ? AND hari = ? AND status_jadwal = 'Aktif' LIMIT 1");
                $stmt_jadwal->bind_param("is", $guru_id, $hari_ini);
            } else {
                $stmt_jadwal = $conn->prepare("SELECT id FROM jadwal_ekskul WHERE guru_id = ? AND hari = ? AND (? BETWEEN jam_mulai AND jam_selesai) AND status_jadwal = 'Aktif' LIMIT 1");
                $stmt_jadwal->bind_param("iss", $guru_id, $hari_ini, $jam_mulai_aju);
            }
            $stmt_jadwal->execute();
            $jadwal = $stmt_jadwal->get_result()->fetch_assoc();
            $stmt_jadwal->close();
            if ($jadwal) $jadwal_id = $jadwal['id'];

            if ($jadwal_id > 0) {
                // Kuncinya berbeda per jenis, dan perbedaan itu disengaja:
                //   piket  — satu hari satu bayar. Label sesi Pagi/Siang tidak
                //            menentukan waktu, jadi jadwal_id TIDAK dipakai.
                //   ekskul — satu guru boleh membina dua ekskul berbeda di hari
                //            yang sama dan dibayar dua kali, jadi jadwal_id wajib.
                if ($tipe_db == 'piket') {
                    $stmt_cek = $conn->prepare("SELECT id FROM absensi WHERE guru_id = ? AND tipe_absensi = 'piket' AND DATE(waktu_absensi) = ?");
                    $stmt_cek->bind_param("is", $guru_id, $tanggal);
                } else {
                    $stmt_cek = $conn->prepare("SELECT id FROM absensi WHERE guru_id = ? AND jadwal_id = ? AND tipe_absensi = 'ekskul' AND DATE(waktu_absensi) = ?");
                    $stmt_cek->bind_param("iis", $guru_id, $jadwal_id, $tanggal);
                }
                $stmt_cek->execute();
                $is_duplicate = $stmt_cek->get_result()->num_rows > 0;
                $stmt_cek->close();

                if ($is_duplicate) {
                    $msg = "Pengajuan gagal disetujui: Absensi " . $req['jenis_absensi'] . " untuk tanggal tersebut sudah ada.";
                } else {
                    $stmt_ins = $conn->prepare("INSERT INTO absensi (guru_id, jadwal_id, tipe_absensi, waktu_absensi, status, keterangan) VALUES (?, ?, ?, ?, 'Hadir', ?)");
                    $stmt_ins->bind_param("iisss", $guru_id, $jadwal_id, $tipe_db, $waktu_absensi, $ket);

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
            } else {
                $msg = "Gagal: Tidak ditemukan jadwal " . $req['jenis_absensi'] . " Aktif pada hari/jam tersebut.";
            }
        }

        if ($berhasil_insert) {
            // Syarat status='Pending' adalah pengaman kedua setelah FOR UPDATE.
            // Baris ini sebelumnya menyisipkan $id langsung ke dalam SQL, jadi
            // id=1 OR 1=1 akan menyetujui SELURUH pengajuan Pending sekaligus.
            $stmt_setuju = $conn->prepare("UPDATE pengajuan_absensi SET status = 'Disetujui' WHERE id = ? AND status = 'Pending'");
            $stmt_setuju->bind_param("i", $id);
            $stmt_setuju->execute();
            $transaksi_sukses = ($stmt_setuju->affected_rows === 1);
            $stmt_setuju->close();

            $msg = $transaksi_sukses
                 ? "Pengajuan disetujui. Honor telah diperbarui."
                 : "Pengajuan ini sudah diproses sebelumnya.";

            // Siapkan data untuk notifikasi
            $notif_title = "✅ Pengajuan Disetujui!";
            $notif_body = "Pengajuan absensi " . $req['jenis_absensi'] . " tanggal " . date('d M Y', strtotime($req['tanggal'])) . " telah disetujui.";
            $send_notif = $transaksi_sukses;
        } elseif ($msg == "") {
            $msg = "Terjadi kesalahan sistem saat menyimpan data absensi.";
        }

    } elseif (!$sudah_diproses) {
        // Jika Ditolak
        $komentar = $_POST['komentar_admin'] ?? '';
        $stmt_reject = $conn->prepare("UPDATE pengajuan_absensi SET status = 'Ditolak', komentar_admin = ? WHERE id = ? AND status = 'Pending'");
        $stmt_reject->bind_param("si", $komentar, $id);

        if($stmt_reject->execute() && $stmt_reject->affected_rows === 1) {
            $transaksi_sukses = true;
            $msg = "Pengajuan ditolak dengan alasan.";

            // Siapkan data untuk notifikasi
            $alasan = !empty($komentar) ? "\nAlasan: " . $komentar : "";
            $notif_title = "❌ Pengajuan Ditolak";
            $notif_body = "Silakan perbaiki pengajuan " . $req['jenis_absensi'] . " Anda." . $alasan;
            $send_notif = true;
        }
        $stmt_reject->close();
    }

    // Selesaikan transaksi SEBELUM menyentuh jaringan. Panggilan FCM bisa
    // menggantung beberapa detik, dan kunci baris tidak boleh ditahan selama itu.
    if ($transaksi_sukses) { $conn->commit(); } else { $conn->rollback(); }

    // --- PROSES PENGIRIMAN PUSH NOTIFICATION KE SERVER API ---
    if ($send_notif) {
        try {
            // Ambil token push guru
            $stmt_token = $conn->prepare("SELECT push_token FROM guru WHERE id = ?");
            $stmt_token->bind_param("i", $guru_id);
            $stmt_token->execute();
            $res_token = $stmt_token->get_result();
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