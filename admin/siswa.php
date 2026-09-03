<?php 
include 'partials/header.php';

// --- FUNGSI BARU UNTUK MENAMPILKAN TOMBOL PAGINASI YANG LEBIH BAIK ---
function renderPagination($currentPage, $totalPages) {
    if ($totalPages <= 1) return;
    $window = 2;

    echo '<nav aria-label="Page navigation"><ul class="pagination justify-content-end">';

    // Tombol "Sebelumnya"
    $prevDisabled = ($currentPage <= 1) ? "disabled" : "";
    $queryParams = $_GET; $queryParams['page'] = $currentPage - 1;
    echo "<li class='page-item {$prevDisabled}'><a class='page-link' href='?" . http_build_query($queryParams) . "'>&laquo;</a></li>";

    $from = max(1, $currentPage - $window);
    $to   = min($totalPages, $currentPage + $window);

    if ($from > 1) {
        $queryParams['page'] = 1;
        echo "<li class='page-item'><a class='page-link' href='?" . http_build_query($queryParams) . "'>1</a></li>";
        if ($from > 2) echo "<li class='page-item disabled'><a class='page-link' href='#'>…</a></li>";
    }

    for ($i = $from; $i <= $to; $i++) {
        $active = ($i == $currentPage) ? "active" : "";
        $queryParams['page'] = $i;
        echo "<li class='page-item {$active}'><a class='page-link' href='?" . http_build_query($queryParams) . "'>{$i}</a></li>";
    }

    if ($to < $totalPages) {
        if ($to < $totalPages - 1) echo "<li class='page-item disabled'><a class='page-link' href='#'>…</a></li>";
        $queryParams['page'] = $totalPages;
        echo "<li class='page-item'><a class='page-link' href='?" . http_build_query($queryParams) . "'>{$totalPages}</a></li>";
    }

    // Tombol "Selanjutnya"
    $nextDisabled = ($currentPage >= $totalPages) ? "disabled" : "";
    $queryParams['page'] = $currentPage + 1;
    echo "<li class='page-item {$nextDisabled}'><a class='page-link' href='?" . http_build_query($queryParams) . "'>&raquo;</a></li>";

    echo '</ul></nav>';
}

// --- LOGIKA SIMPAN (TAMBAH/EDIT) DATA SISWA ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_siswa'])) {
    $nisn = $_POST['nisn'];
    $nama_siswa = $_POST['nama_siswa'];
    $jenis_kelamin = $_POST['jenis_kelamin'];
    $kelas = $_POST['kelas'];
    $kontak_ortu = $_POST['kontak_ortu'];
    $password = $_POST['password'];
    $id = $_POST['id'] ?? null;
    $foto_lama = $_POST['foto_lama'] ?? '';
    $foto_path_db = $foto_lama;

    // Proses upload foto baru jika ada
    if (isset($_FILES['foto_siswa']) && $_FILES['foto_siswa']['error'] == 0) {
        $target_dir = "../uploads/siswa/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
        $file_name = time() . '-' . basename($_FILES["foto_siswa"]["name"]);
        $target_file = $target_dir . $file_name;
        if (move_uploaded_file($_FILES["foto_siswa"]["tmp_name"], $target_file)) {
            if (!empty($foto_lama) && file_exists("../".$foto_lama)) {
                unlink("../".$foto_lama);
            }
            $foto_path_db = "uploads/siswa/" . $file_name;
        }
    }

    if ($id) { // Proses Edit
        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE siswa SET nisn=?, nama_siswa=?, jenis_kelamin=?, kelas=?, kontak_ortu=?, password=?, foto_siswa=? WHERE id=?");
            $stmt->bind_param("sssssssi", $nisn, $nama_siswa, $jenis_kelamin, $kelas, $kontak_ortu, $hashed_password, $foto_path_db, $id);
        } else {
            $stmt = $conn->prepare("UPDATE siswa SET nisn=?, nama_siswa=?, jenis_kelamin=?, kelas=?, kontak_ortu=?, foto_siswa=? WHERE id=?");
            $stmt->bind_param("ssssssi", $nisn, $nama_siswa, $jenis_kelamin, $kelas, $kontak_ortu, $foto_path_db, $id);
        }
    } else { // Proses Tambah Baru
        if(empty($password)) {
            $pesan = "Error: Password wajib diisi untuk siswa baru.";
            $pesan_tipe = "danger";
            $is_error = true;
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO siswa (nisn, nama_siswa, jenis_kelamin, kelas, kontak_ortu, password, foto_siswa) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssss", $nisn, $nama_siswa, $jenis_kelamin, $kelas, $kontak_ortu, $hashed_password, $foto_path_db);
        }
    }

    if (!isset($is_error)) {
        if($stmt->execute()) {
            $pesan = "Data siswa berhasil disimpan.";
            $pesan_tipe = "success";
        } else {
            $pesan = "Gagal menyimpan data: " . $stmt->error;
            $pesan_tipe = "danger";
        }
    }
}

