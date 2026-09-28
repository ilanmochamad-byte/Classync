<?php 
include 'partials/header.php';

// Pastikan koneksi $conn ada (jika header tidak menyediakan)
if (!isset($conn)) {
    require_once 'includes/db.php';
}

// Proses update jika form disubmit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_pengaturan'])) {
    // Validasi & normalisasi format jam ke H:i:s untuk konsistensi penyimpanan
    $jam_masuk_input = trim($_POST['jam_masuk']);
    $jam_pulang_input = trim($_POST['jam_pulang']);

    $jam_masuk = date('H:i:s', strtotime($jam_masuk_input));
    $jam_pulang = date('H:i:s', strtotime($jam_pulang_input));

    // Update jam masuk
    $stmt_masuk = $conn->prepare("UPDATE pengaturan SET nilai_pengaturan = ? WHERE nama_pengaturan = 'jam_masuk'");
    $stmt_masuk->bind_param("s", $jam_masuk);
    $stmt_masuk->execute();
    $stmt_masuk->close();

    // Update jam pulang
    $stmt_pulang = $conn->prepare("UPDATE pengaturan SET nilai_pengaturan = ? WHERE nama_pengaturan = 'jam_pulang'");
    $stmt_pulang->bind_param("s", $jam_pulang);
    $stmt_pulang->execute();
    $stmt_pulang->close();

    // Jam pulang hari Jumat. Disimpan dengan INSERT ... ON DUPLICATE KEY UPDATE
    // supaya tetap tersimpan walau barisnya belum ada. Libur dan pulang cepat
    // pada tanggal tertentu diatur di kalender_sekolah.php, bukan di sini.
    $jam_jumat_input = trim((string)($_POST['jam_pulang_jumat'] ?? ''));
    $jumat_sah = (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $jam_jumat_input);
    if ($jumat_sah) {
        $jam_pulang_jumat = date('H:i:s', strtotime($jam_jumat_input));
        $stmt_jumat = $conn->prepare("INSERT INTO pengaturan (nama_pengaturan, nilai_pengaturan) VALUES ('jam_pulang_jumat', ?) ON DUPLICATE KEY UPDATE nilai_pengaturan = VALUES(nilai_pengaturan)");
        $stmt_jumat->bind_param("s", $jam_pulang_jumat);
        $stmt_jumat->execute();
        $stmt_jumat->close();
    }

    $pesan = $jumat_sah
        ? "Pengaturan jam berhasil disimpan."
        : "Jam masuk dan jam pulang disimpan, tetapi jam pulang Jumat tidak sah sehingga tidak diubah.";
    $tipe_pesan = $jumat_sah ? 'success' : 'warning';
}

// Ambil data pengaturan saat ini dari database
$sql = "SELECT nama_pengaturan, nilai_pengaturan FROM pengaturan WHERE nama_pengaturan IN ('jam_masuk', 'jam_pulang', 'jam_pulang_jumat')";
$result = $conn->query($sql);
$pengaturan = [];
while ($row = $result->fetch_assoc()) {
    // Normalisasi agar value yang ditampilkan di input time adalah H:i (HTML time expects H:i)
    $val = $row['nilai_pengaturan'];
    if (!empty($val)) {
        $val_formatted = date('H:i', strtotime($val));
    } else {
        $val_formatted = '';
    }
    $pengaturan[$row['nama_pengaturan']] = $val_formatted;
}
?>

<h1 class="mb-4">Pengaturan Jam Sekolah</h1>

<?php if(isset($pesan)): ?>
    <div class="alert alert-<?php echo $tipe_pesan; ?>"><?php echo htmlspecialchars($pesan); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-md-8">
        <div class="card shadow-sm">
            <div class="card-header">
                <h5><i class="bi bi-clock-fill"></i> Atur Jam Masuk dan Pulang Siswa</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label for="jam_masuk" class="form-label">Jam Masuk</label>
                        <input type="time" class="form-control" id="jam_masuk" name="jam_masuk" value="<?php echo htmlspecialchars($pengaturan['jam_masuk'] ?? '07:30'); ?>" required>
                        <small class="text-muted">Ini akan menjadi batas waktu untuk status "Tepat Waktu".</small>
                    </div>
                    <div class="mb-3">
                        <label for="jam_pulang" class="form-label">Jam Pulang (Senin–Kamis dan Sabtu)</label>
                        <input type="time" class="form-control" id="jam_pulang" name="jam_pulang" value="<?php echo htmlspecialchars($pengaturan['jam_pulang'] ?? '13:50'); ?>" required>
                        <small class="text-muted">Waktu paling awal siswa diizinkan untuk absen pulang.</small>
                    </div>
                    <div class="mb-3">
                        <label for="jam_pulang_jumat" class="form-label">Jam Pulang Jumat</label>
                        <input type="time" class="form-control" id="jam_pulang_jumat" name="jam_pulang_jumat" value="<?php echo htmlspecialchars($pengaturan['jam_pulang_jumat'] ?? '10:50'); ?>" required>
                        <small class="text-muted">Libur dan pulang cepat pada tanggal tertentu diatur di <a href="kalender_sekolah.php">Kalender Sekolah</a>.</small>
                    </div>
                    <div class="text-end">
                        <button type="submit" name="simpan_pengaturan" class="btn btn-primary">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'partials/footer.php'; ?>