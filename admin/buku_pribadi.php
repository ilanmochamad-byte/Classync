<?php 
include 'partials/header.php'; 

$pesan = '';
$tipe_pesan = '';

// --- CEK PARAMETER SISWA ---
if (!isset($_GET['siswa_id']) || empty($_GET['siswa_id'])) {
    echo "<div class='alert alert-danger'>ID Siswa tidak ditemukan. Silakan pilih siswa dari menu Data Siswa.</div>";
    include 'partials/footer.php';
    exit;
}

$siswa_id = (int)$_GET['siswa_id'];

// --- PROSES SIMPAN FORMULIR ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_buku'])) {
    $nama_ayah = $_POST['nama_ayah'] ?? '';
    $pekerjaan_ayah = $_POST['pekerjaan_ayah'] ?? '';
    $nama_ibu = $_POST['nama_ibu'] ?? '';
    $pekerjaan_ibu = $_POST['pekerjaan_ibu'] ?? '';
    $jumlah_saudara = (int)($_POST['jumlah_saudara'] ?? 0);
    $anak_ke = (int)($_POST['anak_ke'] ?? 0);
    $kondisi_keluarga = $_POST['kondisi_keluarga'] ?? '';
    
    $riwayat_sd = $_POST['riwayat_sd'] ?? '';
    $riwayat_smp = $_POST['riwayat_smp'] ?? '';
    $prestasi_akademik = $_POST['prestasi_akademik'] ?? '';
    $prestasi_non_akademik = $_POST['prestasi_non_akademik'] ?? '';
    
    $kesehatan = $_POST['kesehatan'] ?? '';
    $kebiasaan = $_POST['kebiasaan'] ?? '';
    $kelebihan = $_POST['kelebihan'] ?? '';
    $kekurangan = $_POST['kekurangan'] ?? '';
    $hubungan_teman = $_POST['hubungan_teman'] ?? '';
    $organisasi = $_POST['organisasi'] ?? '';
    $pergaulan = $_POST['pergaulan'] ?? '';
    
    $mapel_favorit = $_POST['mapel_favorit'] ?? '';
    $mapel_sulit = $_POST['mapel_sulit'] ?? '';
    $gaya_belajar = $_POST['gaya_belajar'] ?? '';
    $motivasi = $_POST['motivasi'] ?? '';
    
    $cita_cita = $_POST['cita_cita'] ?? '';
    $minat_karir = $_POST['minat_karir'] ?? '';
    $rencana_lulus = $_POST['rencana_lulus'] ?? '';
    $catatan_perkembangan = $_POST['catatan_perkembangan'] ?? '';

    // Query super efisien: Insert jika baru, Update jika sudah ada
    $sql_save = "INSERT INTO profil_bk_siswa 
        (siswa_id, nama_ayah, pekerjaan_ayah, nama_ibu, pekerjaan_ibu, jumlah_saudara, anak_ke, kondisi_keluarga, 
         riwayat_sd, riwayat_smp, prestasi_akademik, prestasi_non_akademik, kesehatan, kebiasaan, kelebihan, kekurangan, 
         hubungan_teman, organisasi, pergaulan, mapel_favorit, mapel_sulit, gaya_belajar, motivasi, cita_cita, minat_karir, rencana_lulus, catatan_perkembangan) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE 
        nama_ayah=VALUES(nama_ayah), pekerjaan_ayah=VALUES(pekerjaan_ayah), nama_ibu=VALUES(nama_ibu), pekerjaan_ibu=VALUES(pekerjaan_ibu), 
        jumlah_saudara=VALUES(jumlah_saudara), anak_ke=VALUES(anak_ke), kondisi_keluarga=VALUES(kondisi_keluarga), 
        riwayat_sd=VALUES(riwayat_sd), riwayat_smp=VALUES(riwayat_smp), prestasi_akademik=VALUES(prestasi_akademik), prestasi_non_akademik=VALUES(prestasi_non_akademik), 
        kesehatan=VALUES(kesehatan), kebiasaan=VALUES(kebiasaan), kelebihan=VALUES(kelebihan), kekurangan=VALUES(kekurangan), 
        hubungan_teman=VALUES(hubungan_teman), organisasi=VALUES(organisasi), pergaulan=VALUES(pergaulan), 
        mapel_favorit=VALUES(mapel_favorit), mapel_sulit=VALUES(mapel_sulit), gaya_belajar=VALUES(gaya_belajar), motivasi=VALUES(motivasi), 
        cita_cita=VALUES(cita_cita), minat_karir=VALUES(minat_karir), rencana_lulus=VALUES(rencana_lulus), catatan_perkembangan=VALUES(catatan_perkembangan)";
        
    $stmt = $conn->prepare($sql_save);
    $stmt->bind_param("issssiissssssssssssssssssss", 
        $siswa_id, $nama_ayah, $pekerjaan_ayah, $nama_ibu, $pekerjaan_ibu, $jumlah_saudara, $anak_ke, $kondisi_keluarga,
        $riwayat_sd, $riwayat_smp, $prestasi_akademik, $prestasi_non_akademik,
        $kesehatan, $kebiasaan, $kelebihan, $kekurangan, $hubungan_teman, $organisasi, $pergaulan,
        $mapel_favorit, $mapel_sulit, $gaya_belajar, $motivasi,
        $cita_cita, $minat_karir, $rencana_lulus, $catatan_perkembangan
    );
    
    if ($stmt->execute()) {
        $pesan = "Data Buku Pribadi berhasil disimpan!";
        $tipe_pesan = "success";
    } else {
        $pesan = "Gagal menyimpan data: " . $conn->error;
        $tipe_pesan = "danger";
    }
}

