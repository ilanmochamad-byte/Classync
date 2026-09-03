<?php 
include 'partials/header.php';

// --- Pengaturan Filter ---
$filter_bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$filter_tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');
$bulan_indo = [1=>'Januari', 2=>'Februari', 3=>'Maret', 4=>'April', 5=>'Mei', 6=>'Juni', 7=>'Juli', 8=>'Agustus', 9=>'September', 10=>'Oktober', 11=>'November', 12=>'Desember'];
$nama_bulan = $bulan_indo[$filter_bulan];

// --- Rentang tanggal (index-friendly) ---
$start_date = sprintf('%04d-%02d-01 00:00:00', $filter_tahun, $filter_bulan);
$end_date   = date('Y-m-d H:i:s', strtotime($start_date . ' +1 month'));

// --- 1. DATA UNTUK GRAFIK KEHADIRAN BULANAN ---
$sql_grafik = "
    SELECT status, COUNT(id) AS jumlah
    FROM absensi
    WHERE waktu_absensi >= ? AND waktu_absensi < ?
    GROUP BY status
";
$stmt_grafik = $conn->prepare($sql_grafik);
$stmt_grafik->bind_param("ss", $start_date, $end_date);
$stmt_grafik->execute();
$result_grafik = $stmt_grafik->get_result();

$data_grafik = ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0];
$total_absensi = 0;
while ($row = $result_grafik->fetch_assoc()) {
    // Normalisasi status Alpa/Alpha
    $status = ($row['status'] == 'Alpha') ? 'Alpa' : $row['status'];
    if (array_key_exists($status, $data_grafik)) {
        $data_grafik[$status] += (int)$row['jumlah'];
    }
    $total_absensi += (int)$row['jumlah'];
}

// Konversi ke persentase
$persentase_grafik = [];
foreach ($data_grafik as $status => $jumlah) {
    $persentase_grafik[$status] = ($total_absensi > 0) ? round(($jumlah / $total_absensi) * 100, 1) : 0;
}
$grafik_labels_json = json_encode(array_keys($persentase_grafik));
$grafik_data_json = json_encode(array_values($persentase_grafik));


// --- 2. DATA UNTUK PAPAN PERINGKAT GURU ---
$sql_leaderboard = "
    SELECT g.nama_guru, COUNT(a.id) AS jumlah_hadir
    FROM absensi a
    JOIN guru g ON a.guru_id = g.id
    WHERE a.status = 'Hadir'
      AND a.waktu_absensi >= ? AND a.waktu_absensi < ?
    GROUP BY a.guru_id, g.nama_guru
    ORDER BY jumlah_hadir DESC
    LIMIT 5
";
$stmt_leaderboard = $conn->prepare($sql_leaderboard);
$stmt_leaderboard->bind_param("ss", $start_date, $end_date);
$stmt_leaderboard->execute();
$result_leaderboard = $stmt_leaderboard->get_result();


// --- 3. DATA UNTUK PETA PANAS (HEATMAP) ---
$sql_heatmap = "
    SELECT
        CAST(waktu_absensi AS DATE) AS tanggal,
        COUNT(id) AS jumlah_absen
    FROM absensi
    WHERE status IN ('Sakit', 'Izin', 'Alpa', 'Alpha')
      AND waktu_absensi >= ? AND waktu_absensi < ?
    GROUP BY CAST(waktu_absensi AS DATE)
";
$stmt_heatmap = $conn->prepare($sql_heatmap);
$stmt_heatmap->bind_param("ss", $start_date, $end_date);
$stmt_heatmap->execute();
$result_heatmap = $stmt_heatmap->get_result();

$data_heatmap = [];
while ($row = $result_heatmap->fetch_assoc()) {
    $data_heatmap[$row['tanggal']] = (int)$row['jumlah_absen'];
}
?>

