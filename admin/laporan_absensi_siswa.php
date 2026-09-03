<?php
include 'partials/header.php';
require_once '../includes/wa_sender.php';

// --- LOGIKA KIRIM ULANG WA ABSENSI ---
if (isset($_GET['kirim_wa']) && !empty($_GET['kirim_wa']) && isset($_GET['tipe'])) {
    $id_wa = (int)$_GET['kirim_wa'];
    $tipe_wa = $_GET['tipe']; // 'masuk' atau 'pulang'

    // Ambil data absensi dan kontak orang tua siswa (tanpa SELECT *)
    $stmt_wa = $conn->prepare("
        SELECT
            a.id,
            a.siswa_id,
            a.tanggal,
            a.waktu_masuk,
            a.status_masuk,
            a.waktu_pulang,
            a.foto_masuk,
            a.foto_pulang,
            s.nama_siswa,
            s.kelas,
            s.kontak_ortu
        FROM absensi_siswa a
        JOIN siswa s ON a.siswa_id = s.id
        WHERE a.id = ?
    ");
    $stmt_wa->bind_param("i", $id_wa);
    $stmt_wa->execute();
    $data_wa = $stmt_wa->get_result()->fetch_assoc();

    if ($data_wa) {
        if (empty($data_wa['kontak_ortu'])) {
            $pesan = "Gagal mengirim WhatsApp: Nomor kontak orang tua/wali belum terdaftar pada data siswa.";
        } else {
            $nomor = formatNomorWA($data_wa['kontak_ortu']);
            $tgl_fmt = date('d/m/Y', strtotime($data_wa['tanggal']));

            if ($tipe_wa === 'masuk') {
                if (empty($data_wa['waktu_masuk'])) {
                    $pesan = "Gagal: Data waktu masuk tidak ditemukan untuk absensi ini.";
                } else {
                    $waktu_fmt = date('H:i', strtotime($data_wa['waktu_masuk']));
                    
                    $pesan_wa = "INFO ABSENSI SMK TERPADU AL HASAN\n\n";
                    $pesan_wa .= "Yth. Bpk/Ibu Wali dari:\n";
                    $pesan_wa .= "Nama: *" . $data_wa['nama_siswa'] . "*\n";
                    $pesan_wa .= "Kelas: " . $data_wa['kelas'] . "\n\n";
                    $pesan_wa .= "Diberitahukan bahwa hari ini (" . $tgl_fmt . ") Ananda telah melakukan absensi *MASUK* pada pukul *" . $waktu_fmt . " WIB*.\n";
                    $pesan_wa .= "Status Kehadiran: *" . $data_wa['status_masuk'] . "*\n\n";
                    $pesan_wa .= "Terima kasih.";

                    if (kirimNotifikasiWA($nomor, $pesan_wa)) {
                        $pesan = "Berhasil mengirim ulang laporan WA Masuk kepada orang tua dari " . htmlspecialchars($data_wa['nama_siswa']);
                    } else {
                        $pesan = "Gagal mengirim ulang WA Masuk. Periksa koneksi gateway Fonnte / log server.";
                    }
                }
            } elseif ($tipe_wa === 'pulang') {
                if (empty($data_wa['waktu_pulang'])) {
                    $pesan = "Gagal: Data waktu pulang tidak ditemukan untuk absensi ini.";
                } else {
                    $waktu_fmt = date('H:i', strtotime($data_wa['waktu_pulang']));
                    
                    $pesan_wa = "INFO ABSENSI SMK TERPADU AL HASAN\n\n";
                    $pesan_wa .= "Yth. Bpk/Ibu Wali dari:\n";
                    $pesan_wa .= "Nama: *" . $data_wa['nama_siswa'] . "*\n";
                    $pesan_wa .= "Kelas: " . $data_wa['kelas'] . "\n\n";
                    $pesan_wa .= "Diberitahukan bahwa hari ini (" . $tgl_fmt . ") Ananda telah melakukan absensi *PULANG* pada pukul *" . $waktu_fmt . " WIB*.\n\n";
                    $pesan_wa .= "Terima kasih.";

                    if (kirimNotifikasiWA($nomor, $pesan_wa)) {
                        $pesan = "Berhasil mengirim ulang laporan WA Pulang kepada orang tua dari " . htmlspecialchars($data_wa['nama_siswa']);
                    } else {
                        $pesan = "Gagal mengirim ulang WA Pulang. Periksa koneksi gateway Fonnte / log server.";
                    }
                }
            }
        }
    } else {
        $pesan = "Data absensi tidak ditemukan.";
    }
}

// --- LOGIKA HAPUS ABSENSI SISWA ---
if (isset($_GET['hapus']) && !empty($_GET['hapus'])) {
    $id_hapus = (int)$_GET['hapus'];
    // Ambil path foto sebelum menghapus
    $stmt_foto = $conn->prepare("SELECT foto_masuk, foto_pulang FROM absensi_siswa WHERE id = ?");
    $stmt_foto->bind_param("i", $id_hapus);
    $stmt_foto->execute();
    $foto_paths = $stmt_foto->get_result()->fetch_assoc();
    
    $stmt_hapus = $conn->prepare("DELETE FROM absensi_siswa WHERE id = ?");
    $stmt_hapus->bind_param("i", $id_hapus);
    if ($stmt_hapus->execute()) {
        // Hapus file foto jika ada
        if ($foto_paths) {
            if (!empty($foto_paths['foto_masuk']) && file_exists("../".$foto_paths['foto_masuk'])) {
                unlink("../".$foto_paths['foto_masuk']);
            }
            if (!empty($foto_paths['foto_pulang']) && file_exists("../".$foto_paths['foto_pulang'])) {
                unlink("../".$foto_paths['foto_pulang']);
            }
        }
        $pesan = "Data absensi siswa berhasil dihapus.";
    } else {
        $pesan = "Gagal menghapus data.";
    }
}

// --- LOGIKA EDIT ABSENSI SISWA ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_absensi_siswa'])) {
    $id_edit = (int)$_POST['id_absensi'];
    $tanggal = $_POST['tanggal'];
    $waktu_masuk = empty($_POST['waktu_masuk']) ? null : $_POST['waktu_masuk'];
    $waktu_pulang = empty($_POST['waktu_pulang']) ? null : $_POST['waktu_pulang'];
    $status_masuk = $_POST['status_masuk'];

    $stmt_edit = $conn->prepare("UPDATE absensi_siswa SET tanggal = ?, waktu_masuk = ?, waktu_pulang = ?, status_masuk = ? WHERE id = ?");
    $stmt_edit->bind_param("ssssi", $tanggal, $waktu_masuk, $waktu_pulang, $status_masuk, $id_edit);
    if ($stmt_edit->execute()) {
        $pesan = "Data absensi siswa berhasil diperbarui.";
    } else {
        $pesan = "Gagal memperbarui data: " . $stmt_edit->error;
    }
}

