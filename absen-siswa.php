<?php
require 'includes/db.php';

$pengaturan_query = $conn->query("SELECT nama_pengaturan, nilai_pengaturan FROM pengaturan WHERE nama_pengaturan IN ('jam_masuk','jam_pulang')");
$pengaturan = [];
while ($r = $pengaturan_query->fetch_assoc()) {
    $pengaturan[$r['nama_pengaturan']] = $r['nilai_pengaturan'];
}
$jam_masuk  = isset($pengaturan['jam_masuk'])  && $pengaturan['jam_masuk']  !== '' ? date('H:i:s', strtotime($pengaturan['jam_masuk']))  : '07:30:00';
$jam_pulang = isset($pengaturan['jam_pulang']) && $pengaturan['jam_pulang'] !== '' ? date('H:i:s', strtotime($pengaturan['jam_pulang'])) : '13:50:00';

$total_siswa = $conn->query("SELECT COUNT(id) as total FROM siswa WHERE kelas LIKE '%DKV%'")->fetch_assoc()['total'];
$total_guru  = $conn->query("SELECT COUNT(id) as total FROM guru")->fetch_assoc()['total'];
$total_kelas = $conn->query("SELECT COUNT(DISTINCT kelas) as total FROM siswa WHERE kelas LIKE '%DKV%'")->fetch_assoc()['total'];

$tanggal_hari_ini_sql = date('Y-m-d');

$tq = $conn->prepare("SELECT COUNT(a.id) as total FROM absensi_siswa a WHERE a.tanggal=? AND a.waktu_masuk IS NOT NULL AND TIME(a.waktu_masuk)<=?");
$tq->bind_param("ss", $tanggal_hari_ini_sql, $jam_masuk); $tq->execute();
$jumlah_tepat_waktu = $tq->get_result()->fetch_assoc()['total']; $tq->close();

$lq = $conn->prepare("SELECT s.nama_siswa, TIME(a.waktu_masuk) as waktu_masuk FROM absensi_siswa a JOIN siswa s ON a.siswa_id=s.id WHERE a.tanggal=? AND a.waktu_masuk IS NOT NULL AND TIME(a.waktu_masuk)>? ORDER BY a.waktu_masuk ASC");
$lq->bind_param("ss", $tanggal_hari_ini_sql, $jam_masuk); $lq->execute();
$nama_terlambat   = $lq->get_result()->fetch_all(MYSQLI_ASSOC);
$jumlah_terlambat = count($nama_terlambat); $lq->close();

$bmq = $conn->query("SELECT s.kelas, COUNT(s.id) as jumlah_belum_hadir FROM siswa s LEFT JOIN absensi_siswa a ON s.id=a.siswa_id AND a.tanggal='$tanggal_hari_ini_sql' WHERE a.id IS NULL GROUP BY s.kelas ORDER BY s.kelas ASC");
$belum_masuk_per_kelas = $bmq->fetch_all(MYSQLI_ASSOC);

$nhq = $conn->query("SELECT s.kelas, COUNT(a.id) as jumlah_tidak_hadir FROM absensi_siswa a JOIN siswa s ON a.siswa_id=s.id WHERE a.tanggal='$tanggal_hari_ini_sql' AND a.waktu_masuk IS NULL GROUP BY s.kelas");
$tidak_hadir_per_kelas = [];
while ($row = $nhq->fetch_assoc()) { $tidak_hadir_per_kelas[$row['kelas']] = $row['jumlah_tidak_hadir']; }

$gabungan_belum_hadir = [];
foreach ($belum_masuk_per_kelas as $data) {
    $kelas = $data['kelas']; $total = $data['jumlah_belum_hadir'];
    if (isset($tidak_hadir_per_kelas[$kelas])) $total += $tidak_hadir_per_kelas[$kelas];
    $gabungan_belum_hadir[] = ['kelas'=>$kelas,'jumlah_belum_hadir'=>$total];
}
foreach ($tidak_hadir_per_kelas as $kelas => $jumlah) {
    $found=false; foreach($gabungan_belum_hadir as $d){if($d['kelas']===$kelas){$found=true;break;}}
    if(!$found) $gabungan_belum_hadir[] = ['kelas'=>$kelas,'jumlah_belum_hadir'=>$jumlah];
}