<style>
    /* CSS Khusus Halaman Statistik */
    .card-custom { border: none; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.04); }
    .card-header-custom { background-color: #fff; border-bottom: 1px solid #f1f2f6; padding: 1.25rem 1.5rem; border-radius: 16px 16px 0 0 !important; }
    .title-icon { display: inline-flex; align-items: center; justify-content: center; width: 35px; height: 35px; border-radius: 8px; margin-right: 10px; }
    
    /* Styling Papan Peringkat */
    .rank-item { transition: all 0.2s ease; border-left: 4px solid transparent; }
    .rank-item:hover { background-color: #f8f9fa; transform: translateX(5px); }
    .avatar-circle { width: 40px; height: 40px; background: linear-gradient(135deg, #e0eafc 0%, #cfdef3 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #4A90A4; }
    .medal-1 { color: #FFD700; text-shadow: 0 2px 4px rgba(255,215,0,0.4); }
    .medal-2 { color: #C0C0C0; text-shadow: 0 2px 4px rgba(192,192,192,0.4); }
    .medal-3 { color: #CD7F32; text-shadow: 0 2px 4px rgba(205,127,50,0.4); }

    /* Styling Peta Panas (Heatmap) Kalender */
    .heatmap-calendar { width: 100%; border-collapse: separate; border-spacing: 6px; }
    .heatmap-calendar th { text-align: center; color: #6c757d; font-weight: 600; padding-bottom: 10px; font-size: 0.9rem; text-transform: uppercase; }
    .heatmap-calendar td { height: 75px; width: 14.28%; border-radius: 10px; text-align: center; vertical-align: middle; position: relative; transition: transform 0.2s; cursor: default; }
    .heatmap-calendar td:hover { transform: scale(1.05); z-index: 1; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    
    .not-month { background-color: transparent !important; border: 2px dashed #f1f2f6; opacity: 0.5; }
    
    /* Skala Warna Heatmap (Merah = Tingkat Ketidakhadiran) */
    .heatmap-color-0 { background-color: #f8f9fa; border: 1px solid #e9ecef; } /* 0 Absen */
    .heatmap-color-1 { background-color: #ffeeba; color: #856404; border: 1px solid #ffdf7e; } /* 1-2 Absen */
    .heatmap-color-2 { background-color: #f5c6cb; color: #721c24; border: 1px solid #f1b0b7; } /* 3-4 Absen */
    .heatmap-color-3 { background-color: #dc3545; color: #fff; border: 1px solid #c82333; box-shadow: 0 2px 5px rgba(220,53,69,0.3); } /* 5+ Absen */
    
    .day-number { position: absolute; top: 5px; left: 8px; font-size: 0.75rem; font-weight: 700; opacity: 0.6; }
    .heatmap-color-3 .day-number { opacity: 0.9; }
    .absen-count { font-size: 0.85rem; font-weight: 600; margin-top: 12px; }
    
    .heatmap-legend { display: flex; justify-content: center; gap: 20px; margin-top: 20px; padding-top: 15px; border-top: 1px dashed #e9ecef; }
    .legend-item { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #495057; font-weight: 500;}
    .legend-color-box { width: 18px; height: 18px; border-radius: 4px; }
</style>

<div class="mb-4">
    <h2 class="fw-bold text-dark"><i class="bi bi-pie-chart-fill text-primary"></i> Dasbor Statistik Absensi</h2>
    <p class="text-muted">Analitik kehadiran dan pemantauan kinerja harian guru.</p>
</div>

<div class="card card-custom mb-4">
    <div class="card-body p-4">
        <form method="GET" action="statistik.php" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label fw-bold text-muted small">Pilih Bulan</label>
                <select name="bulan" class="form-select border-primary">
                    <?php foreach($bulan_indo as $num => $nama): ?>
                        <option value="<?php echo $num; ?>" <?php echo ($filter_bulan == $num) ? 'selected' : ''; ?>><?php echo $nama; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label fw-bold text-muted small">Tahun</label>
                <select name="tahun" class="form-select border-primary">
                    <?php for($i = date('Y'); $i >= date('Y') - 2; $i--): ?>
                        <option value="<?php echo $i; ?>" <?php echo ($filter_tahun == $i) ? 'selected' : ''; ?>><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card card-custom h-100">
            <div class="card-header card-header-custom d-flex align-items-center">
                <div class="title-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-bar-chart-fill"></i></div>
                <h5 class="mb-0 fw-bold">Persentase Kehadiran</h5>
                <span class="ms-auto badge bg-light text-dark border"><?php echo $nama_bulan . " " . $filter_tahun; ?></span>
            </div>
            <div class="card-body p-4">
                <div style="position: relative; height: 300px; width: 100%;">
                    <canvas id="kehadiranChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card card-custom h-100">
            <div class="card-header card-header-custom d-flex align-items-center">
                <div class="title-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-trophy-fill"></i></div>
                <h5 class="mb-0 fw-bold">Peringkat Kehadiran</h5>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush rounded-bottom-4">
                    <?php 
                    $rank = 1; 
                    if ($result_leaderboard->num_rows > 0):
                        while($guru = $result_leaderboard->fetch_assoc()): 
                            // Tentukan Ikon Medali
                            $medal_icon = "";
                            if ($rank == 1) $medal_icon = '<i class="bi bi-award-fill medal-1 fs-4"></i>';
                            elseif ($rank == 2) $medal_icon = '<i class="bi bi-award-fill medal-2 fs-4"></i>';
                            elseif ($rank == 3) $medal_icon = '<i class="bi bi-award-fill medal-3 fs-4"></i>';
                            else $medal_icon = '<span class="text-muted fw-bold ms-2">#'.$rank.'</span>';
                            
                            // Inisial Nama (2 Huruf)
                            $words = explode(" ", $guru['nama_guru']);
                            $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                    ?>
                        <div class="list-group-item rank-item d-flex justify-content-between align-items-center p-3 <?php echo ($rank <= 3) ? 'border-start border-warning border-4' : ''; ?>">
                            <div class="d-flex align-items-center">
                                <div class="avatar-circle shadow-sm"><?php echo $initials; ?></div>
                                <div>
                                    <h6 class="mb-0 fw-bold text-dark"><?php echo htmlspecialchars($guru['nama_guru']); ?></h6>
                                    <small class="text-success fw-semibold"><i class="bi bi-check-circle"></i> <?php echo $guru['jumlah_hadir']; ?> Hadir</small>
                                </div>
                            </div>
                            <div><?php echo $medal_icon; ?></div>
                        </div>
                    <?php $rank++; endwhile; else: ?>
                        <div class="p-5 text-center text-muted">
                            <i class="bi bi-inbox fs-1 opacity-50 d-block mb-2"></i>
                            Belum ada data kehadiran bulan ini.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card card-custom mb-5">
    <div class="card-header card-header-custom d-flex align-items-center">
        <div class="title-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-calendar-x-fill"></i></div>
        <h5 class="mb-0 fw-bold">Peta Panas Ketidakhadiran (Sakit/Izin/Alpa)</h5>
    </div>
    <div class="card-body p-4">
        <?php
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $filter_bulan, $filter_tahun);
        $firstDayOfMonth = date('N', strtotime("$filter_tahun-$filter_bulan-01"));
        ?>
        <div class="table-responsive overflow-hidden">
            <table class="heatmap-calendar">
                <thead><tr><th>Senin</th><th>Selasa</th><th>Rabu</th><th>Kamis</th><th>Jumat</th><th>Sabtu</th><th>Minggu</th></tr></thead>
                <tbody>
                    <tr>
                        <?php
                        // Sel kosong sebelum tanggal 1
                        for ($i = 1; $i < $firstDayOfMonth; $i++) { echo "<td class='not-month'></td>"; }
                        
                        // Sel untuk setiap tanggal
                        for ($day = 1; $day <= $daysInMonth; $day++) {
                            $currentDate = "$filter_tahun-" . str_pad($filter_bulan, 2, '0', STR_PAD_LEFT) . "-" . str_pad($day, 2, '0', STR_PAD_LEFT);
                            $dayOfWeek = date('N', strtotime($currentDate));
                            
                            $absen_count = $data_heatmap[$currentDate] ?? 0;
                            
                            $heatmap_class = 'heatmap-color-0'; 
                            if ($absen_count >= 1 && $absen_count <= 2) $heatmap_class = 'heatmap-color-1';
                            elseif ($absen_count >= 3 && $absen_count <= 4) $heatmap_class = 'heatmap-color-2';
                            elseif ($absen_count >= 5) $heatmap_class = 'heatmap-color-3';
                            
                            // Tambahkan indikator Hari Ini
                            $is_today = ($currentDate == date('Y-m-d')) ? 'border-primary border-2 shadow' : '';

                            echo "<td class='$heatmap_class $is_today' title='$absen_count Guru Tidak Hadir pada " . date('d M', strtotime($currentDate)) . "'>";
                            echo "<div class='day-number'>$day</div>";
                            if($absen_count > 0) {
                                echo "<div class='absen-count'><i class='bi bi-person-x'></i> $absen_count</div>";
                            }
                            echo "</td>";
                            
                            if ($dayOfWeek == 7 && $day < $daysInMonth) {
                                echo "</tr><tr>";
                            }
                        }
                        
                        // Sel kosong setelah tanggal terakhir
                        $lastDayOfMonth = date('N', strtotime("$filter_tahun-$filter_bulan-$daysInMonth"));
                        for ($i = $lastDayOfMonth; $i < 7; $i++) { echo "<td class='not-month'></td>"; }
                        ?>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="heatmap-legend">
            <div class="legend-item"><div class="legend-color-box heatmap-color-0"></div><span>0 Tidak Hadir</span></div>
            <div class="legend-item"><div class="legend-color-box heatmap-color-1"></div><span>1-2 Tidak Hadir</span></div>
            <div class="legend-item"><div class="legend-color-box heatmap-color-2"></div><span>3-4 Tidak Hadir</span></div>
            <div class="legend-item"><div class="legend-color-box heatmap-color-3"></div><span>5+ Tidak Hadir</span></div>
        </div>

    </div>
</div>

<div class="mb-5 pb-5"></div>

<?php ob_start(); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const ctx = document.getElementById('kehadiranChart');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo $grafik_labels_json; ?>,
            datasets: [{
                label: 'Persentase (%)',
                data: <?php echo $grafik_data_json; ?>,
                backgroundColor: [
                    'rgba(16, 185, 129, 0.85)', // Hadir (Success/Emerald)
                    'rgba(59, 130, 246, 0.85)', // Sakit (Info/Blue)
                    'rgba(245, 158, 11, 0.85)', // Izin (Warning/Amber)
                    'rgba(239, 68, 68, 0.85)'   // Alpa (Danger/Red)
                ],
                borderRadius: 8, // Bikin ujung bar melengkung (Modern)
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,0.8)',
                    padding: 12,
                    titleFont: { size: 14, family: "'Poppins', sans-serif" },
                    bodyFont: { size: 14, family: "'Poppins', sans-serif" },
                    callbacks: {
                        label: function(context) {
                            return ' ' + context.parsed.y + '% dari total absen';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#f1f2f6', drawBorder: false },
                    ticks: {
                        font: { family: "'Poppins', sans-serif" },
                        callback: function(value) { return value + '%' }
                    }
                },
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: { font: { family: "'Poppins', sans-serif", weight: '600' } }
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