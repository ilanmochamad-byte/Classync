<?php 
include 'partials/header.php';

// --- FUNGSI PAGINASI ---
function renderPagination($currentPage, $totalPages) {
    if ($totalPages <= 1) return;
    echo '<nav aria-label="Page navigation"><ul class="pagination justify-content-end">';
    $prevDisabled = ($currentPage <= 1) ? "disabled" : "";
    $queryParams = $_GET; $queryParams['page'] = $currentPage - 1;
    echo "<li class='page-item {$prevDisabled}'><a class='page-link' href='?".http_build_query($queryParams)."'>&laquo;</a></li>";

    for ($i = 1; $i <= $totalPages; $i++) {
        $active = ($i == $currentPage) ? "active" : "";
        $queryParams['page'] = $i;
        echo "<li class='page-item {$active}'><a class='page-link' href='?".http_build_query($queryParams)."'>{$i}</a></li>";
    }

    $nextDisabled = ($currentPage >= $totalPages) ? "disabled" : "";
    $queryParams['page'] = $currentPage + 1;
    echo "<li class='page-item {$nextDisabled}'><a class='page-link' href='?".http_build_query($queryParams)."'>&raquo;</a></li>";
    echo '</ul></nav>';
}

$pesan = '';
$tipe_pesan = '';

// --- LOGIKA KEMBALIKAN STATUS (BATAL LULUS) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['batal_lulus'])) {
    $id_siswa = (int)$_POST['id_siswa'];
    $kelas_baru = trim($_POST['kelas_baru']);

    if (!empty($kelas_baru)) {
        $stmt = $conn->prepare("UPDATE siswa SET kelas = ? WHERE id = ?");
        $stmt->bind_param("si", $kelas_baru, $id_siswa);
        if ($stmt->execute()) {
            $pesan = "Status alumni berhasil dicabut. Siswa dikembalikan ke kelas " . htmlspecialchars($kelas_baru) . ".";
            $tipe_pesan = "success";
        } else {
            $pesan = "Gagal mengubah status: " . $stmt->error;
            $tipe_pesan = "danger";
        }
        $stmt->close();
    }
}

// --- LOGIKA HAPUS DATA ALUMNI ---
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
        $pesan = "Data alumni berhasil dihapus permanen.";
        $tipe_pesan = "success";
    } else {
        $pesan = "Gagal menghapus data.";
        $tipe_pesan = "danger";
    }
}

// --- LOGIKA PENCARIAN & PAGINASI ---
$search = $_GET['search'] ?? '';
$limit = isset($_GET['limit']) && in_array((int)$_GET['limit'], [10, 20, 50]) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Base query KHUSUS ALUMNI
$sql_base = "FROM siswa WHERE kelas = 'Lulus / Alumni'";
$params = [];
$types = '';

if (!empty($search)) {
    $sql_base .= " AND (nama_siswa LIKE ? OR nisn LIKE ?)";
    $search_param = "%" . $search . "%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
}

// Hitung Total Data
$sql_total = "SELECT COUNT(id) as total " . $sql_base;
$stmt_total = $conn->prepare($sql_total);
if (!empty($params)) $stmt_total->bind_param($types, ...$params);
$stmt_total->execute();
$total_rows = $stmt_total->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Ambil Data
$sql_data = "SELECT * " . $sql_base . " ORDER BY nama_siswa ASC LIMIT ?, ?";
$params[] = $offset;
$params[] = $limit;
$types .= 'ii';

$stmt_list = $conn->prepare($sql_data);
$stmt_list->bind_param($types, ...$params);
$stmt_list->execute();
$list_alumni = $stmt_list->get_result();