// --- LOGIKA HAPUS DATA SISWA ---
if (isset($_GET['hapus'])) {
    $id = (int)$_GET['hapus'];
    $stmt_foto = $conn->prepare("SELECT foto_siswa FROM siswa WHERE id = ?");
    $stmt_foto->bind_param("i", $id);
    $stmt_foto->execute();
    $result_foto = $stmt_foto->get_result();
    if($result_foto->num_rows > 0){
        $foto_path = $result_foto->fetch_assoc()['foto_siswa'];
    } else {
        $foto_path = null;
    }

    $stmt_hapus = $conn->prepare("DELETE FROM siswa WHERE id = ?");
    $stmt_hapus->bind_param("i", $id);
    if($stmt_hapus->execute()) {
        if (!empty($foto_path) && file_exists("../".$foto_path)) {
            unlink("../".$foto_path);
        }
        $pesan = "Data siswa berhasil dihapus.";
        $pesan_tipe = "success";
    } else {
        $pesan = "Gagal menghapus data.";
        $pesan_tipe = "danger";
    }
}

// =================================================================
// --- LOGIKA FILTER, LIMIT, DAN PAGINASI ---
// =================================================================
$allowed_limits = [10, 20, 50];
$limit = isset($_GET['limit']) && in_array((int)$_GET['limit'], $allowed_limits) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$filter_kelas = $_GET['kelas'] ?? '';
$filter_jenis_kelamin = $_GET['jenis_kelamin'] ?? '';
$search_query = $_GET['search'] ?? ''; // Tambahan filter pencarian nama/NISN

$sql_base = "FROM siswa";
$conditions = ["kelas != 'Lulus / Alumni'"]; 
$params = [];
$types = '';

if (!empty($filter_kelas)) {
    $conditions[] = "kelas LIKE ?";
    $params[] = "%" . $filter_kelas . "%";
    $types .= 's';
}
if (!empty($filter_jenis_kelamin)) {
    $conditions[] = "jenis_kelamin = ?";
    $params[] = $filter_jenis_kelamin;
    $types .= 's';
}
if (!empty($search_query)) {
    $conditions[] = "(nama_siswa LIKE ? OR nisn LIKE ?)";
    $search_param = "%" . $search_query . "%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
}

$where_clause = !empty($conditions) ? " WHERE " . implode(" AND ", $conditions) : "";

// Query Hitung Total
$sql_total = "SELECT COUNT(id) as total " . $sql_base . $where_clause;
$stmt_total = $conn->prepare($sql_total);
if (!empty($params)) {
    $stmt_total->bind_param($types, ...$params);
}
$stmt_total->execute();
$total_rows = $stmt_total->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Query Ambil Data
$sql_data = "SELECT * " . $sql_base . $where_clause . " ORDER BY kelas ASC, nama_siswa ASC LIMIT ?, ?";
$params[] = $offset;
$params[] = $limit;
$types .= 'ii';
$stmt_list = $conn->prepare($sql_data);
$stmt_list->bind_param($types, ...$params);
$stmt_list->execute();
$list_siswa = $stmt_list->get_result();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold text-dark m-0"><i class="bi bi-people text-primary me-2"></i> Manajemen Siswa Aktif</h2>
    <div>
        <a href="ekspor_siswa.php?kelas=<?php echo urlencode($filter_kelas); ?>&jenis_kelamin=<?php echo urlencode($filter_jenis_kelamin); ?>" class="btn btn-outline-danger rounded-pill shadow-sm me-2">
            <i class="bi bi-file-earmark-excel"></i> Ekspor Excel
        </a>
        <button class="btn btn-primary rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#siswaModal">
            <i class="bi bi-plus-circle"></i> Tambah Manual
        </button>
    </div>
