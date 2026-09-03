<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
ini_set('display_errors', 0);
require_once 'includes/db.php'; 

function hitungJarak($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * asin(sqrt($a));
    return $earth_radius * $c;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login_siswa'])) {
    $nisn = trim($_POST['nisn']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, nisn, nama_siswa, kelas, password FROM siswa WHERE nisn = ?");
    $stmt->bind_param("s", $nisn);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $siswa = $result->fetch_assoc();
        if (password_verify($password, $siswa['password'])) {
            $_SESSION['siswa_id'] = $siswa['id'];
            $_SESSION['nama_siswa'] = $siswa['nama_siswa'];
            $_SESSION['kelas'] = $siswa['kelas'];
            header("Location: absensi_pkl.php");
            exit();
        } else {
            $error_login = "Password salah!";
        }
    } else {
        $error_login = "NISN tidak ditemukan!";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: absensi_pkl.php");
    exit();
}

if (!isset($_SESSION['siswa_id'])) {
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login Absensi PKL</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Poppins', sans-serif; background-color: #f5f7fa; } .login-card { border-radius: 20px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.08); } </style>
</head>
<body class="d-flex align-items-center min-vh-100">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card login-card p-4">
                    <div class="text-center mb-4">
                        <i class="bi bi-geo-alt-fill text-primary" style="font-size: 3rem;"></i>
                        <h4 class="fw-bold mt-2">Portal Absensi PKL</h4>
                        <p class="text-muted small">SMK Terpadu Al Hasan</p>
                    </div>
                    <?php if(isset($error_login)): ?>
                        <div class="alert alert-danger small py-2 rounded-3"><i class="bi bi-exclamation-circle me-2"></i><?php echo $error_login; ?></div>
                    <?php endif; ?>
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-muted">NISN Siswa</label>
                            <input type="text" name="nisn" class="form-control form-control-lg bg-light" required placeholder="Masukkan NISN" inputmode="numeric">
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold small text-muted">Password</label>
                            <input type="password" name="password" class="form-control form-control-lg bg-light" required placeholder="Masukkan Password">
                        </div>
                        <button type="submit" name="login_siswa" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold shadow-sm">Masuk</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php
    exit();
}

$siswa_id = $_SESSION['siswa_id'];
$tanggal_hari_ini = date('Y-m-d');
$jam_sekarang = date('H:i:s');
$pesan_aksi = "";

$lokasi_pkl = null;
$stmt_loc = $conn->prepare("SELECT l.* FROM penempatan_pkl p JOIN lokasi_pkl l ON p.lokasi_id = l.id WHERE p.siswa_id = ? LIMIT 1");
$stmt_loc->bind_param("i", $siswa_id);
$stmt_loc->execute();
$res_loc = $stmt_loc->get_result();
if($res_loc->num_rows > 0) {
    $lokasi_pkl = $res_loc->fetch_assoc();
}
$stmt_loc->close();

function uploadFotoPKL($fileInputName) {
    if (!isset($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] != 0) return null;
    $target_dir = "uploads/absensi_pkl/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
    $file_extension = strtolower(pathinfo($_FILES[$fileInputName]["name"], PATHINFO_EXTENSION));
    $new_filename = "PKL_" . date('Ymd_His') . "_" . rand(100,999) . "." . $file_extension;
    $target_file = $target_dir . $new_filename;
    if(getimagesize($_FILES[$fileInputName]["tmp_name"]) !== false) {
        if (move_uploaded_file($_FILES[$fileInputName]["tmp_name"], $target_file)) return $target_file;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['absen_masuk']) || isset($_POST['absen_pulang']))) {
    $user_lat = (float)$_POST['user_lat'];
    $user_lng = (float)$_POST['user_lng'];
    $target_lat = (float)$lokasi_pkl['latitude'];
    $target_lng = (float)$lokasi_pkl['longitude'];
    $radius_izin = (int)$lokasi_pkl['radius'];

    $jarak_real = hitungJarak($target_lat, $target_lng, $user_lat, $user_lng);

    if ($jarak_real > $radius_izin) {
        $pesan_aksi = "<div class='alert alert-danger rounded-4 small fw-bold'><i class='bi bi-shield-x me-2'></i>Aksi Ditolak! Anda berada di luar area ($jarak_real m). Dilarang titip absen.</div>";
    } else {
        $foto_path = uploadFotoPKL('foto_kamera');
        if ($foto_path) {
            if (isset($_POST['absen_masuk'])) {
                $status_masuk = (strtotime($jam_sekarang) <= strtotime('08:00:00')) ? 'Tepat Waktu' : 'Terlambat';
                $stmt = $conn->prepare("INSERT INTO absensi_siswa (siswa_id, tanggal, waktu_masuk, foto_masuk, status_masuk, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE waktu_masuk = VALUES(waktu_masuk), foto_masuk = VALUES(foto_masuk), status_masuk = VALUES(status_masuk)");
                $stmt->bind_param("issssdd", $siswa_id, $tanggal_hari_ini, $jam_sekarang, $foto_path, $status_masuk, $user_lat, $user_lng);
                $sukses_msg = "Berhasil Absen Masuk!";
            } else {
                $stmt = $conn->prepare("UPDATE absensi_siswa SET waktu_pulang = ?, foto_pulang = ?, latitude = ?, longitude = ? WHERE siswa_id = ? AND tanggal = ?");
                $stmt->bind_param("ssddis", $jam_sekarang, $foto_path, $user_lat, $user_lng, $siswa_id, $tanggal_hari_ini);
                $sukses_msg = "Berhasil Absen Pulang! Hati-hati di jalan.";
            }
            
            if ($stmt->execute()) {
                $pesan_aksi = "<div class='alert alert-success rounded-4 small'><i class='bi bi-check-circle-fill me-2'></i>$sukses_msg</div>";
            } else {
                $pesan_aksi = "<div class='alert alert-danger rounded-4 small'>Gagal menyimpan database.</div>";
            }
            $stmt->close();
        } else {
            $pesan_aksi = "<div class='alert alert-danger rounded-4 small'>Gagal memproses foto bukti.</div>";
        }
    }
}