$kelas_target = ['10-DKV','11-DKV','12-DKV'];
$persentase_kehadiran = [];

// Satu query agregat GROUP BY menggantikan 2 query × 3 kelas (N+1)
$kelas_placeholders = implode(',', array_fill(0, count($kelas_target), '?'));
$stmt_pct = $conn->prepare(
    "SELECT s.kelas,
            COUNT(s.id) AS total_siswa,
            SUM(CASE WHEN a.waktu_masuk IS NOT NULL AND a.tanggal = ? THEN 1 ELSE 0 END) AS total_hadir
     FROM siswa s
     LEFT JOIN absensi_siswa a ON a.siswa_id = s.id AND a.tanggal = ?
     WHERE s.kelas IN ($kelas_placeholders)
     GROUP BY s.kelas"
);
$bind_params = array_merge([$tanggal_hari_ini_sql, $tanggal_hari_ini_sql], $kelas_target);
$bind_types  = 'ss' . str_repeat('s', count($kelas_target));
$stmt_pct->bind_param($bind_types, ...$bind_params);
$stmt_pct->execute();
$pct_res  = $stmt_pct->get_result();
$pct_map  = [];
while ($pr = $pct_res->fetch_assoc()) {
    $pct_map[$pr['kelas']] = $pr;
}
$stmt_pct->close();

foreach ($kelas_target as $kelas) {
    $row        = $pct_map[$kelas] ?? ['total_siswa' => 0, 'total_hadir' => 0];
    $total      = (int)$row['total_siswa'];
    $hadir      = (int)$row['total_hadir'];
    $persentase = ($total > 0) ? round(($hadir / $total) * 100) : 0;
    $persentase_kehadiran[] = [
        'kelas'            => $kelas,
        'total_hadir'      => $hadir,
        'total_tidak_hadir'=> $total - $hadir,
        'total_siswa'      => $total,
        'persentase'       => $persentase
    ];
}

$as=$conn->prepare("SELECT s.nama_siswa,s.kelas,a.waktu_masuk,a.foto_masuk, CASE WHEN a.waktu_masuk IS NULL THEN '' WHEN TIME(a.waktu_masuk)<=? THEN 'Tepat Waktu' ELSE 'Terlambat' END AS status_masuk FROM absensi_siswa a JOIN siswa s ON a.siswa_id=s.id WHERE a.tanggal=? AND a.waktu_masuk IS NOT NULL ORDER BY a.waktu_masuk DESC");
$as->bind_param("ss",$jam_masuk,$tanggal_hari_ini_sql); $as->execute();
$absensi_hari_ini=$as->get_result()->fetch_all(MYSQLI_ASSOC); $as->close();

$daq=$conn->prepare("SELECT s.nisn,s.nama_siswa,s.kelas,TIME(a.waktu_masuk) as jam_masuk,TIME(a.waktu_pulang) as jam_pulang, CASE WHEN TIME(a.waktu_masuk)<=? THEN 'Tepat Waktu' ELSE 'Terlambat' END AS status_kehadiran FROM absensi_siswa a JOIN siswa s ON a.siswa_id=s.id WHERE a.tanggal=? AND a.waktu_masuk IS NOT NULL ORDER BY a.waktu_masuk DESC");
$daq->bind_param("ss",$jam_masuk,$tanggal_hari_ini_sql); $daq->execute();
$data_siswa_absen=$daq->get_result()->fetch_all(MYSQLI_ASSOC); $daq->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Absensi Siswa – SMK Terpadu Al Hasan</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/webcamjs/1.0.26/webcam.min.js"></script>
<style>
/* ============================================================
   DESIGN TOKENS (Optimized for 1366x768)
   ============================================================ */