</div>

<?php if(isset($pesan)): ?>
    <div class="alert alert-<?php echo $pesan_tipe; ?> alert-dismissible fade show border-0 shadow-sm rounded-4" role="alert">
        <i class="bi <?php echo $pesan_tipe == 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> me-2"></i>
        <?php echo $pesan; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if(isset($_GET['import_status'])): ?>
    <div class="alert alert-<?php echo $_GET['import_status'] == 'sukses' ? 'success' : 'danger'; ?> alert-dismissible fade show border-0 shadow-sm rounded-4">
        <i class="bi <?php echo $_GET['import_status'] == 'sukses' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> me-2"></i>
        <?php echo htmlspecialchars(urldecode($_GET['pesan'])); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-header bg-white pt-3 pb-2 border-0">
        <h6 class="fw-bold text-dark mb-0"><i class="bi bi-file-earmark-spreadsheet text-success me-2"></i> Import Data Siswa Massal</h6>
    </div>
    <div class="card-body">
        <form action="proses_import_siswa.php" method="post" enctype="multipart/form-data" class="row align-items-center g-3">
            <div class="col-md-7">
                <input class="form-control border-success" type="file" name="file_excel" id="file_excel" required accept=".xlsx, .xls, .csv">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-success w-100 fw-bold"><i class="bi bi-upload"></i> Upload & Import</button>
            </div>
            <div class="col-md-2">
                <a href="../template_siswa.xlsx" download class="btn btn-outline-secondary w-100"><i class="bi bi-download"></i> Template</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-body p-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="search" class="form-label fw-semibold small text-muted">Cari Nama / NISN</label>
                <input type="text" name="search" id="search" class="form-control border-primary" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Ketik kata kunci...">
            </div>
            <div class="col-md-3">
                <label for="kelas" class="form-label fw-semibold small text-muted">Filter Kelas</label>
                <input type="text" name="kelas" id="kelas" class="form-control border-primary" value="<?php echo htmlspecialchars($filter_kelas); ?>" placeholder="Contoh: 10-DKV">
            </div>
            <div class="col-md-3">
                <label for="jenis_kelamin" class="form-label fw-semibold small text-muted">Filter Gender</label>
                <select name="jenis_kelamin" id="jenis_kelamin" class="form-select border-primary">
                    <option value="">Semua Gender</option>
                    <option value="Laki-laki" <?php if ($filter_jenis_kelamin == 'Laki-laki') echo 'selected'; ?>>Laki-laki</option>
                    <option value="Perempuan" <?php if ($filter_jenis_kelamin == 'Perempuan') echo 'selected'; ?>>Perempuan</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-search"></i></button>
                <a href="siswa.php" class="btn btn-secondary flex-grow-1"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4 mb-5">
    <div class="card-body p-0">
        <div class="d-flex justify-content-between align-items-center p-3 border-bottom bg-light">
            <form method="GET" class="d-flex align-items-center">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>">
                <input type="hidden" name="kelas" value="<?php echo htmlspecialchars($filter_kelas); ?>">
                <input type="hidden" name="jenis_kelamin" value="<?php echo htmlspecialchars($filter_jenis_kelamin); ?>">
                <label for="limit" class="form-label me-2 mb-0 fw-semibold text-muted">Tampil:</label>
                <select name="limit" id="limit" class="form-select form-select-sm border-primary" style="width: auto;" onchange="this.form.submit()">
                    <option value="10" <?php if ($limit == 10) echo 'selected'; ?>>10</option>
                    <option value="20" <?php if ($limit == 20) echo 'selected'; ?>>20</option>
                    <option value="50" <?php if ($limit == 50) echo 'selected'; ?>>50</option>
                </select>
            </form>
            <div class="text-muted small fw-semibold">
                <span class="badge bg-primary rounded-pill"><?php echo $total_rows; ?></span> Total Siswa Aktif
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-muted">
                    <tr>
                        <th class="ps-4 text-center" width="5%">No.</th>
                        <th class="text-center" width="10%">Foto</th>
                        <th width="15%">NISN</th>
                        <th width="25%">Nama Siswa</th>
                        <th width="15%">Jenis Kelamin</th>
                        <th width="10%">Kelas</th>
                        <th class="text-center pe-4" width="20%">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $nomor = $offset + 1;
                    if($list_siswa->num_rows > 0):
                        while($siswa = $list_siswa->fetch_assoc()):
                    ?>
                    <tr>
                        <td class="ps-4 text-center fw-semibold text-muted"><?php echo $nomor++; ?></td>
                        <td class="text-center">
                            <img src="../<?php echo !empty($siswa['foto_siswa']) ? htmlspecialchars($siswa['foto_siswa']) : 'uploads/default.png'; ?>" alt="Foto" class="rounded-circle border" width="45" height="45" style="object-fit: cover;">
                        </td>
                        <td><span class="badge bg-secondary bg-opacity-10 text-dark border"><?php echo htmlspecialchars($siswa['nisn']); ?></span></td>
                        <td class="fw-bold text-dark">
                            <a href="detail_siswa.php?id=<?php echo $siswa['id']; ?>" class="text-decoration-none text-dark" title="Lihat Detail Kehadiran">
                                <?php echo htmlspecialchars($siswa['nama_siswa']); ?>
                            </a>
                        </td>
                        <td class="text-muted small">
                            <i class="bi <?php echo $siswa['jenis_kelamin'] == 'Laki-laki' ? 'bi-gender-male text-primary' : 'bi-gender-female text-danger'; ?>"></i>
                            <?php echo htmlspecialchars($siswa['jenis_kelamin']); ?>
                        </td>
                        <td class="fw-bold"><?php echo htmlspecialchars($siswa['kelas']); ?></td>
                        <td class="text-center pe-4">
                            <a href="buku_pribadi.php?siswa_id=<?php echo $siswa['id']; ?>" class="btn btn-sm btn-info text-white rounded-pill px-3 me-1" title="Buku Pribadi BK">
                                <i class="bi bi-book-half"></i> Rekam Jejak
                            </a>
                            <button class="btn btn-sm btn-warning rounded-circle me-1 btn-edit" 
                                    data-bs-toggle="modal" data-bs-target="#siswaModal"
                                    data-id="<?php echo $siswa['id']; ?>"
                                    data-nisn="<?php echo htmlspecialchars($siswa['nisn']); ?>"
                                    data-nama_siswa="<?php echo htmlspecialchars($siswa['nama_siswa']); ?>"
                                    data-jenis_kelamin="<?php echo htmlspecialchars($siswa['jenis_kelamin']); ?>"
                                    data-kelas="<?php echo htmlspecialchars($siswa['kelas']); ?>"
                                    data-kontak_ortu="<?php echo htmlspecialchars($siswa['kontak_ortu']); ?>"
                                    data-foto_lama="<?php echo htmlspecialchars($siswa['foto_siswa']); ?>" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <a href="siswa.php?hapus=<?php echo $siswa['id']; ?>" class="btn btn-sm btn-danger rounded-circle" onclick="return confirm('Yakin ingin menghapus data siswa ini?')" title="Hapus">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-people fs-1 d-block mb-2 opacity-50"></i>
                            Tidak ada data siswa aktif yang ditemukan.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="p-3 border-top bg-light">
            <?php renderPagination($page, $total_pages); ?>
        </div>
    </div>
