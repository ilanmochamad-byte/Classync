<?php 
include 'partials/header.php'; 

// ====================================================================
// FUNGSI AMAN: Mencegah Blank Page jika terjadi error pada database
// ====================================================================
function getCountSafe($conn, $query) {
    $result = $conn->query($query);
    return $result ? $result->fetch_assoc()['total'] : 0;
}

// --- BOUNDARY WAKTU (INDEX-FRIENDLY) ---
$todayStart = date('Y-m-d 00:00:00');
$tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));
$thisMonthStart = date('Y-m-01');
$nextMonthStart = date('Y-m-01', strtotime('+1 month'));

// --- 1. AMBIL STATISTIK DASAR DENGAN AMAN ---
$total_guru = getCountSafe($conn, "SELECT COUNT(*) AS total FROM guru");

// DIUBAH: hindari DATE(waktu_absensi), gunakan range agar index terpakai
$stmt_absen = $conn->prepare("
    SELECT COUNT(a.id) AS total
    FROM absensi a
    WHERE a.waktu_absensi >= ? AND a.waktu_absensi < ?
");
$stmt_absen->bind_param("ss", $todayStart, $tomorrowStart);
$stmt_absen->execute();
$res_absen = $stmt_absen->get_result()->fetch_assoc();
$absen_hari_ini = $res_absen ? (int)$res_absen['total'] : 0;

// DIPERBAIKI: Hanya menghitung jadwal yang statusnya 'Aktif'
$total_jadwal = getCountSafe($conn, "SELECT 
    (SELECT COUNT(*) FROM jadwal_mengajar WHERE status_jadwal = 'Aktif') + 
    (SELECT COUNT(*) FROM jadwal_piket WHERE status_jadwal = 'Aktif') + 
    (SELECT COUNT(*) FROM jadwal_ekskul WHERE status_jadwal = 'Aktif') as total");

// DIUBAH: Menghitung Jurnal dan BK pakai range waktu (index-friendly)
$stmt_jurnal_count = $conn->prepare("
    SELECT COUNT(a.id) AS total
    FROM absensi a
    WHERE a.tipe_absensi = 'mengajar'
      AND a.waktu_absensi >= ? AND a.waktu_absensi < ?
");
$stmt_jurnal_count->bind_param("ss", $todayStart, $tomorrowStart);
$stmt_jurnal_count->execute();
$res_jurnal_count = $stmt_jurnal_count->get_result()->fetch_assoc();
$jurnal_hari_ini = $res_jurnal_count ? (int)$res_jurnal_count['total'] : 0;

$stmt_bk_count = $conn->prepare("
    SELECT COUNT(a.id) AS total
    FROM absensi a
    WHERE a.tipe_absensi = 'bimbingan'
      AND a.waktu_absensi >= ? AND a.waktu_absensi < ?
");
$stmt_bk_count->bind_param("ss", $todayStart, $tomorrowStart);
$stmt_bk_count->execute();
$res_bk_count = $stmt_bk_count->get_result()->fetch_assoc();
$bk_hari_ini = $res_bk_count ? (int)$res_bk_count['total'] : 0;


// --- 2. AMBIL JURNAL MENGAJAR TERBARU (12 Data Terakhir) ---
$stmt_jurnal = $conn->prepare("
    SELECT
        a.waktu_absensi,
        g.nama_guru,
        jm.mata_pelajaran,
        jm.kelas
    FROM absensi a
    JOIN guru g ON a.guru_id = g.id
    JOIN jadwal_mengajar jm ON a.jadwal_id = jm.id
    WHERE a.tipe_absensi = 'mengajar'
    ORDER BY a.waktu_absensi DESC
    LIMIT 12
");
$stmt_jurnal->execute();
$jurnal_terbaru = $stmt_jurnal->get_result();


// --- 3. AMBIL LAYANAN BK TERBARU (5 Data Terakhir) ---
$stmt_bk = $conn->prepare("
    SELECT
        a.waktu_absensi,
        g.nama_guru,
        jbk.topik_tema,
        jbk.sasaran_layanan
    FROM absensi a
    JOIN guru g ON a.guru_id = g.id
    JOIN jurnal_bk jbk ON a.id = jbk.absensi_guru_id
    WHERE a.tipe_absensi = 'bimbingan'
    ORDER BY a.waktu_absensi DESC
    LIMIT 5
");
$stmt_bk->execute();
$bk_terbaru = $stmt_bk->get_result();


// --- 4. REKAP KEHADIRAN SISWA HARI INI (Global) ---
$stmt_rekap_siswa = $conn->prepare("
    SELECT das.status_kehadiran, COUNT(das.id) as jumlah
    FROM detail_absensi_siswa das
    JOIN absensi a ON das.absensi_guru_id = a.id
    WHERE a.waktu_absensi >= ? AND a.waktu_absensi < ?
    GROUP BY das.status_kehadiran
");
$stmt_rekap_siswa->bind_param("ss", $todayStart, $tomorrowStart);
$stmt_rekap_siswa->execute();
$rekap_siswa = $stmt_rekap_siswa->get_result();

$data_kehadiran = ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0];
if($rekap_siswa) {
    while($row = $rekap_siswa->fetch_assoc()) {
        $status = $row['status_kehadiran'];
        if (isset($data_kehadiran[$status])) {
            $data_kehadiran[$status] = (int)$row['jumlah'];
        }
    }
}

// --- 5. STATISTIK ABSENSI HARIAN GURU (GEOFENCING) ---
$guru_masuk_hari_ini = getCountSafe($conn, "SELECT COUNT(id) AS total FROM absensi_harian WHERE tanggal = CURDATE() AND jam_masuk IS NOT NULL");
$guru_pulang_hari_ini = getCountSafe($conn, "SELECT COUNT(id) AS total FROM absensi_harian WHERE tanggal = CURDATE() AND jam_pulang IS NOT NULL");

// DIUBAH: hindari MONTH()/YEAR() pada a.tanggal, gunakan range bulan
$stmt_top_bonus = $conn->prepare("
    SELECT g.nama_guru, SUM(a.bonus) as total_bonus
    FROM absensi_harian a
    JOIN guru g ON a.guru_id = g.id
    WHERE a.tanggal >= ? AND a.tanggal < ?
    GROUP BY a.guru_id
    ORDER BY total_bonus DESC
    LIMIT 1
");
$stmt_top_bonus->bind_param("ss", $thisMonthStart, $nextMonthStart);
$stmt_top_bonus->execute();
$res_top_bonus = $stmt_top_bonus->get_result();
$top_bonus_guru = ($res_top_bonus && $res_top_bonus->num_rows > 0) ? $res_top_bonus->fetch_assoc() : null;

?>

<style>
    .stat-card { transition: transform 0.3s ease, box-shadow 0.3s ease; border: none; border-radius: 12px; overflow: hidden; position: relative;}
    .stat-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;}
    .stat-icon { font-size: 3rem; opacity: 0.2; position: absolute; right: 20px; bottom: 10px; }
    .stat-link { text-decoration: none; color: rgba(255,255,255,0.8); font-size: 0.85rem; display: block; margin-top: 10px; border-top: 1px solid rgba(255,255,255,0.2); padding-top: 8px;}
    .stat-link:hover { color: #fff; }
    .dashboard-title { font-weight: 700; color: #2c3e50; }
    .table-hover tbody tr:hover { background-color: #f8f9fa; }
    .card-header-custom { background-color: #fff; border-bottom: 2px solid #f1f2f6; font-weight: 600; color: #4A90A4;}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="dashboard-title mb-1"><i class="bi bi-speedometer2 text-primary"></i> Dashboard Admin</h2>
        <p class="text-muted">Ringkasan aktivitas SMK Terpadu Al Hasan hari ini: <strong><?php echo date('d F Y'); ?></strong></p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="card stat-card bg-primary text-white shadow-sm h-100">
            <div class="card-body p-4">
                <h6 class="text-uppercase fw-semibold mb-2" style="letter-spacing: 1px; opacity: 0.9;">Total Guru</h6>
                <h2 class="fw-bold mb-0 fs-1"><?php echo $total_guru; ?></h2>
                <i class="bi bi-person-badge stat-icon"></i>
                <a href="guru.php" class="stat-link">Lihat Detail &rarr;</a>
            </div>
        </div>
    </div>
    
    <div class="col-12 col-md-4">
        <div class="card stat-card bg-success text-white shadow-sm h-100">
            <div class="card-body p-4">
                <h6 class="text-uppercase fw-semibold mb-2" style="letter-spacing: 1px; opacity: 0.9;">Absensi Kelas (Harian)</h6>
                <h2 class="fw-bold mb-0 fs-1"><?php echo $absen_hari_ini; ?></h2>
                <i class="bi bi-calendar-check stat-icon"></i>
                <a href="laporan.php" class="stat-link">Lihat Laporan &rarr;</a>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-4">
        <div class="card stat-card bg-info text-white shadow-sm h-100">
            <div class="card-body p-4">
                <h6 class="text-uppercase fw-semibold mb-2" style="letter-spacing: 1px; opacity: 0.9;">Total Jadwal Aktif</h6>
                <h2 class="fw-bold mb-0 fs-1"><?php echo $total_jadwal; ?></h2>
                <i class="bi bi-calendar-week stat-icon"></i>
                <a href="jadwal_mengajar.php" class="stat-link">Kelola Jadwal &rarr;</a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-6">
        <div class="card stat-card text-white shadow-sm h-100" style="background-color: #6c5ce7;">
            <div class="card-body p-3">
                <h6 class="mb-1" style="opacity: 0.9;">Jurnal Mengajar Masuk Hari Ini</h6>
                <h3 class="fw-bold mb-0"><?php echo $jurnal_hari_ini; ?> <small class="fs-6 fw-normal">Jurnal</small></h3>
                <i class="bi bi-journal-text stat-icon" style="font-size: 2rem;"></i>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card stat-card text-white shadow-sm h-100" style="background-color: #00b894;">
            <div class="card-body p-3">
                <h6 class="mb-1" style="opacity: 0.9;">Layanan BK Hari Ini</h6>
                <h3 class="fw-bold mb-0"><?php echo $bk_hari_ini; ?> <small class="fs-6 fw-normal">Layanan</small></h3>
                <i class="bi bi-people stat-icon" style="font-size: 2rem;"></i>
            </div>
        </div>
    </div>
</div>

<h5 class="fw-bold text-dark mt-4 mb-3"><i class="bi bi-geo-alt-fill text-danger me-2"></i> Kehadiran Guru Hari Ini</h5>
<div class="row g-3 mb-5">
    <div class="col-12 col-md-4">
        <a href="laporan_absen_harian.php" class="text-decoration-none">
            <div class="card stat-card shadow-sm h-100 bg-white" style="border-left: 5px solid #3498db;">
                <div class="card-body p-3">
                    <h6 class="mb-1 text-muted">Guru Hadir (Masuk)</h6>
                    <h3 class="fw-bold text-dark mb-0"><?php echo $guru_masuk_hari_ini; ?> <small class="fs-6 fw-normal text-muted">Orang</small></h3>
                    <i class="bi bi-box-arrow-in-right stat-icon text-primary" style="opacity: 0.15; font-size: 2.5rem;"></i>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-md-4">
        <a href="laporan_absen_harian.php" class="text-decoration-none">
            <div class="card stat-card shadow-sm h-100 bg-white" style="border-left: 5px solid #f39c12;">
                <div class="card-body p-3">
                    <h6 class="mb-1 text-muted">Selesai (Pulang)</h6>
                    <h3 class="fw-bold text-dark mb-0"><?php echo $guru_pulang_hari_ini; ?> <small class="fs-6 fw-normal text-muted">Orang</small></h3>
                    <i class="bi bi-box-arrow-right stat-icon text-warning" style="opacity: 0.15; font-size: 2.5rem;"></i>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-md-4">
        <div class="card stat-card shadow-sm h-100 bg-white" style="border-left: 5px solid #2ecc71;">
            <div class="card-body p-3">
                <h6 class="mb-1 text-muted">Top Bonus Bulan Ini</h6>
                <?php if($top_bonus_guru): ?>
                    <h5 class="fw-bold text-success mb-0 text-truncate" title="<?php echo htmlspecialchars($top_bonus_guru['nama_guru']); ?>">
                        <?php echo htmlspecialchars($top_bonus_guru['nama_guru']); ?>
                    </h5>
                    <small class="text-muted fw-semibold">Rp <?php echo number_format($top_bonus_guru['total_bonus'], 0, ',', '.'); ?></small>
                <?php else: ?>
                    <h5 class="fw-bold text-muted mb-0">-</h5>
                    <small class="text-muted">Belum ada data</small>
                <?php endif; ?>
                <i class="bi bi-star-fill stat-icon text-success" style="opacity: 0.15; font-size: 2.5rem;"></i>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm border-0 mb-4 rounded-4">
            <div class="card-header card-header-custom pt-3 pb-2">
                <i class="bi bi-bar-chart-fill me-2 text-warning"></i> Rekap Kehadiran Siswa
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">Akumulasi dari laporan kelas hari ini.</p>
                
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-semibold text-secondary"><i class="bi bi-check-circle-fill text-success me-2"></i> Hadir</span>
                    <span class="badge bg-success rounded-pill px-3 py-2"><?php echo $data_kehadiran['Hadir']; ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-semibold text-secondary"><i class="bi bi-thermometer-half text-warning me-2"></i> Sakit</span>
                    <span class="badge bg-warning text-dark rounded-pill px-3 py-2"><?php echo $data_kehadiran['Sakit']; ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-semibold text-secondary"><i class="bi bi-envelope-paper-fill text-info me-2"></i> Izin</span>
                    <span class="badge bg-info text-dark rounded-pill px-3 py-2"><?php echo $data_kehadiran['Izin']; ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-semibold text-secondary"><i class="bi bi-x-circle-fill text-danger me-2"></i> Alpa</span>
                    <span class="badge bg-danger rounded-pill px-3 py-2"><?php echo $data_kehadiran['Alpa']; ?></span>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header card-header-custom pt-3 pb-2 d-flex justify-content-between align-items-center">
                <span><i class="bi bi-heart-pulse-fill me-2" style="color: #FF6B00;"></i> Layanan BK Terbaru</span>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php if($bk_terbaru && $bk_terbaru->num_rows > 0): ?>
                        <?php while($bk = $bk_terbaru->fetch_assoc()): ?>
                            <div class="list-group-item p-3 border-bottom-0 border-light">
                                <div class="d-flex w-100 justify-content-between mb-1">
                                    <strong class="text-dark"><?php echo htmlspecialchars($bk['nama_guru']); ?></strong>
                                    <small class="text-muted"><?php echo date('H:i', strtotime($bk['waktu_absensi'])); ?></small>
                                </div>
                                <p class="mb-1 small text-primary fw-semibold"><?php echo htmlspecialchars($bk['topik_tema']); ?></p>
                                <small class="text-muted"><i class="bi bi-person me-1"></i> <?php echo htmlspecialchars($bk['sasaran_layanan']); ?></small>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="p-4 text-center text-muted small">
                            <em>Belum ada layanan BK tercatat.</em>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
    </div>

   <div class="col-12 col-lg-8">
        <div class="card shadow-sm border-0 rounded-4 h-100">
            <div class="card-header card-header-custom pt-3 pb-2 d-flex justify-content-between align-items-center">
                <span><i class="bi bi-journal-text me-2"></i> Jurnal Mengajar Terbaru</span>
                <a href="https://smkt.alhasan.co.id/classync/jurnal.php" class="btn btn-sm btn-outline-primary rounded-pill" target="_blank" rel="noopener noreferrer">Lihat Semua</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted small">
                            <tr>
                                <th class="ps-4">Waktu</th>
                                <th>Guru Pengajar</th>
                                <th>Mapel & Kelas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($jurnal_terbaru && $jurnal_terbaru->num_rows > 0): ?>
                                <?php while($jurnal = $jurnal_terbaru->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4 text-muted small">
                                        <span class="fw-semibold text-dark d-block"><?php echo date('d/m/Y', strtotime($jurnal['waktu_absensi'])); ?></span>
                                        <?php echo date('H:i', strtotime($jurnal['waktu_absensi'])); ?> WIB
                                    </td>
                                    <td class="fw-semibold text-dark"><?php echo htmlspecialchars($jurnal['nama_guru']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($jurnal['mata_pelajaran']); ?><br>
                                        <span class="text-muted small">Kelas <?php echo htmlspecialchars($jurnal['kelas']); ?></span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-5">
                                        <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                                        <em>Belum ada jurnal mengajar yang masuk.</em>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div> 
<div class="mb-5 pb-5"></div>

<?php include 'partials/footer.php'; ?>