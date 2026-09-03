<?php
// jurnal.php - Halaman Publik Jurnal Mengajar (Responsif Mobile & Tablet)

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set('display_errors', 0);

// --- KONFIGURASI DATABASE ---
require_once 'includes/db.php'; 

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
} catch (Exception $e) {
    die("Koneksi database gagal. Sistem sedang dalam perbaikan.");
}

// --- FILTER BULAN & TAHUN ---
$bulan_pilih = isset($_GET['bulan']) ? (int)$_GET['bulan'] : date('n');
$tahun_pilih = isset($_GET['tahun']) ? (int)$_GET['tahun'] : date('Y');

$bulan_indo = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

// --- QUERY AMBIL DATA JURNAL + REKAP KEHADIRAN ---
$sql = "SELECT 
            a.id, 
            a.waktu_absensi, 
            a.materi_pokok, 
            a.tujuan_pembelajaran, 
            a.kegiatan_pembelajaran, 
            a.catatan_refleksi, 
            a.penilaian_evaluasi, 
            a.foto_bukti, 
            g.nama_guru, 
            jm.mata_pelajaran, 
            jm.kelas,
            (SELECT COUNT(*) FROM detail_absensi_siswa WHERE absensi_guru_id = a.id AND status_kehadiran = 'Hadir') AS hadir,
            (SELECT COUNT(*) FROM detail_absensi_siswa WHERE absensi_guru_id = a.id AND status_kehadiran = 'Sakit') AS sakit,
            (SELECT COUNT(*) FROM detail_absensi_siswa WHERE absensi_guru_id = a.id AND status_kehadiran = 'Izin') AS izin,
            (SELECT COUNT(*) FROM detail_absensi_siswa WHERE absensi_guru_id = a.id AND status_kehadiran = 'Alpa') AS alpa
        FROM absensi a
        JOIN guru g ON a.guru_id = g.id
        JOIN jadwal_mengajar jm ON a.jadwal_id = jm.id
        WHERE a.tipe_absensi = 'mengajar' 
          AND MONTH(a.waktu_absensi) = ? 
          AND YEAR(a.waktu_absensi) = ?
        ORDER BY a.waktu_absensi DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $bulan_pilih, $tahun_pilih);