// Fungsi Paginasi
function renderPagination($currentPage, $totalPages) {
    if ($totalPages <= 1) return;
    $queryParams = $_GET;
    // Bersihkan parameter aksi dari query string paginasi
    unset($queryParams['hapus'], $queryParams['kirim_wa'], $queryParams['tipe']);
    
    echo '<nav aria-label="Page navigation"><ul class="pagination justify-content-end">';
    if ($currentPage > 1) {
        $queryParams['page'] = $currentPage - 1;
        echo "<li class='page-item'><a class='page-link' href='?".http_build_query($queryParams)."'>&laquo;</a></li>";
    }
    for ($i = 1; $i <= $totalPages; $i++) {
        $active = ($i == $currentPage) ? "active" : "";
        $queryParams['page'] = $i;
        echo "<li class='page-item {$active}'><a class='page-link' href='?".http_build_query($queryParams)."'>{$i}</a></li>";
    }
    if ($currentPage < $totalPages) {
        $queryParams['page'] = $currentPage + 1;
        echo "<li class='page-item'><a class='page-link' href='?".http_build_query($queryParams)."'>&raquo;</a></li>";
    }
    echo '</ul></nav>';
}

// Logika Filter & Paginasi
$filter_bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$filter_tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');

$limit = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// DIUBAH: boundary periode bulan [start, next_month_start)
$month_start = sprintf('%04d-%02d-01', $filter_tahun, $filter_bulan);
$next_month_start = date('Y-m-d', strtotime($month_start . ' +1 month'));

