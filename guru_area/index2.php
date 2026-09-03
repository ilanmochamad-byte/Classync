<?php 
include 'partials/header.php';

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
// LOGIKA BARU UNTUK LAPORAN HONOR
// =================================================================
$filter_bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$filter_tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');

// 1. Ambil Tunjangan Tetap
$tunjangan_data = $conn->query("SELECT * FROM tunjangan_guru WHERE guru_id = $guru_id")->fetch_assoc() ?? [];
// 2. Hitung Honor Mengajar
$total_jp = 0;
$sql_mengajar_honor = "SELECT jm.jam_mulai, jm.jam_selesai FROM absensi a JOIN jadwal_mengajar jm ON a.jadwal_id = jm.id WHERE a.guru_id = $guru_id AND a.tipe_absensi = 'mengajar' AND MONTH(a.waktu_absensi) = $filter_bulan AND YEAR(a.waktu_absensi) = $filter_tahun";
$result_mengajar_honor = $conn->query($sql_mengajar_honor);
while($absen = $result_mengajar_honor->fetch_assoc()) {
    $total_jp += hitungJP($absen['jam_mulai'], $absen['jam_selesai']);
}
$honor_mengajar = $total_jp * HONOR_PER_JP;
// 3. Hitung Honor Piket & Ekskul
$sql_lain = "SELECT tipe_absensi, COUNT(id) as jumlah FROM absensi WHERE guru_id = $guru_id AND tipe_absensi IN ('piket', 'ekskul') AND MONTH(waktu_absensi) = $filter_bulan AND YEAR(waktu_absensi) = $filter_tahun GROUP BY tipe_absensi";
$result_lain = $conn->query($sql_lain);
$honor_piket = 0;
$jumlah_piket = 0;
$honor_ekskul = 0;
$jumlah_ekskul = 0;
while($absen_lain = $result_lain->fetch_assoc()) {
    if ($absen_lain['tipe_absensi'] == 'piket') {
        $jumlah_piket = $absen_lain['jumlah'];
        $honor_piket = $jumlah_piket * HONOR_PIKET;
    }
    if ($absen_lain['tipe_absensi'] == 'ekskul') {
        $jumlah_ekskul = $absen_lain['jumlah'];
        $honor_ekskul = $jumlah_ekskul * HONOR_EKSKUL;
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
                        <form action="proses_absen.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="tipe_absensi" value="mengajar">
                            <input type="hidden" name="jadwal_id" value="<?php echo $jadwal_mengajar['id']; ?>">
                            <div class="mb-3"><label for="foto_mengajar" class="form-label">Upload Foto Bukti</label><input class="form-control" type="file" name="foto_bukti" id="foto_mengajar" accept="image/*" required></div>
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
                        <form action="proses_absen.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="tipe_absensi" value="piket">
                            <input type="hidden" name="jadwal_id" value="<?php echo $jadwal_piket['id']; ?>">
                            <div class="mb-3"><label for="foto_piket" class="form-label">Upload Foto Bukti</label><input class="form-control" type="file" name="foto_bukti" id="foto_piket" accept="image/*" required></div>
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
                        <form action="proses_absen.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="tipe_absensi" value="ekskul">
                            <input type="hidden" name="jadwal_id" value="<?php echo $jadwal_ekskul['id']; ?>">
                            <div class="mb-3"><label for="foto_ekskul" class="form-label">Upload Foto Bukti</label><input class="form-control" type="file" name="foto_bukti" id="foto_ekskul" accept="image/*" required></div>
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
                    <tr><td>Tunjangan Tetap (Masa Kerja, Jabatan, dll)</td><td class="text-end">Rp</td><td class="text-end"><?php echo number_format($total_tunjangan, 0, ',', '.'); ?></td></tr>
                    <tr><td>Tunjangan Transportasi</td><td class="text-end">Rp</td><td class="text-end"><?php echo number_format($tunjangan_transportasi, 0, ',', '.'); ?></td></tr>
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