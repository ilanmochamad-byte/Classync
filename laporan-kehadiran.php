<?php
// laporan-kehadiran.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'includes/db.php';

// Ambil daftar kelas untuk filter
$kelas_query = $conn->query("SELECT DISTINCT kelas FROM siswa ORDER BY kelas ASC");
$daftar_kelas = [];
while ($row = $kelas_query->fetch_assoc()) {
    $daftar_kelas[] = $row['kelas'];
}

// Set Filter Default
$bulan_pilih = isset($_GET['bulan']) ? (int)$_GET['bulan'] : date('n');
$tahun_pilih = isset($_GET['tahun']) ? (int)$_GET['tahun'] : date('Y');
$kelas_pilih = isset($_GET['kelas']) ? $_GET['kelas'] : ($daftar_kelas[0] ?? '');

$bulan_indo = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
$tahun_sekarang = date('Y');

// 1. DATA UNTUK GRAFIK
$sql_grafik = "SELECT status_masuk, COUNT(*) as jumlah 
               FROM absensi_siswa a 
               JOIN siswa s ON a.siswa_id = s.id 
               WHERE s.kelas = ? AND MONTH(a.tanggal) = ? AND YEAR(a.tanggal) = ?
               GROUP BY status_masuk";
$stmt_grafik = $conn->prepare($sql_grafik);
$stmt_grafik->bind_param("sii", $kelas_pilih, $bulan_pilih, $tahun_pilih);
$stmt_grafik->execute();
$res_grafik = $stmt_grafik->get_result();