$stmt->execute();
$list_jurnal = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Jurnal Mengajar - SMK Terpadu Al Hasan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f5f7fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .navbar-brand { font-weight: 700; color: #4A90A4 !important; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        
        /* Tabel Styles */
        .table th { background-color: #4A90A4; color: white; font-weight: 600; white-space: nowrap; }
        .badge-kelas { background-color: #FF6B00; font-size: 0.8rem; }
        .rekap-mini { font-size: 0.75rem; margin-top: 5px; font-weight: 600; white-space: nowrap;}
        
        /* Buttons */
        .btn-baca { background-color: #4A90A4; color: white; border: none; white-space: nowrap; }
        .btn-baca:hover { background-color: #3A7A8E; color: white; }
        .btn-search { background-color: #4A90A4; color: white; }
        .btn-search:hover { background-color: #3A7A8E; color: white; }

        /* Penyesuaian khusus untuk Mobile (Layar kecil) */
        @media (max-width: 767px) {
            .page-title { font-size: 1.5rem; }
            .page-subtitle { font-size: 0.9rem; }
            .table-responsive { border-radius: 12px; }
            .table td { min-width: 120px; /* Mencegah kolom terlalu rapat di HP */ }
            .td-materi { min-width: 200px; } /* Kolom materi butuh ruang lebih */
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm mb-4">
    <div class="container">
        <a class="navbar-brand" href="#">
            <i class="bi bi-journal-bookmark-fill me-2"></i> Classync SMKTAH
        </a>
    </div>
</nav>

<div class="container mb-5 px-3 px-md-4">
    <div class="text-center mb-4">
        <h2 class="fw-bold text-dark page-title">Jurnal Mengajar Guru</h2>
        <p class="text-muted page-subtitle">Dokumentasi harian kegiatan belajar mengajar SMK Terpadu Al Hasan Ciamis.</p>
    </div>

    <div class="card mb-4">
        <div class="card-body p-3 p-md-4">
            <form method="GET" action="jurnal.php" class="row g-2 g-md-3 align-items-center justify-content-center">
                <div class="col-12 col-md-auto text-center text-md-start mb-2 mb-md-0">
                    <label class="col-form-label fw-bold">Periode:</label>
                </div>
                <div class="col-6 col-md-auto">
                    <select name="bulan" class="form-select w-100">
                        <?php for($i=1; $i<=12; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo ($i == $bulan_pilih) ? 'selected' : ''; ?>>
                                <?php echo $bulan_indo[$i]; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-6 col-md-auto">
                    <select name="tahun" class="form-select w-100">
                        <?php 
                        $tahun_sekarang = date('Y');
                        for($i = $tahun_sekarang; $i >= $tahun_sekarang - 1; $i--): 
                        ?>
                            <option value="<?php echo $i; ?>" <?php echo ($i == $tahun_pilih) ? 'selected' : ''; ?>>
                                <?php echo $i; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-12 col-md-auto mt-3 mt-md-0">
                    <button type="submit" class="btn btn-search w-100">
                        <i class="bi bi-search"></i> Tampilkan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="text-center py-3">No.</th>
                            <th class="py-3">Waktu</th>
                            <th class="py-3">Nama Guru</th>
                            <th class="py-3">Mapel & Kelas</th>
                            <th class="py-3 td-materi">Materi Pokok</th>
                            <th class="text-center py-3">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if($list_jurnal->num_rows > 0):
                            $nomor = 1; 
                            while($row = $list_jurnal->fetch_assoc()): 
                                $tanggal = date('d M Y, H:i', strtotime($row['waktu_absensi']));
                        ?>
                        <tr>
                            <td class="text-center"><?php echo $nomor++; ?></td>
                            <td>
                                <span class="fw-bold text-nowrap"><?php echo date('d/m/Y', strtotime($row['waktu_absensi'])); ?></span><br>
                                <span class="text-muted small text-nowrap"><i class="bi bi-clock"></i> <?php echo date('H:i', strtotime($row['waktu_absensi'])); ?> WIB</span>
                            </td>
                            <td class="fw-bold"><?php echo htmlspecialchars($row['nama_guru'] ?? ''); ?></td>
                            <td>
                                <span class="d-block text-nowrap"><?php echo htmlspecialchars($row['mata_pelajaran'] ?? ''); ?></span>
                                <span class="badge badge-kelas mb-1">Kelas <?php echo htmlspecialchars($row['kelas'] ?? ''); ?></span>
                                <div class="rekap-mini">
                                    <span class="text-success" title="Hadir">H:<?php echo $row['hadir']; ?></span> | 
                                    <span class="text-warning" title="Sakit">S:<?php echo $row['sakit']; ?></span> | 
                                    <span class="text-info" title="Izin">I:<?php echo $row['izin']; ?></span> | 
                                    <span class="text-danger" title="Alpa">A:<?php echo $row['alpa']; ?></span>
                                </div>
                            </td>
                            <td class="text-muted td-materi">
                                <?php 
                                    $materi = htmlspecialchars($row['materi_pokok'] ?? '-'); 
                                    echo (strlen($materi) > 40) ? substr($materi, 0, 40) . '...' : $materi;
                                ?>
                            </td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-baca rounded-pill px-3" 
                                    data-guru="<?php echo htmlspecialchars($row['nama_guru'] ?? ''); ?>"
                                    data-mapel="<?php echo htmlspecialchars($row['mata_pelajaran'] ?? ''); ?>"
                                    data-kelas="<?php echo htmlspecialchars($row['kelas'] ?? ''); ?>"
                                    data-waktu="<?php echo $tanggal; ?>"
                                    data-materi="<?php echo htmlspecialchars($row['materi_pokok'] ?? '-'); ?>"
                                    data-tujuan="<?php echo htmlspecialchars($row['tujuan_pembelajaran'] ?? '-'); ?>"
                                    data-kegiatan="<?php echo htmlspecialchars($row['kegiatan_pembelajaran'] ?? '-'); ?>"
                                    data-refleksi="<?php echo htmlspecialchars($row['catatan_refleksi'] ?? '-'); ?>"
                                    data-penilaian="<?php echo htmlspecialchars($row['penilaian_evaluasi'] ?? '-'); ?>"
                                    data-foto="<?php echo htmlspecialchars($row['foto_bukti'] ?? ''); ?>"
                                    data-hadir="<?php echo $row['hadir']; ?>"
                                    data-sakit="<?php echo $row['sakit']; ?>"
                                    data-izin="<?php echo $row['izin']; ?>"
                                    data-alpa="<?php echo $row['alpa']; ?>"
                                    data-bs-toggle="modal" data-bs-target="#jurnalModal">
                                    Baca
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="bi bi-folder2-open fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                <em>Tidak ada data jurnal mengajar pada periode ini.</em>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="jurnalModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-md-down">
    <div class="modal-content border-0">
      <div class="modal-header border-0 pb-0">
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-0 px-3 px-md-4 pb-4">
        
        <div class="text-center mb-4">
            <h4 class="fw-bold" style="color: #4A90A4;">Detail Jurnal Mengajar</h4>
        </div>

        <div class="row bg-light rounded-3 p-3 mb-3 mx-0">
            <div class="col-12 col-md-6 mb-2 mb-md-0"><small class="text-muted d-block">Guru Pengajar:</small><strong id="mod-guru" class="fs-6"></strong></div>
            <div class="col-12 col-md-6 mb-2 mb-md-0"><small class="text-muted d-block">Mapel (Kelas):</small><strong id="mod-mapel" class="fs-6"></strong></div>
            <div class="col-12 mt-md-2"><small class="text-muted d-block">Waktu Pelaksanaan:</small><strong id="mod-waktu" class="fs-6"></strong></div>
        </div>

        <h6 class="fw-bold text-dark border-bottom pb-2"><i class="bi bi-people-fill me-2" style="color: #FF6B00;"></i>Kehadiran Siswa</h6>
        <div class="row text-center mb-4 mx-0 g-2">
            <div class="col-6 col-md-3">
                <div class="p-2 bg-success bg-opacity-10 border border-success border-opacity-25 rounded-3 h-100">
                    <strong class="text-success d-block fs-4" id="mod-hadir">0</strong>
                    <small class="text-success fw-bold">Hadir</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-2 bg-warning bg-opacity-10 border border-warning border-opacity-25 rounded-3 h-100">
                    <strong class="text-warning d-block fs-4" id="mod-sakit">0</strong>
                    <small class="text-warning fw-bold">Sakit</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-2 bg-info bg-opacity-10 border border-info border-opacity-25 rounded-3 h-100">
                    <strong class="text-info d-block fs-4" id="mod-izin">0</strong>
                    <small class="text-info fw-bold">Izin</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-2 bg-danger bg-opacity-10 border border-danger border-opacity-25 rounded-3 h-100">
                    <strong class="text-danger d-block fs-4" id="mod-alpa">0</strong>
                    <small class="text-danger fw-bold">Alpa</small>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <h6 class="fw-bold text-dark border-bottom pb-2"><i class="bi bi-book me-2" style="color: #FF6B00;"></i>Materi Pokok</h6>
            <p id="mod-materi" class="text-muted ms-1"></p>
        </div>

        <div class="mb-3">
            <h6 class="fw-bold text-dark border-bottom pb-2"><i class="bi bi-bullseye me-2" style="color: #FF6B00;"></i>Tujuan Pembelajaran</h6>
            <p id="mod-tujuan" class="text-muted ms-1"></p>
        </div>

        <div class="mb-3">
            <h6 class="fw-bold text-dark border-bottom pb-2"><i class="bi bi-list-task me-2" style="color: #FF6B00;"></i>Kegiatan Pembelajaran</h6>
            <p id="mod-kegiatan" class="text-muted ms-1" style="white-space: pre-wrap;"></p>
        </div>

        <div class="mb-3">
            <h6 class="fw-bold text-dark border-bottom pb-2"><i class="bi bi-pencil-square me-2" style="color: #FF6B00;"></i>Refleksi & Penilaian</h6>
            <div class="bg-light p-3 rounded-3">
                <small class="text-dark fw-bold d-block mb-1">Catatan/Refleksi:</small>
                <p id="mod-refleksi" class="text-muted small mb-3" style="white-space: pre-wrap;"></p>
                <small class="text-dark fw-bold d-block mb-1">Evaluasi:</small>
                <p id="mod-penilaian" class="text-muted small mb-0" style="white-space: pre-wrap;"></p>
            </div>
        </div>

        <div class="text-center mt-4 mb-2">
            <h6 class="fw-bold text-dark border-bottom pb-2 text-start"><i class="bi bi-camera me-2" style="color: #FF6B00;"></i>Dokumentasi Kelas</h6>
            <img id="mod-foto" src="" alt="Foto Bukti" class="img-fluid rounded shadow mt-2" style="max-height: 400px; width: 100%; object-fit: contain; display: none;">
            <p id="mod-nofoto" class="text-muted fst-italic mt-3" style="display: none;">Tidak ada dokumentasi foto.</p>
        </div>

      </div>
      <div class="modal-footer d-md-none border-0 pt-0">
          <button type="button" class="btn btn-secondary w-100" data-bs-dismiss="modal">Tutup Detail</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const detailButtons = document.querySelectorAll('.btn-baca');
    detailButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Informasi Utama
            document.getElementById('mod-guru').textContent = this.getAttribute('data-guru');
            document.getElementById('mod-mapel').textContent = this.getAttribute('data-mapel') + ' (Kelas ' + this.getAttribute('data-kelas') + ')';
            document.getElementById('mod-waktu').textContent = this.getAttribute('data-waktu') + ' WIB';
            
            // Rekap Kehadiran
            document.getElementById('mod-hadir').textContent = this.getAttribute('data-hadir');
            document.getElementById('mod-sakit').textContent = this.getAttribute('data-sakit');
            document.getElementById('mod-izin').textContent = this.getAttribute('data-izin');
            document.getElementById('mod-alpa').textContent = this.getAttribute('data-alpa');

            // Isi Jurnal
            document.getElementById('mod-materi').innerText = this.getAttribute('data-materi');
            document.getElementById('mod-tujuan').innerText = this.getAttribute('data-tujuan');
            document.getElementById('mod-kegiatan').innerText = this.getAttribute('data-kegiatan');
            document.getElementById('mod-refleksi').innerText = this.getAttribute('data-refleksi');
            document.getElementById('mod-penilaian').innerText = this.getAttribute('data-penilaian');
            
            // Foto
            const fotoPath = this.getAttribute('data-foto');
            const imgEl = document.getElementById('mod-foto');
            const noImgEl = document.getElementById('mod-nofoto');
            
            if(fotoPath && fotoPath !== '') {
                imgEl.src = fotoPath; 
                imgEl.style.display = 'block';
                noImgEl.style.display = 'none';
            } else {
                imgEl.style.display = 'none';
                noImgEl.style.display = 'block';
            }
        });
    });
});
</script>
</body>
</html>