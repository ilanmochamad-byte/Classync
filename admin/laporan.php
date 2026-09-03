<?php
include 'partials/header.php';

// =================================================================
// --- BLOK LOGIKA EKSPOR EXCEL (WAJIB ADA DI PALING ATAS) ---
// =================================================================
if (isset($_GET['action']) && $_GET['action'] == 'export') {
    require '../vendor/autoload.php';
    
    $filter_bulan_export = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
    $filter_tahun_export = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');
    $filter_tipe_export = $_GET['tipe'] ?? '';
    
    $sql_export = "SELECT a.waktu_absensi, a.status, a.keterangan, g.nama_guru, a.tipe_absensi, COALESCE(CONCAT(jm.mata_pelajaran, ' - Kelas ', jm.kelas), CONCAT('Piket Sesi ', jp.sesi), je.nama_ekskul) as keterangan_jadwal
        FROM absensi a 
        JOIN guru g ON a.guru_id = g.id 
        LEFT JOIN jadwal_mengajar jm ON a.jadwal_id = jm.id AND a.tipe_absensi = 'mengajar'
        LEFT JOIN jadwal_piket jp ON a.jadwal_id = jp.id AND a.tipe_absensi = 'piket'
        LEFT JOIN jadwal_ekskul je ON a.jadwal_id = je.id AND a.tipe_absensi = 'ekskul'
        WHERE MONTH(a.waktu_absensi) = ? AND YEAR(a.waktu_absensi) = ?";
    
    $params_export = [$filter_bulan_export, $filter_tahun_export];
    $types_export = 'ii';

    if (!empty($filter_tipe_export)) {
        $sql_export .= " AND a.tipe_absensi = ?";
        $params_export[] = $filter_tipe_export;
        $types_export .= 's';
    }
    $sql_export .= " ORDER BY a.waktu_absensi ASC";

    $stmt_export = $conn->prepare($sql_export);
    $stmt_export->bind_param($types_export, ...$params_export);
    $stmt_export->execute();
    $result_export = $stmt_export->get_result();

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Laporan Absensi');

    $sheet->setCellValue('A1', 'No.')->setCellValue('B1', 'Waktu Absensi')->setCellValue('C1', 'Nama Guru')->setCellValue('D1', 'Tipe')->setCellValue('E1', 'Jadwal')->setCellValue('F1', 'Status')->setCellValue('G1', 'Keterangan');

    $rowNum = 2; $no = 1;
    while ($row_export = $result_export->fetch_assoc()) {
        $sheet->setCellValue('A' . $rowNum, $no++);
        $sheet->setCellValue('B' . $rowNum, $row_export['waktu_absensi']);
        $sheet->setCellValue('C' . $rowNum, $row_export['nama_guru']);
        $sheet->setCellValue('D' . $rowNum, $row_export['tipe_absensi']);
        $sheet->setCellValue('E' . $rowNum, $row_export['keterangan_jadwal']);
        $sheet->setCellValue('F' . $rowNum, $row_export['status']);
        $sheet->setCellValue('G' . $rowNum, $row_export['keterangan']);
        $rowNum++;
    }
    
    foreach (range('A', 'G') as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }

    ob_end_clean(); 
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Laporan Absensi Guru - '.date('F Y', mktime(0,0,0, $filter_bulan_export, 1, $filter_tahun_export)).'.xlsx"');
    header('Cache-Control: max-age=0');
    
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

// Fungsi Paginasi
function renderPagination($currentPage, $totalPages) {
    if ($totalPages <= 1) return;
    $queryParams = $_GET;
    $window      = 2; // Tampilkan ±2 halaman dari halaman aktif
    echo '<nav aria-label="Page navigation"><ul class="pagination justify-content-end">';

    // Tombol Prev
    if ($currentPage > 1) {
        $queryParams['page'] = $currentPage - 1;
        echo "<li class='page-item'><a class='page-link' href='?" . http_build_query($queryParams) . "'>&laquo;</a></li>";
    } else {
        echo "<li class='page-item disabled'><a class='page-link' href='#'>&laquo;</a></li>";
    }

    $from = max(1, $currentPage - $window);
    $to   = min($totalPages, $currentPage + $window);

    // Halaman pertama + ellipsis jika ada jarak
    if ($from > 1) {
        $queryParams['page'] = 1;
        echo "<li class='page-item'><a class='page-link' href='?" . http_build_query($queryParams) . "'>1</a></li>";
        if ($from > 2) {
            echo "<li class='page-item disabled'><a class='page-link' href='#'>…</a></li>";
        }
    }

    for ($i = $from; $i <= $to; $i++) {
        $active = ($i == $currentPage) ? "active" : "";
        $queryParams['page'] = $i;
        echo "<li class='page-item {$active}'><a class='page-link' href='?" . http_build_query($queryParams) . "'>{$i}</a></li>";
    }

    // Ellipsis + halaman terakhir jika ada jarak
    if ($to < $totalPages) {
        if ($to < $totalPages - 1) {
            echo "<li class='page-item disabled'><a class='page-link' href='#'>…</a></li>";
        }
        $queryParams['page'] = $totalPages;
        echo "<li class='page-item'><a class='page-link' href='?" . http_build_query($queryParams) . "'>{$totalPages}</a></li>";
    }

    // Tombol Next
    if ($currentPage < $totalPages) {
        $queryParams['page'] = $currentPage + 1;
        echo "<li class='page-item'><a class='page-link' href='?" . http_build_query($queryParams) . "'>&raquo;</a></li>";
    } else {
        echo "<li class='page-item disabled'><a class='page-link' href='#'>&raquo;</a></li>";
    }
    echo '</ul></nav>';
}

// Logika Hapus Absensi
if (isset($_GET['hapus']) && !empty($_GET['hapus'])) {
    $id_hapus = (int)$_GET['hapus'];
    $stmt_hapus = $conn->prepare("DELETE FROM absensi WHERE id = ?");
    $stmt_hapus->bind_param("i", $id_hapus);
    if ($stmt_hapus->execute()) {
        $pesan = "Data absensi berhasil dihapus.";
    } else {
        $pesan = "Gagal menghapus data.";
    }
}

// Logika Edit Absensi
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_absensi'])) {
    $id_edit = $_POST['id_absensi'];
    $waktu_absensi = $_POST['waktu_absensi'];
    $status = $_POST['status'];
    $keterangan = $_POST['keterangan'];

    $stmt_edit = $conn->prepare("UPDATE absensi SET waktu_absensi = ?, status = ?, keterangan = ? WHERE id = ?");
    $stmt_edit->bind_param("sssi", $waktu_absensi, $status, $keterangan, $id_edit);
    if ($stmt_edit->execute()) {
        $pesan = "Data absensi berhasil diperbarui.";
    } else {
        $pesan = "Gagal memperbarui data: " . $stmt_edit->error;
    }
}