// Query untuk menghitung total data (index-friendly: range tanggal)
$sql_total = "
    SELECT COUNT(a.id) as total
    FROM absensi_siswa a
    WHERE a.tanggal >= ? AND a.tanggal < ?
";
$stmt_total = $conn->prepare($sql_total);
$stmt_total->bind_param("ss", $month_start, $next_month_start);
$stmt_total->execute();
$total_rows = (int)$stmt_total->get_result()->fetch_assoc()['total'];
$total_pages = max(1, (int)ceil($total_rows / $limit));

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}

// Query untuk mengambil data dengan limit (tanpa SELECT *)
$sql_data = "
    SELECT
        a.id,
        a.siswa_id,
        a.tanggal,
        a.waktu_masuk,
        a.status_masuk,
        a.waktu_pulang,
        a.foto_masuk,
        a.foto_pulang,
        s.nama_siswa,
        s.kelas
    FROM absensi_siswa a
    JOIN siswa s ON a.siswa_id = s.id
    WHERE a.tanggal >= ? AND a.tanggal < ?
    ORDER BY a.tanggal DESC, a.waktu_masuk DESC
    LIMIT ?, ?
";
$stmt_data = $conn->prepare($sql_data);
$stmt_data->bind_param("ssii", $month_start, $next_month_start, $offset, $limit);
$stmt_data->execute();
$list_absensi = $stmt_data->get_result();
?>

<h1 class="mb-4">Laporan Absensi Harian Siswa</h1>

<?php if(isset($pesan)): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <?php echo $pesan; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card mb-4 shadow-sm">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label for="bulan" class="form-label">Pilih Periode</label>
                <select name="bulan" id="bulan" class="form-select">
                    <?php for ($i=1; $i<=12; $i++){ echo "<option value='$i'".($filter_bulan==$i?'selected':'').">".date('F',mktime(0,0,0,$i,10))."</option>"; } ?>
                </select>
            </div>
            <div class="col-md-5">
                <input type="number" class="form-control" name="tahun" value="<?php echo $filter_tahun; ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Tampilkan</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle">
                <thead class="table-light">
                    <tr>
                        <th>No.</th>
                        <th>Tanggal</th>
                        <th>Nama Siswa</th>
                        <th>Kelas</th>
                        <th>Waktu Masuk</th>
                        <th>Status Masuk</th>
                        <th>Waktu Pulang</th>
                        <th>Foto Masuk</th>
                        <th>Foto Pulang</th>
                        <th style="min-width: 120px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $nomor = $offset + 1; while($row = $list_absensi->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $nomor++; ?></td>
                        <td><?php echo date('d M Y', strtotime($row['tanggal'])); ?></td>
                        <td><?php echo htmlspecialchars($row['nama_siswa']); ?></td>
                        <td><?php echo htmlspecialchars($row['kelas']); ?></td>
                        <td><?php echo $row['waktu_masuk'] ?? '-'; ?></td>
                        <td><span class="badge bg-<?php echo $row['status_masuk'] == 'Terlambat' ? 'danger' : 'success'; ?>"><?php echo $row['status_masuk']; ?></span></td>
                        <td><?php echo $row['waktu_pulang'] ?? '-'; ?></td>
                        <td><?php if($row['foto_masuk']) { ?><a href="../<?php echo $row['foto_masuk']; ?>" target="_blank">Lihat</a><?php } else { echo '-'; } ?></td>
                        <td><?php if($row['foto_pulang']) { ?><a href="../<?php echo $row['foto_pulang']; ?>" target="_blank">Lihat</a><?php } else { echo '-'; } ?></td>
                        <td>
                            <div class="btn-group btn-group-sm me-1" role="group">
                                <button type="button" class="btn btn-sm btn-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Kirim Ulang WA">
                                    <i class="bi bi-whatsapp"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow">
                                    <?php if (!empty($row['waktu_masuk'])): ?>
                                        <li>
                                            <a class="dropdown-item text-success" href="?kirim_wa=<?php echo $row['id']; ?>&tipe=masuk&bulan=<?php echo $filter_bulan; ?>&tahun=<?php echo $filter_tahun; ?>&page=<?php echo $page; ?>">
                                                <i class="bi bi-box-arrow-in-right me-1"></i> WA Masuk
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (!empty($row['waktu_pulang'])): ?>
                                        <li>
                                            <a class="dropdown-item text-primary" href="?kirim_wa=<?php echo $row['id']; ?>&tipe=pulang&bulan=<?php echo $filter_bulan; ?>&tahun=<?php echo $filter_tahun; ?>&page=<?php echo $page; ?>">
                                                <i class="bi bi-box-arrow-left me-1"></i> WA Pulang
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>

                            <button class="btn btn-sm btn-warning btn-edit me-1" data-bs-toggle="modal" data-bs-target="#editAbsenSiswaModal"
                                    data-id="<?php echo $row['id']; ?>"
                                    data-tanggal="<?php echo $row['tanggal']; ?>"
                                    data-waktu_masuk="<?php echo $row['waktu_masuk']; ?>"
                                    data-waktu_pulang="<?php echo $row['waktu_pulang']; ?>"
                                    data-status_masuk="<?php echo $row['status_masuk']; ?>"
                                    data-nama_siswa="<?php echo htmlspecialchars($row['nama_siswa']); ?>">
                                <i class="bi bi-pencil"></i>
                            </button>

                            <a href="?hapus=<?php echo $row['id']; ?>&bulan=<?php echo $filter_bulan; ?>&tahun=<?php echo $filter_tahun; ?>&page=<?php echo $page; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Yakin ingin menghapus data absensi ini?')">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php if($list_absensi->num_rows == 0): ?>
                    <tr><td colspan="10" class="text-center">Tidak ada data absensi untuk periode yang dipilih.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            <?php renderPagination($page, $total_pages); ?>
        </div>
    </div>
