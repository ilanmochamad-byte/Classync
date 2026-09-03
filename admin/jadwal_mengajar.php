<?php 
include 'partials/header.php'; 

// --- 1. PERSIAPAN DATA DASAR ---
$guru_query = $conn->query("SELECT id, nama_guru FROM guru ORDER BY nama_guru ASC");
$semua_guru = $guru_query->fetch_all(MYSQLI_ASSOC);

// =========================================================================
// --- 2. LOGIKA AKSI (EKSEKUSI DAHULU SEBELUM DATA DITAMPILKAN) ---
// =========================================================================

// A. Logika Simpan Data (Tambah/Edit)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_jadwal'])) {
    $guru_id = $_POST['guru_id'];
    $hari = $_POST['hari'];
    $jam_mulai = $_POST['jam_mulai'];
    $jam_selesai = $_POST['jam_selesai'];
    $mata_pelajaran = $_POST['mata_pelajaran'];
    $kelas = $_POST['kelas'];
    $status_jadwal = $_POST['status_jadwal'] ?? 'Aktif';
    
    if (isset($_POST['id']) && !empty($_POST['id'])) { 
        $id = $_POST['id'];
        $stmt = $conn->prepare("UPDATE jadwal_mengajar SET guru_id=?, hari=?, jam_mulai=?, jam_selesai=?, mata_pelajaran=?, kelas=?, status_jadwal=? WHERE id=?");
        $stmt->bind_param("issssssi", $guru_id, $hari, $jam_mulai, $jam_selesai, $mata_pelajaran, $kelas, $status_jadwal, $id);
    } else { 
        $stmt = $conn->prepare("INSERT INTO jadwal_mengajar (guru_id, hari, jam_mulai, jam_selesai, mata_pelajaran, kelas, status_jadwal) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issssss", $guru_id, $hari, $jam_mulai, $jam_selesai, $mata_pelajaran, $kelas, $status_jadwal);
    }
    
    if($stmt->execute()) {
        $pesan = "Jadwal mengajar berhasil disimpan.";
        $pesan_tipe = "success";
    } else {
        $pesan = "Gagal menyimpan jadwal: " . $stmt->error;
        $pesan_tipe = "danger";
    }
    $stmt->close();
}

// B. Logika Arsip Massal
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['arsipkan_massal'])) {
    $update_arsip = $conn->query("UPDATE jadwal_mengajar SET status_jadwal = 'Arsip' WHERE status_jadwal = 'Aktif'");
    if ($update_arsip) {
        $pesan = "Berhasil! Semua jadwal aktif telah dipindahkan ke Arsip. Silakan import jadwal baru untuk tahun pelajaran baru.";
        $pesan_tipe = "success";
        $_GET['status_jadwal'] = 'Arsip'; // Otomatis pindah ke tab Arsip
    } else {
        $pesan = "Gagal mengarsipkan jadwal harian.";
        $pesan_tipe = "danger";
    }
}

// C. Logika Hapus Data
if (isset($_GET['hapus']) && !empty($_GET['hapus'])) {
    $id = (int)$_GET['hapus'];
    $stmt = $conn->prepare("DELETE FROM jadwal_mengajar WHERE id = ?");
    $stmt->bind_param("i", $id);
    if($stmt->execute()) {
        $pesan = "Jadwal berhasil dihapus.";
        $pesan_tipe = "success";
    } else {
        $pesan = "Gagal menghapus jadwal.";
        $pesan_tipe = "danger";
    }
    $stmt->close();
}

// =========================================================================
// --- 3. LOGIKA FILTER & PENGAMBILAN DATA (MENAMPILKAN DATA TERBARU) ---
// =========================================================================

$filter_hari = $_GET['hari'] ?? '';
$filter_guru_id = $_GET['guru_id'] ?? '';
$filter_kelas = $_GET['kelas'] ?? '';
$filter_status = $_GET['status_jadwal'] ?? 'Aktif'; 

$sql = "SELECT jm.*, g.nama_guru 
        FROM jadwal_mengajar jm 
        JOIN guru g ON jm.guru_id = g.id";
$conditions = [];
$params = [];
$types = '';

// Filter status_jadwal wajib masuk kueri
$conditions[] = "jm.status_jadwal = ?";
$params[] = $filter_status;
$types .= 's';