$absen_hari_ini = null;
$stmt_cek = $conn->prepare("SELECT * FROM absensi_siswa WHERE siswa_id = ? AND tanggal = ?");
$stmt_cek->bind_param("is", $siswa_id, $tanggal_hari_ini);
$stmt_cek->execute();
$result_cek = $stmt_cek->get_result();
if ($result_cek->num_rows > 0) {
    $absen_hari_ini = $result_cek->fetch_assoc();
}
$stmt_cek->close();

$sudah_masuk = ($absen_hari_ini && !empty($absen_hari_ini['waktu_masuk']));
$sudah_pulang = ($absen_hari_ini && !empty($absen_hari_ini['waktu_pulang']));

// =========================================================================
// --- PAGINASI RIWAYAT SISWA ---
// =========================================================================
$limit_riwayat = 10; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset_riwayat = ($page - 1) * $limit_riwayat;

// 1. Hitung total riwayat
$stmt_count = $conn->prepare("SELECT COUNT(id) as total FROM absensi_siswa WHERE siswa_id = ?");
$stmt_count->bind_param("i", $siswa_id);
$stmt_count->execute();
$res_count = $stmt_count->get_result()->fetch_assoc();
$total_riwayat = $res_count['total'];
$total_pages = ceil($total_riwayat / $limit_riwayat);
$stmt_count->close();

// 2. Ambil data dengan Limit dan Offset
$riwayat_siswa = [];
$stmt_riwayat = $conn->prepare("SELECT * FROM absensi_siswa WHERE siswa_id = ? ORDER BY tanggal DESC LIMIT ?, ?");
$stmt_riwayat->bind_param("iii", $siswa_id, $offset_riwayat, $limit_riwayat);
$stmt_riwayat->execute();
$res_riwayat = $stmt_riwayat->get_result();
while($row_r = $res_riwayat->fetch_assoc()) {
    $riwayat_siswa[] = $row_r;
}
$stmt_riwayat->close();

