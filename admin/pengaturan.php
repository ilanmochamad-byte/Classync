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

    $pesan = "Pengaturan jam berhasil disimpan.";
}

// Ambil data pengaturan saat ini dari database
$sql = "SELECT nama_pengaturan, nilai_pengaturan FROM pengaturan WHERE nama_pengaturan IN ('jam_masuk', 'jam_pulang')";
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
    <div class="alert alert-success"><?php echo htmlspecialchars($pesan); ?></div>
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
                        <label for="jam_pulang" class="form-label">Jam Pulang</label>
                        <input type="time" class="form-control" id="jam_pulang" name="jam_pulang" value="<?php echo htmlspecialchars($pengaturan['jam_pulang'] ?? '13:50'); ?>" required>
                        <small class="text-muted">Waktu paling awal siswa diizinkan untuk absen pulang.</small>
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