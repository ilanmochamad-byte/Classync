<?php 
include 'partials/header.php'; // Mengambil header, koneksi DB, dan session

// 1. Ambil ID guru yang sedang login dari session
$guru_id = $_SESSION['guru_id']; 

// 2. Ambil data utama guru
$stmt_guru = $conn->prepare("SELECT * FROM guru WHERE id = ?");
$stmt_guru->bind_param("i", $guru_id);
$stmt_guru->execute();
$guru = $stmt_guru->get_result()->fetch_assoc();

// 3. Ambil data jadwal mengajar
$stmt_mengajar = $conn->prepare("SELECT * FROM jadwal_mengajar WHERE guru_id = ? ORDER BY FIELD(hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'), jam_mulai");
$stmt_mengajar->bind_param("i", $guru_id);
$stmt_mengajar->execute();
$jadwal_mengajar = $stmt_mengajar->get_result();

// 4. Ambil data jadwal piket
$stmt_piket = $conn->prepare("SELECT * FROM jadwal_piket WHERE guru_id = ? ORDER BY FIELD(hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu')");
$stmt_piket->bind_param("i", $guru_id);
$stmt_piket->execute();
$jadwal_piket = $stmt_piket->get_result();

// 5. Ambil data jadwal ekskul
$stmt_ekskul = $conn->prepare("SELECT * FROM jadwal_ekskul WHERE guru_id = ? ORDER BY FIELD(hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'), jam_mulai");
$stmt_ekskul->bind_param("i", $guru_id);
$stmt_ekskul->execute();
$jadwal_ekskul = $stmt_ekskul->get_result();

?>

<h1 class="mb-4">Profil Saya</h1>

<?php if(isset($_GET['status'])): ?>
    <div class="alert alert-success">Perubahan berhasil disimpan!</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="row">
            <div class="col-md-3 text-center">
                <img src="../<?php echo !empty($guru['foto_profil']) ? htmlspecialchars($guru['foto_profil']) : 'uploads/default.png'; ?>" alt="Foto Profil" class="img-thumbnail rounded-circle mb-3" style="width: 150px; height: 150px; object-fit: cover;">
                <a href="edit_profil_guru.php" class="btn btn-primary w-100">
                    <i class="bi bi-pencil-square"></i> Edit Profil
                </a>
            </div>
            <div class="col-md-9">
                <h3><?php echo htmlspecialchars($guru['nama_guru']); ?></h3>
                <p class="text-muted">NIK: <?php echo htmlspecialchars($guru['nip']); ?></p>
                <hr>
                <dl class="row">
                    <dt class="col-sm-4">Tempat, Tanggal Lahir</dt>
                    <dd class="col-sm-8"><?php echo htmlspecialchars($guru['tempat_lahir'] ?? '-'); ?>, <?php echo isset($guru['tanggal_lahir']) ? date('d F Y', strtotime($guru['tanggal_lahir'])) : '-'; ?></dd>

                    <dt class="col-sm-4">Kontak</dt>
                    <dd class="col-sm-8"><?php echo htmlspecialchars($guru['kontak'] ?? '-'); ?></dd>

                    <dt class="col-sm-4">Tugas Tambahan</dt>
                    <dd class="col-sm-8"><?php echo htmlspecialchars($guru['tugas_tambahan'] ?? '-'); ?></dd>
                    
                    <dt class="col-sm-4">Riwayat Pendidikan</dt>
                    <dd class="col-sm-8 mb-0">
                        <ul class="list-unstyled">
                            <li><strong>S1:</strong> <?php echo htmlspecialchars($guru['pendidikan_s1'] ?? '-'); ?></li>
                            <li><strong>S2:</strong> <?php echo htmlspecialchars($guru['pendidikan_s2'] ?? '-'); ?></li>
                            <li><strong>S3:</strong> <?php echo htmlspecialchars($guru['pendidikan_s3'] ?? '-'); ?></li>
                        </ul>
                    </dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-lg-6 mb-4">
        <div class="card shadow-sm">
            <div class="card-header"><h5 class="mb-0">Jadwal Mengajar</h5></div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    <?php while($j = $jadwal_mengajar->fetch_assoc()): ?>
                        <li class="list-group-item"><?php echo "<b>{$j['hari']}</b>, {$j['jam_mulai']} - {$j['jam_selesai']}: {$j['mata_pelajaran']} (Kelas {$j['kelas']})"; ?></li>
                    <?php endwhile; ?>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-lg-6 mb-4">
        <div class="card shadow-sm mb-4">
            <div class="card-header"><h5 class="mb-0">Jadwal Piket</h5></div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    <?php while($j = $jadwal_piket->fetch_assoc()): ?>
                        <li class="list-group-item"><?php echo "<b>{$j['hari']}</b>: Sesi {$j['sesi']}"; ?></li>
                    <?php endwhile; ?>
                </ul>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-header"><h5 class="mb-0">Jadwal Ekstrakurikuler</h5></div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    <?php while($j = $jadwal_ekskul->fetch_assoc()): ?>
                        <li class="list-group-item"><?php echo "<b>{$j['hari']}</b>, {$j['jam_mulai']} - {$j['jam_selesai']}: {$j['nama_ekskul']}"; ?></li>
                    <?php endwhile; ?>
                </ul>
            </div>
        </div>
    </div>
</div>


<?php include 'partials/footer.php'; ?>