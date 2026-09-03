<?php 
include 'partials/header.php'; 

// Ambil daftar guru untuk dropdown
$guru_list = $conn->query("SELECT id, nama_guru FROM guru ORDER BY nama_guru ASC");
$gurus = $guru_list->fetch_all(MYSQLI_ASSOC);

// Logika untuk Tambah/Edit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['arsipkan_massal'])) {
    $guru_id = $_POST['guru_id'];
    $nama_ekskul = $_POST['nama_ekskul'];
    $hari = $_POST['hari'];
    $jam_mulai = $_POST['jam_mulai'];
    $jam_selesai = $_POST['jam_selesai'];
    $status_jadwal = $_POST['status_jadwal'] ?? 'Aktif';
    
    if (isset($_POST['id']) && !empty($_POST['id'])) { // Edit
        $id = $_POST['id'];
        $stmt = $conn->prepare("UPDATE jadwal_ekskul SET guru_id=?, nama_ekskul=?, hari=?, jam_mulai=?, jam_selesai=?, status_jadwal=? WHERE id=?");
        $stmt->bind_param("isssssi", $guru_id, $nama_ekskul, $hari, $jam_mulai, $jam_selesai, $status_jadwal, $id);
    } else { // Tambah
        $stmt = $conn->prepare("INSERT INTO jadwal_ekskul (guru_id, nama_ekskul, hari, jam_mulai, jam_selesai, status_jadwal) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssss", $guru_id, $nama_ekskul, $hari, $jam_mulai, $jam_selesai, $status_jadwal);
    }
    
    if($stmt->execute()) {
        $pesan = "Jadwal ekskul berhasil disimpan.";
        $pesan_tipe = "success";
    } else {
        $pesan = "Gagal menyimpan jadwal: " . $stmt->error;
        $pesan_tipe = "danger";
    }
}

// --- LOGIKA ARSIP MASSAL (UNTUK TAHUN PELAJARAN BARU) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['arsipkan_massal'])) {
    $update_arsip = $conn->query("UPDATE jadwal_ekskul SET status_jadwal = 'Arsip' WHERE status_jadwal = 'Aktif'");
    if ($update_arsip) {
        $pesan = "Berhasil! Semua jadwal ekskul aktif telah dipindahkan ke Arsip.";
        $pesan_tipe = "success";
        $_GET['status_jadwal'] = 'Arsip';
    } else {
        $pesan = "Gagal mengarsipkan jadwal ekskul.";
        $pesan_tipe = "danger";
    }
}

// Logika untuk Hapus
if (isset($_GET['hapus'])) {
    $id = $_GET['hapus'];
    $stmt = $conn->prepare("DELETE FROM jadwal_ekskul WHERE id = ?");
    $stmt->bind_param("i", $id);
    if($stmt->execute()) {
        $pesan = "Jadwal berhasil dihapus.";
        $pesan_tipe = "success";
    } else {
        $pesan = "Gagal menghapus jadwal.";
        $pesan_tipe = "danger";
    }
}

// --- LOGIKA FILTER STATUS ---
$filter_status = $_GET['status_jadwal'] ?? 'Aktif'; 

// Ambil data jadwal ekskul 
$sql = "SELECT je.*, g.nama_guru FROM jadwal_ekskul je JOIN guru g ON je.guru_id = g.id WHERE je.status_jadwal = ? ORDER BY g.nama_guru, FIELD(je.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'), je.jam_mulai";
$stmt_list = $conn->prepare($sql);
$stmt_list->bind_param("s", $filter_status);
$stmt_list->execute();
$list_jadwal = $stmt_list->get_result();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold text-dark m-0"><i class="bi bi-dribbble text-primary me-2"></i> Manajemen Jadwal Ekskul</h2>
    <div class="d-flex gap-2">
        <form method="POST" action="" onsubmit="return confirm('PENTING: Tindakan ini akan mengarsipkan seluruh jadwal Ekskul Aktif saat ini untuk pergantian tahun pelajaran. Lanjutkan?')">
            <button type="submit" name="arsipkan_massal" class="btn btn-warning rounded-pill shadow-sm fw-bold">
                <i class="bi bi-archive-fill"></i> Arsipkan Semua Jadwal
            </button>
        </form>
        <button class="btn btn-primary rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#jadwalModal">
            <i class="bi bi-plus-circle"></i> Tambah Ekskul
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

