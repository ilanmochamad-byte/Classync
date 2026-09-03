<?php
date_default_timezone_set('Asia/Jakarta');
require_once 'includes/db.php';

// --- PENGATURAN PAGINASI ---
$limit = 15; // Jumlah data per halaman (Ubah menjadi 50 jika ingin lebih panjang)
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// --- FILTER DATA ---
$filter_bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$filter_tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');
$filter_lokasi = isset($_GET['lokasi_id']) ? (int)$_GET['lokasi_id'] : 0;
$filter_kelas = isset($_GET['kelas']) ? trim($_GET['kelas']) : '';
$search_nama = isset($_GET['search']) ? trim($_GET['search']) : '';

// Ambil daftar lokasi PKL untuk dropdown
$list_lokasi = $conn->query("SELECT id, nama_instansi FROM lokasi_pkl ORDER BY nama_instansi ASC");

// Ambil daftar kelas siswa PKL (Hanya yang terdaftar PKL)
$list_kelas = $conn->query("
    SELECT DISTINCT s.kelas 
    FROM siswa s 
    JOIN penempatan_pkl p ON s.id = p.siswa_id 
    ORDER BY s.kelas ASC
");

// --- MEMBANGUN KLAUSA WHERE DINAMIS ---
$where_clause = "WHERE MONTH(a.tanggal) = ? AND YEAR(a.tanggal) = ?";
$params_where = [$filter_bulan, $filter_tahun];
$types_where = "ii";

if ($filter_lokasi > 0) {
    $where_clause .= " AND l.id = ?";
    $params_where[] = $filter_lokasi;
    $types_where .= "i";
}

if (!empty($filter_kelas)) {
    $where_clause .= " AND s.kelas = ?";
    $params_where[] = $filter_kelas;
    $types_where .= "s";
}

if (!empty($search_nama)) {
    $where_clause .= " AND (s.nama_siswa LIKE ? OR s.nisn LIKE ?)";
    $search_param = "%" . $search_nama . "%";
    $params_where[] = $search_param;
    $params_where[] = $search_param;
    $types_where .= "ss";
}

// --- 1. MENGHITUNG TOTAL DATA (UNTUK PAGINASI) ---
$sql_count = "SELECT COUNT(a.id) as total_data 
              FROM absensi_siswa a
              JOIN siswa s ON a.siswa_id = s.id
              JOIN penempatan_pkl p ON s.id = p.siswa_id
              JOIN lokasi_pkl l ON p.lokasi_id = l.id 
              $where_clause";

$stmt_count = $conn->prepare($sql_count);

// PERBAIKAN FATAL ERROR: Membangun array of references untuk PHP 8.1+
$refs_count = [];
foreach ($params_where as $key => $value) {
    $refs_count[$key] = &$params_where[$key];
}
$stmt_count->bind_param($types_where, ...$refs_count);

$stmt_count->execute();
$res_count = $stmt_count->get_result()->fetch_assoc();
$total_data = $res_count['total_data'];
$total_pages = ceil($total_data / $limit);
$stmt_count->close();

// --- 2. KUERI UTAMA REKAP PKL DENGAN LIMIT & OFFSET ---
$sql_data = "SELECT 
            a.id, a.tanggal, a.waktu_masuk, a.waktu_pulang, a.foto_masuk, a.foto_pulang, a.status_masuk,
            s.nama_siswa, s.kelas, s.nisn, l.nama_instansi
        FROM absensi_siswa a
        JOIN siswa s ON a.siswa_id = s.id
        JOIN penempatan_pkl p ON s.id = p.siswa_id
        JOIN lokasi_pkl l ON p.lokasi_id = l.id
        $where_clause
        ORDER BY a.tanggal DESC, a.waktu_masuk DESC 
        LIMIT ?, ?";

// Tambahkan offset dan limit ke parameter
$params_data = $params_where;
$params_data[] = $offset;
$params_data[] = $limit;
$types_data = $types_where . "ii";

$stmt_data = $conn->prepare($sql_data);

// PERBAIKAN FATAL ERROR: Membangun array of references untuk parameter data
$refs_data = [];
foreach ($params_data as $key => $value) {
    $refs_data[$key] = &$params_data[$key];
}
$stmt_data->bind_param($types_data, ...$refs_data);

$stmt_data->execute();
$rekap_result = $stmt_data->get_result();

$bulan_nama = [1=>'Januari', 2=>'Februari', 3=>'Maret', 4=>'April', 5=>'Mei', 6=>'Juni', 7=>'Juli', 8=>'Agustus', 9=>'September', 10=>'Oktober', 11=>'November', 12=>'Desember'];

// Fungsi pembantu untuk membuat URL paginasi (mempertahankan filter yang ada)
function buildPageUrl($page_num) {
    $params = $_GET;
    $params['page'] = $page_num;
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Absensi PKL Siswa - SMK Terpadu Al Hasan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; color: #2c3e50; }
        .header-section { background: linear-gradient(135deg, #1e3c72, #2a5298); color: white; padding: 2.5rem 1rem; border-radius: 0 0 25px 25px; }
        .card-custom { border-radius: 16px; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .thumb-img { width: 50px; height: 50px; object-fit: cover; border-radius: 8px; cursor: pointer; border: 2px solid #e9ecef; }
        .thumb-img:hover { border-color: #0d6efd; transform: scale(1.05); transition: all 0.2s; }
        .pagination .page-link { color: #1e3c72; border-radius: 8px; margin: 0 3px; border: none; font-weight: 500;}
        .pagination .page-item.active .page-link { background-color: #1e3c72; color: white; }
        .pagination .page-item.disabled .page-link { background-color: #f8f9fa; color: #6c757d; }
        @media print {
            .no-print { display: none !important; }
            .header-section { background: none; color: black; padding: 1rem 0; }
            .card-custom { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>

    <div class="header-section text-center shadow-sm">
        <div class="container">
            <i class="bi bi-buildings-fill text-warning mb-2" style="font-size: 2.5rem;"></i>
            <h3 class="fw-bold mb-1">Laporan Rekapitulasi Absensi PKL</h3>
            <p class="mb-0 opacity-75">Monitoring Kehadiran Praktik Kerja Lapangan Siswa SMK Terpadu Al Hasan</p>
        </div>
    </div>

    <div class="container my-4">
        <div class="card card-custom p-4 mb-4 no-print">
            <form method="GET" action="" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="1"> <div class="col-md-2 col-6">
                    <label class="form-label fw-semibold small text-muted">Bulan</label>
                    <select name="bulan" class="form-select border-primary">
                        <?php foreach($bulan_nama as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php if($filter_bulan == $k) echo 'selected'; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label fw-semibold small text-muted">Tahun</label>
                    <input type="number" name="tahun" class="form-control border-primary" value="<?php echo $filter_tahun; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted">Tempat PKL / Instansi</label>
                    <select name="lokasi_id" class="form-select border-primary">
                        <option value="0">-- Semua Tempat PKL --</option>
                        <?php while($lok = $list_lokasi->fetch_assoc()): ?>
                            <option value="<?php echo $lok['id']; ?>" <?php if($filter_lokasi == $lok['id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($lok['nama_instansi']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted">Kelas</label>
                    <select name="kelas" class="form-select border-primary">
                        <option value="">-- Semua PKL --</option>
                        <?php while($kls = $list_kelas->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($kls['kelas']); ?>" <?php if($filter_kelas == $kls['kelas']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($kls['kelas']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted">Cari Nama / NISN</label>
                    <input type="text" name="search" class="form-control border-primary" value="<?php echo htmlspecialchars($search_nama); ?>" placeholder="Ketik nama siswa...">
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end mt-3">
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold"><i class="bi bi-filter me-1"></i> Terapkan Filter</button>
                    <a href="rekap_pkl.php" class="btn btn-outline-secondary rounded-pill px-3"><i class="bi bi-arrow-counterclockwise me-1"></i> Reset</a>
                    <button type="button" onclick="window.print()" class="btn btn-success rounded-pill px-3"><i class="bi bi-printer-fill me-1"></i> Cetak</button>
                </div>
            </form>
        </div>

        <div class="card card-custom">
            <div class="card-header bg-white pt-3 pb-2 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold m-0"><i class="bi bi-list-check text-primary me-2"></i> Data Kehadiran PKL: <?php echo $bulan_nama[$filter_bulan] . " " . $filter_tahun; ?></h6>
                <span class="badge bg-primary rounded-pill px-3 py-2">Total: <?php echo $total_data; ?> Record</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted">
                            <tr>
                                <th class="ps-4" width="5%">No.</th>
                                <th width="15%">Tanggal</th>
                                <th width="20%">Nama Siswa</th>
                                <th width="20%">Lokasi PKL</th>
                                <th class="text-center" width="15%">Absen Masuk</th>
                                <th class="text-center" width="15%">Absen Pulang</th>
                                <th class="text-center pe-4" width="10%">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = $offset + 1; // Penomoran berlanjut antar halaman
                            if ($rekap_result->num_rows > 0):
                                while($row = $rekap_result->fetch_assoc()):
                                    $tgl = date('d/m/Y', strtotime($row['tanggal']));
                                    $jam_m = $row['waktu_masuk'] ? date('H:i', strtotime($row['waktu_masuk'])) : '--:--';
                                    $jam_p = $row['waktu_pulang'] ? date('H:i', strtotime($row['waktu_pulang'])) : '--:--';
                            ?>
                            <tr>
                                <td class="ps-4 fw-semibold text-muted"><?php echo $no++; ?></td>
                                <td><span class="fw-bold text-dark"><?php echo $tgl; ?></span></td>
                                <td>
                                    <strong class="text-primary d-block"><?php echo htmlspecialchars($row['nama_siswa']); ?></strong>
                                    <small class="text-muted"><i class="bi bi-mortarboard me-1"></i><?php echo htmlspecialchars($row['kelas']); ?> | NISN: <?php echo htmlspecialchars($row['nisn']); ?></small>
                                </td>
                                <td>
                                    <span class="fw-semibold text-dark d-block"><i class="bi bi-building me-1 text-secondary"></i><?php echo htmlspecialchars($row['nama_instansi']); ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="fw-bold d-block text-success"><?php echo $jam_m; ?></span>
                                    <?php if($row['foto_masuk'] && file_exists($row['foto_masuk'])): ?>
                                        <img src="<?php echo $row['foto_masuk']; ?>" class="thumb-img mt-1 shadow-sm" onclick="showPhoto('<?php echo $row['foto_masuk']; ?>', 'Foto Masuk - <?php echo htmlspecialchars(addslashes($row['nama_siswa'])); ?>')" title="Klik perbesar">
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="fw-bold d-block text-primary"><?php echo $jam_p; ?></span>
                                    <?php if($row['foto_pulang'] && file_exists($row['foto_pulang'])): ?>
                                        <img src="<?php echo $row['foto_pulang']; ?>" class="thumb-img mt-1 shadow-sm" onclick="showPhoto('<?php echo $row['foto_pulang']; ?>', 'Foto Pulang - <?php echo htmlspecialchars(addslashes($row['nama_siswa'])); ?>')" title="Klik perbesar">
                                    <?php endif; ?>
                                </td>
                                <td class="text-center pe-4">
                                    <?php if($row['status_masuk'] == 'Tepat Waktu'): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success px-2 py-1 rounded-pill">Tepat Waktu</span>
                                    <?php elseif($row['status_masuk'] == 'Terlambat'): ?>
                                        <span class="badge bg-warning bg-opacity-10 text-dark border border-warning px-2 py-1 rounded-pill">Terlambat</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary px-2 py-1 rounded-pill">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php 
                                endwhile; 
                            else: 
                            ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="bi bi-calendar-x fs-1 d-block mb-2 opacity-50"></i>
                                    Tidak ada data kehadiran PKL yang sesuai dengan filter.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="card-footer bg-white border-0 py-3 no-print">
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center mb-0">
                            <li class="page-item <?php if($page <= 1) echo 'disabled'; ?>">
                                <a class="page-link shadow-sm" href="<?php echo $page <= 1 ? '#' : buildPageUrl($page - 1); ?>"><i class="bi bi-chevron-left"></i></a>
                            </li>

                            <?php 
                            // Tampilkan nomor halaman (batasi tampilan max 5 angka agar tidak terlalu panjang)
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);

                            for ($i = $start_page; $i <= $end_page; $i++): 
                            ?>
                                <li class="page-item <?php if($page == $i) echo 'active'; ?>">
                                    <a class="page-link shadow-sm" href="<?php echo buildPageUrl($i); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>

                            <li class="page-item <?php if($page >= $total_pages) echo 'disabled'; ?>">
                                <a class="page-link shadow-sm" href="<?php echo $page >= $total_pages ? '#' : buildPageUrl($page + 1); ?>"><i class="bi bi-chevron-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <div class="modal fade" id="photoModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow rounded-4">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title fw-bold" id="photoModalLabel">Foto Bukti</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <img id="modalImg" src="" class="img-fluid rounded-4 shadow-sm" style="max-height: 400px; width: 100%; object-fit: cover;">
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showPhoto(src, title) {
            document.getElementById('modalImg').src = src;
            document.getElementById('photoModalLabel').textContent = title;
            var myModal = new bootstrap.Modal(document.getElementById('photoModal'));
            myModal.show();
        }
    </script>
</body>
</html>