<?php 
include 'partials/header.php';

// Ambil daftar semua guru untuk dropdown
$guru_query = $conn->query("SELECT id, nama_guru FROM guru ORDER BY nama_guru ASC");
$semua_guru = $guru_query->fetch_all(MYSQLI_ASSOC);

// Default filter
$filter_bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$filter_tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');

// Proses simpan data potongan
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_potongan'])) {
    if (!isset($_POST['guru_id']) || !is_numeric($_POST['guru_id']) || $_POST['guru_id'] <= 0) {
        $pesan = "Terjadi kesalahan: Anda harus memilih guru terlebih dahulu.";
    } else {
        $guru_id = (int)$_POST['guru_id'];
        $bulan = (int)$_POST['bulan'];
        $tahun = (int)$_POST['tahun'];
        $arisan = (int)($_POST['arisan'] ?? 0);
        $tabungan = (int)($_POST['tabungan'] ?? 0);

        $stmt = $conn->prepare("
            INSERT INTO potongan_guru (guru_id, bulan, tahun, arisan, tabungan) 
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            arisan=VALUES(arisan), tabungan=VALUES(tabungan)
        ");
        $stmt->bind_param("iiiii", $guru_id, $bulan, $tahun, $arisan, $tabungan);
        
        if ($stmt->execute()) {
            $pesan = "Data potongan berhasil disimpan untuk periode yang dipilih.";
        } else {
            $pesan = "Gagal menyimpan data: " . $stmt->error;
        }
        $stmt->close();
        
        $filter_bulan = $bulan;
        $filter_tahun = $tahun;
    }
}

// Ambil data guru beserta potongannya untuk bulan & tahun yang difilter
$sql_potongan = "
    SELECT g.id, g.nama_guru, p.arisan, p.tabungan 
    FROM guru g 
    LEFT JOIN potongan_guru p ON g.id = p.guru_id AND p.bulan = ? AND p.tahun = ?
    ORDER BY g.nama_guru ASC
";
$stmt = $conn->prepare($sql_potongan);
$stmt->bind_param("ii", $filter_bulan, $filter_tahun);
$stmt->execute();
$list_guru = $stmt->get_result();
?>

<h1 class="mb-4">Input Potongan Bulanan Guru</h1>

<div class="card mb-4 shadow-sm">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-5"><label class="form-label">Pilih Periode</label><select name="bulan" class="form-select"><?php for ($i=1; $i<=12; $i++){ echo "<option value='$i'".($filter_bulan==$i?'selected':'').">".date('F',mktime(0,0,0,$i,10))."</option>"; } ?></select></div>
            <div class="col-md-5"><input type="number" class="form-control" name="tahun" value="<?php echo $filter_tahun; ?>"></div>
            <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Tampilkan</button></div>
        </form>
    </div>
</div>

<?php if(isset($pesan)): ?>
    <div class="alert alert-info"><?php echo $pesan; ?></div>
<?php endif; ?>

<button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#potonganModal">
    <i class="bi bi-plus-circle"></i> Input Potongan
</button>

<div class="card shadow-sm">
    <div class="card-body">
        <p class="text-muted">Menampilkan data untuk periode: <strong><?php echo date('F', mktime(0, 0, 0, $filter_bulan, 10)) . " " . $filter_tahun; ?></strong></p>
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>No.</th>
                        <th>Nama Guru</th>
                        <th>Potongan Arisan</th>
                        <th>Potongan Tabungan</th>
                        </tr>
                        </thead>
                <tbody>
                    <?php $nomor = 1; // PERUBAHAN 2: Inisiasi Counter
                    while($guru = $list_guru->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $nomor; ?></td>
                        <td><?php echo htmlspecialchars($guru['nama_guru']); ?></td>
                        <td>Rp <?php echo number_format($guru['arisan'] ?? 0); ?></td>
                        <td>Rp <?php echo number_format($guru['tabungan'] ?? 0); ?></td>
                    </tr>
                    <?php $nomor++; // PERUBAHAN 4: Increment Counter
                    endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="potonganModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="potonganModalLabel">Input Potongan Guru</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST" action="keuangan_potongan.php">
          <div class="modal-body">
              <div class="mb-3">
                  <label for="modal-guru_id" class="form-label">Pilih Guru</label>
                  <select name="guru_id" id="modal-guru_id" class="form-select" required>
                      <option value="">-- Pilih Guru --</option>
                      <?php foreach ($semua_guru as $guru): ?>
                          <option value="<?php echo $guru['id']; ?>"><?php echo htmlspecialchars($guru['nama_guru']); ?></option>
                      <?php endforeach; ?>
                  </select>
              </div>
              <input type="hidden" name="bulan" value="<?php echo $filter_bulan; ?>">
              <input type="hidden" name="tahun" value="<?php echo $filter_tahun; ?>">
              <p>Periode: <strong><?php echo date('F', mktime(0, 0, 0, $filter_bulan, 10)) . " " . $filter_tahun; ?></strong></p><hr>
              <div class="mb-3"><label for="modal-arisan" class="form-label">Potongan Arisan</label><input type="number" name="arisan" id="modal-arisan" class="form-control" value="0"></div>
              <div class="mb-3"><label for="modal-tabungan" class="form-label">Potongan Tabungan</label><input type="number" name="tabungan" id="modal-tabungan" class="form-control" value="0"></div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button type="submit" name="simpan_potongan" class="btn btn-primary">Simpan Potongan</button></div>
      </form>
    </div>
  </div>
</div>

<?php include 'partials/footer.php'; ?>