</div>

<div class="modal fade" id="siswaModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow rounded-4">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold text-primary" id="siswaModalLabel"><i class="bi bi-person-plus me-2"></i> Form Data Siswa</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="siswa.php" enctype="multipart/form-data" id="form-siswa">
          <div class="modal-body">
              <input type="hidden" name="id" id="modal-id">
              <input type="hidden" name="foto_lama" id="modal-foto_lama">
              
              <div class="mb-3">
                  <label class="form-label fw-semibold text-muted">NISN (Menjadi Username) <span class="text-danger">*</span></label>
                  <input type="text" class="form-control bg-light" name="nisn" id="modal-nisn" required>
              </div>
              <div class="mb-3">
                  <label class="form-label fw-semibold text-muted">Nama Lengkap Siswa <span class="text-danger">*</span></label>
                  <input type="text" class="form-control bg-light" name="nama_siswa" id="modal-nama_siswa" required>
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-semibold text-muted">Jenis Kelamin <span class="text-danger">*</span></label>
                      <select name="jenis_kelamin" id="modal-jenis_kelamin" class="form-select bg-light" required>
                          <option value="Laki-laki">Laki-laki</option>
                          <option value="Perempuan">Perempuan</option>
                      </select>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-semibold text-muted">Kelas Tujuan <span class="text-danger">*</span></label>
                      <input type="text" class="form-control bg-light" name="kelas" id="modal-kelas" required>
                  </div>
              </div>
              <div class="mb-3">
                  <label class="form-label fw-semibold text-muted">Kontak Orang Tua (WA)</label>
                  <input type="text" class="form-control bg-light" name="kontak_ortu" id="modal-kontak_ortu">
              </div>
              <div class="mb-3">
                  <label class="form-label fw-semibold text-muted">Password Akun Siswa <span id="pwd-req" class="text-danger">*</span></label>
                  <input type="password" class="form-control border-warning" name="password" id="modal-password">
                  <small class="form-text text-muted" id="password-help">Wajib diisi untuk siswa baru.</small>
              </div>
              <div class="mb-3">
                  <label class="form-label fw-semibold text-muted">Foto Wajah Siswa</label>
                  <input type="file" class="form-control border-primary" name="foto_siswa" id="modal-foto_siswa" accept="image/*">
              </div>
          </div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
            <button type="submit" name="simpan_siswa" class="btn btn-primary rounded-pill px-4 fw-bold">Simpan</button>
          </div>
      </form>
    </div>
  </div>