</div>

<div class="modal fade" id="editAbsenSiswaModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="editModalLabel">Edit Absensi</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
          <div class="modal-body">
              <input type="hidden" name="id_absensi" id="edit-id">
              <p>Mengedit absensi untuk: <strong id="edit-nama-siswa"></strong></p>
              <div class="mb-3">
                  <label for="edit-tanggal" class="form-label">Tanggal</label>
                  <input type="date" class="form-control" name="tanggal" id="edit-tanggal" required>
              </div>
              <div class="mb-3">
                  <label for="edit-waktu_masuk" class="form-label">Waktu Masuk</label>
                  <input type="time" class="form-control" name="waktu_masuk" id="edit-waktu_masuk" step="1">
              </div>
              <div class="mb-3">
                  <label for="edit-waktu_pulang" class="form-label">Waktu Pulang</label>
                  <input type="time" class="form-control" name="waktu_pulang" id="edit-waktu_pulang" step="1">
              </div>
              <div class="mb-3">
                  <label for="edit-status_masuk" class="form-label">Status Masuk</label>
                  <select class="form-select" name="status_masuk" id="edit-status_masuk" required>
                      <option value="Tepat Waktu">Tepat Waktu</option>
                      <option value="Terlambat">Terlambat</option>
                  </select>
              </div>
          </div>
          <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
              <button type="submit" name="edit_absensi_siswa" class="btn btn-primary">Simpan Perubahan</button>
          </div>
      </form>
    </div>
  </div>
</div>

<?php
ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const editModal = document.getElementById('editAbsenSiswaModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('edit-id').value = button.getAttribute('data-id');
            document.getElementById('edit-nama-siswa').textContent = button.getAttribute('data-nama_siswa');
            document.getElementById('edit-tanggal').value = button.getAttribute('data-tanggal');
            document.getElementById('edit-waktu_masuk').value = button.getAttribute('data-waktu_masuk');
            document.getElementById('edit-waktu_pulang').value = button.getAttribute('data-waktu_pulang');
            document.getElementById('edit-status_masuk').value = button.getAttribute('data-status_masuk');
        });
    }
});
</script>
<?php
$custom_script = ob_get_clean();
include 'partials/footer.php';
?>