<?php 
require 'includes/db.php';
include 'includes/header.php'; 

// =================================================================
// FUNGSI PAGINASI BARU YANG LEBIH CERDAS
// =================================================================
function renderPagination($currentPage, $totalPages, $pageParamName) {
    if ($totalPages <= 1) {
        return;
    }

    // Ambil semua parameter URL yang sedang aktif
    $queryParams = $_GET;

    echo '<nav aria-label="Page navigation"><ul class="pagination justify-content-center">';

    // Tombol "Sebelumnya"
    if ($currentPage > 1) {
        $queryParams[$pageParamName] = $currentPage - 1;
        echo "<li class='page-item'><a class='page-link' href='?".http_build_query($queryParams)."'>Sebelumnya</a></li>";
    } else {
        echo "<li class='page-item disabled'><a class='page-link' href='#'>Sebelumnya</a></li>";
    }

    // Tombol Angka (bisa disederhanakan jika halaman terlalu banyak)
    for ($i = 1; $i <= $totalPages; $i++) {
        $active = ($i == $currentPage) ? "active" : "";
        $queryParams[$pageParamName] = $i;
        echo "<li class='page-item {$active}'><a class='page-link' href='?".http_build_query($queryParams)."'>{$i}</a></li>";
    }

    // Tombol "Selanjutnya"
    if ($currentPage < $totalPages) {
        $queryParams[$pageParamName] = $currentPage + 1;
        echo "<li class='page-item'><a class='page-link' href='?".http_build_query($queryParams)."'>Selanjutnya</a></li>";
    } else {
        echo "<li class='page-item disabled'><a class='page-link' href='#'>Selanjutnya</a></li>";
    }

    echo '</ul></nav>';
}
// --- AKHIR FUNGSI BARU ---

// --- PENGATURAN PAGINASI UNTUK SEMUA BAGIAN ---
$limit = 4;
$hari_ini = getNamaHariIndonesia(date('l'));

// Paginasi Jadwal Mengajar
$page_jadwal = isset($_GET['page_jadwal']) ? (int)$_GET['page_jadwal'] : 1;
$offset_jadwal = ($page_jadwal - 1) * $limit;

// Paginasi Jadwal Piket
$page_piket = isset($_GET['page_piket']) ? (int)$_GET['page_piket'] : 1;
$offset_piket = ($page_piket - 1) * $limit;

// Paginasi Jadwal Ekskul
$page_ekskul = isset($_GET['page_ekskul']) ? (int)$_GET['page_ekskul'] : 1;
$offset_ekskul = ($page_ekskul - 1) * $limit;

// Paginasi Laporan
$page_laporan = isset($_GET['page_laporan']) ? (int)$_GET['page_laporan'] : 1;
$offset_laporan = ($page_laporan - 1) * $limit;

// --- PENGAMBILAN DATA UNTUK SETIAP TAB ---
// 1. Data Jadwal Mengajar
$sql_total_jadwal = "SELECT COUNT(jm.id) as total FROM jadwal_mengajar jm WHERE jm.hari = ? AND status_jadwal = 'Aktif'";
$stmt_total_jadwal = $conn->prepare($sql_total_jadwal);
$stmt_total_jadwal->bind_param("s", $hari_ini);
$stmt_total_jadwal->execute();
$total_rows_jadwal = $stmt_total_jadwal->get_result()->fetch_assoc()['total'];
$total_pages_jadwal = ceil($total_rows_jadwal / $limit);
$sql_jadwal = "SELECT jm.jam_mulai, jm.jam_selesai, jm.mata_pelajaran, jm.kelas, g.nama_guru 
               FROM jadwal_mengajar jm 
               JOIN guru g ON jm.guru_id = g.id 
               WHERE jm.hari = ? 
               AND jm.status_jadwal = 'Aktif'
               ORDER BY jm.jam_mulai ASC
               LIMIT ?, ?";
$stmt_jadwal = $conn->prepare($sql_jadwal);
$stmt_jadwal->bind_param("sii", $hari_ini, $offset_jadwal, $limit);
$stmt_jadwal->execute();
$result_jadwal = $stmt_jadwal->get_result();