// --- LOGIKA FILTER, LIMIT, DAN PAGINASI ---
$filter_bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$filter_tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');
$filter_tipe = $_GET['tipe'] ?? '';
$allowed_limits = [10, 20, 50, 100];
$limit = isset($_GET['limit']) && in_array((int)$_GET['limit'], $allowed_limits) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;
$sql_base = " FROM absensi a JOIN guru g ON a.guru_id = g.id LEFT JOIN jadwal_mengajar jm ON a.jadwal_id=jm.id AND a.tipe_absensi='mengajar' LEFT JOIN jadwal_piket jp ON a.jadwal_id=jp.id AND a.tipe_absensi='piket' LEFT JOIN jadwal_ekskul je ON a.jadwal_id=je.id AND a.tipe_absensi='ekskul'";
$conditions = " WHERE MONTH(a.waktu_absensi) = ? AND YEAR(a.waktu_absensi) = ?";
$params = [$filter_bulan, $filter_tahun];
$types = 'ii';
if (!empty($filter_tipe)) {
    $conditions .= " AND a.tipe_absensi = ?";
    $params[] = $filter_tipe;
    $types .= 's';
}
$sql_total = "SELECT COUNT(a.id) as total " . $sql_base . $conditions;
$stmt_total = $conn->prepare($sql_total);
if(!empty($params)) $stmt_total->bind_param($types, ...$params);
$stmt_total->execute();
$total_rows = $stmt_total->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);
$sql_tampil = "SELECT a.*, g.nama_guru, COALESCE(CONCAT(jm.mata_pelajaran, ' - Kelas ', jm.kelas), CONCAT('Piket Sesi ', jp.sesi), je.nama_ekskul) as keterangan_jadwal " . $sql_base . $conditions . " ORDER BY a.waktu_absensi DESC LIMIT ?, ?";
$params[] = $offset; $params[] = $limit;
$types .= 'ii';
$stmt = $conn->prepare($sql_tampil);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
?>

<h1 class="mb-4">Laporan Absensi</h1>

