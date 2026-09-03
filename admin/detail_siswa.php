<?php
// --- AWAL KODE DEBUG ---
// Baris ini akan memaksa PHP untuk menampilkan SEMUA error ke layar
ini_set('display_errors', 1);
error_reporting(E_ALL);
// --- AKHIR KODE DEBUG ---

include 'partials/header.php';

// Validasi ID Siswa dari URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo '<div class="alert alert-danger">ID Siswa tidak valid.</div>';
    include 'partials/footer.php';
    exit;
}
$siswa_id = (int)$_GET['id'];

// Ambil data identitas siswa
$stmt_siswa = $conn->prepare("SELECT * FROM siswa WHERE id = ?");
$stmt_siswa->bind_param("i", $siswa_id);
$stmt_siswa->execute();
$siswa = $stmt_siswa->get_result()->fetch_assoc();

if (!$siswa) {
    echo '<div class="alert alert-danger">Data siswa tidak ditemukan.</div>';
    include 'partials/footer.php';
    exit;
}

// Logika Filter untuk Laporan Kehadiran
$filter_tipe = $_GET['tipe'] ?? 'harian'; // Default ke harian
$filter_bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$filter_tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');

// (Untuk pengembangan selanjutnya) Query dinamis bisa diletakkan di sini
$sql_laporan = "SELECT * FROM absensi_siswa WHERE siswa_id = ? AND MONTH(tanggal) = ? AND YEAR(tanggal) = ? ORDER BY tanggal DESC";
$params = [$siswa_id, $filter_bulan, $filter_tahun];
$types = 'iii';

$stmt_laporan = $conn->prepare($sql_laporan);
$stmt_laporan->bind_param($types, ...$params);
$stmt_laporan->execute();
$laporan_absensi = $stmt_laporan->get_result();
?>

<div class="container mt-5">
    <a href="siswa.php" class="btn btn-secondary mb-3"><i class="bi bi-arrow-left"></i> Kembali ke Daftar Siswa</a>
    <h1 class="mb-4">Detail Kehadiran Siswa</h1>

    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-2 text-center">
                    <img src="../<?php echo !empty($siswa['foto_siswa']) ? htmlspecialchars($siswa['foto_siswa']) : 'uploads/default.png'; ?>" alt="Foto Siswa" class="img-fluid rounded" style="width: 120px; height: 120px; object-fit: cover;">
                </div>
                <div class="col-md-10">
                    <h3><?php echo htmlspecialchars($siswa['nama_siswa']); ?></h3>
                    <table class="table table-sm table-borderless" style="max-width: 500px;">
                        <tbody>
                            <tr>
                                <th style="width: 150px;">NISN</th>
                                <td>: <?php echo htmlspecialchars($siswa['nisn']); ?></td>
                            </tr>
                            <tr>
                                <th>Kelas</th>
                                <td>: <?php echo htmlspecialchars($siswa['kelas']); ?></td>
                            </tr>
                            <tr>
                                <th>Jenis Kelamin</th>
                                <td>: <?php echo htmlspecialchars($siswa['jenis_kelamin']); ?></td>
                            </tr>
                            <tr>
                                <th>Kontak Orang Tua</th>
                                <td>: <?php echo htmlspecialchars($siswa['kontak_ortu']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header">
            <h5><i class="bi bi-calendar-check"></i> Laporan Kehadiran</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end bg-light p-3 rounded mb-4">
                <input type="hidden" name="id" value="<?php echo $siswa_id; ?>">
                <div class="col-md-4">
                    <label class="form-label">Tipe Absensi</label>
                    <select name="tipe" class="form-select" disabled>
                        <option value="harian" selected>Harian (Masuk/Pulang)</option>
                        <option value="pelajaran">Pelajaran (Segera Hadir)</option>
                        <option value="ekskul">Ekstrakurikuler (Segera Hadir)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Bulan</label>
                    <select name="bulan" class="form-select">
                        <?php for ($i=1; $i<=12; $i++){ echo "<option value='$i'".($filter_bulan==$i?'selected':'').">".date('F',mktime(0,0,0,$i,10))."</option>"; } ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tahun</label>
                    <input type="number" class="form-control" name="tahun" value="<?php echo $filter_tahun; ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead class="table-light">
                        <tr>
                            <th>No.</th>
                            <th>Tanggal</th>
                            <th>Waktu Masuk</th>
                            <th>Status Masuk</th>
                            <th>Waktu Pulang</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($laporan_absensi->num_rows > 0): ?>
                            <?php $nomor = 1; while($row = $laporan_absensi->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $nomor++; ?></td>
                                <td><?php echo date('d M Y', strtotime($row['tanggal'])); ?></td>
                                <td><?php echo $row['waktu_masuk']; ?></td>
                                <td><span class="badge bg-<?php echo $row['status_masuk'] == 'Terlambat' ? 'danger' : 'success'; ?>"><?php echo $row['status_masuk']; ?></span></td>
                                <td><?php echo $row['waktu_pulang'] ?? '-'; ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center">Tidak ada data absensi untuk periode yang dipilih.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'partials/footer.php'; ?>