// 2. Data Jadwal Piket
$sql_total_piket = "SELECT COUNT(id) as total FROM jadwal_piket WHERE hari = ? AND status_jadwal = 'Aktif'";
$stmt_total_piket = $conn->prepare($sql_total_piket); $stmt_total_piket->bind_param("s", $hari_ini); $stmt_total_piket->execute();
$total_rows_piket = $stmt_total_piket->get_result()->fetch_assoc()['total'];
$total_pages_piket = ceil($total_rows_piket / $limit);
$sql_piket = "SELECT jp.*, g.nama_guru FROM jadwal_piket jp JOIN guru g ON jp.guru_id = g.id WHERE jp.hari = ? AND jp.status_jadwal = 'Aktif' ORDER BY g.nama_guru ASC LIMIT ?, ?";
$stmt_piket = $conn->prepare($sql_piket); $stmt_piket->bind_param("sii", $hari_ini, $offset_piket, $limit); $stmt_piket->execute();
$result_piket = $stmt_piket->get_result();

// 3. Data Jadwal Ekskul
$sql_total_ekskul = "SELECT COUNT(id) as total FROM jadwal_ekskul WHERE hari = ? AND status_jadwal = 'Aktif'";
$stmt_total_ekskul = $conn->prepare($sql_total_ekskul); $stmt_total_ekskul->bind_param("s", $hari_ini); $stmt_total_ekskul->execute();
$total_rows_ekskul = $stmt_total_ekskul->get_result()->fetch_assoc()['total'];
$total_pages_ekskul = ceil($total_rows_ekskul / $limit);
$sql_ekskul = "SELECT je.*, g.nama_guru FROM jadwal_ekskul je JOIN guru g ON je.guru_id = g.id WHERE je.hari = ? AND je.status_jadwal = 'Aktif' ORDER BY je.jam_mulai ASC LIMIT ?, ?";
$stmt_ekskul = $conn->prepare($sql_ekskul); $stmt_ekskul->bind_param("sii", $hari_ini, $offset_ekskul, $limit); $stmt_ekskul->execute();
$result_ekskul = $stmt_ekskul->get_result();

// --- BAGIAN LAPORAN KEHADIRAN DENGAN PAGINASI DENGAN STATUS---
// 1. Hitung total data untuk laporan
$sql_total_laporan = "SELECT COUNT(id) as total FROM absensi WHERE DATE(waktu_absensi) = CURDATE()";
$total_rows_laporan = $conn->query($sql_total_laporan)->fetch_assoc()['total'];
$total_pages_laporan = ceil($total_rows_laporan / $limit);

// 2. Ambil data laporan sesuai halaman (DIPERBARUI: MENDUKUNG BK)
$sql_laporan = "SELECT a.waktu_absensi, a.tipe_absensi, g.nama_guru, a.foto_bukti, a.status, 
                       jm.kelas, jbk.sasaran_layanan
                FROM absensi a
                JOIN guru g ON a.guru_id = g.id
                LEFT JOIN jadwal_mengajar jm ON a.jadwal_id = jm.id AND a.tipe_absensi = 'mengajar'
                LEFT JOIN jurnal_bk jbk ON a.id = jbk.absensi_guru_id AND a.tipe_absensi = 'bimbingan'
                WHERE DATE(a.waktu_absensi) = CURDATE()
                ORDER BY a.waktu_absensi DESC
                LIMIT ?, ?";
$stmt_laporan = $conn->prepare($sql_laporan);
$stmt_laporan->bind_param("ii", $offset_laporan, $limit);
$stmt_laporan->execute();
$result_laporan = $stmt_laporan->get_result();