<?php if(isset($pesan)): ?>
    <div class="alert alert-info"><?php echo $pesan; ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
         <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4"><label for="bulan" class="form-label">Bulan</label><select name="bulan" id="bulan" class="form-select"><?php for ($i = 1; $i <= 12; $i++): ?><option value="<?php echo $i; ?>" <?php if($filter_bulan == $i) echo 'selected'; ?>><?php echo date('F', mktime(0, 0, 0, $i, 10)); ?></option><?php endfor; ?></select></div>
            <div class="col-md-3"><label for="tahun" class="form-label">Tahun</label><input type="number" class="form-control" id="tahun" name="tahun" value="<?php echo $filter_tahun; ?>"></div>
            <div class="col-md-3"><label for="tipe" class="form-label">Tipe Absensi</label><select name="tipe" id="tipe" class="form-select"><option value="">Semua Tipe</option><option value="mengajar" <?php if($filter_tipe == 'mengajar') echo 'selected'; ?>>Mengajar</option><option value="piket" <?php if($filter_tipe == 'piket') echo 'selected'; ?>>Piket</option><option value="ekskul" <?php if($filter_tipe == 'ekskul') echo 'selected'; ?>>Ekstrakurikuler</option></select></div>
            <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Filter</button></div>
        </form>
        <hr>
        <div class="text-end">
            <?php $export_params = http_build_query(['action' => 'export', 'bulan' => $filter_bulan, 'tahun' => $filter_tahun, 'tipe' => $filter_tipe]); ?>
            <a href="?<?php echo $export_params; ?>" class="btn btn-success"><i class="bi bi-file-earmark-excel-fill"></i> Export ke Excel</a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <form method="GET" class="d-flex align-items-center">
                <input type="hidden" name="bulan" value="<?php echo htmlspecialchars($filter_bulan); ?>">
                <input type="hidden" name="tahun" value="<?php echo htmlspecialchars($filter_tahun); ?>">
                <input type="hidden" name="tipe" value="<?php echo htmlspecialchars($filter_tipe); ?>">
                <label for="limit" class="form-label me-2 mb-0">Tampil:</label>
                <select name="limit" id="limit" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                    <option value="10" <?php if ($limit == 10) echo 'selected'; ?>>10</option>
                    <option value="20" <?php if ($limit == 20) echo 'selected'; ?>>20</option>
                    <option value="50" <?php if ($limit == 50) echo 'selected'; ?>>50</option>
                    <option value="100" <?php if ($limit == 100) echo 'selected'; ?>>100</option>
                </select>
                <span class="ms-2">baris</span>
            </form>
            <div class="text-muted">Menampilkan <?php echo $result->num_rows ?> dari <?php echo $total_rows; ?> data</div>
        </div>
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                <th>No.</th>
                <th>Waktu</th>
                <th>Nama Guru</th>
                <th>Tipe</th>
                <th>Jadwal</th>
                <th>Status</th>
                <th>Keterangan</th>
                <th>Foto</th>
                <th>Aksi</th>
                </tr>
                </thead>
            <tbody>
                <?php $nomor =1; // PERUBAHAN 2: Inisiasi Counter
                if ($result->num_rows > 0): while($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $nomor++; ?></td>
                    <td><?php echo date('d M Y, H:i', strtotime($row['waktu_absensi'])); ?></td>
                    <td><?php echo htmlspecialchars($row['nama_guru']); ?></td>
                    <td><span class="badge bg-info"><?php echo ucfirst($row['tipe_absensi']); ?></span></td>
                    <td><?php echo htmlspecialchars($row['keterangan_jadwal']); ?></td>
                    <td><span class="badge bg-<?php echo $row['status'] == 'Hadir' ? 'success' : 'danger'; ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                    <td><?php echo htmlspecialchars($row['keterangan']); ?></td>
                    <td><?php if($row['foto_bukti']): ?><a href="../<?php echo $row['foto_bukti']; ?>" target="_blank">Lihat</a><?php else: ?>-<?php endif; ?></td>
                    <td>
                        <button class="btn btn-sm btn-warning btn-edit" data-bs-toggle="modal" data-bs-target="#editAbsenModal"
                                data-id="<?php echo $row['id']; ?>"
                                data-waktu="<?php echo date('Y-m-d\TH:i', strtotime($row['waktu_absensi'])); ?>"
                                data-status="<?php echo $row['status']; ?>"
                                data-keterangan="<?php echo htmlspecialchars($row['keterangan']); ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <a href="?hapus=<?php echo $row['id']; ?>&tanggal=<?php echo $filter_tanggal; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Yakin ingin menghapus data ini?')"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
                <?php endwhile; 
                else: ?>
                <tr>
                    <td colspan="8" class="text-center">Tidak ada data absensi.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3">
        <?php renderPagination($page, $total_pages); ?>
        </div>
    </div>
</div>

<div class="modal fade" id="editAbsenModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Edit Data Absensi</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
          <div class="modal-body">
              <input type="hidden" name="id_absensi" id="edit-id">
              <div class="mb-3"><label for="edit-waktu" class="form-label">Waktu Absensi</label><input type="datetime-local" class="form-control" name="waktu_absensi" id="edit-waktu" required></div>
              <div class="mb-3"><label for="edit-status" class="form-label">Status</label><select class="form-select" name="status" id="edit-status" required><option value="Hadir">Hadir</option><option value="Sakit">Sakit</option><option value="Izin">Izin</option><option value="Alpa">Alpa</option></select></div>
              <div class="mb-3"><label for="edit-keterangan" class="form-label">Keterangan</label><textarea class="form-control" name="keterangan" id="edit-keterangan" rows="3"></textarea></div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button type="submit" name="edit_absensi" class="btn btn-primary">Simpan</button></div>
      </form>
    </div>
  </div>
</div>

<?php
ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const editModal = document.getElementById('editAbsenModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('edit-id').value = button.getAttribute('data-id');
            document.getElementById('edit-waktu').value = button.getAttribute('data-waktu');
            document.getElementById('edit-status').value = button.getAttribute('data-status');
            document.getElementById('edit-keterangan').value = button.getAttribute('data-keterangan');
        });
    }
});
</script>
<?php
$custom_script = ob_get_clean();
include 'partials/footer.php';
?>