<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-body p-3">
        <form method="GET" class="row g-3 align-items-center">
            <div class="col-md-6">
                <label for="status_jadwal" class="form-label fw-semibold small text-muted mb-1">Pilih Status Jadwal Ekskul yang Ditampilkan:</label>
                <select name="status_jadwal" id="status_jadwal" class="form-select border-primary fw-bold" onchange="this.form.submit()">
                    <option value="Aktif" <?php if ($filter_status == 'Aktif') echo 'selected'; ?>>Jadwal Aktif (Saat Ini)</option>
                    <option value="Arsip" <?php if ($filter_status == 'Arsip') echo 'selected'; ?>>Jadwal Arsip (Masa Lalu)</option>
                </select>
            </div>
            <div class="col-md-6 text-end">
                <span class="badge bg-primary rounded-pill px-3 py-2">Total: <?php echo $list_jadwal->num_rows; ?> Jadwal</span>
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
                        <th width="25%">Nama Guru</th>
                        <th width="25%">Nama Ekskul</th>
                        <th width="15%">Hari</th>
                        <th class="text-center" width="15%">Jam</th>
                        <th class="text-center pe-4" width="15%">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $nomor = 1; 
                    if($list_jadwal->num_rows > 0):
                    while($jadwal = $list_jadwal->fetch_assoc()): ?>
                    <tr>
                        <td class="ps-4 text-center fw-semibold text-muted"><?php echo $nomor++; ?></td>
                        <td class="fw-bold text-dark"><?php echo htmlspecialchars($jadwal['nama_guru']); ?></td>
                        <td class="text-secondary"><?php echo htmlspecialchars($jadwal['nama_ekskul']); ?></td>
                        <td><span class="badge bg-primary bg-opacity-10 text-primary border border-primary"><?php echo htmlspecialchars($jadwal['hari']); ?></span></td>
                        <td class="text-center fw-semibold"><?php echo date('H:i', strtotime($jadwal['jam_mulai'])) . ' - ' . date('H:i', strtotime($jadwal['jam_selesai'])); ?></td>
                        <td class="text-center pe-4">
                            <button class="btn btn-sm btn-outline-secondary rounded-circle me-1 btn-edit" 
                                    data-id="<?php echo $jadwal['id']; ?>" 
                                    data-guru_id="<?php echo $jadwal['guru_id']; ?>"
                                    data-nama_ekskul="<?php echo htmlspecialchars($jadwal['nama_ekskul']); ?>"
                                    data-hari="<?php echo $jadwal['hari']; ?>"
                                    data-jam_mulai="<?php echo $jadwal['jam_mulai']; ?>"
                                    data-jam_selesai="<?php echo $jadwal['jam_selesai']; ?>"
                                    data-status_jadwal="<?php echo $jadwal['status_jadwal']; ?>"
                                    data-bs-toggle="modal" data-bs-target="#jadwalModal">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <a href="jadwal_ekskul.php?hapus=<?php echo $jadwal['id']; ?>" class="btn btn-sm btn-outline-danger rounded-circle" onclick="return confirm('Yakin ingin menghapus jadwal ini?')">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="6" class="text-center text-muted py-5"><i class="bi bi-calendar-x fs-1 d-block opacity-50 mb-2"></i> Belum ada jadwal yang ditemukan</td></tr>
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
        <h5 class="modal-title fw-bold text-primary" id="jadwalModalLabel"><i class="bi bi-pencil-square me-2"></i> Form Jadwal Ekstrakurikuler</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="jadwal_ekskul.php">
          <div class="modal-body">
              <input type="hidden" name="id" id="jadwal-id">
              <input type="hidden" name="status_jadwal" id="jadwal-status_jadwal" value="Aktif">
              
              <div class="mb-3">
                  <label for="guru_id" class="form-label fw-semibold text-muted">Guru Pembina <span class="text-danger">*</span></label>
                  <select class="form-select bg-light" name="guru_id" id="jadwal-guru_id" required>
                      <option value="">-- Pilih Guru --</option>
                      <?php foreach ($gurus as $guru): ?>
                      <option value="<?php echo $guru['id']; ?>"><?php echo htmlspecialchars($guru['nama_guru']); ?></option>
                      <?php endforeach; ?>
                  </select>
              </div>
               <div class="mb-3">
                  <label for="nama_ekskul" class="form-label fw-semibold text-muted">Nama Ekstrakurikuler <span class="text-danger">*</span></label>
                  <input type="text" class="form-control bg-light" name="nama_ekskul" id="jadwal-nama_ekskul" required placeholder="Contoh: Pramuka">
              </div>
              <div class="mb-3">
                  <label for="hari" class="form-label fw-semibold text-muted">Hari <span class="text-danger">*</span></label>
                  <select class="form-select bg-light" name="hari" id="jadwal-hari" required>
                      <option value="Senin">Senin</option>
                      <option value="Selasa">Selasa</option>
                      <option value="Rabu">Rabu</option>
                      <option value="Kamis">Kamis</option>
                      <option value="Jumat">Jumat</option>
                      <option value="Sabtu">Sabtu</option>
                      <option value="Minggu">Minggu</option>
                  </select>
              </div>
              <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="jam_mulai" class="form-label fw-semibold text-muted">Jam Mulai <span class="text-danger">*</span></label>
                    <input type="time" class="form-control border-primary" name="jam_mulai" id="jadwal-jam_mulai" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="jam_selesai" class="form-label fw-semibold text-muted">Jam Selesai <span class="text-danger">*</span></label>
                    <input type="time" class="form-control border-warning" name="jam_selesai" id="jadwal-jam_selesai" required>
                </div>
              </div>
          </div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Simpan</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const jadwalModal = document.getElementById('jadwalModal');
    jadwalModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const modalTitle = jadwalModal.querySelector('.modal-title');
        
        const fields = ['id', 'guru_id', 'nama_ekskul', 'hari', 'jam_mulai', 'jam_selesai', 'status_jadwal'];

        if (button.classList.contains('btn-edit')) {
            modalTitle.innerHTML = '<i class="bi bi-pencil-square me-2"></i> Edit Jadwal Ekskul';
            fields.forEach(field => {
                const input = document.getElementById(`jadwal-${field}`);
                if(input) input.value = button.getAttribute(`data-${field}`);
            });
        } else {
            modalTitle.innerHTML = '<i class="bi bi-plus-circle me-2"></i> Tambah Jadwal Ekskul';
            fields.forEach(field => {
                const input = document.getElementById(`jadwal-${field}`);
                if(input && field !== 'status_jadwal' && field !== 'hari') input.value = '';
                if(field === 'status_jadwal') input.value = 'Aktif';
                if(field === 'hari') input.value = 'Senin';
            });
        }
    });
});
</script>

<?php include 'partials/footer.php'; ?>