// =================================================================
// LOGIKA BARU: Ambil data untuk Galeri Foto Hari Ini (DIPERBARUI: MENDUKUNG BK)
// =================================================================
$sql_galeri = "
    SELECT 
        a.foto_bukti,
        g.nama_guru,
        COALESCE(
            CONCAT(jm.mata_pelajaran, ' - Kelas ', jm.kelas), 
            CONCAT('Piket Sesi ', jp.sesi), 
            je.nama_ekskul,
            CONCAT('Layanan BK: ', jbk.topik_tema)
        ) as keterangan_jadwal
    FROM absensi a
    JOIN guru g ON a.guru_id = g.id
    LEFT JOIN jadwal_mengajar jm ON a.jadwal_id = jm.id AND a.tipe_absensi = 'mengajar'
    LEFT JOIN jadwal_piket jp ON a.jadwal_id = jp.id AND a.tipe_absensi = 'piket'
    LEFT JOIN jadwal_ekskul je ON a.jadwal_id = je.id AND a.tipe_absensi = 'ekskul'
    LEFT JOIN jurnal_bk jbk ON a.id = jbk.absensi_guru_id AND a.tipe_absensi = 'bimbingan'
    WHERE DATE(a.waktu_absensi) = CURDATE()
      AND a.foto_bukti IS NOT NULL 
      AND a.foto_bukti != ''
    ORDER BY a.waktu_absensi DESC
";
$result_galeri = $conn->query($sql_galeri);
// --- AKHIR LOGIKA BARU ---
?>

<div class="hero-section text-center">
    <div class="container">
        <h3 class="display-8"><img src="classync.png" alt="Logo Sekolah" height="60" class="me-2"> <br>Aktivitas Sekolah Hari Ini</h3>
        <p><strong><?php echo $hari_ini . ", " . date('d F Y'); ?></strong></p>
    </div>
</div>

