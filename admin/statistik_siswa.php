<?php
include 'partials/header.php';

$filter_bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$filter_tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');
$bulan_indo = [1=>'Januari', 2=>'Februari', 3=>'Maret', 4=>'April', 5=>'Mei', 6=>'Juni', 7=>'Juli', 8=>'Agustus', 9=>'September', 10=>'Oktober', 11=>'November', 12=>'Desember'];
$nama_bulan = $bulan_indo[$filter_bulan];

// --- 1. DATA UNTUK GRAFIK STATUS MASUK (KOMPREHENSIF) ---
$sql_grafik = "SELECT status_masuk, COUNT(id) as jumlah FROM absensi_siswa WHERE MONTH(tanggal) = ? AND YEAR(tanggal) = ? GROUP BY status_masuk";
$stmt_grafik = $conn->prepare($sql_grafik);
$stmt_grafik->bind_param("ii", $filter_bulan, $filter_tahun);
$stmt_grafik->execute();
$result_grafik = $stmt_grafik->get_result();

// Inisialisasi semua kemungkinan status
$data_grafik = ['Tepat Waktu' => 0, 'Terlambat' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0];
while ($row = $result_grafik->fetch_assoc()) {
    $status = ($row['status_masuk'] == 'Alpha') ? 'Alpa' : $row['status_masuk']; // Normalisasi penulisan
    if (isset($data_grafik[$status])) {
        $data_grafik[$status] += $row['jumlah'];
    }
}
$grafik_labels_json = json_encode(array_keys($data_grafik));
$grafik_data_json = json_encode(array_values($data_grafik));


// --- 2. DATA UNTUK REKAP PER KELAS (DIPERBAIKI RUMUSNYA) ---
$sql_rekap = "
    SELECT s.kelas, 
           COUNT(a.id) as total_data, 
           SUM(CASE WHEN a.status_masuk IN ('Tepat Waktu', 'Terlambat') THEN 1 ELSE 0 END) as total_hadir_fisik,
           SUM(CASE WHEN a.status_masuk = 'Tepat Waktu' THEN 1 ELSE 0 END) as total_tepat,
           SUM(CASE WHEN a.status_masuk = 'Terlambat' THEN 1 ELSE 0 END) as total_terlambat,
           SUM(CASE WHEN a.status_masuk IN ('Sakit', 'Izin', 'Alpa', 'Alpha') THEN 1 ELSE 0 END) as total_tidak_hadir
    FROM absensi_siswa a
    JOIN siswa s ON a.siswa_id = s.id
    WHERE MONTH(a.tanggal) = ? AND YEAR(a.tanggal) = ?
    GROUP BY s.kelas
    ORDER BY s.kelas ASC
";
$stmt_rekap = $conn->prepare($sql_rekap);
$stmt_rekap->bind_param("ii", $filter_bulan, $filter_tahun);
$stmt_rekap->execute();
$rekap_kelas = $stmt_rekap->get_result();
?>

