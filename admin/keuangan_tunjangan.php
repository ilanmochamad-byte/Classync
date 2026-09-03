<?php 
include 'partials/header.php';

// --- LOGIKA SIMPAN DATA TUNJANGAN ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_tunjangan'])) {
    if (!isset($_POST['guru_id']) || empty($_POST['guru_id'])) {
        $pesan = "Terjadi kesalahan: Anda harus memilih guru terlebih dahulu.";
        $pesan_tipe = "danger";
    } else {
        $guru_id = (int)$_POST['guru_id'];
        $tunjangan = [
            'masa_kerja' => (int)($_POST['masa_kerja'] ?? 0),
            'jabatan' => (int)($_POST['jabatan'] ?? 0),
            'transportasi' => (int)($_POST['transportasi'] ?? 0),
            'suami_istri' => (int)($_POST['suami_istri'] ?? 0),
            'anak' => (int)($_POST['anak'] ?? 0),
            'wali_kelas' => (int)($_POST['wali_kelas'] ?? 0)
        ];

        $stmt = $conn->prepare("
            INSERT INTO tunjangan_guru (guru_id, masa_kerja, jabatan, transportasi, suami_istri, anak, wali_kelas) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            masa_kerja=VALUES(masa_kerja), jabatan=VALUES(jabatan), transportasi=VALUES(transportasi), 
            suami_istri=VALUES(suami_istri), anak=VALUES(anak), wali_kelas=VALUES(wali_kelas)
        ");
        
        $stmt->bind_param("iiiiiii", $guru_id, $tunjangan['masa_kerja'], $tunjangan['jabatan'], $tunjangan['transportasi'], $tunjangan['suami_istri'], $tunjangan['anak'], $tunjangan['wali_kelas']);
        
        if ($stmt->execute()) {
            $pesan = "Data tunjangan berhasil disimpan!";
            $pesan_tipe = "success";
        } else {
            $pesan = "Gagal menyimpan data: " . $stmt->error;
            $pesan_tipe = "danger";
        }
        $stmt->close();
    }
}

