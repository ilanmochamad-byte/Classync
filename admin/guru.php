<?php 
include 'partials/header.php';

// Logika untuk Tambah/Edit Data Guru
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nip = $_POST['nip'];
    $nama_guru = $_POST['nama_guru'];
    $username = $_POST['username'];
    $kontak = $_POST['kontak'];
    $password = $_POST['password'];
    $is_bk = isset($_POST['is_bk']) ? 1 : 0; // --- BARU:
    
    if (isset($_POST['id']) && !empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            // Tambahkan is_bk=? di query UPDATE
            $stmt = $conn->prepare("UPDATE guru SET nip=?, nama_guru=?, username=?, password=?, kontak=?, is_bk=? WHERE id=?");
            $stmt->bind_param("sssssii", $nip, $nama_guru, $username, $hashed_password, $kontak, $is_bk, $id);
        } else {
            // Tambahkan is_bk=? di query UPDATE (tanpa password)
            $stmt = $conn->prepare("UPDATE guru SET nip=?, nama_guru=?, username=?, kontak=?, is_bk=? WHERE id=?");
            $stmt->bind_param("ssssii", $nip, $nama_guru, $username, $kontak, $is_bk, $id);
        }
    } else {
        if(empty($password)) {
            $pesan = "Error: Password wajib diisi untuk guru baru.";
            $is_error = true;
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            // Tambahkan is_bk di query INSERT
            $stmt = $conn->prepare("INSERT INTO guru (nip, nama_guru, username, password, kontak, is_bk) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssi", $nip, $nama_guru, $username, $hashed_password, $kontak, $is_bk);
        }
    }
    
    if (!isset($is_error)) {
        if($stmt->execute()) {
            $pesan = "Data guru berhasil disimpan.";
        } else {
            if ($conn->errno == 1062) {
                $pesan = "Gagal menyimpan data: Username '{$username}' sudah digunakan.";
            } else {
                $pesan = "Gagal menyimpan data: " . $stmt->error;
            }
        }
        $stmt->close();
    }
}

// Logika untuk Hapus Data Guru
if (isset($_GET['hapus'])) {
    $id = (int)$_GET['hapus'];
    $stmt = $conn->prepare("DELETE FROM guru WHERE id = ?");
    $stmt->bind_param("i", $id);
    if($stmt->execute()) {
        $pesan = "Data guru berhasil dihapus.";
    } else {
        $pesan = "Gagal menghapus data.";
    }
    $stmt->close();
}

$list_guru = $conn->query("SELECT id, nip, nama_guru, username, kontak, is_bk FROM guru ORDER BY nama_guru ASC");
?>

<h1 class="mb-4">Manajemen Data Guru</h1>

<?php if(isset($pesan)): ?>
    <div class="alert alert-info"><?php echo $pesan; ?></div>
<?php endif; ?>

<button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#guruModal">
    <i class="bi bi-plus-circle"></i> Tambah Guru
