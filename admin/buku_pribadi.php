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
        <?php echo htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-light">
        <strong>Identitas Utama:</strong> <?php echo htmlspecialchars((string)$data_siswa['nama_siswa'], ENT_QUOTES, 'UTF-8'); ?> | NISN: <?php echo htmlspecialchars((string)$data_siswa['nisn'], ENT_QUOTES, 'UTF-8'); ?> | Kelas: <?php echo htmlspecialchars((string)$data_siswa['kelas'], ENT_QUOTES, 'UTF-8'); ?>
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
                            <input type="text" name="nama_ayah" class="form-control" value="<?php echo htmlspecialchars((string)($bk['nama_ayah'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Pekerjaan Ayah</label>
                            <input type="text" name="pekerjaan_ayah" class="form-control" value="<?php echo htmlspecialchars((string)($bk['pekerjaan_ayah'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Nama Ibu</label>
                            <input type="text" name="nama_ibu" class="form-control" value="<?php echo htmlspecialchars((string)($bk['nama_ibu'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Pekerjaan Ibu</label>
                            <input type="text" name="pekerjaan_ibu" class="form-control" value="<?php echo htmlspecialchars((string)($bk['pekerjaan_ibu'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>Jumlah Saudara</label>
                            <input type="number" name="jumlah_saudara" class="form-control" value="<?php echo htmlspecialchars((string)($bk['jumlah_saudara'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>Anak Ke-</label>
                            <input type="number" name="anak_ke" class="form-control" value="<?php echo htmlspecialchars((string)($bk['anak_ke'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>Kondisi Keluarga (Utuh/Cerai/dll)</label>
                            <input type="text" name="kondisi_keluarga" class="form-control" value="<?php echo htmlspecialchars((string)($bk['kondisi_keluarga'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                </div>

                <div class="tab-pane container fade px-0" id="pendidikan">
                    <div class="row">
                        <div class="col-md-6 mb-3"><label>Riwayat SD</label><input type="text" name="riwayat_sd" class="form-control" value="<?php echo htmlspecialchars((string)($bk['riwayat_sd'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <div class="col-md-6 mb-3"><label>Riwayat SMP</label><input type="text" name="riwayat_smp" class="form-control" value="<?php echo htmlspecialchars((string)($bk['riwayat_smp'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <div class="col-md-6 mb-3"><label>Prestasi Akademik</label><textarea name="prestasi_akademik" class="form-control" rows="3"><?php echo htmlspecialchars((string)($bk['prestasi_akademik'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                        <div class="col-md-6 mb-3"><label>Prestasi Non-Akademik</label><textarea name="prestasi_non_akademik" class="form-control" rows="3"><?php echo htmlspecialchars((string)($bk['prestasi_non_akademik'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                    </div>
                </div>

                <div class="tab-pane container fade px-0" id="pribadi">
                    <div class="row">
                        <div class="col-md-6 mb-3"><label>Kesehatan / Penyakit</label><textarea name="kesehatan" class="form-control"><?php echo htmlspecialchars((string)($bk['kesehatan'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                        <div class="col-md-6 mb-3"><label>Kebiasaan Sehari-hari</label><textarea name="kebiasaan" class="form-control"><?php echo htmlspecialchars((string)($bk['kebiasaan'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                        <div class="col-md-6 mb-3"><label>Kelebihan Diri</label><textarea name="kelebihan" class="form-control"><?php echo htmlspecialchars((string)($bk['kelebihan'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                        <div class="col-md-6 mb-3"><label>Kekurangan Diri</label><textarea name="kekurangan" class="form-control"><?php echo htmlspecialchars((string)($bk['kekurangan'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                        <div class="col-md-4 mb-3"><label>Hubungan Teman</label><input type="text" name="hubungan_teman" class="form-control" value="<?php echo htmlspecialchars((string)($bk['hubungan_teman'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <div class="col-md-4 mb-3"><label>Organisasi</label><input type="text" name="organisasi" class="form-control" value="<?php echo htmlspecialchars((string)($bk['organisasi'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <div class="col-md-4 mb-3"><label>Pergaulan</label><input type="text" name="pergaulan" class="form-control" value="<?php echo htmlspecialchars((string)($bk['pergaulan'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                    </div>
                </div>

                <div class="tab-pane container fade px-0" id="belajar">
                    <div class="row">
                        <div class="col-md-6 mb-3"><label>Mapel Favorit</label><input type="text" name="mapel_favorit" class="form-control" value="<?php echo htmlspecialchars((string)($bk['mapel_favorit'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <div class="col-md-6 mb-3"><label>Mapel Sulit</label><input type="text" name="mapel_sulit" class="form-control" value="<?php echo htmlspecialchars((string)($bk['mapel_sulit'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <div class="col-md-6 mb-3"><label>Gaya Belajar</label><input type="text" name="gaya_belajar" class="form-control" placeholder="Visual/Auditori/Kinestetik" value="<?php echo htmlspecialchars((string)($bk['gaya_belajar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <div class="col-md-6 mb-3"><label>Motivasi Belajar</label><input type="text" name="motivasi" class="form-control" value="<?php echo htmlspecialchars((string)($bk['motivasi'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <div class="col-md-4 mb-3"><label>Cita-cita</label><input type="text" name="cita_cita" class="form-control" value="<?php echo htmlspecialchars((string)($bk['cita_cita'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <div class="col-md-4 mb-3"><label>Minat Karir</label><input type="text" name="minat_karir" class="form-control" value="<?php echo htmlspecialchars((string)($bk['minat_karir'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <div class="col-md-4 mb-3"><label>Rencana Setelah Lulus</label><input type="text" name="rencana_lulus" class="form-control" value="<?php echo htmlspecialchars((string)($bk['rencana_lulus'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                    </div>
                </div>

                <div class="tab-pane container fade px-0" id="perkembangan">
                    <div class="mb-3">
                        <label>J. Catatan Perkembangan Siswa</label>
                        <textarea name="catatan_perkembangan" class="form-control" rows="6" placeholder="Uraian perkembangan siswa dari waktu ke waktu..."><?php echo htmlspecialchars((string)($bk['catatan_perkembangan'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
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

<?php
// --- RIWAYAT LAYANAN BK (KEMUNGKINAN TERKAIT) ---
// Jurnal BK dan laporan konseling tidak menyimpan siswa_id. Penghubungnya
// hanya teks bebas yang diketik guru BK: sasaran_layanan, nama_konseli, dan
// anggota_kelompok. Nama sering ditulis sebagian, dan kelas ditulis dalam
// bentuk yang tidak sama dengan kolom siswa.kelas (mis. "XI DKV" lawan
// "11-DKV"), jadi hasil di bawah ini perkiraan untuk dinilai admin, bukan
// riwayat pasti. Penyelesaian tepatnya tabel penghubung ke siswa.id
// (tahap 4 perbaikan BK).
//
// Aturannya: nama siswa dipecah per kata; kata umum dan kata < 3 huruf
// diabaikan. Sebuah jurnal dianggap mungkin terkait kalau salah satu kata
// itu muncul UTUH (tanpa membedakan huruf besar-kecil) di salah satu teks
// tadi. Urutan menurut jumlah kata yang cocok, lalu tanggal.
$bk_kata_umum = ['muhamad', 'muhammad', 'mohamad', 'mohammad', 'moh', 'muh', 'siti', 'nur', 'ahmad', 'abdul', 'bin', 'binti', 'dan'];
$bk_pecah_kata = function ($teks) {
    $kata = preg_split('/[^\p{L}]+/u', mb_strtolower((string)$teks, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
    return $kata ?: [];
};
$kata_nama = [];
foreach ($bk_pecah_kata($data_siswa['nama_siswa']) as $kata) {
    if (mb_strlen($kata, 'UTF-8') >= 3 && !in_array($kata, $bk_kata_umum, true)) {
        $kata_nama[$kata] = true;
    }
}
$kata_nama = array_keys($kata_nama);

$riwayat = [];
if ($kata_nama) {
    // Laporan dibaca terpisah, bukan GROUP_CONCAT, supaya anggota_kelompok
    // yang panjang tidak terpotong group_concat_max_len.
    $laporan_per_jurnal = [];
    $res_ind = $conn->query("SELECT id, jurnal_bk_id, nama_konseli FROM konseling_individu WHERE jurnal_bk_id > 0 ORDER BY id");
    while ($l = $res_ind->fetch_assoc()) {
        $laporan_per_jurnal[(int)$l['jurnal_bk_id']][] = ['individu', (int)$l['id'], 'Nama konseli', (string)$l['nama_konseli']];
    }
    $res_kel = $conn->query("SELECT id, jurnal_bk_id, anggota_kelompok FROM konseling_kelompok WHERE jurnal_bk_id > 0 ORDER BY id");
    while ($l = $res_kel->fetch_assoc()) {
        $laporan_per_jurnal[(int)$l['jurnal_bk_id']][] = ['kelompok', (int)$l['id'], 'Anggota kelompok', (string)$l['anggota_kelompok']];
    }

    $res_jurnal = $conn->query(
        "SELECT a.id AS absensi_id, a.waktu_absensi, g.nama_guru, j.komponen_layanan, j.topik_tema, j.sasaran_layanan
         FROM jurnal_bk j
         JOIN absensi a ON a.id = j.absensi_guru_id
         LEFT JOIN guru g ON g.id = a.guru_id
         WHERE a.tipe_absensi = 'bimbingan'
         ORDER BY a.waktu_absensi DESC, a.id DESC");
    while ($j = $res_jurnal->fetch_assoc()) {
        $laporan = $laporan_per_jurnal[(int)$j['absensi_id']] ?? [];
        $sumber = [['Sasaran layanan', (string)$j['sasaran_layanan']]];
        foreach ($laporan as [$jenis_lap, $id_lap, $label_lap, $teks_lap]) {
            $sumber[] = [$label_lap . ' (' . $jenis_lap . ' #' . $id_lap . ')', $teks_lap];
        }

        $cocok = [];
        $temuan = [];
        foreach ($sumber as [$label_sumber, $teks_sumber]) {
            $kata_cocok = array_intersect($kata_nama, $bk_pecah_kata($teks_sumber));
            if (!$kata_cocok) {
                continue;
            }
            $cocok += array_flip($kata_cocok);
            // Teks panjang (daftar anggota) dipersempit ke baris yang memuat nama.
            $baris_cocok = [];
            foreach (preg_split('/\R/u', $teks_sumber) as $baris) {
                if (array_intersect($kata_nama, $bk_pecah_kata($baris))) {
                    $baris_cocok[] = trim($baris);
                }
            }
            $temuan[] = [$label_sumber, implode("\n", $baris_cocok)];
        }
        if ($cocok) {
            $j['skor'] = count($cocok);
            $j['temuan'] = $temuan;
            $j['laporan'] = $laporan;
            $riwayat[] = $j;
        }
    }
    // usort stabil sejak PHP 8.0, jadi urutan tanggal tetap di dalam skor yang sama.
    usort($riwayat, fn($x, $y) => $y['skor'] <=> $x['skor']);
}
?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-light">
        <strong>Riwayat Layanan BK</strong> <span class="text-muted">(kemungkinan terkait)</span>
    </div>
    <div class="card-body">
        <div class="alert alert-info small">
            Jurnal dan laporan BK tidak menyimpan identitas siswa. Daftar ini dicocokkan dari teks nama yang
            ditulis guru BK, jadi <strong>bisa keliru</strong>: nama dengan ejaan berbeda tidak terdeteksi, dan
            siswa lain yang namanya sama bisa ikut muncul. Periksa kolom "Ditemukan di" sebelum menyimpulkan.
            Layanan untuk satu kelas tidak ditampilkan.
        </div>

        <?php if (!$kata_nama): ?>
            <p class="text-muted mb-0">Nama siswa ini hanya berisi kata umum, sehingga tidak bisa dicocokkan.</p>
        <?php elseif (!$riwayat): ?>
            <p class="text-muted mb-0">Tidak ditemukan layanan BK yang menyebut nama siswa ini.</p>
        <?php else: ?>
            <p class="small text-muted">
                Kata nama yang dicari: <?php echo htmlspecialchars(implode(', ', $kata_nama), ENT_QUOTES, 'UTF-8'); ?>.
                Ditemukan <?php echo count($riwayat); ?> jurnal.
            </p>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Layanan</th>
                            <th>Konselor</th>
                            <th>Ditemukan di</th>
                            <th>Laporan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($riwayat as $r): ?>
                        <tr>
                            <td class="small text-nowrap"><?php echo date('d/m/Y H:i', strtotime($r['waktu_absensi'])); ?></td>
                            <td>
                                <span class="badge bg-info text-dark mb-1"><?php echo htmlspecialchars((string)$r['komponen_layanan'], ENT_QUOTES, 'UTF-8'); ?></span><br>
                                <?php echo htmlspecialchars((string)$r['topik_tema'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td><?php echo htmlspecialchars((string)($r['nama_guru'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="small">
                                <?php foreach ($r['temuan'] as [$label_temuan, $teks_temuan]): ?>
                                    <div class="mb-1">
                                        <span class="text-muted"><?php echo htmlspecialchars($label_temuan, ENT_QUOTES, 'UTF-8'); ?>:</span>
                                        <span style="white-space: pre-wrap;"><?php echo htmlspecialchars($teks_temuan, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td class="small">
                                <?php foreach ($r['laporan'] as [$jenis_lap, $id_lap]): ?>
                                    <a href="detail_konseling.php?jenis=<?php echo $jenis_lap; ?>&amp;id=<?php echo $id_lap; ?>"><?php echo ucfirst($jenis_lap); ?> #<?php echo $id_lap; ?></a><br>
                                <?php endforeach; ?>
                                <?php if (!$r['laporan']): ?>
                                    <span class="text-muted">Belum ada</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'partials/footer.php'; ?>