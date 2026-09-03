<?php
// admin/laporan_absen_harian.php
include 'partials/header.php'; 

$filter_bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$filter_tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');
$bulan_indo = [1=>'Januari', 2=>'Februari', 3=>'Maret', 4=>'April', 5=>'Mei', 6=>'Juni', 7=>'Juli', 8=>'Agustus', 9=>'September', 10=>'Oktober', 11=>'November', 12=>'Desember'];

// Ambil Data Absensi Harian
$sql = "SELECT a.id, a.tanggal, a.jam_masuk, a.jam_pulang, a.bonus, g.nama_guru 
        FROM absensi_harian a
        JOIN guru g ON a.guru_id = g.id
        WHERE MONTH(a.tanggal) = ? AND YEAR(a.tanggal) = ?
        ORDER BY a.tanggal DESC, a.jam_masuk DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $filter_bulan, $filter_tahun);
$stmt->execute();
$result = $stmt->get_result();

$total_bonus = 0;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="fw-bold text-dark"><i class="bi bi-clock-history text-primary"></i> Riwayat Absen Harian</h2>
        <p class="text-muted">Kelola rekam jejak kedatangan dan kepulangan Guru (Sistem Geofencing).</p>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <form method="GET" action="laporan_absen_harian.php" class="row g-3 align-items-center">
            <div class="col-md-5">
                <label class="form-label fw-bold">Pilih Bulan</label>
                <select name="bulan" class="form-select">
                    <?php foreach($bulan_indo as $num => $nama): ?>
                        <option value="<?php echo $num; ?>" <?php echo ($filter_bulan == $num) ? 'selected' : ''; ?>><?php echo $nama; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Pilih Tahun</label>
                <select name="tahun" class="form-select">
                    <?php for($i = date('Y'); $i >= date('Y') - 2; $i--): ?>
                        <option value="<?php echo $i; ?>" <?php echo ($filter_tahun == $i) ? 'selected' : ''; ?>><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label d-none d-md-block">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Tampilkan</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-5">
    <div class="card-header bg-white pt-3 pb-2 border-bottom-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0">Tabel Riwayat - <?php echo $bulan_indo[$filter_bulan] . " " . $filter_tahun; ?></h6>
        <span class="badge bg-primary rounded-pill px-3 py-2"><?php echo $result->num_rows; ?> Data Terakhir</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Tanggal</th>
                        <th>Nama Guru</th>
                        <th class="text-center">Jam Masuk</th>
                        <th class="text-center">Jam Pulang</th>
                        <th class="text-end">Transport Diterima</th>
                        <th class="text-center pe-4">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($result->num_rows > 0): while($row = $result->fetch_assoc()): 
                        $total_bonus += $row['bonus'];
                    ?>
                    <tr id="row-<?php echo $row['id']; ?>">
                        <td class="ps-4 small fw-semibold text-muted"><?php echo date('d/m/Y', strtotime($row['tanggal'])); ?></td>
                        <td class="fw-bold text-dark"><?php echo htmlspecialchars($row['nama_guru']); ?></td>
                        <td class="text-center text-primary fw-bold" id="masuk-<?php echo $row['id']; ?>"><?php echo date('H:i:s', strtotime($row['jam_masuk'])); ?></td>
                        <td class="text-center <?php echo $row['jam_pulang'] ? 'text-warning' : 'text-danger fst-italic'; ?> fw-bold" id="pulang-<?php echo $row['id']; ?>">
                            <?php echo $row['jam_pulang'] ? date('H:i:s', strtotime($row['jam_pulang'])) : 'Belum'; ?>
                        </td>
                        <td class="text-end text-success fw-bold" id="bonus-<?php echo $row['id']; ?>">Rp <?php echo number_format($row['bonus'], 0, ',', '.'); ?></td>
                        <td class="text-center pe-4">
                            <button class="btn btn-sm btn-outline-secondary rounded-circle me-1" title="Edit" 
                                onclick="openEditModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['nama_guru']); ?>', '<?php echo $row['jam_masuk']; ?>', '<?php echo $row['jam_pulang']; ?>', <?php echo $row['bonus']; ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger rounded-circle" title="Hapus" 
                                onclick="confirmDelete(<?php echo $row['id']; ?>)">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="6" class="text-center text-muted py-5">Belum ada riwayat absensi harian pada periode ini.</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if($total_bonus > 0): ?>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="4" class="text-end fw-bold">Total Transport Dikeluarkan:</td>
                        <td class="text-end fw-bold text-success fs-5">Rp <?php echo number_format($total_bonus, 0, ',', '.'); ?></td>
                        <td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold text-primary"><i class="bi bi-pencil-square"></i> Edit Absensi</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="formEdit">
            <input type="hidden" id="edit_id">
            <div class="mb-3">
                <label class="form-label fw-semibold text-muted">Nama Guru</label>
                <input type="text" class="form-control bg-light" id="edit_nama" readonly>
            </div>
            <div class="row mb-3">
                <div class="col-6">
                    <label class="form-label fw-semibold text-muted">Jam Masuk</label>
                    <input type="time" step="1" class="form-control border-primary" id="edit_masuk" required>
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold text-muted">Jam Pulang</label>
                    <input type="time" step="1" class="form-control border-warning" id="edit_pulang">
                    <small class="text-muted" style="font-size:10px;">Kosongkan jika belum pulang</small>
                </div>
            </div>
            <div class="mb-2">
                <label class="form-label fw-semibold text-success">Transport Harian (Rp)</label>
                <input type="number" class="form-control border-success" id="edit_bonus" required>
            </div>
        </form>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary rounded-pill px-4" onclick="saveEdit()">Simpan Perubahan</button>
      </div>
    </div>
  </div>