</div>

<?php ob_start(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const siswaModal = document.getElementById('siswaModal');
    const formSiswa = document.getElementById('form-siswa');
    
    const resetForm = () => {
        document.getElementById('siswaModalLabel').innerHTML = '<i class="bi bi-person-plus me-2"></i> Tambah Siswa Baru';
        document.getElementById('modal-id').value = '';
        document.getElementById('modal-nisn').value = '';
        document.getElementById('modal-nama_siswa').value = '';
        document.getElementById('modal-jenis_kelamin').value = 'Laki-laki';
        document.getElementById('modal-kelas').value = '';
        document.getElementById('modal-kontak_ortu').value = '';
        document.getElementById('modal-password').value = '';
        document.getElementById('modal-password').required = true;
        document.getElementById('pwd-req').style.display = 'inline';
        document.getElementById('modal-foto_siswa').value = '';
        document.getElementById('modal-foto_lama').value = '';
    };

    if(siswaModal) {
        siswaModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            formSiswa.reset(); // Clear previous states
            
            if (button.classList.contains('btn-edit')) {
                // Mode Edit
                document.getElementById('siswaModalLabel').innerHTML = '<i class="bi bi-pencil-square me-2"></i> Edit Data Siswa';
                document.getElementById('modal-id').value = button.getAttribute('data-id');
                document.getElementById('modal-nisn').value = button.getAttribute('data-nisn');
                document.getElementById('modal-nama_siswa').value = button.getAttribute('data-nama_siswa');
                document.getElementById('modal-jenis_kelamin').value = button.getAttribute('data-jenis_kelamin');
                document.getElementById('modal-kelas').value = button.getAttribute('data-kelas');
                document.getElementById('modal-kontak_ortu').value = button.getAttribute('data-kontak_ortu');
                document.getElementById('modal-foto_lama').value = button.getAttribute('data-foto_lama');
                
                document.getElementById('modal-password').required = false;
                document.getElementById('pwd-req').style.display = 'none';
                document.getElementById('password-help').textContent = 'Biarkan kosong jika tidak ingin merubah password.';
            } else {
                // Mode Tambah Baru
                resetForm();
            }
        });

        siswaModal.addEventListener('hidden.bs.modal', function () {
            resetForm();
        });
    }
});
</script>
<?php
$custom_script = ob_get_clean();
include 'partials/footer.php'; 
?>