<?php 
include 'partials/header.php';

// Fungsi Paginasi Baru yang Lebih Cerdas
function renderPaginationGuru($currentPage, $totalPages, $paramPrefix) {
    if ($totalPages <= 1) return;

    $pageParam = $paramPrefix . '_page';
    
    // Simpan parameter filter lain yang sedang aktif
    $queryParams = $_GET;

    echo '<nav aria-label="Page navigation"><ul class="pagination justify-content-end">';

    // Tombol Sebelumnya
    $prevDisabled = ($currentPage <= 1) ? "disabled" : "";
    $queryParams[$pageParam] = $currentPage - 1;
    echo "<li class='page-item {$prevDisabled}'><a class='page-link' href='?".http_build_query($queryParams)."'>&laquo;</a></li>";

    // Tombol Angka
    for ($i = 1; $i <= $totalPages; $i++) {
        $active = ($i == $currentPage) ? "active" : "";
        $queryParams[$pageParam] = $i;
        echo "<li class='page-item {$active}'><a class='page-link' href='?".http_build_query($queryParams)."'>{$i}</a></li>";
    }

    // Tombol Selanjutnya
    $nextDisabled = ($currentPage >= $totalPages) ? "disabled" : "";
    $queryParams[$pageParam] = $currentPage + 1;
    echo "<li class='page-item {$nextDisabled}'><a class='page-link' href='?".http_build_query($queryParams)."'>&raquo;</a></li>";

    echo '</ul></nav>';
}

$guru_id = $_SESSION['guru_id'];
$hari_ini = getNamaHariIndonesia(date('l'));
$jam_sekarang = date('H:i:s');