:root {
    --green-deep:  #0d4a2f;
    --green-mid:   #166534;
    --green-light: #22c55e;
    --green-pale:  #dcfce7;
    --gold:        #c9a227;
    --gold-light:  #f5d66b;
    --cream:       #faf9f4;
    --ink:         #1a1a1a;
    --ink-muted:   #4b5563;
    --white:       #ffffff;
    --red:         #dc2626;
    --red-pale:    #fee2e2;
    --amber:       #d97706;
    --amber-pale:  #fef3c7;
    --r-lg:        12px;
    --r-md:        8px;
    --sh:          0 2px 10px rgba(13,74,47,.06);
    --sh-h:        0 4px 16px rgba(13,74,47,.12);

    /* Fixed sizes untuk stabilitas grid vertikal */
    --hdr-h:      56px;
    --sidebar-w:  240px;
    --gap:        0.8rem;
    --pad:        1rem;
    --cam-h:      240px; /* Tinggi webcam diturunkan agar form fit di atas tabel */
}

/* ============================================================
   BASE
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; overflow: hidden; font-family: 'Plus Jakarta Sans', sans-serif; background: var(--cream); color: var(--ink); }

body::before {
    content:''; position:fixed; inset:0; pointer-events:none; z-index:0;
    background:
        radial-gradient(circle at 18% 80%, rgba(13,74,47,.05) 0%, transparent 55%),
        radial-gradient(circle at 82% 10%, rgba(201,162,39,.07) 0%, transparent 50%);
}

/* ============================================================
   SHELL GRID
   ============================================================ */
.kiosk-shell {
    display: grid;
    grid-template-rows: var(--hdr-h) 1fr;
    grid-template-columns: var(--sidebar-w) 1fr;
    height: 100vh;
    position: relative; z-index: 1;
}

/* ============================================================
   HEADER
   ============================================================ */