// --- AMBIL DATA SISWA ---
$stmt_siswa = $conn->prepare("SELECT nisn, nama_siswa, kelas FROM siswa WHERE id = ?");
$stmt_siswa->bind_param("i", $siswa_id);
$stmt_siswa->execute();
$data_siswa = $stmt_siswa->get_result()->fetch_assoc();

if (!$data_siswa) {
    echo "<div class='alert alert-danger'>Data siswa tidak ditemukan di database.</div>";
    include 'partials/footer.php';
    exit;
}

// --- AMBIL DATA PROFIL BK JIKA ADA ---
$stmt_bk = $conn->prepare("SELECT * FROM profil_bk_siswa WHERE siswa_id = ?");
$stmt_bk->bind_param("i", $siswa_id);
$stmt_bk->execute();
$bk = $stmt_bk->get_result()->fetch_assoc() ?: []; // Jika kosong, set array kosong
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-book-half text-success"></i> Buku Pribadi Peserta Didik</h2>
    <a href="siswa.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Kembali</a>
</div>

<?php if($pesan): ?>
    <div class="alert alert-<?php echo $tipe_pesan; ?> alert-dismissible fade show">
        <?php echo $pesan; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-light">
        <strong>Identitas Utama:</strong> <?php echo $data_siswa['nama_siswa']; ?> | NISN: <?php echo $data_siswa['nisn']; ?> | Kelas: <?php echo $data_siswa['kelas']; ?>
    </div>
    <div class="card-body">
        
        <form method="POST">
            <input type="hidden" name="siswa_id" value="<?php echo $siswa_id; ?>">
            
            <ul class="nav nav-tabs mb-4" id="bkTabs" role="tablist">
                <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#keluarga">B. Keluarga</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#pendidikan">C. Pendidikan</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#pribadi">D & E. Pribadi & Sosial</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#belajar">F & G. Belajar & Karir</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#perkembangan">J. Perkembangan</a></li>
            </ul>

            <div class="tab-content">
                
                <div class="tab-pane container active px-0" id="keluarga">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Nama Ayah</label>
                            <input type="text" name="nama_ayah" class="form-control" value="<?php echo $bk['nama_ayah'] ?? ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Pekerjaan Ayah</label>
                            <input type="text" name="pekerjaan_ayah" class="form-control" value="<?php echo $bk['pekerjaan_ayah'] ?? ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Nama Ibu</label>
                            <input type="text" name="nama_ibu" class="form-control" value="<?php echo $bk['nama_ibu'] ?? ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Pekerjaan Ibu</label>
                            <input type="text" name="pekerjaan_ibu" class="form-control" value="<?php echo $bk['pekerjaan_ibu'] ?? ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>Jumlah Saudara</label>
                            <input type="number" name="jumlah_saudara" class="form-control" value="<?php echo $bk['jumlah_saudara'] ?? ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>Anak Ke-</label>
                            <input type="number" name="anak_ke" class="form-control" value="<?php echo $bk['anak_ke'] ?? ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>Kondisi Keluarga (Utuh/Cerai/dll)</label>
                            <input type="text" name="kondisi_keluarga" class="form-control" value="<?php echo $bk['kondisi_keluarga'] ?? ''; ?>">
                        </div>
                    </div>
                </div>

                <div class="tab-pane container fade px-0" id="pendidikan">
                    <div class="row">
                        <div class="col-md-6 mb-3"><label>Riwayat SD</label><input type="text" name="riwayat_sd" class="form-control" value="<?php echo $bk['riwayat_sd'] ?? ''; ?>"></div>
                        <div class="col-md-6 mb-3"><label>Riwayat SMP</label><input type="text" name="riwayat_smp" class="form-control" value="<?php echo $bk['riwayat_smp'] ?? ''; ?>"></div>
                        <div class="col-md-6 mb-3"><label>Prestasi Akademik</label><textarea name="prestasi_akademik" class="form-control" rows="3"><?php echo $bk['prestasi_akademik'] ?? ''; ?></textarea></div>
                        <div class="col-md-6 mb-3"><label>Prestasi Non-Akademik</label><textarea name="prestasi_non_akademik" class="form-control" rows="3"><?php echo $bk['prestasi_non_akademik'] ?? ''; ?></textarea></div>
                    </div>
                </div>

                <div class="tab-pane container fade px-0" id="pribadi">
                    <div class="row">
                        <div class="col-md-6 mb-3"><label>Kesehatan / Penyakit</label><textarea name="kesehatan" class="form-control"><?php echo $bk['kesehatan'] ?? ''; ?></textarea></div>
                        <div class="col-md-6 mb-3"><label>Kebiasaan Sehari-hari</label><textarea name="kebiasaan" class="form-control"><?php echo $bk['kebiasaan'] ?? ''; ?></textarea></div>
                        <div class="col-md-6 mb-3"><label>Kelebihan Diri</label><textarea name="kelebihan" class="form-control"><?php echo $bk['kelebihan'] ?? ''; ?></textarea></div>
                        <div class="col-md-6 mb-3"><label>Kekurangan Diri</label><textarea name="kekurangan" class="form-control"><?php echo $bk['kekurangan'] ?? ''; ?></textarea></div>
                        <div class="col-md-4 mb-3"><label>Hubungan Teman</label><input type="text" name="hubungan_teman" class="form-control" value="<?php echo $bk['hubungan_teman'] ?? ''; ?>"></div>
                        <div class="col-md-4 mb-3"><label>Organisasi</label><input type="text" name="organisasi" class="form-control" value="<?php echo $bk['organisasi'] ?? ''; ?>"></div>
                        <div class="col-md-4 mb-3"><label>Pergaulan</label><input type="text" name="pergaulan" class="form-control" value="<?php echo $bk['pergaulan'] ?? ''; ?>"></div>
                    </div>
                </div>

                <div class="tab-pane container fade px-0" id="belajar">
                    <div class="row">
                        <div class="col-md-6 mb-3"><label>Mapel Favorit</label><input type="text" name="mapel_favorit" class="form-control" value="<?php echo $bk['mapel_favorit'] ?? ''; ?>"></div>
                        <div class="col-md-6 mb-3"><label>Mapel Sulit</label><input type="text" name="mapel_sulit" class="form-control" value="<?php echo $bk['mapel_sulit'] ?? ''; ?>"></div>
                        <div class="col-md-6 mb-3"><label>Gaya Belajar</label><input type="text" name="gaya_belajar" class="form-control" placeholder="Visual/Auditori/Kinestetik" value="<?php echo $bk['gaya_belajar'] ?? ''; ?>"></div>
                        <div class="col-md-6 mb-3"><label>Motivasi Belajar</label><input type="text" name="motivasi" class="form-control" value="<?php echo $bk['motivasi'] ?? ''; ?>"></div>
                        <div class="col-md-4 mb-3"><label>Cita-cita</label><input type="text" name="cita_cita" class="form-control" value="<?php echo $bk['cita_cita'] ?? ''; ?>"></div>
                        <div class="col-md-4 mb-3"><label>Minat Karir</label><input type="text" name="minat_karir" class="form-control" value="<?php echo $bk['minat_karir'] ?? ''; ?>"></div>
                        <div class="col-md-4 mb-3"><label>Rencana Setelah Lulus</label><input type="text" name="rencana_lulus" class="form-control" value="<?php echo $bk['rencana_lulus'] ?? ''; ?>"></div>
                    </div>
                </div>

                <div class="tab-pane container fade px-0" id="perkembangan">
                    <div class="mb-3">
                        <label>J. Catatan Perkembangan Siswa</label>
                        <textarea name="catatan_perkembangan" class="form-control" rows="6" placeholder="Uraian perkembangan siswa dari waktu ke waktu..."><?php echo $bk['catatan_perkembangan'] ?? ''; ?></textarea>
                    </div>
                </div>
                
            </div>

            <hr>
            <div class="d-flex justify-content-end">
                <button type="submit" name="simpan_buku" class="btn btn-success btn-lg px-5">
                    <i class="bi bi-save"></i> Simpan Buku Pribadi
                </button>
            </div>
            
        </form>
    </div>
</div>

<?php include 'partials/footer.php'; ?>