</button>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>NIP</th>
                        <th>Nama Guru</th>
                        <th>Username</th>
                        <th>Kontak</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $nomor = 1; 
                    while($guru = $list_guru->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $nomor; ?></td> <td><?php echo htmlspecialchars($guru['nip']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($guru['nama_guru']); ?>
                            <?php if($guru['is_bk'] == 1): ?>
                                <span class="badge bg-success ms-1"><i class="bi bi-star-fill" style="font-size: 0.7em;"></i> BK</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($guru['username']); ?></td>
                        <td><?php echo htmlspecialchars($guru['kontak']); ?></td>
                        <td>
                            <button class="btn btn-sm btn-warning btn-edit" 
                                    data-id="<?php echo $guru['id']; ?>" 
                                    data-nip="<?php echo htmlspecialchars($guru['nip']); ?>"
                                    data-nama_guru="<?php echo htmlspecialchars($guru['nama_guru']); ?>"
                                    data-username="<?php echo htmlspecialchars($guru['username']); ?>"
                                    data-kontak="<?php echo htmlspecialchars($guru['kontak']); ?>"
                                    data-is_bk="<?php echo $guru['is_bk']; ?>" data-bs-toggle="modal" data-bs-target="#guruModal">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <a href="guru.php?hapus=<?php echo $guru['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Anda yakin ingin menghapus data guru ini? Semua jadwal yang terkait juga akan terhapus.')">
                                <i class="bi bi-trash"></i> Hapus
                            </a>
                        </td>
                    </tr>
                    <?php $nomor++; 
                    endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="guruModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="guruModalLabel">Form Data Guru</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="guru.php">
          <div class="modal-body">
              <input type="hidden" name="id" id="guru-id">
              <div class="mb-3">
                  <label for="guru-nip" class="form-label">NIP</label>
                  <input type="text" class="form-control" name="nip" id="guru-nip" required>
              </div>
              <div class="mb-3">
                  <label for="guru-nama_guru" class="form-label">Nama Guru</label>
                  <input type="text" class="form-control" name="nama_guru" id="guru-nama_guru" required>
              </div>
              <hr>
              <div class="mb-3">
                  <label for="guru-username" class="form-label">Username</label>
                  <input type="text" class="form-control" name="username" id="guru-username" required>
              </div>
              <div class="mb-3">
                  <label for="guru-password" class="form-label">Password</label>
                  <input type="password" class="form-control" name="password" id="guru-password">
                  <small class="form-text text-muted" id="password-help">Wajib diisi untuk guru baru. Kosongkan jika tidak ingin mengubah password saat edit.</small>
              </div>
              <hr>
              <div class="mb-3">
                  <label for="guru-kontak" class="form-label">Kontak / No. HP</label>
                  <input type="text" class="form-control" name="kontak" id="guru-kontak" required>
                  </div>
              <div class="mb-3 form-check">
                  <input type="checkbox" class="form-check-input" name="is_bk" id="guru-is_bk" value="1">
                  <label class="form-check-label text-success fw-bold" for="guru-is_bk">Jadikan sebagai Guru Bimbingan Konseling (BK)</label>
              </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan</button>
          </div>
      </form>
    </div>
  </div>
</div>

<?php
// Mendefinisikan skrip kustom untuk "disuntikkan" ke footer
ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const guruModal = document.getElementById('guruModal');
    
    const resetForm = () => {
        document.getElementById('guruModalLabel').textContent = 'Tambah Guru Baru';
        document.getElementById('guru-id').value = '';
        document.getElementById('guru-nip').value = '';
        document.getElementById('guru-nama_guru').value = '';
        document.getElementById('guru-username').value = '';
        document.getElementById('guru-password').value = '';
        document.getElementById('guru-password').required = true;
        document.getElementById('password-help').textContent = 'Wajib diisi untuk guru baru.';
        document.getElementById('guru-kontak').value = '';
    };

    if(guruModal) {
        guruModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            
            if (button.classList.contains('btn-edit')) {
                document.getElementById('guruModalLabel').textContent = 'Edit Data Guru';
                document.getElementById('guru-id').value = button.getAttribute('data-id');
                document.getElementById('guru-nip').value = button.getAttribute('data-nip');
                document.getElementById('guru-nama_guru').value = button.getAttribute('data-nama_guru');
                document.getElementById('guru-username').value = button.getAttribute('data-username');
                document.getElementById('guru-kontak').value = button.getAttribute('data-kontak');
                document.getElementById('guru-password').value = '';
                document.getElementById('guru-password').required = false;
                document.getElementById('password-help').textContent = 'Kosongkan jika tidak ingin mengubah password.';
                document.getElementById('guru-is_bk').checked = button.getAttribute('data-is_bk') == '1';
            } else {
                resetForm();
                document.getElementById('guru-is_bk').checked = false;
            }
        });

        guruModal.addEventListener('hidden.bs.modal', function () {
            resetForm();
        });
    }
});
</script>
<?php
$custom_script = ob_get_clean();
?>

<?php include 'partials/footer.php'; ?>