<style>
    /* CSS Khusus Halaman Statistik */
    .card-custom { border: none; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.04); }
    .card-header-custom { background-color: #fff; border-bottom: 1px solid #f1f2f6; padding: 1.25rem 1.5rem; border-radius: 16px 16px 0 0 !important; }
    .title-icon { display: inline-flex; align-items: center; justify-content: center; width: 35px; height: 35px; border-radius: 8px; margin-right: 10px; }
    
    /* Styling Progress Bar Modern */
    .progress-custom { height: 8px; border-radius: 10px; background-color: #f1f2f6; overflow: hidden; margin-top: 5px;}
    .progress-bar-custom { border-radius: 10px; }
    .badge-soft-success { background-color: #d1fae5; color: #065f46; }
    .badge-soft-warning { background-color: #fef3c7; color: #92400e; }
    .badge-soft-danger { background-color: #fee2e2; color: #991b1b; }
</style>

<div class="mb-4">
    <h2 class="fw-bold text-dark"><i class="bi bi-person-bounding-box text-primary"></i> Statistik Absensi Siswa</h2>
    <p class="text-muted">Analitik persentase kedisiplinan dan rekapitulasi kehadiran per kelas.</p>
</div>

<div class="card card-custom mb-4">
    <div class="card-body p-4">
        <form method="GET" action="statistik_siswa.php" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label fw-bold text-muted small">Pilih Bulan</label>
                <select name="bulan" id="bulan" class="form-select border-primary">
                    <?php foreach($bulan_indo as $num => $nama): ?>
                        <option value="<?php echo $num; ?>" <?php echo ($filter_bulan == $num) ? 'selected' : ''; ?>><?php echo $nama; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label fw-bold text-muted small">Tahun</label>
                <input type="number" class="form-control border-primary" name="tahun" value="<?php echo $filter_tahun; ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-4 mb-5">
    <div class="col-lg-5">
        <div class="card card-custom h-100">
            <div class="card-header card-header-custom d-flex align-items-center">
                <div class="title-icon bg-info bg-opacity-10 text-info"><i class="bi bi-pie-chart-fill"></i></div>
                <h5 class="mb-0 fw-bold">Komposisi Kehadiran</h5>
            </div>
            <div class="card-body p-4 d-flex align-items-center justify-content-center">
                <div style="position: relative; height: 300px; width: 100%;">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
            <div class="card-footer bg-white border-0 pb-4 pt-0 text-center text-muted small">
                Data ditarik dari absensi harian bulan <?php echo $nama_bulan . " " . $filter_tahun; ?>.
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card card-custom h-100">
            <div class="card-header card-header-custom d-flex align-items-center">
                <div class="title-icon bg-success bg-opacity-10 text-success"><i class="bi bi-list-columns-reverse"></i></div>
                <h5 class="mb-0 fw-bold">Rekapitulasi per Kelas</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted">
                            <tr>
                                <th class="ps-4">Kelas</th>
                                <th class="text-center">Hadir Fisik</th>
                                <th class="text-center">Terlambat</th>
                                <th class="pe-4" style="width: 35%;">Tingkat Kedisiplinan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($rekap_kelas->num_rows > 0): while($rekap = $rekap_kelas->fetch_assoc()): 
                                // Hitung persentase kedisiplinan (Berapa persen yang datang TEPAT WAKTU dari total yang HADIR FISIK)
                                $persentase = ($rekap['total_hadir_fisik'] > 0) ? round(($rekap['total_tepat'] / $rekap['total_hadir_fisik']) * 100, 1) : 0;
                                
                                // Tentukan warna bar berdasarkan persentase
                                $bar_color = "bg-success";
                                $badge_color = "badge-soft-success";
                                if($persentase < 80 && $persentase >= 50) {
                                    $bar_color = "bg-warning";
                                    $badge_color = "badge-soft-warning";
                                } elseif($persentase < 50) {
                                    $bar_color = "bg-danger";
                                    $badge_color = "badge-soft-danger";
                                }
                            ?>
                            <tr>
                                <td class="ps-4 fw-bold text-dark fs-6"><?php echo htmlspecialchars($rekap['kelas']); ?></td>
                                <td class="text-center fw-semibold text-primary"><?php echo $rekap['total_hadir_fisik']; ?></td>
                                <td class="text-center fw-semibold <?php echo $rekap['total_terlambat'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo $rekap['total_terlambat']; ?>
                                </td>
                                <td class="pe-4">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="small fw-semibold text-muted">Tepat Waktu</span>
                                        <span class="badge rounded-pill <?php echo $badge_color; ?>"><?php echo $persentase; ?>%</span>
                                    </div>
                                    <div class="progress progress-custom">
                                        <div class="progress-bar progress-bar-custom <?php echo $bar_color; ?>" role="progressbar" style="width: <?php echo $persentase; ?>%;"></div>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-5">
                                    <i class="bi bi-inbox fs-1 opacity-50 d-block mb-2"></i>
                                    Belum ada data rekapitulasi kelas bulan ini.
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

<?php ob_start(); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const ctx = document.getElementById('statusChart');
    new Chart(ctx, {
        type: 'doughnut', // Diubah menjadi doughnut agar terlihat lebih modern dari pie chart biasa
        data: {
            labels: <?php echo $grafik_labels_json; ?>,
            datasets: [{
                label: 'Jumlah Siswa',
                data: <?php echo $grafik_data_json; ?>,
                backgroundColor: [
                    'rgba(16, 185, 129, 0.85)',  // Tepat Waktu (Emerald)
                    'rgba(245, 158, 11, 0.85)',  // Terlambat (Amber)
                    'rgba(59, 130, 246, 0.85)',  // Sakit (Blue)
                    'rgba(139, 92, 246, 0.85)',  // Izin (Purple)
                    'rgba(239, 68, 68, 0.85)'    // Alpa (Red)
                ],
                borderWidth: 2,
                borderColor: '#ffffff',
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%', // Besaran lubang di tengah
            plugins: { 
                legend: { 
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        font: { family: "'Poppins', sans-serif", size: 12 }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,0.8)',
                    padding: 12,
                    titleFont: { size: 14, family: "'Poppins', sans-serif" },
                    bodyFont: { size: 14, family: "'Poppins', sans-serif" },
                    callbacks: {
                        label: function(context) {
                            return ' ' + context.label + ': ' + context.parsed + ' Data';
                        }
                    }
                }
            }
        }
    });
});
</script>
<?php
$custom_script = ob_get_clean();
include 'partials/footer.php'; 
?>