<div class="container">
    <div class="row g-5">
        <div class="col-lg-6">
            <div class="info-card d-flex flex-column h-100">
                 <ul class="nav nav-tabs nav-fill" id="jadwalTab" role="tablist">
                <li class="nav-item" role="presentation"><button class="nav-link active" id="mengajar-tab" data-bs-toggle="tab" data-bs-target="#mengajar-pane" type="button" role="tab"><h6><i class="bi bi-calendar-day"></i> Pelajaran</h6></button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" id="piket-tab" data-bs-toggle="tab" data-bs-target="#piket-pane" type="button" role="tab"><h6><i class="bi bi-broadcast"></i> Guru Piket</h6></button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" id="ekskul-tab" data-bs-toggle="tab" data-bs-target="#ekskul-pane" type="button" role="tab"><h6><i class="bi bi-clipboard-data"></i> Ekstrakurikuler</h6></button></li>
            </ul>
            
            <div class="tab-content flex-grow-1" id="jadwalTabContent">
                <div class="tab-pane fade show active" id="mengajar-pane" role="tabpanel">
                    <ul class="list-group list-group-flush">
                        <?php if ($result_jadwal->num_rows > 0): ?>
                            <?php while($jadwal = $result_jadwal->fetch_assoc()): ?>
                                <li class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <div class="schedule-details">
                                            <h6><?php echo htmlspecialchars($jadwal['mata_pelajaran']); ?>
                                            <br>Kelas <?php echo htmlspecialchars($jadwal['kelas']); ?></h6>
                                            <p class="text-muted mb-0"><i class="bi bi-person-fill"></i> <?php echo htmlspecialchars($jadwal['nama_guru']); ?></p>
                                        </div>
                                        <div class="text-end">
                                            <span class="text-muted fw-bold d-block mb-1"><i class="bi bi-clock-fill"></i> <?php echo date('H:i', strtotime($jadwal['jam_mulai'])); ?></span>
                                            <span class="badge bg-info text-dark rounded-pill">
                                                <?php echo hitungJP($jadwal['jam_mulai'], $jadwal['jam_selesai']) . " JP"; ?>
                                            </span>
                                        </div>
                                    </div>
                                </li>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <li class="list-group-item text-center p-5">Tidak ada jadwal mengajar hari ini.</li>
                        <?php endif; ?>
                    </ul>
                    <div class="card-footer bg-white pt-3">
                        <?php renderPagination($page_jadwal, $total_pages_jadwal, 'page_jadwal'); ?>
                    </div>
                </div>

                <div class="tab-pane fade" id="piket-pane" role="tabpanel">
                    <ul class="list-group list-group-flush">
                         <?php if ($result_piket->num_rows > 0): while($piket = $result_piket->fetch_assoc()): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                                <div>
                                    <h6><?php echo htmlspecialchars($piket['nama_guru']); ?></h6>
                                    <p class="mb-1 text-muted">Sesi <?php echo htmlspecialchars($piket['sesi']); ?></p>
                                </div>
                            </li>
                         <?php endwhile; else: ?>
                            <li class="list-group-item text-center p-5">Tidak ada jadwal piket hari ini.</li>
                         <?php endif; ?>
                    </ul>
                    <div class="card-footer bg-white pt-3">
                        <?php renderPagination($page_piket, $total_pages_piket, 'page_piket'); ?>
                    </div>
                </div>

                <div class="tab-pane fade" id="ekskul-pane" role="tabpanel">
                    <ul class="list-group list-group-flush">
                        <?php if ($result_ekskul->num_rows > 0): while($ekskul = $result_ekskul->fetch_assoc()): ?>
                             <li class="list-group-item">
                                <div class="d-flex w-100 justify-content-between">
                                    <div class="schedule-details">
                                        <h6><?php echo htmlspecialchars($ekskul['nama_ekskul']); ?></h6>
                                        <p class="text-muted"><i class="bi bi-person-fill"></i> <?php echo htmlspecialchars($ekskul['nama_guru']); ?></p>
                                    </div>
                                    <span class="text-end text-muted fw-bold">
                                        <i class="bi bi-clock-fill"></i>
                                        <?php echo date('H:i', strtotime($ekskul['jam_mulai'])); ?>
                                    </span>
                                </div>
                            </li>
                        <?php endwhile; else: ?>
                            <li class="list-group-item text-center p-5">Tidak ada jadwal ekstrakurikuler hari ini.</li>
                        <?php endif; ?>
                    </ul>
                    <div class="card-footer bg-white pt-3">
                        <?php renderPagination($page_ekskul, $total_pages_ekskul, 'page_ekskul'); ?>
                    </div>
            </div>
        </div>
        </div>
        </div>

        <div class="col-lg-6">
            <div class="info-card d-flex flex-column h-100">
                <div class="info-card-header">
                    <h5><i class="bi bi-patch-check-fill"></i> Kehadiran Hari Ini:</h5>
                </div>
                <ul class="list-group list-group-flush list-group-flush flex-grow-1">
                     <?php if ($result_laporan->num_rows > 0): ?>
                        <?php while($laporan = $result_laporan->fetch_assoc()): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <strong class="text-dark"><?php echo htmlspecialchars($laporan['nama_guru']); ?></strong>
                                    <br><span class="badge rounded-pill bg-absen 
                                        <?php 
                                            // LOGIKA WARNA DIPERBARUI UNTUK BK
                                            switch($laporan['tipe_absensi']){
                                                case 'mengajar': echo 'bg-primary'; break;
                                                case 'piket': echo 'bg-success'; break;
                                                case 'ekskul': echo 'bg-warning text-dark'; break;
                                                case 'bimbingan': echo 'bg-info text-dark'; break;
                                            }
                                        ?>">
                                        <?php echo ($laporan['tipe_absensi'] == 'bimbingan') ? 'Layanan BK' : ucfirst($laporan['tipe_absensi']); ?>
                                        
                                        <?php if ($laporan['tipe_absensi'] == 'mengajar' && !empty($laporan['kelas'])): ?>
                                            : Kelas <?php echo htmlspecialchars($laporan['kelas']); ?>
                                        <?php elseif ($laporan['tipe_absensi'] == 'bimbingan' && !empty($laporan['sasaran_layanan'])): ?>
                                            : <?php echo htmlspecialchars($laporan['sasaran_layanan']); ?>
                                        <?php endif; ?>
                                    </span>
                                    <?php
                                $status = htmlspecialchars($laporan['status']);
                                $badge_class = 'bg-secondary'; // Default
                                switch ($status) {
                                    case 'Hadir': $badge_class = 'bg-success'; break;
                                    case 'Sakit': $badge_class = 'bg-info text-dark'; break;
                                    case 'Izin': $badge_class = 'bg-warning text-dark'; break;
                                    case 'Alpa': $badge_class = 'bg-danger'; break;
                                }
                                ?>
                                <span class="badge rounded-pill <?php echo $badge_class; ?> ms-2"><?php echo $status; ?></span>
                                    <br>
                                    <small class="text-muted">
                                        Pukul: <?php echo date('H:i', strtotime($laporan['waktu_absensi'])); ?></small>
                                </div>
                                <?php if (!empty($laporan['foto_bukti'])): ?>
                                    <a href="<?php echo htmlspecialchars($laporan['foto_bukti']); ?>" target="_blank" class="photo-link">
                                        <i class="bi bi-camera-fill"></i> Foto
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <li class="list-group-item text-center p-4">Belum ada laporan kehadiran hari ini.</li>
                    <?php endif; ?>
                </ul>
                <div class="card-footer bg-white pt-3">
                    <?php renderPagination($page_laporan, $total_pages_laporan, 'page_laporan'); ?>
                </div>
            </div>
        </div>
    </div>