</div>

<?php ob_start(); ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    const API_UPDATE = '../api/update_absen_harian.php';
    const API_DELETE = '../api/delete_absen_harian.php';

    // Buka Modal Edit
    function openEditModal(id, nama, masuk, pulang, bonus) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_nama').value = nama;
        document.getElementById('edit_masuk').value = masuk;
        document.getElementById('edit_pulang').value = pulang || '';
        document.getElementById('edit_bonus').value = bonus;
        new bootstrap.Modal(document.getElementById('editModal')).show();
    }

    // Simpan Perubahan (AJAX)
    function saveEdit() {
        const id = document.getElementById('edit_id').value;
        const masuk = document.getElementById('edit_masuk').value;
        let pulang = document.getElementById('edit_pulang').value;
        const bonus = document.getElementById('edit_bonus').value;

        if(pulang === '') pulang = null;

        Swal.fire({ title: 'Menyimpan...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); }});

        fetch(API_UPDATE, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, jam_masuk: masuk, jam_pulang: pulang, bonus: bonus })
        })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
                Swal.fire('Tersimpan!', 'Data berhasil diperbarui.', 'success');
                // Update UI Table langsung tanpa reload
                document.getElementById(`masuk-${id}`).innerText = masuk;
                document.getElementById(`pulang-${id}`).innerText = pulang ? pulang : 'Belum';
                document.getElementById(`pulang-${id}`).className = pulang ? 'text-center text-warning fw-bold' : 'text-center text-danger fst-italic fw-bold';
                document.getElementById(`bonus-${id}`).innerText = 'Rp ' + Number(bonus).toLocaleString('id-ID');
            } else {
                Swal.fire('Gagal!', data.message, 'error');
            }
        })
        .catch(err => Swal.fire('Error!', 'Terjadi kesalahan koneksi.', 'error'));
    }

    // Konfirmasi Hapus (AJAX)
    function confirmDelete(id) {
        Swal.fire({
            title: 'Hapus Riwayat?',
            text: "Data absen dan bonus untuk tanggal ini akan dihapus permanen!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Hapus!'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch(API_DELETE, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                })
                .then(res => res.json())
                .then(data => {
                    if(data.success) {
                        Swal.fire('Terhapus!', 'Riwayat absensi berhasil dihapus.', 'success');
                        document.getElementById(`row-${id}`).remove(); // Hilangkan baris
                    } else {
                        Swal.fire('Gagal!', data.message, 'error');
                    }
                });
            }
        });
    }
</script>
<?php 
$custom_script = ob_get_clean();
include 'partials/footer.php'; 
?>