$chart_data = ['Tepat Waktu' => 0, 'Terlambat' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpha' => 0];
while($row = $res_grafik->fetch_assoc()){
    $status = $row['status_masuk'] == 'Alpa' ? 'Alpha' : $row['status_masuk']; // Normalisasi
    if(isset($chart_data[$status])) {
        $chart_data[$status] += $row['jumlah'];
    }
}

// 2. SISWA TERAWAL (Rata-Rata Masuk Paling Awal)
$sql_terawal = "SELECT s.nama_siswa, SEC_TO_TIME(AVG(TIME_TO_SEC(a.waktu_masuk))) as rata_waktu, COUNT(a.id) as jml_hadir 
                FROM absensi_siswa a 
                JOIN siswa s ON a.siswa_id = s.id 
                WHERE s.kelas = ? AND MONTH(a.tanggal) = ? AND YEAR(a.tanggal) = ? 
                AND a.status_masuk IN ('Tepat Waktu', 'Terlambat')
                GROUP BY s.id
                HAVING jml_hadir >= 3
                ORDER BY AVG(TIME_TO_SEC(a.waktu_masuk)) ASC LIMIT 1";
$stmt_terawal = $conn->prepare($sql_terawal);
$stmt_terawal->bind_param("sii", $kelas_pilih, $bulan_pilih, $tahun_pilih);
$stmt_terawal->execute();
$siswa_terawal = $stmt_terawal->get_result()->fetch_assoc();

// 3. SISWA TERLAMBAT (Frekuensi Terbanyak & Rata-Rata Waktu Terlambat)
$sql_terlambat = "SELECT s.nama_siswa, 
                         COUNT(a.id) as total_telat, 
                         SEC_TO_TIME(AVG(TIME_TO_SEC(a.waktu_masuk))) as rata_waktu_telat 
                  FROM absensi_siswa a 
                  JOIN siswa s ON a.siswa_id = s.id 
                  WHERE s.kelas = ? AND MONTH(a.tanggal) = ? AND YEAR(a.tanggal) = ? 
                  AND a.status_masuk = 'Terlambat'
                  GROUP BY s.id
                  ORDER BY total_telat DESC, rata_waktu_telat DESC LIMIT 1";
$stmt_terlambat = $conn->prepare($sql_terlambat);
$stmt_terlambat->bind_param("sii", $kelas_pilih, $bulan_pilih, $tahun_pilih);
$stmt_terlambat->execute();
$siswa_terlambat = $stmt_terlambat->get_result()->fetch_assoc();

// 4. SISWA TERBANYAK TIDAK ABSEN (Bolos Terselubung / Kosong Absen)
$sql_he = "SELECT COUNT(DISTINCT a.tanggal) as he FROM absensi_siswa a JOIN siswa s ON a.siswa_id = s.id WHERE s.kelas = ? AND MONTH(a.tanggal) = ? AND YEAR(a.tanggal) = ?";
$stmt_he = $conn->prepare($sql_he);
$stmt_he->bind_param("sii", $kelas_pilih, $bulan_pilih, $tahun_pilih);
$stmt_he->execute();
$hari_efektif = $stmt_he->get_result()->fetch_assoc()['he'] ?? 0;

$sql_ta = "SELECT s.nama_siswa, COUNT(a.id) as total_absen 
           FROM siswa s 
           LEFT JOIN absensi_siswa a ON s.id = a.siswa_id AND MONTH(a.tanggal) = ? AND YEAR(a.tanggal) = ? 
           WHERE s.kelas = ? 
           GROUP BY s.id 
           ORDER BY total_absen ASC LIMIT 1";
$stmt_ta = $conn->prepare($sql_ta);
$stmt_ta->bind_param("iis", $bulan_pilih, $tahun_pilih, $kelas_pilih);
$stmt_ta->execute();
$siswa_ta = $stmt_ta->get_result()->fetch_assoc();

$jumlah_ta = 0;
if ($siswa_ta) {
    $jumlah_ta = $hari_efektif - $siswa_ta['total_absen'];
    if ($jumlah_ta < 0) $jumlah_ta = 0; 
}

// 5. DATA PERHATIAN KHUSUS (> 5 KALI ALPA, IZIN, SAKIT)
// Alpa > 5
$stmt_alpa = $conn->prepare("SELECT s.nama_siswa, COUNT(a.id) as total FROM absensi_siswa a JOIN siswa s ON a.siswa_id = s.id WHERE s.kelas = ? AND MONTH(a.tanggal) = ? AND YEAR(a.tanggal) = ? AND a.status_masuk IN ('Alpa', 'Alpha') GROUP BY s.id HAVING total > 5 ORDER BY total DESC");
$stmt_alpa->bind_param("sii", $kelas_pilih, $bulan_pilih, $tahun_pilih);
$stmt_alpa->execute();
$res_alpa = $stmt_alpa->get_result();

// Izin > 5
$stmt_izin = $conn->prepare("SELECT s.nama_siswa, COUNT(a.id) as total FROM absensi_siswa a JOIN siswa s ON a.siswa_id = s.id WHERE s.kelas = ? AND MONTH(a.tanggal) = ? AND YEAR(a.tanggal) = ? AND a.status_masuk = 'Izin' GROUP BY s.id HAVING total > 5 ORDER BY total DESC");
$stmt_izin->bind_param("sii", $kelas_pilih, $bulan_pilih, $tahun_pilih);
$stmt_izin->execute();
$res_izin = $stmt_izin->get_result();

// Sakit > 5
$stmt_sakit = $conn->prepare("SELECT s.nama_siswa, COUNT(a.id) as total FROM absensi_siswa a JOIN siswa s ON a.siswa_id = s.id WHERE s.kelas = ? AND MONTH(a.tanggal) = ? AND YEAR(a.tanggal) = ? AND a.status_masuk = 'Sakit' GROUP BY s.id HAVING total > 5 ORDER BY total DESC");
$stmt_sakit->bind_param("sii", $kelas_pilih, $bulan_pilih, $tahun_pilih);
$stmt_sakit->execute();
$res_sakit = $stmt_sakit->get_result();


// 6. DATA TABEL RINCIAN
$sql_detail = "SELECT s.nama_siswa, a.tanggal, a.waktu_masuk, a.waktu_pulang, a.status_masuk 
               FROM absensi_siswa a 
               JOIN siswa s ON a.siswa_id = s.id 
               WHERE s.kelas = ? AND MONTH(a.tanggal) = ? AND YEAR(a.tanggal) = ?
               ORDER BY a.tanggal DESC, a.waktu_masuk ASC";
$stmt_detail = $conn->prepare($sql_detail);
$stmt_detail->bind_param("sii", $kelas_pilih, $bulan_pilih, $tahun_pilih);
$stmt_detail->execute();
$data_detail = $stmt_detail->get_result();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analitik Kehadiran - Classync</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f5f7fa; }
        .card-custom { border-radius: 15px; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .stat-icon { font-size: 2.5rem; opacity: 0.8; }
        .bg-gradient-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .bg-gradient-success { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white; }
        .bg-gradient-warning { background: linear-gradient(135deg, #f6d365 0%, #fda085 100%); color: white; }
        .bg-gradient-dark { background: linear-gradient(135deg, #434343 0%, #000000 100%); color: white; }
        .list-group-item { font-size: 0.9rem; font-weight: 500; border-color: #f1f2f6; }
    </style>
</head>
<body>

<div class="bg-white shadow-sm py-3 mb-4">
    <div class="container d-flex justify-content-between align-items-center">
        <h4 class="fw-bold text-dark mb-0"><i class="bi bi-graph-up-arrow text-primary me-2"></i> Analitik Kehadiran Siswa</h4>
        <a href="absen-siswa.php" class="btn btn-outline-secondary btn-sm rounded-pill"><i class="bi bi-house"></i> Beranda</a>
    </div>
</div>

<div class="container mb-5">
    
    <div class="card card-custom p-3">
        <form method="GET" action="" class="row g-3 align-items-end justify-content-center">
            <div class="col-md-3">
                <label class="form-label fw-semibold small">Kelas</label>
                <select name="kelas" class="form-select border-primary">
                    <?php foreach ($daftar_kelas as $kls): ?>
                        <option value="<?php echo htmlspecialchars($kls); ?>" <?php echo ($kelas_pilih == $kls) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($kls); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small">Bulan</label>
                <select name="bulan" class="form-select border-primary">
                    <?php foreach ($bulan_indo as $num => $nama): ?>
                        <option value="<?php echo $num; ?>" <?php echo ($bulan_pilih == $num) ? 'selected' : ''; ?>>
                            <?php echo $nama; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small">Tahun</label>
                <select name="tahun" class="form-select border-primary">
                    <?php for($i = $tahun_sekarang; $i >= $tahun_sekarang - 2; $i--): ?>
                        <option value="<?php echo $i; ?>" <?php echo ($tahun_pilih == $i) ? 'selected' : ''; ?>>
                            <?php echo $i; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-filter"></i> Terapkan</button>
            </div>
        </form>
    </div>

    <!-- 3 STATISTIK KARTU UTAMA -->
    <div class="row g-4 mb-3">
        <!-- Terawal -->
        <div class="col-md-4">
            <div class="card card-custom bg-gradient-success h-100">
                <div class="card-body d-flex align-items-start justify-content-between p-4">
                    <div class="flex-grow-1 me-2">
                        <h6 class="fw-semibold opacity-75 mb-1">Paling Awal (Min 3x Masuk)</h6>
                        <?php if($siswa_terawal): ?>
                            <h4 class="fw-bold mb-1"><?php echo htmlspecialchars($siswa_terawal['nama_siswa']); ?></h4>
                            <span class="small bg-white text-success px-2 py-1 rounded d-inline-block mt-1">Rata-rata pukul <?php echo date('H:i', strtotime($siswa_terawal['rata_waktu'])); ?> WIB</span>
                        <?php else: ?>
                            <h5>Belum ada data valid</h5>
                        <?php endif; ?>
                    </div>
                    <i class="bi bi-award stat-icon"></i>
                </div>
            </div>
        </div>
        
        <!-- Terlambat -->
        <div class="col-md-4">
            <div class="card card-custom bg-gradient-warning h-100">
                <div class="card-body d-flex align-items-start justify-content-between p-4">
                    <div class="flex-grow-1 me-2">
                        <h6 class="fw-semibold opacity-75 mb-1">Paling Sering Terlambat</h6>
                        <?php if($siswa_terlambat): ?>
                            <h4 class="fw-bold mb-1"><?php echo htmlspecialchars($siswa_terlambat['nama_siswa']); ?></h4>
                            <div class="d-flex flex-wrap align-items-center gap-1 mt-1">
                                <span class="small bg-white text-dark px-2 py-1 rounded d-inline-block mb-1"><?php echo $siswa_terlambat['total_telat']; ?>x Telat</span>
                                <span class="small bg-white text-dark px-2 py-1 rounded d-inline-block mb-1">Rata-rata Terlambat: <?php echo date('H:i', strtotime($siswa_terlambat['rata_waktu_telat'])); ?></span>
                            </div>
                        <?php else: ?>
                            <h5>Aman / Nihil</h5>
                        <?php endif; ?>
                    </div>
                    <i class="bi bi-alarm stat-icon"></i>
                </div>
            </div>
        </div>
        
        <!-- Kosong -->
        <div class="col-md-4">
            <div class="card card-custom bg-gradient-dark h-100">
                <div class="card-body d-flex align-items-start justify-content-between p-4">
                    <div class="flex-grow-1 me-2">
                        <h6 class="fw-semibold opacity-75 mb-1">Paling Sering Kosong/TA</h6>
                        <?php if($siswa_ta && $jumlah_ta > 0): ?>
                            <h4 class="fw-bold mb-1"><?php echo htmlspecialchars($siswa_ta['nama_siswa']); ?></h4>
                            <span class="small bg-white text-dark px-2 py-1 rounded fw-bold d-inline-block mt-1"><?php echo $jumlah_ta; ?> Hari Tidak Absen</span>
                        <?php else: ?>
                            <h5>Aman / Nihil</h5>
                        <?php endif; ?>
                    </div>
                    <i class="bi bi-person-x stat-icon"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- SEKSI PERHATIAN KHUSUS (> 5 KALI) -->
    <h5 class="fw-bold mb-3 mt-4 text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i> Perhatian Khusus (> 5x Absensi)</h5>
    <div class="row g-4 mb-4">
        <!-- SERING ALPA -->
        <div class="col-md-4">
            <div class="card card-custom h-100 border-top border-danger border-4">
                <div class="card-header bg-white fw-bold text-danger pt-3"><i class="bi bi-x-circle-fill"></i> Sering Alpa</div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush rounded-bottom">
                        <?php if($res_alpa->num_rows > 0): while($row = $res_alpa->fetch_assoc()): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><?php echo htmlspecialchars($row['nama_siswa']); ?></span>
                                <span class="badge bg-danger rounded-pill ms-2"><?php echo $row['total']; ?>x</span>
                            </li>
                        <?php endwhile; else: ?>
                            <li class="list-group-item text-muted text-center py-4">Nihil</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- SERING IZIN -->
        <div class="col-md-4">
            <div class="card card-custom h-100 border-top border-info border-4">
                <div class="card-header bg-white fw-bold text-info pt-3"><i class="bi bi-envelope-paper-fill"></i> Sering Izin</div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush rounded-bottom">
                        <?php if($res_izin->num_rows > 0): while($row = $res_izin->fetch_assoc()): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><?php echo htmlspecialchars($row['nama_siswa']); ?></span>
                                <span class="badge bg-info text-dark rounded-pill ms-2"><?php echo $row['total']; ?>x</span>
                            </li>
                        <?php endwhile; else: ?>
                            <li class="list-group-item text-muted text-center py-4">Nihil</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- SERING SAKIT -->
        <div class="col-md-4">
            <div class="card card-custom h-100 border-top border-primary border-4">
                <div class="card-header bg-white fw-bold text-primary pt-3"><i class="bi bi-thermometer-half"></i> Sering Sakit</div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush rounded-bottom">
                        <?php if($res_sakit->num_rows > 0): while($row = $res_sakit->fetch_assoc()): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><?php echo htmlspecialchars($row['nama_siswa']); ?></span>
                                <span class="badge bg-primary rounded-pill ms-2"><?php echo $row['total']; ?>x</span>
                            </li>
                        <?php endwhile; else: ?>
                            <li class="list-group-item text-muted text-center py-4">Nihil</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- BAGIAN DISTRIBUSI DAN RINCIAN -->
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card card-custom h-100 p-3">
                <h6 class="fw-bold text-center mb-3">Distribusi Kehadiran</h6>
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="kehadiranChart"></canvas>
                </div>
            </div>
        </div>
        
        <div class="col-lg-8">
            <div class="card card-custom h-100 p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0">Rincian Waktu Presensi (Kelas <?php echo htmlspecialchars($kelas_pilih); ?>)</h6>
                    <form action="api/ekspor_detail_absensi.php" method="POST" target="_blank">
                        <input type="hidden" name="kelas" value="<?php echo htmlspecialchars($kelas_pilih); ?>">
                        <input type="hidden" name="bulan" value="<?php echo $bulan_pilih; ?>">
                        <input type="hidden" name="tahun" value="<?php echo $tahun_pilih; ?>">
                        <button type="submit" class="btn btn-sm btn-danger rounded-pill px-3">
                            <i class="bi bi-file-pdf-fill"></i> Ekspor Rekap PDF
                        </button>
                    </form>
                </div>
                <div class="table-responsive" style="max-height: 400px;">
                    <table class="table table-hover align-middle">
                        <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                            <tr>
                                <th>Tanggal</th>
                                <th>Nama Siswa</th>
                                <th>Jam Masuk</th>
                                <th>Jam Pulang</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($data_detail->num_rows > 0): ?>
                                <?php while($row = $data_detail->fetch_assoc()): 
                                    $status = $row['status_masuk'];
                                    $badge = 'bg-secondary';
                                    if($status == 'Tepat Waktu') $badge = 'bg-success';
                                    if($status == 'Terlambat') $badge = 'bg-warning text-dark';
                                    if(in_array($status, ['Sakit', 'Izin'])) $badge = 'bg-info text-dark';
                                    if(in_array($status, ['Alpa', 'Alpha'])) $badge = 'bg-danger';
                                ?>
                                <tr>
                                    <td class="small text-muted fw-semibold"><?php echo date('d/m/Y', strtotime($row['tanggal'])); ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($row['nama_siswa']); ?></td>
                                    <td class="text-primary fw-semibold"><?php echo $row['waktu_masuk'] ? date('H:i', strtotime($row['waktu_masuk'])) : '-'; ?></td>
                                    <td class="text-secondary"><?php echo $row['waktu_pulang'] ? date('H:i', strtotime($row['waktu_pulang'])) : '-'; ?></td>
                                    <td><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">Belum ada data absensi untuk periode ini.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const ctx = document.getElementById('kehadiranChart').getContext('2d');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Tepat Waktu', 'Terlambat', 'Sakit', 'Izin', 'Alpha'],
            datasets: [{
                data: [
                    <?php echo $chart_data['Tepat Waktu']; ?>, 
                    <?php echo $chart_data['Terlambat']; ?>, 
                    <?php echo $chart_data['Sakit']; ?>, 
                    <?php echo $chart_data['Izin']; ?>, 
                    <?php echo $chart_data['Alpha']; ?>
                ],
                backgroundColor: ['#28a745', '#ffc107', '#17a2b8', '#0dcaf0', '#dc3545'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
</script>
</body>
</html>