</div>
    
<?php if ($result_galeri->num_rows > 0): ?>
<hr class="my-5">
<div class="container mt-5">
    <h2 class="text-center mb-4"><i class="bi bi-images"></i> Galeri Aktivitas Hari Ini</h2>
    <div id="galleryCarousel" class="carousel slide shadow-lg rounded gallery-carousel" data-bs-ride="carousel">
        
        <div class="carousel-indicators">
            <?php for ($i = 0; $i < $result_galeri->num_rows; $i++): ?>
                <button type="button" data-bs-target="#galleryCarousel" data-bs-slide-to="<?php echo $i; ?>" class="<?php echo ($i == 0) ? 'active' : ''; ?>" aria-current="<?php echo ($i == 0) ? 'true' : 'false'; ?>"></button>
            <?php endfor; ?>
        </div>
        
        <div class="carousel-inner rounded">
            <?php $first = true; ?>
            <?php while($foto = $result_galeri->fetch_assoc()): ?>
                <div class="carousel-item <?php echo ($first) ? 'active' : ''; ?>">
                    <img src="<?php echo htmlspecialchars($foto['foto_bukti']); ?>" class="d-block w-100 carousel-image-fixed" alt="Foto Kegiatan">
                    <div class="carousel-caption d-none d-md-block">
                        <h5><?php echo htmlspecialchars($foto['nama_guru']); ?></h5>
                        <p><?php echo htmlspecialchars($foto['keterangan_jadwal']); ?></p>
                    </div>
                </div>
            <?php $first = false; endwhile; ?>
        </div>

        <button class="carousel-control-prev" type="button" data-bs-target="#galleryCarousel" data-bs-slide="prev">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Previous</span>
        </button>
        <button class="carousel-control-next" type="button" data-bs-target="#galleryCarousel" data-bs-slide="next">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Next</span>
        </button>
    </div>
</div>
<?php endif; ?>
    
    <hr class="my g-5">
    <div class="hero-section text-center">
    <div class="container">
        <h3 class="display-8"><img src="ICON_RUANG_SEKOLAH_COLOUR2x.png" alt="Logo Sekolah" height="60" class="me-2">
        <br>Informasi dan Aktivitas Belajar Mengajar Terpadu</h3>
    </div>
</div>

<div class="container mb-5">
    <center><section class="feature-section">
        <div class="row g-4 justify-content-center">
            <div class="col-6 col-md-3">
                <a href="https://smkt.alhasan.co.id/classync/absen-siswa.php" target="_blank" rel="noopener noreferrer" class="feature-card">
                    <img src="https://img.icons8.com/color/96/classroom.png" alt="Kelas Digital" class="feature-icon"><h6>Absensi Siswa</h6></a>
            </div>
            <div class="col-6 col-md-3">
                <a href="https://smkt.alhasan.co.id/classync/jurnal.php" target="_blank" rel="noopener noreferrer" class="feature-card">
                    <img src="https://img.icons8.com/color/96/books.png" alt="Jurnal Mengajar" class="feature-icon"><h6>Jurnal Mengajar</h6></a>
            </div>
            <div class="col-6 col-md-3">
                <a href="#" class="feature-card">
                    <img src="https://img.icons8.com/color/96/test-passed.png" alt="Bank Soal" class="feature-icon"><h6>Bank Soal</h6></a>
            </div>
            <div class="col-6 col-md-3">
                <a href="https://smkt.alhasan.co.id" class="feature-card">
                    <img src="https://img.icons8.com/color/96/school-building.png" alt="Info Sekolah" class="feature-icon"><h6>Info Sekolah</h6></a>
            </div>
        </div>
    </section></center>
</div>

<?php include 'includes/footer.php'; ?>