.kiosk-header {
    grid-column: 1/-1;
    background: linear-gradient(90deg, var(--green-deep), var(--green-mid) 65%, #1a7a40);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 1.25rem; gap: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,.2);
    position: relative; overflow: hidden;
}
.kiosk-header::after {
    content:''; position:absolute; bottom:0; left:0; right:0; height:3px;
    background:linear-gradient(90deg, transparent, var(--gold), var(--gold-light), var(--gold), transparent);
}

.header-brand { display:flex; align-items:center; gap:0.75rem; z-index:1; }
.header-logo  { width:36px; height:36px; border-radius:50%; border:2px solid var(--gold); padding:2px; object-fit:contain; background:rgba(255,255,255,.12); }
.school-title { font-family:'Amiri',serif; font-size:1.1rem; font-weight:700; color:var(--gold-light); line-height:1.15; }
.app-label    { font-size:0.65rem; font-weight:600; color:rgba(255,255,255,.7); text-transform:uppercase; letter-spacing:1px; }

.header-clock { text-align:center; z-index:1; }
.clock-time   { font-size:1.25rem; font-weight:800; color:#fff; letter-spacing:2px; line-height:1; }
.clock-date   { font-size:0.65rem; color:rgba(255,255,255,.75); font-weight:500; margin-top:3px; }

.header-stats { display:flex; gap:0.5rem; z-index:1; }
.hstat {
    display:flex; align-items:center; gap:0.5rem;
    background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.15);
    border-radius:var(--r-md); padding:0.25rem 0.65rem;
}
.hstat-icon  { font-size:1.1rem; }
.hstat-label { font-size:0.6rem; color:rgba(255,255,255,.7); font-weight:500; line-height:1; }
.hstat-val   { font-size:0.95rem; font-weight:800; color:var(--gold-light); line-height:1; }

/* ============================================================
   SIDEBAR
   ============================================================ */
.kiosk-sidebar {
    grid-column:1; grid-row:2;
    background:var(--white);
    border-right:1px solid rgba(13,74,47,.1);
    overflow-y:auto; overflow-x:hidden;
    padding: 1rem 0.85rem;
    scrollbar-width:thin; scrollbar-color:var(--green-light) transparent;
}
.sidebar-section-label {
    font-size:0.6rem; font-weight:700; color:var(--ink-muted);
    text-transform:uppercase; letter-spacing:1px; padding:0 0.4rem; margin-bottom:0.4rem;
}
.sidebar-nav { margin-bottom:1rem; }
.sidebar-nav a {
    display:flex; align-items:center; gap:0.5rem;
    padding:0.4rem 0.7rem; border-radius:var(--r-md); color:var(--ink-muted);
    text-decoration:none; font-size:0.8rem; font-weight:500; transition:all .2s;
}
.sidebar-nav a:hover { background:var(--green-pale); color:var(--green-mid); }
.sidebar-nav a i { font-size:0.9rem; color:var(--green-mid); }

.sstat-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; margin-bottom:1rem; }
.sstat-card { border-radius:var(--r-md); padding:0.65rem; position:relative; overflow:hidden; }
.sstat-card.green { background:linear-gradient(135deg,var(--green-mid),var(--green-deep)); color:#fff; }
.sstat-card.red   { background:linear-gradient(135deg,#ef4444,var(--red)); color:#fff; }
.sstat-card.full  { grid-column:1/-1; background:linear-gradient(135deg,var(--green-deep),#0a3521); color:#fff; }
.sstat-icon  { font-size:1.2rem; opacity:.2; position:absolute; top:0.4rem; right:0.5rem; }
.sstat-label { font-size:0.6rem; font-weight:600; text-transform:uppercase; letter-spacing:1px; opacity:.8; }
.sstat-val   { font-size:1.4rem; font-weight:800; line-height:1.1; margin-top:2px; }
.sstat-sub   { font-size:0.65rem; opacity:.8; margin-top:2px; }
.late-names  { list-style:none; padding:0; margin-top:4px; }
.late-names li { font-size:0.65rem; opacity:.9; display:flex; align-items:center; gap:3px; }
.late-names li::before { content:'•'; }

.schedule-box { border-radius:var(--r-md); background:linear-gradient(135deg,var(--green-deep),#0a3521); padding:0.75rem; color:#fff; margin-top:auto; }
.schedule-box-title { font-size:0.65rem; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:var(--gold-light); margin-bottom:0.5rem; }
.sched-row { display:flex; justify-content:space-between; align-items:center; background:rgba(255,255,255,.08); border-radius:6px; padding:0.35rem 0.65rem; margin-bottom:0.4rem; }
.sched-row:last-child { margin-bottom:0; }
.sched-label { font-size:0.75rem; }
.sched-time  { font-family:monospace; font-size:0.9rem; font-weight:700; color:var(--gold-light); }

/* ============================================================
   MAIN GRID (Strictly managed for 768px height)
   ============================================================ */
.kiosk-main {
    grid-column:2; grid-row:2;
    overflow-y:auto; overflow-x:hidden;
    padding:var(--gap);
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:var(--gap);
    align-content:start;
}

/* ============================================================
   CARDS
   ============================================================ */
.ah-card { background:var(--white); border-radius:var(--r-lg); box-shadow:var(--sh); border:1px solid rgba(13,74,47,.07); }
.ah-card-body { padding:var(--pad); }
.ah-card-title {
    font-size:0.85rem; font-weight:700; color:var(--green-deep);
    display:flex; align-items:center; gap:0.4rem;
    margin-bottom:0.5rem; padding-bottom:0.4rem; border-bottom:2px solid var(--green-pale);
}
.ah-card-title i { color:var(--gold); font-size:1rem; }

/* ============================================================
   FORM & CAROUSEL (ROW 1)
   ============================================================ */
.form-card { grid-column:1; grid-row:1; }
.carousel-card { grid-column:2; grid-row:1; }

.mode-toggle { display:flex; background:var(--green-pale); border-radius:var(--r-md); padding:3px; margin-bottom:0.5rem; gap:3px; }
.mode-toggle input { display:none; }
.mode-toggle label { flex:1; text-align:center; padding:0.35rem; border-radius:6px; font-size:0.75rem; font-weight:700; color:var(--green-mid); cursor:pointer; }
.mode-toggle input:checked + label { background:var(--green-mid); color:#fff; }

.nisn-wrap { position:relative; margin-bottom:0.5rem; }
.nisn-wrap input {
    width:100%; padding:0.5rem 0.5rem 0.5rem 2.2rem;
    border:2px solid rgba(13,74,47,.15); border-radius:var(--r-md);
    font-size:0.9rem; font-weight:600; font-family:monospace; background:var(--cream); outline:none;
}
.nisn-wrap input:focus { border-color:var(--green-mid); background:#fff; }
.nisn-icon { position:absolute; top:50%; transform:translateY(-50%); left:0.75rem; font-size:1rem; color:var(--gold); }

.webcam-wrap { width:100%; height:var(--cam-h); border-radius:var(--r-md); overflow:hidden; background:#f0f0f0; display:flex; align-items:center; justify-content:center; margin-bottom:0.5rem; }
#webcam-container, #webcam-container video, #webcam-container canvas { width:100% !important; height:100% !important; object-fit:cover; }

.output-row { display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; }
.output-field { background:var(--cream); border:1.5px solid rgba(13,74,47,.12); border-radius:var(--r-md); padding:0.4rem 0.6rem; font-size:0.8rem; font-weight:600; width:100%; outline:none; }

.carousel-image-fixed { width:100%; height:calc(var(--cam-h) + 130px); object-fit:cover; border-radius:var(--r-md); }
.carousel-caption { background:linear-gradient(0deg,rgba(13,74,47,.9) 0%,transparent 100%); left:0; right:0; bottom:0; border-radius:0 0 var(--r-md) var(--r-md); padding:0.6rem; }
.carousel-caption h5 { font-size:0.9rem; font-weight:700; margin:0; }
.carousel-caption p  { font-size:0.7rem; margin:0; }

/* ============================================================
   TABLE & PROGRESS (ROW 2 & 3)
   ============================================================ */
.table-card { grid-column:1/-1; grid-row:2; }
.progress-card { grid-column:1/-1; grid-row:3; }

.ah-table { width:100%; border-collapse:collapse; font-size:0.75rem; }
.ah-table thead th { background:var(--green-deep); color:#fff; padding:0.4rem 0.5rem; position:sticky; top:0; z-index:2; text-transform:uppercase; font-size:0.65rem; }
.ah-table tbody tr { border-bottom:1px solid rgba(13,74,47,.06); }
.ah-table tbody td { padding:0.35rem 0.5rem; vertical-align:middle; }
.ah-table tbody tr:nth-child(even) { background:rgba(220,252,231,.28); }

/* Kunci utama agar tinggi stabil: batasi max-height tabel */
.table-scroll { max-height:165px; overflow-y:auto; scrollbar-width:thin; scrollbar-color:var(--green-light) transparent; border-radius:0 0 6px 6px;}

.progress-items { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:0.65rem; }
.progress-item { background:var(--cream); border-radius:var(--r-md); padding:0.65rem; border:1px solid rgba(13,74,47,.1); }
.progress-top { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.35rem; }
.progress-class-name { font-weight:700; font-size:0.8rem; color:var(--green-deep); }
.progress-pct-badge { font-size:0.8rem; font-weight:800; color:var(--green-mid); background:var(--green-pale); border-radius:20px; padding:2px 8px; }
.progress-mini-stats { display:flex; gap:0.5rem; font-size:0.65rem; color:var(--ink-muted); margin-bottom:0.4rem; }
.progress-track { height:6px; background:#e2e8f0; border-radius:6px; overflow:hidden; }
.progress-fill  { height:100%; border-radius:6px; width:0; transition:width 1s; }
.progress-fill.high   { background:#22c55e; }
.progress-fill.medium { background:#f59e0b; }
.progress-fill.low    { background:#ef4444; }

.badge-status { padding:2px 6px; border-radius:12px; font-size:0.65rem; font-weight:700; }
.badge-status.tepat    { background:var(--green-pale); color:var(--green-mid); }
.badge-status.terlambat{ background:var(--red-pale); color:var(--red); }
.badge-status.belum    { background:var(--amber-pale); color:var(--amber); }
.badge-kelas { background:rgba(13,74,47,.08); color:var(--green-deep); padding:2px 6px; border-radius:4px; font-weight:600; font-size:0.7rem; }
.notification-bar { display:none; border-radius:var(--r-md); padding:0.4rem; font-size:0.8rem; font-weight:600; text-align:center; margin-top:0.4rem; }
</style>
</head>
<body>
<div class="kiosk-shell">

<header class="kiosk-header">
    <div class="header-brand">
        <img src="classync.png" alt="Logo" class="header-logo">
        <div>
            <div class="school-title">SMK Terpadu Al Hasan</div>
            <div class="app-label">Digital Attendance System</div>
        </div>
    </div>
    <div class="header-clock">
        <div id="clock-time" class="clock-time">00:00:00</div>
        <div id="clock-date" class="clock-date">Memuat...</div>
    </div>
    <div class="header-stats">
        <div class="hstat"><i class="bi bi-people-fill hstat-icon" style="color:#86efac"></i><div><div class="hstat-label">Siswa</div><div class="hstat-val"><?php echo $total_siswa; ?></div></div></div>
        <div class="hstat"><i class="bi bi-person-video3 hstat-icon" style="color:#fde68a"></i><div><div class="hstat-label">Guru</div><div class="hstat-val"><?php echo $total_guru; ?></div></div></div>
        <div class="hstat"><i class="bi bi-door-open-fill hstat-icon" style="color:#7dd3fc"></i><div><div class="hstat-label">Kelas</div><div class="hstat-val"><?php echo $total_kelas; ?></div></div></div>
    </div>
</header>

<aside class="kiosk-sidebar">
    <div class="sidebar-section-label">Menu</div>
    <nav class="sidebar-nav">
        <a href="absen_manual.php"><i class="bi bi-pencil-square"></i> Absen Manual</a>
        <a href="ekspor-absensi.php"><i class="bi bi-file-earmark-pdf"></i> Ekspor</a>
        <a href="laporan-kehadiran.php"><i class="bi bi-clipboard-data"></i> Analisis Siswa</a>
    </nav>
    <div class="sidebar-section-label">Statistik Hari Ini</div>
    <div class="sstat-grid">
        <div class="sstat-card green">
            <i class="bi bi-check-circle-fill sstat-icon"></i>
            <div class="sstat-label">Tepat Waktu</div>
            <div class="sstat-val"><?php echo $jumlah_tepat_waktu; ?></div>
        </div>
        <div class="sstat-card red">
            <i class="bi bi-clock-history sstat-icon"></i>
            <div class="sstat-label">Terlambat</div>
            <div class="sstat-val"><?php echo $jumlah_terlambat; ?></div>
        </div>
        <div class="ah-card progress-card">
        <div class="ah-card-body">
            <div class="ah-card-title"><i class="bi bi-graph-up-arrow"></i> Progres Kehadiran Per Kelas Target</div>
            <?php if(!empty($persentase_kehadiran)): ?>
                <div class="progress-items">
                    <?php foreach($persentase_kehadiran as $d): $cls=$d['persentase']>=80?'high':($d['persentase']>=50?'medium':'low'); ?>
                        <div class="progress-item">
                            <div class="progress-top">
                                <div class="progress-class-name"><?php echo htmlspecialchars($d['kelas'] ?? ''); ?></div>
                                <div class="progress-pct-badge"><?php echo $d['persentase']; ?>%</div>
                            </div>
                            <div class="progress-mini-stats">
                                <span><i class="bi bi-people-fill"></i> <?php echo $d['total_siswa']; ?></span>
                                <span style="color:#22c55e"><i class="bi bi-check-circle-fill"></i> <?php echo $d['total_hadir']; ?></span>
                                <span style="color:#ef4444"><i class="bi bi-x-circle-fill"></i> <?php echo $d['total_tidak_hadir']; ?></span>
                            </div>
                            <div class="progress-track"><div class="progress-fill <?php echo $cls; ?>" style="width:0" data-width="<?php echo $d['persentase']; ?>%"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
        </div>
    <div class="schedule-box">
        <div class="schedule-box-title"><i class="bi bi-clock-fill"></i> Waktu Operasional</div>
        <div class="sched-row"><div class="sched-label"><i class="bi bi-door-open"></i> Masuk</div><div class="sched-time"><?php echo htmlspecialchars(date('H:i',strtotime($jam_masuk)) ?? ''); ?></div></div>
        <div class="sched-row"><div class="sched-label"><i class="bi bi-door-closed"></i> Pulang</div><div class="sched-time"><?php echo htmlspecialchars(date('H:i',strtotime($jam_pulang)) ?? ''); ?></div></div>
    </div>
</aside>

<main class="kiosk-main">

    <div class="ah-card form-card">
        <div class="ah-card-body">
            <div class="ah-card-title"><i class="bi bi-qr-code-scan"></i> Absensi Siswa</div>
            <div class="mode-toggle">
                <input type="radio" name="mode_absen" id="mode_masuk" checked>
                <label for="mode_masuk">🚪 MASUK</label>
                <input type="radio" name="mode_absen" id="mode_pulang">
                <label for="mode_pulang">🏠 PULANG</label>
            </div>
            <form id="absen-form">
                <div class="nisn-wrap">
                    <i class="bi bi-upc nisn-icon"></i>
                    <input type="text" id="nisn-input" placeholder="Scan QR Code / ketik NISN…" autofocus>
                </div>
            </form>
            <div class="webcam-wrap"><div id="webcam-container"></div></div>
            <div class="output-row">
                <input type="text" id="nama-output"  class="output-field" placeholder="Nama Lengkap" readonly>
                <input type="text" id="kelas-output" class="output-field" placeholder="Kelas" readonly>
            </div>
            <div id="notification-bar" class="notification-bar"></div>
        </div>
    </div>

    <div class="ah-card carousel-card">
        <div class="ah-card-body">
            <div class="ah-card-title"><i class="bi bi-camera2"></i> Aktivitas Terbaru</div>
            <div id="galleryCarousel" class="carousel slide" data-bs-ride="carousel">
                <div class="carousel-inner rounded" id="carousel-inner-container">
                    <?php if(empty($absensi_hari_ini)): ?>
                        <div class="carousel-item active"><div class="carousel-image-fixed" style="background:#eee;display:flex;align-items:center;justify-content:center;color:#999">Belum ada absensi</div></div>
                    <?php else: ?>
                        <?php foreach($absensi_hari_ini as $idx=>$absen): ?>
                            <div class="carousel-item <?php echo $idx===0?'active':''; ?>">
                                <img src="<?php echo htmlspecialchars($absen['foto_masuk'] ?? ''); ?>" class="d-block w-100 carousel-image-fixed" alt="Foto">
                                <div class="carousel-caption d-none d-md-block">
                                    <h5><?php echo htmlspecialchars($absen['nama_siswa'] ?? ''); ?></h5>
                                    <p><?php echo htmlspecialchars($absen['kelas'] ?? ''); ?> | <span class="badge-status <?php echo $absen['status_masuk']==='Terlambat'?'terlambat':'tepat'; ?>"><?php echo $absen['status_masuk']; ?></span></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="ah-card table-card">
        <div class="ah-card-body">
            <div class="ah-card-title" style="margin-bottom:0.25rem;"><i class="bi bi-table"></i> Log Kehadiran Hari Ini (<?php echo count($data_siswa_absen); ?> dari <?php echo $total_siswa; ?>)</div>
            <div class="table-scroll">
                <table class="ah-table">
                    <thead><tr><th>No</th><th>NISN</th><th>Nama Siswa</th><th>Kelas</th><th>Masuk</th><th>Pulang</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if(empty($data_siswa_absen)): ?>
                            <tr><td colspan="7" style="text-align:center;padding:1rem;color:#aaa;">Belum ada data</td></tr>
                        <?php else: ?>
                            <?php foreach($data_siswa_absen as $i=>$s): ?>
                                <tr>
                                    <td><?php echo $i+1; ?></td>
                                    <td><span style="font-family:monospace;font-weight:700;"><?php echo htmlspecialchars($s['nisn'] ?? ''); ?></span></td>
                                    <td style="font-weight:600;"><?php echo htmlspecialchars($s['nama_siswa'] ?? ''); ?></td>
                                    <td><span class="badge-kelas"><?php echo htmlspecialchars($s['kelas'] ?? ''); ?></span></td>
                                    <td><?php echo htmlspecialchars($s['jam_masuk'] ?? ''); ?></td>
                                    <td><?php echo !empty($s['jam_pulang'])?$s['jam_pulang']:'-'; ?></td>
                                    <td><span class="badge-status <?php echo $s['status_kehadiran']==='Terlambat'?'terlambat':'tepat'; ?>"><?php echo htmlspecialchars($s['status_kehadiran'] ?? ''); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="ah-card progress-card">
        <div class="ah-card-body">
            <div class="ah-card-title"><i class="bi bi-graph-up-arrow"></i> Progres Kehadiran Per Kelas Target</div>
            <?php if(!empty($persentase_kehadiran)): ?>
                <div class="progress-items">
                    <?php foreach($persentase_kehadiran as $d): $cls=$d['persentase']>=80?'high':($d['persentase']>=50?'medium':'low'); ?>
                        <div class="progress-item">
                            <div class="progress-top">
                                <div class="progress-class-name"><?php echo htmlspecialchars($d['kelas'] ?? ''); ?></div>
                                <div class="progress-pct-badge"><?php echo $d['persentase']; ?>%</div>
                            </div>
                            <div class="progress-mini-stats">
                                <span><i class="bi bi-people-fill"></i> <?php echo $d['total_siswa']; ?></span>
                                <span style="color:#22c55e"><i class="bi bi-check-circle-fill"></i> <?php echo $d['total_hadir']; ?></span>
                                <span style="color:#ef4444"><i class="bi bi-x-circle-fill"></i> <?php echo $d['total_tidak_hadir']; ?></span>
                            </div>
                            <div class="progress-track"><div class="progress-fill <?php echo $cls; ?>" style="width:0" data-width="<?php echo $d['persentase']; ?>%"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

</main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const ct=document.getElementById('clock-time'), cd=document.getElementById('clock-date');
    function tick(){
        const n=new Date();
        if(ct) ct.textContent=n.toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
        if(cd) cd.textContent=n.toLocaleDateString('id-ID',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
    }
    setInterval(tick,1000); tick();

    setTimeout(()=>{ document.querySelectorAll('.progress-fill').forEach(b=>{ b.style.width=b.dataset.width; }); },400);

    let webcamAttached = false;
    function attachWebcam() {
        const wrap = document.querySelector('.webcam-wrap');
        if (!wrap) return;
        const w = Math.floor(wrap.offsetWidth), h = Math.floor(wrap.offsetHeight);
        if (w < 10 || h < 10) return;
        Webcam.set({ width:w, height:h, image_format:'jpeg', jpeg_quality:90, dest_width:w, dest_height:h, crop_width:w, crop_height:h });
        if (webcamAttached) Webcam.reset();
        Webcam.attach('#webcam-container');
        webcamAttached = true;
    }
    attachWebcam();
    let resizeTimer; window.addEventListener('resize', ()=>{ clearTimeout(resizeTimer); resizeTimer = setTimeout(attachWebcam, 250); });

    const nisnInput=document.getElementById('nisn-input'); nisnInput.focus();
    document.body.addEventListener('click',(e)=>{ if(e.target.tagName !== 'INPUT') nisnInput.focus(); });

    const notif=document.getElementById('notification-bar');
    function showNotif(msg,ok){
        notif.textContent=msg; notif.style.color=ok?'#166534':'#dc2626'; notif.style.background=ok?'#dcfce7':'#fee2e2';
        notif.style.display='block'; setTimeout(()=>{ notif.style.display='none'; },3500);
    }

    document.getElementById('absen-form').addEventListener('submit',function(e){
        e.preventDefault();
        const nisn=nisnInput.value.trim(); if(!nisn) return;
        const namaOut=document.getElementById('nama-output'), kelasOut=document.getElementById('kelas-output');
        namaOut.value='Mengambil foto…'; kelasOut.value=''; notif.style.display='none';

        Webcam.snap(function(dataUri){
            namaOut.value='Memproses…';
            const mode=document.getElementById('mode_masuk').checked?'masuk':'pulang';
            fetch('api/proses_absen_siswa.php',{
                method:'POST', headers:{'Content-Type':'application/json'},
                body:JSON.stringify({nisn,foto_base64:dataUri,mode})
            })
            .then(r=>r.json()).then(res=>{
                if(res.status==='success'){
                    showNotif(res.message,true); namaOut.value=res.data.nama_siswa; kelasOut.value=res.data.kelas;
                    setTimeout(()=>location.reload(),2000);
                } else { showNotif(res.message,false); namaOut.value='GAGAL'; kelasOut.value='Ulangi Scan'; }
            }).catch(()=>showNotif('Terjadi kesalahan koneksi.',false)).finally(()=>{ nisnInput.value=''; nisnInput.focus(); });
        });
    });
});
</script>
</body>
</html>