// ... (Fungsi `sudahAbsen` tetap sama seperti sebelumnya) ...
function sudahAbsen($conn, $guru_id, $jadwal_id, $tipe_absensi) {
    $sql = "SELECT id FROM absensi WHERE guru_id = ? AND jadwal_id = ? AND tipe_absensi = ? AND DATE(waktu_absensi) = CURDATE()";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iis", $guru_id, $jadwal_id, $tipe_absensi);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

// Cek Jadwal Mengajar, Piket, dan Ekskul (Logika ini tetap sama)
// ... (Salin-tempel blok pengecekan jadwal dari kode Anda sebelumnya di sini) ...
// 1. Cek Jadwal Mengajar yang Sedang Berlangsung
$sql_mengajar = "SELECT id, mata_pelajaran, kelas FROM jadwal_mengajar WHERE guru_id = ? AND hari = ? AND ? BETWEEN jam_mulai AND jam_selesai LIMIT 1";
$stmt_mengajar = $conn->prepare($sql_mengajar);
$stmt_mengajar->bind_param("iss", $guru_id, $hari_ini, $jam_sekarang);
$stmt_mengajar->execute();
$jadwal_mengajar = $stmt_mengajar->get_result()->fetch_assoc();

// 2. Cek Jadwal Piket Hari Ini
$sql_piket = "SELECT id, sesi FROM jadwal_piket WHERE guru_id = ? AND hari = ? LIMIT 1";
$stmt_piket = $conn->prepare($sql_piket);
$stmt_piket->bind_param("is", $guru_id, $hari_ini);
$stmt_piket->execute();
$jadwal_piket = $stmt_piket->get_result()->fetch_assoc();

// 3. Cek Jadwal Ekskul yang Sedang Berlangsung
$sql_ekskul = "SELECT id, nama_ekskul FROM jadwal_ekskul WHERE guru_id = ? AND hari = ? AND ? BETWEEN jam_mulai AND jam_selesai LIMIT 1";
$stmt_ekskul = $conn->prepare($sql_ekskul);
$stmt_ekskul->bind_param("iss", $guru_id, $hari_ini, $jam_sekarang);
$stmt_ekskul->execute();
$jadwal_ekskul = $stmt_ekskul->get_result()->fetch_assoc();

// =================================================================
// LOGIKA UNTUK RIWAYAT ABSENSI DENGAN FILTER DAN PAGINASI
// =================================================================
// 1. Tentukan Limit Baris
$allowed_limits = [5, 10, 20, 50, 100];
$limit = isset($_GET['riwayat_limit']) && in_array((int)$_GET['riwayat_limit'], $allowed_limits) ? (int)$_GET['riwayat_limit'] : 5;

// 2. Tentukan Halaman Saat ini
$page = isset($GET['riwayat_page']) ? (int)$_GET['riwayat_page'] : 1;
$offset = ($page - 1) * $limit;

// Ambil nilai filter dari URL, jika tidak ada, gunakan bulan & tahun saat ini
$filter_riwayat_bulan = isset($_GET['riwayat_bulan']) ? (int)$_GET['riwayat_bulan'] : (int)date('m');
$filter_riwayat_tahun = isset($_GET['riwayat_tahun']) ? (int)$_GET['riwayat_tahun'] : (int)date('Y');

// 4. Query untuk Menghitung Total Data (untuk paginasi)
$sql_total_riwayat = "SELECT COUNT(a.id) as total FROM absensi a WHERE a.guru_id = ? AND MONTH(a.waktu_absensi) = ? AND YEAR(a.waktu_absensi) = ?";
$stmt_total_riwayat = $conn->prepare($sql_total_riwayat);
$stmt_total_riwayat->bind_param("iii", $guru_id, $filter_riwayat_bulan, $filter_riwayat_tahun);
$stmt_total_riwayat->execute();
$total_rows_riwayat = $stmt_total_riwayat->get_result()->fetch_assoc()['total'];
$total_pages_riwayat = ceil($total_rows_riwayat / $limit);

// 5. Query untuk Mengambil Data Sesuai Halaman
$sql_riwayat = "
    SELECT 
        a.waktu_absensi, a.tipe_absensi, a.status, -- Ambil status
        COALESCE(
            CONCAT(jm.mata_pelajaran, ' - Kelas ', jm.kelas), 
            CONCAT('Piket Sesi ', jp.sesi), 
            je.nama_ekskul) as keterangan_jadwal
    FROM absensi a
    LEFT JOIN jadwal_mengajar jm ON a.jadwal_id = jm.id AND a.tipe_absensi = 'mengajar'
    LEFT JOIN jadwal_piket jp ON a.jadwal_id = jp.id AND a.tipe_absensi = 'piket'
    LEFT JOIN jadwal_ekskul je ON a.jadwal_id = je.id AND a.tipe_absensi = 'ekskul'
    WHERE a.guru_id = ? 
      AND MONTH(a.waktu_absensi) = ? 
      AND YEAR(a.waktu_absensi) = ?
    ORDER BY a.waktu_absensi DESC
    LIMIT ?, ?
";
$stmt_riwayat = $conn->prepare($sql_riwayat);
// Gunakan variabel filter di sini
$stmt_riwayat->bind_param("iiiii", $guru_id, $filter_riwayat_bulan, $filter_riwayat_tahun, $offset, $limit);
$stmt_riwayat->execute();
$result_riwayat = $stmt_riwayat->get_result();
// --- AKHIR LOGIKA RIWAYAT ---

// =================================================================
// LOGIKA BARU UNTUK LAPORAN HONOR DENGAN PERBAIKAN (23-09-2025)
// =================================================================
$filter_bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$filter_tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');

// 1. Ambil Tunjangan Tetap
$tunjangan_data = $conn->query("SELECT * FROM tunjangan_guru WHERE guru_id = $guru_id")->fetch_assoc() ?? [];

// 2. Hitung Honor Mengajar
    $sql_mengajar = "SELECT a.status, jm.jam_mulai, jm.jam_selesai FROM absensi a JOIN jadwal_mengajar jm ON a.jadwal_id = jm.id WHERE a.guru_id = $guru_id AND a.tipe_absensi = 'mengajar' AND MONTH(a.waktu_absensi) = $filter_bulan AND YEAR(a.waktu_absensi) = $filter_tahun";
    $result_mengajar = $conn->query($sql_mengajar);
    while($absen = $result_mengajar->fetch_assoc()) {
        $jp = hitungJP($absen['jam_mulai'], $absen['jam_selesai']);
        $honor_basis = $jp * HONOR_PER_JP;
        
        // --- PERBAIKAN DI SINI ---
        // Hitung JP hanya jika statusnya bukan Alpa
        switch ($absen['status']) {
            case 'Hadir':
            case 'Sakit':
                $honor_mengajar += $honor_basis;
                $total_jp += $jp; // Tambahkan JP di sini
                break;
            case 'Izin':
                $honor_mengajar += ($honor_basis * 0.75);
                $total_jp += $jp; // Tambahkan JP di sini
                break;
            // Jika Alpa, tidak ada yang ditambahkan
        }
    }

// 3. Hitung Honor Piket & Ekskul
    $sql_lain = "SELECT status, tipe_absensi FROM absensi WHERE guru_id = $guru_id AND tipe_absensi IN ('piket', 'ekskul') AND MONTH(waktu_absensi) = $filter_bulan AND YEAR(waktu_absensi) = $filter_tahun";
    $result_lain = $conn->query($sql_lain);
    while($absen_lain = $result_lain->fetch_assoc()) {
        $honor_diterima = 0;
        $tipe = trim($absen_lain['tipe_absensi']);
        $status = trim($absen_lain['status']);
        
        $honor_basis = ($tipe == 'piket') ? HONOR_PIKET : HONOR_EKSKUL;
        
        switch ($status) {
            case 'Hadir': case 'Sakit': $honor_diterima = $honor_basis; break;
            case 'Izin': $honor_diterima = $honor_basis * 0.75; break;
        }

        // --- PERBAIKAN DI SINI ---
        // Hitung jumlah kehadiran hanya jika honor diterima (bukan Alpa)
        if ($honor_diterima > 0) {
            if ($tipe == 'piket') {
                $jumlah_piket++;
                $honor_piket += $honor_diterima;
            } elseif ($tipe == 'ekskul') {
                $jumlah_ekskul++;
                $honor_ekskul += $honor_diterima;
            }
        }
    }

// 4. Cek penalti transportasi
$tunjangan_transportasi = $tunjangan_data['transportasi'] ?? 0;
if (cekAbsenBerturutTurut($conn, $guru_id, $filter_bulan, $filter_tahun)) {
    $tunjangan_transportasi = 0; // Penalti!
}
// 5. Hitung Total Tunjangan
$total_tunjangan = ($tunjangan_data['masa_kerja'] ?? 0) + ($tunjangan_data['jabatan'] ?? 0) + $tunjangan_transportasi + ($tunjangan_data['suami_istri'] ?? 0) + ($tunjangan_data['anak'] ?? 0) + ($tunjangan_data['wali_kelas'] ?? 0);
// 6. Ambil Potongan
$potongan_data = $conn->query("SELECT * FROM potongan_guru WHERE guru_id = $guru_id AND bulan = $filter_bulan AND tahun = $filter_tahun")->fetch_assoc() ?? [];
$potongan_arisan = $potongan_data['arisan'] ?? 0;
$potongan_tabungan = $potongan_data['tabungan'] ?? 0;
// 7. Kalkulasi Final
$subtotal_pendapatan = $total_tunjangan + $honor_mengajar + $honor_piket + $honor_ekskul;
$total_potongan = $potongan_arisan + $potongan_tabungan;
$total_diterima = $subtotal_pendapatan - $total_potongan;
?>

<h1 class="mb-4">Dashboard Guru</h1>

<?php if(isset($_GET['status'])): ?>
    <div class="alert alert-<?php echo $_GET['status'] == 'sukses' ? 'success' : 'danger'; ?>">
        <?php echo htmlspecialchars(urldecode($_GET['pesan'])); ?>
    </div>
<?php endif; ?>

<h3 class="mb-3">Absensi Hari Ini</h3>
<div class="row g-4">
    <div class="col-md-4">
        <div class="card h-100 shadow-sm">
            <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="bi bi-book-half"></i> Absensi Mengajar</h5></div>
            <div class="card-body">
                <?php if ($jadwal_mengajar): ?>
                    <p><strong>Mapel:</strong> <?php echo htmlspecialchars($jadwal_mengajar['mata_pelajaran']); ?> (Kelas <?php echo htmlspecialchars($jadwal_mengajar['kelas']); ?>)</p>
                    <?php if (sudahAbsen($conn, $guru_id, $jadwal_mengajar['id'], 'mengajar')): ?>
                        <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> Anda sudah absen mengajar hari ini.</div>
                    <?php else: ?>
                        <form action="proses_absen.php" method="POST" enctype="multipart/form-data" class="absen-form">
                            <input type="hidden" name="tipe_absensi" value="mengajar">
                            <input type="hidden" name="jadwal_id" value="<?php echo $jadwal_mengajar['id']; ?>">
                            <div class="mb-3"><label for="foto_mengajar" class="form-label">Upload Foto Bukti</label><input class="form-control" type="file" name="foto_bukti" id="foto_mengajar" accept="image/*" capture="user" required></div>
                            <button type="submit" class="btn btn-primary w-100">Hadir</button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted text-center mt-3">Tidak ada jadwal mengajar untuk Anda saat ini.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card h-100 shadow-sm">
            <div class="card-header bg-success text-white"><h5 class="mb-0"><i class="bi bi-person-check-fill"></i> Absensi Piket</h5></div>
            <div class="card-body">
                <?php if ($jadwal_piket): ?>
                    <p><strong>Sesi:</strong> <?php echo htmlspecialchars($jadwal_piket['sesi']); ?></p>
                    <?php if (sudahAbsen($conn, $guru_id, $jadwal_piket['id'], 'piket')): ?>
                        <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> Anda sudah absen piket hari ini.</div>
                    <?php else: ?>
                        <form action="proses_absen.php" method="POST" enctype="multipart/form-data" class="absen-form">
                            <input type="hidden" name="tipe_absensi" value="piket">
                            <input type="hidden" name="jadwal_id" value="<?php echo $jadwal_piket['id']; ?>">
                            <div class="mb-3"><label for="foto_piket" class="form-label">Upload Foto Bukti</label><input class="form-control" type="file" name="foto_bukti" id="foto_piket" accept="image/*" capture="user" required></div>
                            <button type="submit" class="btn btn-success w-100">Hadir</button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted text-center mt-3">Tidak ada jadwal piket untuk Anda hari ini.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card h-100 shadow-sm">
            <div class="card-header bg-warning text-dark"><h5 class="mb-0"><i class="bi bi-bicycle"></i> Absensi Ekstrakurikuler</h5></div>
            <div class="card-body">
                <?php if ($jadwal_ekskul): ?>
                    <p><strong>Ekskul:</strong> <?php echo htmlspecialchars($jadwal_ekskul['nama_ekskul']); ?></p>
                    <?php if (sudahAbsen($conn, $guru_id, $jadwal_ekskul['id'], 'ekskul')): ?>
                        <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> Anda sudah absen ekskul hari ini.</div>
                    <?php else: ?>
                        <form action="proses_absen.php" method="POST" enctype="multipart/form-data" class="absen-form">
                            <input type="hidden" name="tipe_absensi" value="ekskul">
                            <input type="hidden" name="jadwal_id" value="<?php echo $jadwal_ekskul['id']; ?>">
                            <div class="mb-3"><label for="foto_ekskul" class="form-label">Upload Foto Bukti</label><input class="form-control" type="file" name="foto_bukti" id="foto_ekskul" accept="image/*" capture="user" required></div>
                            <button type="submit" class="btn btn-warning w-100">Hadir</button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted text-center mt-3">Tidak ada jadwal ekskul untuk Anda saat ini.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<hr class="my-5">

<h3 class="mb-3">Riwayat Kehadiran Bulanan</h3>
<div class="card shadow-sm">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end bg-light p-3 rounded mb-4">
            <div class="col-md-5">
                <label for="riwayat_bulan" class="form-label">Pilih Periode Riwayat</label>
                <select name="riwayat_bulan" id="riwayat_bulan" class="form-select">
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php if ($filter_riwayat_bulan == $i) echo 'selected'; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $i, 10)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-5">
                 <input type="number" class="form-control" name="riwayat_tahun" value="<?php echo $filter_riwayat_tahun; ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-info w-100">Lihat Riwayat</button>
            </div>
        </form>
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <form method="GET" class="d-flex align-items-center">
                <input type="hidden" name="riwayat_bulan" value="<?php echo $filter_riwayat_bulan; ?>">
                <input type="hidden" name="riwayat_tahun" value="<?php echo $filter_riwayat_tahun; ?>">
                <label for="riwayat_limit" class="form-label me-2 mb-0">Tampil:</label>
                <select name="riwayat_limit" id="riwayat_limit" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                    <option value="5" <?php if ($limit == 5) echo 'selected'; ?>>5</option>
                    <option value="10" <?php if ($limit == 10) echo 'selected'; ?>>10</option>
                    <option value="20" <?php if ($limit == 20) echo 'selected'; ?>>20</option>
                    <option value="50" <?php if ($limit == 50) echo 'selected'; ?>>50</option>
                    <option value="100" <?php if ($limit == 100) echo 'selected'; ?>>100</option>
                </select>
                <span class="ms-2">baris</span>
            </form>
            <div class="text-muted">
                Menampilkan <?php echo $result_riwayat->num_rows; ?> dari <?php echo $total_rows_riwayat; ?> data
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Waktu</th>
                        <th>Tipe Absen</th>
                        <th>Jadwal</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result_riwayat->num_rows > 0): ?>
                        <?php $nomor = $offset + 1; ?>
                        <?php while ($row = $result_riwayat->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $nomor++; ?></td>
                                <td><?php echo date('d M Y, H:i', strtotime($row['waktu_absensi'])); ?></td>
                                <td><span class="badge bg-info text-dark"><?php echo ucfirst($row['tipe_absensi']); ?></span></td>
                                <td><?php echo htmlspecialchars($row['keterangan_jadwal']); ?></td>
                                <td>
                                    <?php
                                    $status = htmlspecialchars($row['status']);
                                    $badge_class = 'bg-secondary'; // Default
                                    switch ($status) {
                                        case 'Hadir': $badge_class = 'bg-success'; break;
                                        case 'Sakit': $badge_class = 'bg-info text-dark'; break;
                                        case 'Izin': $badge_class = 'bg-warning text-dark'; break;
                                        case 'Alpa': $badge_class = 'bg-danger'; break;
                                    }
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>"><?php echo $status; ?></span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center">Belum ada riwayat absensi untuk periode yang dipilih.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<hr class="my-5">

<h3 class="mb-3">Laporan Honor Bulanan Anda</h3>
<div class="card shadow-sm">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end bg-light p-3 rounded mb-4">
            <div class="col-md-5">
                <label for="bulan" class="form-label">Pilih Periode</label>
                <select name="bulan" id="bulan" class="form-select">
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $filter_bulan == $i ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $i, 10)); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-5"><input type="number" class="form-control" name="tahun" value="<?php echo $filter_tahun; ?>"></div>
            <div class="col-md-2"><button type="submit" class="btn btn-success w-100">Lihat</button></div>
        </form>

        <div class="table-responsive">
            <table class="table table-bordered">
                <thead class="table-light"><tr><th colspan="3">Laporan Periode: <?php echo date('F Y', mktime(0,0,0, $filter_bulan, 1, $filter_tahun)); ?></th></tr></thead>
                <tbody>
                    <tr class="table-primary"><td colspan="3"><strong><i class="bi bi-plus-circle-fill"></i> PENDAPATAN</strong></td></tr>
                    <tr><td>Tunjangan Tetap (Masa Kerja, Jabatan, Trans, dll)</td><td class="text-end">Rp</td><td class="text-end"><?php echo number_format($total_tunjangan, 0, ',', '.'); ?></td></tr>
                    <tr><td>Honor Mengajar (<?php echo $total_jp; ?> JP)</td><td class="text-end">Rp</td><td class="text-end"><?php echo number_format($honor_mengajar, 0, ',', '.'); ?></td></tr>
                    <tr><td>Honor Piket (<?php echo $jumlah_piket; ?>x)</td><td class="text-end">Rp</td><td class="text-end"><?php echo number_format($honor_piket, 0, ',', '.'); ?></td></tr>
                    <tr><td>Honor Pembina Ekskul (<?php echo $jumlah_ekskul; ?>x)</td><td class="text-end">Rp</td><td class="text-end"><?php echo number_format($honor_ekskul, 0, ',', '.'); ?></td></tr>
                    <tr class="fw-bold"><td>Subtotal Pendapatan</td><td class="text-end">Rp</td><td class="text-end"><?php echo number_format($subtotal_pendapatan, 0, ',', '.'); ?></td></tr>
                    
                    <tr class="table-warning"><td colspan="3"><strong><i class="bi bi-dash-circle-fill"></i> POTONGAN</strong></td></tr>
                    <tr><td>Arisan</td><td class="text-end">Rp</td><td class="text-end"><?php echo number_format($potongan_arisan, 0, ',', '.'); ?></td></tr>
                    <tr><td>Tabungan</td><td class="text-end">Rp</td><td class="text-end"><?php echo number_format($potongan_tabungan, 0, ',', '.'); ?></td></tr>
                    <tr class="fw-bold"><td>Total Potongan</td><td class="text-end">Rp</td><td class="text-end"><?php echo number_format($total_potongan, 0, ',', '.'); ?></td></tr>

                    <tr class="table-success fw-bold fs-5"><td><i class="bi bi-wallet-fill"></i> TOTAL DITERIMA</td><td class="text-end">Rp</td><td class="text-end"><?php echo number_format($total_diterima, 0, ',', '.'); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'partials/footer.php'; ?>