// --- PERBAIKAN FATAL BUG DI SINI ---
// Kita memilih kolom secara spesifik (t.masa_kerja dll) agar g.id tidak tertimpa t.id yang kosong
$list_guru = $conn->query("
    SELECT 
        g.id, 
        g.nama_guru, 
        t.masa_kerja, 
        t.jabatan, 
        t.transportasi, 
        t.suami_istri, 
        t.anak, 
        t.wali_kelas 
    FROM guru g 
    LEFT JOIN tunjangan_guru t ON g.id = t.guru_id 
    ORDER BY g.nama_guru ASC
");

$data_guru = [];
if ($list_guru) {
    while($row = $list_guru->fetch_assoc()) {
        $data_guru[] = $row;
    }
}
?>

<h1 class="mb-4">Manajemen Tunjangan Guru</h1>

<?php if(isset($pesan)): ?>
    <div class="alert alert-<?php echo $pesan_tipe; ?> alert-dismissible fade show" role="alert">
        <i class="bi <?php echo $pesan_tipe == 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> me-2"></i>
        <?php echo $pesan; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<button class="btn btn-primary mb-3 shadow-sm rounded-pill fw-bold" data-bs-toggle="modal" data-bs-target="#tunjanganModal">
    <i class="bi bi-plus-circle me-1"></i> Set Tunjangan Guru
</button>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">No.</th>
                        <th>Nama Guru</th>
                        <th>Masa Kerja</th>
                        <th>Jabatan</th>
                        <th>Transportasi Harian</th>
                        <th>Suami/Istri</th>
                        <th>Anak</th>
                        <th>Wali Kelas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $nomor = 1; foreach($data_guru as $guru): ?>
                    <tr>
                        <td class="ps-4"><?php echo $nomor; ?></td>
                        <td class="fw-bold"><?php echo htmlspecialchars($guru['nama_guru']); ?></td>
                        <td>Rp <?php echo number_format($guru['masa_kerja'] ?? 0); ?></td>
                        <td>Rp <?php echo number_format($guru['jabatan'] ?? 0); ?></td>
                        <td class="text-primary fw-bold">Rp <?php echo number_format($guru['transportasi'] ?? 0); ?></td>
                        <td>Rp <?php echo number_format($guru['suami_istri'] ?? 0); ?></td>
                        <td>Rp <?php echo number_format($guru['anak'] ?? 0); ?></td>
                        <td>Rp <?php echo number_format($guru['wali_kelas'] ?? 0); ?></td>
                    </tr>
                    <?php $nomor++; endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="tunjanganModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow rounded-4">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold text-primary" id="tunjanganModalLabel"><i class="bi bi-wallet2 me-2"></i> Set Tunjangan Guru</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="">
          <div class="modal-body">
              <div class="mb-4">
                  <label for="modal-guru_id" class="form-label fw-semibold text-muted">Pilih Guru <span class="text-danger">*</span></label>
                  <select name="guru_id" id="modal-guru_id" class="form-select form-select-lg bg-light" required>
                      <option value="">-- Ketuk untuk memilih Guru --</option>
                      <?php foreach ($data_guru as $guru): ?>
                          <option value="<?php echo $guru['id']; ?>"
                                  data-masa="<?php echo $guru['masa_kerja'] ?? 0; ?>"
                                  data-jabatan="<?php echo $guru['jabatan'] ?? 0; ?>"
                                  data-transport="<?php echo $guru['transportasi'] ?? 0; ?>"
                                  data-suami="<?php echo $guru['suami_istri'] ?? 0; ?>"
                                  data-anak="<?php echo $guru['anak'] ?? 0; ?>"
                                  data-wali="<?php echo $guru['wali_kelas'] ?? 0; ?>">
                              <?php echo htmlspecialchars($guru['nama_guru']); ?>
                          </option>
                      <?php endforeach; ?>
                  </select>
              </div>
              
              <div class="alert alert-warning border-0 rounded-3 small py-2 mb-3">
                  <i class="bi bi-info-circle-fill me-2"></i> Nominal akan terisi otomatis jika guru sudah pernah di-set sebelumnya.
              </div>

              <div class="row g-3">
                <div class="col-md-6"><label class="form-label fw-semibold">Masa Kerja</label>
                    <div class="input-group"><span class="input-group-text bg-light border-end-0">Rp</span><input type="number" name="masa_kerja" id="modal-masa_kerja" class="form-control border-start-0" value="0" required></div>
                </div>
                <div class="col-md-6"><label class="form-label fw-semibold">Jabatan</label>
                    <div class="input-group"><span class="input-group-text bg-light border-end-0">Rp</span><input type="number" name="jabatan" id="modal-jabatan" class="form-control border-start-0" value="0" required></div>
                </div>
                <div class="col-md-6"><label class="form-label fw-bold text-primary">Transportasi (Per Hari)</label>
                    <div class="input-group"><span class="input-group-text bg-primary text-white border-primary border-end-0">Rp</span><input type="number" name="transportasi" id="modal-transportasi" class="form-control border-primary border-start-0 fw-bold" value="0" required></div>
                </div>
                <div class="col-md-6"><label class="form-label fw-semibold">Suami/Istri</label>
                    <div class="input-group"><span class="input-group-text bg-light border-end-0">Rp</span><input type="number" name="suami_istri" id="modal-suami_istri" class="form-control border-start-0" value="0" required></div>
                </div>
                <div class="col-md-6"><label class="form-label fw-semibold">Anak</label>
                    <div class="input-group"><span class="input-group-text bg-light border-end-0">Rp</span><input type="number" name="anak" id="modal-anak" class="form-control border-start-0" value="0" required></div>
                </div>
                <div class="col-md-6"><label class="form-label fw-semibold">Wali Kelas</label>
                    <div class="input-group"><span class="input-group-text bg-light border-end-0">Rp</span><input type="number" name="wali_kelas" id="modal-wali_kelas" class="form-control border-start-0" value="0" required></div>
                </div>
              </div>
          </div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
            <button type="submit" name="simpan_tunjangan" class="btn btn-primary rounded-pill px-4 fw-bold"><i class="bi bi-save2 me-2"></i>Simpan Perubahan</button>
          </div>
      </form>
    </div>
  </div>
</div>

<?php ob_start(); ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectGuru = document.getElementById('modal-guru_id');
    
    if (selectGuru) {
        selectGuru.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            
            if (this.value) {
                // Tembakkan nilai atribut 'data-' ke kotak input
                document.getElementById('modal-masa_kerja').value = selectedOption.getAttribute('data-masa');
                document.getElementById('modal-jabatan').value = selectedOption.getAttribute('data-jabatan');
                document.getElementById('modal-transportasi').value = selectedOption.getAttribute('data-transport');
                document.getElementById('modal-suami_istri').value = selectedOption.getAttribute('data-suami');
                document.getElementById('modal-anak').value = selectedOption.getAttribute('data-anak');
                document.getElementById('modal-wali_kelas').value = selectedOption.getAttribute('data-wali');
            } else {
                // Reset ke 0
                document.getElementById('modal-masa_kerja').value = 0;
                document.getElementById('modal-jabatan').value = 0;
                document.getElementById('modal-transportasi').value = 0;
                document.getElementById('modal-suami_istri').value = 0;
                document.getElementById('modal-anak').value = 0;
                document.getElementById('modal-wali_kelas').value = 0;
            }
        });
    }
});
</script>
<?php 
$custom_script = ob_get_clean();
include 'partials/footer.php'; 
?>