// Ambil daftar kelas untuk modal Batal Lulus
$kelas_query = $conn->query("SELECT DISTINCT kelas FROM siswa WHERE kelas != 'Lulus / Alumni' ORDER BY kelas ASC");
$daftar_kelas = [];
while ($row = $kelas_query->fetch_assoc()) {
    $daftar_kelas[] = $row['kelas'];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Alumni - Classync</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f5f7fa; }
        .card-custom { border-radius: 16px; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.04); }
        .card-header-custom { background-color: #fff; border-bottom: 1px solid #f1f2f6; padding: 1.25rem 1.5rem; border-radius: 16px 16px 0 0 !important; }
        .table-hover tbody tr:hover { background-color: #f8f9fa; }
    </style>
</head>
<body>

<div class="bg-white shadow-sm py-3 mb-4">
    <div class="container d-flex justify-content-between align-items-center">
        <h4 class="fw-bold text-dark mb-0"><i class="bi bi-mortarboard-fill text-success me-2"></i> Data Alumni</h4>
        <a href="siswa.php" class="btn btn-outline-primary btn-sm rounded-pill"><i class="bi bi-people"></i> Siswa Aktif</a>
    </div>
</div>

<div class="container mb-5">

    <?php if(!empty($pesan)): ?>
        <div class="alert alert-<?php echo $tipe_pesan; ?> alert-dismissible fade show shadow-sm border-0 rounded-4" role="alert">
            <i class="bi <?php echo $tipe_pesan == 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> me-2"></i>
            <?php echo $pesan; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card card-custom mb-4">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label fw-bold text-muted small">Cari Nama atau NISN</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-primary text-primary"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control border-primary" value="<?php echo htmlspecialchars($search); ?>" placeholder="Ketik kata kunci...">
                    </div>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">Cari</button>
                    <a href="alumni.php" class="btn btn-secondary"><i class="bi bi-arrow-counterclockwise"></i></a>
                </div>
            </form>
        </div>
    </div>

    <div class="card card-custom shadow-sm h-100">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <form method="GET" class="d-flex align-items-center">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <label for="limit" class="form-label me-2 mb-0 fw-semibold text-muted">Tampil:</label>
                    <select name="limit" id="limit" class="form-select form-select-sm border-primary" style="width: 80px;" onchange="this.form.submit()">
                        <option value="10" <?php if ($limit == 10) echo 'selected'; ?>>10</option>
                        <option value="20" <?php if ($limit == 20) echo 'selected'; ?>>20</option>
                        <option value="50" <?php if ($limit == 50) echo 'selected'; ?>>50</option>
                    </select>
                </form>
                <div class="text-muted small fw-semibold">
                    <span class="badge bg-success rounded-pill"><?php echo $total_rows; ?></span> Total Alumni
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover align-middle border">
                    <thead class="table-light text-muted">
                        <tr>
                            <th class="text-center" width="5%">No.</th>
                            <th class="text-center" width="10%">Foto</th>
                            <th width="15%">NISN</th>
                            <th width="30%">Nama Lengkap</th>
                            <th width="15%">Jenis Kelamin</th>
                            <th class="text-center" width="25%">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $nomor = $offset + 1;
                        if($list_alumni->num_rows > 0):
                            while($alumni = $list_alumni->fetch_assoc()):
                        ?>
                        <tr>
                            <td class="text-center fw-semibold text-muted"><?php echo $nomor++; ?></td>
                            <td class="text-center">
                                <img src="../<?php echo !empty($alumni['foto_siswa']) ? htmlspecialchars($alumni['foto_siswa']) : 'uploads/default.png'; ?>" alt="Foto" class="rounded-circle border" width="45" height="45" style="object-fit: cover;">
                            </td>
                            <td><span class="badge bg-secondary bg-opacity-10 text-dark border"><?php echo htmlspecialchars($alumni['nisn']); ?></span></td>
                            <td class="fw-bold text-dark"><?php echo htmlspecialchars($alumni['nama_siswa']); ?></td>
                            <td class="text-muted small">
                                <i class="bi <?php echo $alumni['jenis_kelamin'] == 'Laki-laki' ? 'bi-gender-male text-primary' : 'bi-gender-female text-danger'; ?>"></i>
                                <?php echo htmlspecialchars($alumni['jenis_kelamin']); ?>
                            </td>
                            <td class="text-center">
                                <a href="buku_pribadi.php?siswa_id=<?php echo $alumni['id']; ?>" class="btn btn-sm btn-info text-white rounded-pill px-3" title="Lihat Rekam Jejak">
                                    <i class="bi bi-book-half"></i> Rekam Jejak
                                </a>
                                <button class="btn btn-sm btn-warning rounded-circle ms-1" title="Batal Lulus / Kembalikan ke Siswa Aktif" 
                                    onclick="openRevertModal(<?php echo $alumni['id']; ?>, '<?php echo htmlspecialchars(addslashes($alumni['nama_siswa'])); ?>')">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                                <a href="alumni.php?hapus=<?php echo $alumni['id']; ?>" class="btn btn-sm btn-danger rounded-circle ms-1" title="Hapus Permanen" onclick="return confirm('Yakin ingin menghapus permanen data alumni ini?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="bi bi-mortarboard fs-1 d-block mb-2 opacity-25"></i>
                                    Belum ada data alumni yang tersimpan.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="mt-4">
                <?php renderPagination($page, $total_pages); ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="revertModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold text-warning"><i class="bi bi-exclamation-circle-fill me-2"></i>Kembalikan Status Siswa</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="alumni.php">
          <div class="modal-body">
              <p class="text-muted small">Tindakan ini akan membatalkan status kelulusan <strong><span id="revert_nama_siswa" class="text-dark"></span></strong> dan mengembalikannya ke daftar Siswa Aktif.</p>
              
              <input type="hidden" name="id_siswa" id="revert_id_siswa">
              
              <div class="mb-3">
                  <label class="form-label fw-bold">Pilih Kelas Tujuan <span class="text-danger">*</span></label>
                  <select name="kelas_baru" class="form-select border-warning" required>
                      <option value="">-- Tentukan Kelas Baru --</option>
                      <?php foreach($daftar_kelas as $k): ?>
                          <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($k); ?></option>
                      <?php endforeach; ?>
                  </select>
              </div>
          </div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
            <button type="submit" name="batal_lulus" class="btn btn-warning rounded-pill px-4 fw-bold">Kembalikan Status</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script>
    function openRevertModal(id, nama) {
        document.getElementById('revert_id_siswa').value = id;
        document.getElementById('revert_nama_siswa').innerText = nama;
        new bootstrap.Modal(document.getElementById('revertModal')).show();
    }
</script>

<?php include 'partials/footer.php'; ?>