$hari = ['Sunday'=>'Minggu', 'Monday'=>'Senin', 'Tuesday'=>'Selasa', 'Wednesday'=>'Rabu', 'Thursday'=>'Kamis', 'Friday'=>'Jumat', 'Saturday'=>'Sabtu'];
$bulan = [1=>'Januari', 2=>'Februari', 3=>'Maret', 4=>'April', 5=>'Mei', 6=>'Juni', 7=>'Juli', 8=>'Agustus', 9=>'September', 10=>'Oktober', 11=>'November', 12=>'Desember'];
$tanggal_tampil = $hari[date('l')] . ", " . date('d') . " " . $bulan[(int)date('m')] . " " . date('Y');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Absensi PKL Siswa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; padding-bottom: 30px; }
        .header-bg { background: linear-gradient(135deg, #4A90A4, #3b7484); border-radius: 0 0 25px 25px; padding: 2rem 1rem 3rem 1rem; color: white; }
        .main-card { margin-top: -30px; border-radius: 20px; border: none; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
        .btn-absen { border-radius: 15px; padding: 15px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }
        .time-badge { font-size: 1.5rem; font-weight: 700; color: #34495e; background: #f1f2f6; padding: 10px 20px; border-radius: 12px; display: inline-block; }
        .file-upload-camera { display: none; }
        .camera-btn { border: 2px dashed #4A90A4; border-radius: 15px; padding: 30px; text-align: center; color: #4A90A4; cursor: pointer; transition: all 0.3s; background-color: #f8f9fa; }
        .camera-btn:hover, .camera-btn.active { background-color: #e9f2f5; }
        #preview-img { max-height: 250px; border-radius: 12px; object-fit: cover; display: none; width: 100%; margin-top: 15px; }
        .thumb-history { width: 40px; height: 40px; object-fit: cover; border-radius: 8px; cursor: pointer; }
        .pagination .page-link { color: #4A90A4; border-radius: 8px; margin: 0 3px; border: none; }
        .pagination .page-item.active .page-link { background-color: #4A90A4; color: white; }
    </style>
</head>
<body>

    <div class="header-bg text-center shadow-sm">
        <h5 class="fw-bold mb-1">Halo, <?php echo htmlspecialchars($_SESSION['nama_siswa']); ?>!</h5>
        <p class="mb-0 opacity-75 small"><i class="bi bi-mortarboard-fill me-1"></i> Kelas <?php echo htmlspecialchars($_SESSION['kelas']); ?> (PKL)</p>
        <div class="mt-3">
            <a href="?logout=true" class="btn btn-sm btn-outline-light rounded-pill px-3"><i class="bi bi-box-arrow-right me-1"></i> Keluar</a>
        </div>
    </div>

    <div class="container">
        <div class="card main-card mb-4">
            <div class="card-body p-4 text-center">
                
                <?php if(!$lokasi_pkl): ?>
                    <div class="alert alert-warning rounded-4 border-0 p-4 mt-3">
                        <i class="bi bi-exclamation-triangle-fill text-warning d-block mb-2" style="font-size: 3rem;"></i>
                        <h6 class="fw-bold mb-1">Belum Terdaftar</h6>
                        <p class="small mb-0 opacity-75">Maaf, Pembimbing belum menetapkan titik lokasi PKL Anda. Hubungi pihak sekolah.</p>
                    </div>
                <?php else: ?>

                    <p class="text-muted small fw-semibold text-uppercase mb-1">Waktu Saat Ini</p>
                    <div class="time-badge mb-2"><i class="bi bi-clock me-2"></i><span id="live-clock"><?php echo date('H:i:s'); ?></span></div>
                    <p class="text-muted small mb-3"><?php echo $tanggal_tampil; ?></p>
                    
                    <div class="alert bg-light border border-info rounded-3 text-start small mb-4">
                        <i class="bi bi-building text-info me-2"></i><strong>Lokasi PKL:</strong> <?php echo htmlspecialchars($lokasi_pkl['nama_instansi']); ?><br>
                        <i class="bi bi-geo text-danger me-2"></i><span id="gps-status" class="fw-bold text-warning">Mencari sinyal GPS...</span>
                    </div>

                    <?php echo $pesan_aksi; ?>

                    <div class="row g-3 text-start mb-4">
                        <div class="col-6">
                            <div class="p-3 border rounded-4 <?php echo $sudah_masuk ? 'border-success bg-success bg-opacity-10' : 'border-secondary bg-light'; ?>">
                                <small class="text-muted d-block mb-1">Jam Masuk</small>
                                <h6 class="fw-bold mb-0 <?php echo $sudah_masuk ? 'text-success' : 'text-dark'; ?>">
                                    <?php echo $sudah_masuk ? date('H:i', strtotime($absen_hari_ini['waktu_masuk'])) : '--:--'; ?>
                                </h6>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 border rounded-4 <?php echo $sudah_pulang ? 'border-primary bg-primary bg-opacity-10' : 'border-secondary bg-light'; ?>">
                                <small class="text-muted d-block mb-1">Jam Pulang</small>
                                <h6 class="fw-bold mb-0 <?php echo $sudah_pulang ? 'text-primary' : 'text-dark'; ?>">
                                    <?php echo $sudah_pulang ? date('H:i', strtotime($absen_hari_ini['waktu_pulang'])) : '--:--'; ?>
                                </h6>
                            </div>
                        </div>
                    </div>

                    <?php if(!$sudah_pulang): ?>
                        <form method="POST" action="" enctype="multipart/form-data" id="form-absen">
                            <input type="hidden" name="user_lat" id="user_lat">
                            <input type="hidden" name="user_lng" id="user_lng">

                            <label for="kamera" class="camera-btn d-block w-100 mb-3" id="kamera-label" style="pointer-events: none; opacity: 0.5;">
                                <i class="bi bi-camera-fill d-block mb-2" style="font-size: 2.5rem;"></i>
                                <span class="fw-bold" id="kamera-text">Menunggu Kunci GPS...</span>
                                <img id="preview-img" alt="Preview Foto">
                            </label>
                            
                            <input type="file" name="foto_kamera" id="kamera" class="file-upload-camera" accept="image/*" capture="environment" required disabled>
                            
                            <?php if(!$sudah_masuk): ?>
                                <button type="submit" name="absen_masuk" class="btn btn-success btn-absen w-100 shadow-sm" id="btn-submit" disabled>
                                    <i class="bi bi-geo-alt-fill me-2"></i> Rekam Absen Masuk
                                </button>
                            <?php else: ?>
                                <button type="submit" name="absen_pulang" class="btn btn-primary btn-absen w-100 shadow-sm" id="btn-submit" disabled>
                                    <i class="bi bi-box-arrow-right me-2"></i> Rekam Absen Pulang
                                </button>
                            <?php endif; ?>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-info rounded-4 border-0 p-4">
                            <i class="bi bi-patch-check-fill text-info d-block mb-2" style="font-size: 3rem;"></i>
                            <h6 class="fw-bold mb-1">Tugas Hari Ini Selesai!</h6>
                            <p class="small mb-0 opacity-75">Anda telah menyelesaikan absensi masuk dan pulang hari ini. Selamat beristirahat!</p>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>

            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-5" id="riwayat">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
                <h6 class="fw-bold m-0 text-dark"><i class="bi bi-clock-history text-primary me-2"></i> Riwayat Kehadiran Saya</h6>
                <small class="text-muted">Total: <?php echo $total_riwayat; ?> hari</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                        <thead class="table-light text-muted">
                            <tr>
                                <th class="ps-4">Tanggal</th>
                                <th class="text-center">Masuk</th>
                                <th class="text-center">Pulang</th>
                                <th class="text-center pe-4">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(!empty($riwayat_siswa)): foreach($riwayat_siswa as $rw): 
                                $tgl_fmt = date('d/m/Y', strtotime($rw['tanggal']));
                                $masuk_fmt = $rw['waktu_masuk'] ? date('H:i', strtotime($rw['waktu_masuk'])) : '--:--';
                                $pulang_fmt = $rw['waktu_pulang'] ? date('H:i', strtotime($rw['waktu_pulang'])) : '--:--';
                            ?>
                            <tr>
                                <td class="ps-4 fw-semibold text-dark"><?php echo $tgl_fmt; ?></td>
                                <td class="text-center">
                                    <strong class="text-success d-block"><?php echo $masuk_fmt; ?></strong>
                                    <?php if($rw['foto_masuk'] && file_exists($rw['foto_masuk'])): ?>
                                        <img src="<?php echo $rw['foto_masuk']; ?>" class="thumb-history border shadow-sm mt-1" onclick="showModalFoto('<?php echo $rw['foto_masuk']; ?>')">
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <strong class="text-primary d-block"><?php echo $pulang_fmt; ?></strong>
                                    <?php if($rw['foto_pulang'] && file_exists($rw['foto_pulang'])): ?>
                                        <img src="<?php echo $rw['foto_pulang']; ?>" class="thumb-history border shadow-sm mt-1" onclick="showModalFoto('<?php echo $rw['foto_pulang']; ?>')">
                                    <?php endif; ?>
                                </td>
                                <td class="text-center pe-4">
                                    <?php if($rw['status_masuk'] == 'Tepat Waktu'): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success rounded-pill px-2 py-1">Tepat Waktu</span>
                                    <?php elseif($rw['status_masuk'] == 'Terlambat'): ?>
                                        <span class="badge bg-warning bg-opacity-10 text-dark border border-warning rounded-pill px-2 py-1">Terlambat</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary rounded-pill">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted small">Belum ada riwayat absensi.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="pt-3 pb-3">
                    <nav aria-label="Page navigation">
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <li class="page-item <?php if($page <= 1) echo 'disabled'; ?>">
                                <a class="page-link shadow-sm" href="?page=<?php echo $page - 1; ?>#riwayat"><i class="bi bi-chevron-left"></i></a>
                            </li>

                            <?php 
                            $start_page = max(1, $page - 1);
                            $end_page = min($total_pages, $page + 1);

                            for ($i = $start_page; $i <= $end_page; $i++): 
                            ?>
                                <li class="page-item <?php if($page == $i) echo 'active'; ?>">
                                    <a class="page-link shadow-sm" href="?page=<?php echo $i; ?>#riwayat"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>

                            <li class="page-item <?php if($page >= $total_pages) echo 'disabled'; ?>">
                                <a class="page-link shadow-sm" href="?page=<?php echo $page + 1; ?>#riwayat"><i class="bi bi-chevron-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </div>

    <div class="modal fade" id="modalFotoView" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow rounded-4">
                <div class="modal-body text-center p-3">
                    <img id="imgViewSrc" src="" class="img-fluid rounded-3" style="max-height: 350px;">
                </div>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Live Clock
    setInterval(() => {
        const now = new Date();
        const t = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0') + ':' + now.getSeconds().toString().padStart(2, '0');
        if(document.getElementById('live-clock')) document.getElementById('live-clock').textContent = t;
    }, 1000);

    function showModalFoto(src) {
        document.getElementById('imgViewSrc').src = src;
        var myModal = new bootstrap.Modal(document.getElementById('modalFotoView'));
        myModal.show();
    }

    // Geofencing Javascript
    <?php if($lokasi_pkl && !$sudah_pulang): ?>
    
    const TARGET_LAT = <?php echo $lokasi_pkl['latitude']; ?>;
    const TARGET_LNG = <?php echo $lokasi_pkl['longitude']; ?>;
    const RADIUS_MAKSIMAL = <?php echo $lokasi_pkl['radius']; ?>;

    function calculateDistance(lat1, lon1, lat2, lon2) {
        var R = 6371e3;
        var p1 = lat1 * Math.PI/180;
        var p2 = lat2 * Math.PI/180;
        var dp = (lat2-lat1) * Math.PI/180;
        var dl = (lon2-lon1) * Math.PI/180;
        var a = Math.sin(dp/2) * Math.sin(dp/2) + Math.cos(p1) * Math.cos(p2) * Math.sin(dl/2) * Math.sin(dl/2);
        var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        return Math.round(R * c);
    }

    if (navigator.geolocation) {
        navigator.geolocation.watchPosition(function(position) {
            const userLat = position.coords.latitude;
            const userLng = position.coords.longitude;
            
            document.getElementById('user_lat').value = userLat;
            document.getElementById('user_lng').value = userLng;

            const jarak = calculateDistance(userLat, userLng, TARGET_LAT, TARGET_LNG);
            const statusEl = document.getElementById('gps-status');
            const camLabel = document.getElementById('kamera-label');
            const camInput = document.getElementById('kamera');
            const camText = document.getElementById('kamera-text');

            if (jarak <= RADIUS_MAKSIMAL) {
                statusEl.innerHTML = `Area Valid (Jarak: ${jarak}m dari titik)`;
                statusEl.className = "fw-bold text-success";
                
                camLabel.style.pointerEvents = 'auto';
                camLabel.style.opacity = '1';
                camInput.disabled = false;
                
                if(!document.getElementById('preview-img').src.includes('data:image')) {
                    camText.textContent = "Ketuk untuk Ambil Foto";
                }
            } else {
                statusEl.innerHTML = `Area di luar batas (Jarak: ${jarak}m / Maks: ${RADIUS_MAKSIMAL}m)`;
                statusEl.className = "fw-bold text-danger";
                
                camLabel.style.pointerEvents = 'none';
                camLabel.style.opacity = '0.5';
                camInput.disabled = true;
                camText.textContent = "Mendekatlah ke lokasi PKL!";
                document.getElementById('btn-submit').disabled = true;
            }
        }, function(error) {
            document.getElementById('gps-status').innerHTML = "Harap aktifkan Izin Lokasi (GPS) browser Anda!";
            document.getElementById('gps-status').className = "fw-bold text-danger";
        }, {
            enableHighAccuracy: true, maximumAge: 0
        });
    }

    const fileInput = document.getElementById('kamera');
    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('preview-img').src = e.target.result;
                    document.getElementById('preview-img').style.display = 'block';
                    document.getElementById('kamera-label').classList.add('active');
                    document.getElementById('kamera-text').textContent = "Foto Tersimpan (Ketuk untuk ganti)";
                    document.querySelector('.bi-camera-fill').style.display = 'none';
                    document.getElementById('btn-submit').disabled = false;
                }
                reader.readAsDataURL(this.files[0]);
            }
        });
    }
    <?php endif; ?>
</script>
</body>
</html>