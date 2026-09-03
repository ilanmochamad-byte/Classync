<?php 
include 'partials/header.php';

$guru_id = $_SESSION['guru_id']; 

// Ambil data guru saat ini untuk ditampilkan di form
$stmt = $conn->prepare("SELECT * FROM guru WHERE id = ?");
$stmt->bind_param("i", $guru_id);
$stmt->execute();
$guru = $stmt->get_result()->fetch_assoc();
?>

<h1 class="mb-4">Edit Profil Saya</h1>

<div class="card shadow-sm">
    <div class="card-body">
        <form action="proses_edit_profil.php" method="POST" enctype="multipart/form-data">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="nama_guru" class="form-label">Nama Lengkap</label>
                    <input type="text" class="form-control" id="nama_guru" name="nama_guru" value="<?php echo htmlspecialchars($guru['nama_guru']); ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="nip" class="form-label">NIP</label>
                    <input type="text" class="form-control" id="nip" name="nip" value="<?php echo htmlspecialchars($guru['nip']); ?>" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="tempat_lahir" class="form-label">Tempat Lahir</label>
                    <input type="text" class="form-control" id="tempat_lahir" name="tempat_lahir" value="<?php echo htmlspecialchars($guru['tempat_lahir'] ?? ''); ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="tanggal_lahir" class="form-label">Tanggal Lahir</label>
                    <input type="date" class="form-control" id="tanggal_lahir" name="tanggal_lahir" value="<?php echo htmlspecialchars($guru['tanggal_lahir'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="mb-3">
                <label for="kontak" class="form-label">Kontak (No. HP/WA)</label>
                <input type="text" class="form-control" id="kontak" name="kontak" value="<?php echo htmlspecialchars($guru['kontak'] ?? ''); ?>">
            </div>

            <hr class="my-4">
            <h5 class="mb-3">Riwayat Pendidikan</h5>
            <div class="mb-3">
                <label for="pendidikan_s1" class="form-label">S1</label>
                <input type="text" class="form-control" id="pendidikan_s1" name="pendidikan_s1" placeholder="Contoh: S.Pd. Pendidikan Teknik Mesin, Universitas Siliwangi" value="<?php echo htmlspecialchars($guru['pendidikan_s1'] ?? ''); ?>">
            </div>
            <div class="mb-3">
                <label for="pendidikan_s2" class="form-label">S2</label>
                <input type="text" class="form-control" id="pendidikan_s2" name="pendidikan_s2" value="<?php echo htmlspecialchars($guru['pendidikan_s2'] ?? ''); ?>">
            </div>
            <div class="mb-3">
                <label for="pendidikan_s3" class="form-label">S3</label>
                <input type="text" class="form-control" id="pendidikan_s3" name="pendidikan_s3" value="<?php echo htmlspecialchars($guru['pendidikan_s3'] ?? ''); ?>">
            </div>

            <hr class="my-4">
            <h5 class="mb-3">Informasi Lain</h5>
             <div class="mb-3">
                <label for="tugas_tambahan" class="form-label">Tugas Tambahan</label>
                <input type="text" class="form-control" id="tugas_tambahan" name="tugas_tambahan" placeholder="Contoh: Wakil Kepala Kurikulum" value="<?php echo htmlspecialchars($guru['tugas_tambahan'] ?? ''); ?>">
            </div>
             <div class="mb-3">
                <label for="foto_profil" class="form-label">Ganti Foto Profil</label>
                <input type="file" class="form-control" id="foto_profil" name="foto_profil" accept="image/*">
                <small class="form-text text-muted">Kosongkan jika tidak ingin mengganti foto.</small>
                <input type="hidden" name="foto_lama" value="<?php echo htmlspecialchars($guru['foto_profil'] ?? ''); ?>">
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-success">Simpan Perubahan</button>
                <a href="profil_guru.php" class="btn btn-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>

<?php include 'partials/footer.php'; ?>