<?php
include 'partials/header.php';

$pesan = '';
$is_error = false;

// Proses form jika disubmit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $admin_id = $_SESSION['admin_id'];
    $password_lama = $_POST['password_lama'];
    $password_baru = $_POST['password_baru'];
    $konfirmasi_password = $_POST['konfirmasi_password'];

    // 1. Validasi input dasar
    if (empty($password_lama) || empty($password_baru) || empty($konfirmasi_password)) {
        $pesan = "Semua kolom wajib diisi.";
        $is_error = true;
    } elseif (strlen($password_baru) < 6) {
        $pesan = "Password baru minimal harus 6 karakter.";
        $is_error = true;
    } elseif ($password_baru !== $konfirmasi_password) {
        $pesan = "Password baru dan konfirmasi tidak cocok.";
        $is_error = true;
    } else {
        // 2. Ambil hash password saat ini dari database
        $stmt_cek = $conn->prepare("SELECT password FROM admin WHERE id = ?");
        $stmt_cek->bind_param("i", $admin_id);
        $stmt_cek->execute();
        $result = $stmt_cek->get_result();
        $admin_data = $result->fetch_assoc();
        $stmt_cek->close();

        // 3. Verifikasi password lama
        if ($admin_data && password_verify($password_lama, $admin_data['password'])) {
            // Jika password lama benar, hash dan update password baru
            $hash_password_baru = password_hash($password_baru, PASSWORD_DEFAULT);
            
            $stmt_update = $conn->prepare("UPDATE admin SET password = ? WHERE id = ?");
            $stmt_update->bind_param("si", $hash_password_baru, $admin_id);
            
            if ($stmt_update->execute()) {
                $pesan = "Password Anda berhasil diubah.";
            } else {
                $pesan = "Terjadi kesalahan saat memperbarui database.";
                $is_error = true;
            }
            $stmt_update->close();
        } else {
            $pesan = "Password lama yang Anda masukkan salah.";
            $is_error = true;
        }
    }
}
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <h1 class="mb-4">Ubah Password Admin</h1>

            <?php if (!empty($pesan)): ?>
                <div class="alert <?php echo $is_error ? 'alert-danger' : 'alert-success'; ?>">
                    <?php echo $pesan; ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm">
                <div class="card-body">
                    <form method="POST" action="ubah_password.php">
                        <div class="mb-3">
                            <label for="password_lama" class="form-label">Password Lama</label>
                            <input type="password" class="form-control" id="password_lama" name="password_lama" required>
                        </div>
                        <div class="mb-3">
                            <label for="password_baru" class="form-label">Password Baru</label>
                            <input type="password" class="form-control" id="password_baru" name="password_baru" required>
                            <small class="form-text text-muted">Minimal 6 karakter.</small>
                        </div>
                        <div class="mb-3">
                            <label for="konfirmasi_password" class="form-label">Konfirmasi Password Baru</label>
                            <input type="password" class="form-control" id="konfirmasi_password" name="konfirmasi_password" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'partials/footer.php'; ?>