if (!empty($filter_hari)) {
    $conditions[] = "jm.hari = ?";
    $params[] = $filter_hari;
    $types .= 's';
}
if (!empty($filter_guru_id)) {
    $conditions[] = "jm.guru_id = ?";
    $params[] = $filter_guru_id;
    $types .= 'i';
}
if (!empty($filter_kelas)) {
    $conditions[] = "jm.kelas LIKE ?";
    $params[] = "%" . $filter_kelas . "%";
    $types .= 's';
}

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}
$sql .= " ORDER BY FIELD(jm.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'), jm.jam_mulai";

$stmt_list = $conn->prepare($sql);
if (!empty($params)) {
    $stmt_list->bind_param($types, ...$params);
}
$stmt_list->execute();
$list_jadwal = $stmt_list->get_result();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold text-dark m-0"><i class="bi bi-calendar-week text-primary me-2"></i> Manajemen Jadwal Mengajar</h2>
    <div class="d-flex gap-2">
        <form method="POST" action="" onsubmit="return confirm('PENTING: Tindakan ini akan mengarsipkan seluruh jadwal Aktif saat ini untuk pergantian tahun pelajaran. Data honor sebelumnya akan tetap aman. Lanjutkan?')">
            <button type="submit" name="arsipkan_massal" class="btn btn-warning rounded-pill shadow-sm fw-bold">
                <i class="bi bi-archive-fill"></i> Arsipkan Semua Jadwal Aktif
            </button>
        </form>
        <a href="ekspor_jadwal.php?hari=<?php echo urlencode($filter_hari); ?>&guru_id=<?php echo urlencode($filter_guru_id); ?>&kelas=<?php echo urlencode($filter_kelas); ?>" class="btn btn-outline-danger rounded-pill shadow-sm">
            <i class="bi bi-file-earmark-excel"></i> Ekspor Excel
        </a>
        <button class="btn btn-primary rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#jadwalModal">
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
        <h6 class="fw-bold text-dark mb-0"><i class="bi bi-file-earmark-spreadsheet text-success me-2"></i> Import Jadwal Massal</h6>
    </div>
    <div class="card-body">
        <form action="proses_import_jadwal.php" method="post" enctype="multipart/form-data" class="row align-items-center g-3">
            <div class="col-md-7">
                <input class="form-control border-success" type="file" name="file_excel" id="file_excel" required accept=".xlsx, .xls, .csv">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-success w-100 fw-bold"><i class="bi bi-upload"></i> Upload & Import</button>
            </div>
            <div class="col-md-2">
                <a href="../template_jadwal.xlsx" download class="btn btn-outline-secondary w-100"><i class="bi bi-download"></i> Template</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-body p-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="status_jadwal" class="form-label fw-semibold small text-muted">Status Roster</label>
                <select name="status_jadwal" id="status_jadwal" class="form-select border-primary fw-bold" onchange="this.form.submit()">
                    <option value="Aktif" <?php if ($filter_status == 'Aktif') echo 'selected'; ?>>Jadwal Aktif (Saat Ini)</option>
                    <option value="Arsip" <?php if ($filter_status == 'Arsip') echo 'selected'; ?>>Jadwal Arsip (Masa Lalu)</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="hari" class="form-label fw-semibold small text-muted">Hari</label>
                <select name="hari" id="hari" class="form-select border-primary">
                    <option value="">Semua Hari</option>
                    <?php $daftar_hari = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu']; ?>
                    <?php foreach ($daftar_hari as $hari): ?>
                        <option value="<?php echo $hari; ?>" <?php if ($filter_hari == $hari) echo 'selected'; ?>><?php echo $hari; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="guru_id" class="form-label fw-semibold small text-muted">Guru</label>
                <select name="guru_id" id="guru_id" class="form-select border-primary">
                    <option value="">Semua Guru</option>
                    <?php foreach ($semua_guru as $guru): ?>
                        <option value="<?php echo $guru['id']; ?>" <?php if ($filter_guru_id == $guru['id']) echo 'selected'; ?>><?php echo htmlspecialchars($guru['nama_guru']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="kelas" class="form-label fw-semibold small text-muted">Kelas</label>
                <input type="text" name="kelas" id="kelas" class="form-control border-primary" value="<?php echo htmlspecialchars($filter_kelas); ?>" placeholder="Contoh: 10-DKV">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-search"></i></button>
                <a href="jadwal_mengajar.php" class="btn btn-secondary flex-grow-1"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4 mb-5">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-muted">
                    <tr>
                        <th class="ps-4 text-center" width="5%">No.</th>
                        <th width="20%">Nama Guru</th>
                        <th width="10%">Hari</th>
                        <th class="text-center" width="15%">Jam</th>
                        <th class="text-center" width="10%">Total JP</th>
                        <th width="20%">Mata Pelajaran</th>
                        <th width="10%">Kelas</th>
                        <th class="text-center pe-4" width="10%">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $nomor = 1; 
                    if($list_jadwal->num_rows > 0):
                    while($jadwal = $list_jadwal->fetch_assoc()): ?>
                    <tr>
                        <td class="ps-4 text-center fw-semibold text-muted"><?php echo $nomor; ?></td>
                        <td class="fw-bold text-dark"><?php echo htmlspecialchars($jadwal['nama_guru']); ?></td>
                        <td><span class="badge bg-primary bg-opacity-10 text-primary border border-primary"><?php echo htmlspecialchars($jadwal['hari']); ?></span></td>
                        <td class="text-center fw-semibold"><?php echo date('H:i', strtotime($jadwal['jam_mulai'])) . ' - ' . date('H:i', strtotime($jadwal['jam_selesai'])); ?></td>
                        
                        <td class="text-center"><span class="badge bg-success rounded-pill"><?php echo function_exists('hitungJP') ? hitungJP($jadwal['jam_mulai'], $jadwal['jam_selesai']) : 0; ?> JP</span></td>
                        
                        <td class="text-secondary"><?php echo htmlspecialchars($jadwal['mata_pelajaran']); ?></td>
                        <td class="fw-bold"><?php echo htmlspecialchars($jadwal['kelas']); ?></td>
                        <td class="text-center pe-4">
                            <button class="btn btn-sm btn-outline-secondary rounded-circle me-1 btn-edit" 
                                    data-id="<?php echo $jadwal['id']; ?>" 
                                    data-guru_id="<?php echo $jadwal['guru_id']; ?>"
                                    data-hari="<?php echo $jadwal['hari']; ?>"
                                    data-jam_mulai="<?php echo date('H:i', strtotime($jadwal['jam_mulai'])); ?>"
                                    data-jam_selesai="<?php echo date('H:i', strtotime($jadwal['jam_selesai'])); ?>"
                                    data-mata_pelajaran="<?php echo htmlspecialchars($jadwal['mata_pelajaran']); ?>"
                                    data-kelas="<?php echo htmlspecialchars($jadwal['kelas']); ?>"
                                    data-status_jadwal="<?php echo $jadwal['status_jadwal']; ?>"
                                    data-bs-toggle="modal" data-bs-target="#jadwalModal" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <a href="jadwal_mengajar.php?hapus=<?php echo $jadwal['id']; ?>" class="btn btn-sm btn-outline-danger rounded-circle" onclick="return confirm('Yakin ingin menghapus jadwal ini?')" title="Hapus">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php $nomor++; endwhile; else: ?>
                        <tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-calendar-x fs-1 d-block opacity-50 mb-2"></i> Belum ada jadwal yang ditemukan</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="jadwalModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow rounded-4">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold text-primary" id="jadwalModalLabel"><i class="bi bi-pencil-square me-2"></i> Form Jadwal Mengajar</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="jadwal_mengajar.php">
          <div class="modal-body">
              <input type="hidden" name="id" id="modal-id">
              <input type="hidden" name="status_jadwal" id="modal-status_jadwal" value="Aktif">
              <div class="mb-3">
                  <label for="modal-guru_id" class="form-label fw-semibold text-muted">Guru Pengajar <span class="text-danger">*</span></label>
                  <select class="form-select bg-light" name="guru_id" id="modal-guru_id" required>
                      <option value="">-- Pilih Guru --</option>
                      <?php foreach ($semua_guru as $guru): ?>
                      <option value="<?php echo $guru['id']; ?>"><?php echo htmlspecialchars($guru['nama_guru']); ?></option>
                      <?php endforeach; ?>
                  </select>
              </div>
              <div class="mb-3">
                  <label for="modal-hari" class="form-label fw-semibold text-muted">Hari Mengajar <span class="text-danger">*</span></label>
                  <select class="form-select bg-light" name="hari" id="modal-hari" required>
                      <?php foreach ($daftar_hari as $hari): ?>
                          <option value="<?php echo $hari; ?>"><?php echo $hari; ?></option>
                      <?php endforeach; ?>
                  </select>
              </div>
              <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="modal-jam_mulai" class="form-label fw-semibold text-muted">Jam Mulai <span class="text-danger">*</span></label>
                    <input type="time" class="form-control border-primary" name="jam_mulai" id="modal-jam_mulai" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="modal-jam_selesai" class="form-label fw-semibold text-muted">Jam Selesai <span class="text-danger">*</span></label>
                    <input type="time" class="form-control border-warning" name="jam_selesai" id="modal-jam_selesai" required>
                </div>
              </div>
              <div class="mb-3">
                  <label for="modal-mata_pelajaran" class="form-label fw-semibold text-muted">Mata Pelajaran <span class="text-danger">*</span></label>
                  <input type="text" class="form-control bg-light" name="mata_pelajaran" id="modal-mata_pelajaran" placeholder="Contoh: Matematika" required>
              </div>
              <div class="mb-3">
                  <label for="modal-kelas" class="form-label fw-semibold text-muted">Kelas Tujuan <span class="text-danger">*</span></label>
                  <input type="text" class="form-control bg-light" name="kelas" id="modal-kelas" placeholder="Contoh: 10-DKV" required>
              </div>
          </div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
            <button type="submit" name="simpan_jadwal" class="btn btn-primary rounded-pill px-4 fw-bold">Simpan Jadwal</button>
          </div>
      </form>
    </div>
  </div>
</div>

<?php ob_start(); ?>
<script>
// Skrip ini membersihkan parameter '?hapus=' dari URL agar jika di-refresh, tidak menghapus data lagi
if (window.history.replaceState) {
    const url = new URL(window.location);
    if (url.searchParams.has('hapus')) {
        url.searchParams.delete('hapus');
        window.history.replaceState(null, null, url.pathname + url.search);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const jadwalModal = document.getElementById('jadwalModal');
    
    const resetForm = () => {
        document.getElementById('jadwalModalLabel').innerHTML = '<i class="bi bi-plus-circle me-2"></i> Tambah Jadwal Baru';
        document.getElementById('modal-id').value = '';
        document.getElementById('modal-guru_id').value = '';
        document.getElementById('modal-hari').value = 'Senin';
        document.getElementById('modal-jam_mulai').value = '';
        document.getElementById('modal-jam_selesai').value = '';
        document.getElementById('modal-mata_pelajaran').value = '';
        document.getElementById('modal-kelas').value = '';
        document.getElementById('modal-status_jadwal').value = 'Aktif';
    };

    if(jadwalModal) {
        jadwalModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            
            if (button.classList.contains('btn-edit')) {
                document.getElementById('jadwalModalLabel').innerHTML = '<i class="bi bi-pencil-square me-2"></i> Edit Jadwal Mengajar';
                document.getElementById('modal-id').value = button.getAttribute('data-id');
                document.getElementById('modal-guru_id').value = button.getAttribute('data-guru_id');
                document.getElementById('modal-hari').value = button.getAttribute('data-hari');
                document.getElementById('modal-jam_mulai').value = button.getAttribute('data-jam_mulai');
                document.getElementById('modal-jam_selesai').value = button.getAttribute('data-jam_selesai');
                document.getElementById('modal-mata_pelajaran').value = button.getAttribute('data-mata_pelajaran');
                document.getElementById('modal-kelas').value = button.getAttribute('data-kelas');
                document.getElementById('modal-status_jadwal').value = button.getAttribute('data-status_jadwal');
            } else {
                resetForm();
            }
        });

        jadwalModal.addEventListener('hidden.bs.modal', function () {
            resetForm();
        });
    }
});
</script>
<?php
$custom_script = ob_get_clean();
include 'partials/footer.php'; 
?>