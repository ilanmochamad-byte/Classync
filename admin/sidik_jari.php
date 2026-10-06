<?php
// sidik_jari.php — persetujuan dan pendaftaran sidik jari.
//
// Templat sidik jari hanya ada di jembatan PC kiosk. Halaman ini mencatat
// siapa yang menyetujui pemakaian sidik jarinya, dan menampilkan jari mana
// yang sudah terdaftar menurut tanda terima jembatan (admin/sj_catat.php).
// - Yang ditampilkan: guru yang punya jadwal piket Aktif, dan siswa yang
//   belum lulus. Aturan "sedang PKL" tetap hanya di daftarSiswaPkl().
// - Persetujuan dicatat admin dari surat yang sudah ditandatangani. Tanpa
//   persetujuan 'setuju', admin/sj_izin.php tidak memberi izin mendaftar.
// - Mencatat banyak orang sekaligus hanya mengisi yang belum punya catatan.
//   Catatan yang sudah ada diubah satu per satu, supaya salah centang tidak
//   membalik penolakan.
// - Mengubah persetujuan tidak menghapus templat. Orang yang jarinya masih
//   terdaftar tanpa persetujuan masuk daftar "Perlu dicabut".
// - Semua perubahan lewat POST + token CSRF. Token yang sama dipakai
//   admin/sj_izin.php dan admin/sj_catat.php.
// - Mendaftarkan dan mencabut jari dikerjakan skrip di bawah, dan hanya
//   berhasil di Chrome PC kiosk: skripnya berbicara dengan jembatan di
//   127.0.0.1 dan dengan pembaca lewat includes/sj_tangkap_klien.php. Urutannya
//   jembatan (sesi) → admin/sj_izin.php (izin) → jembatan (tempelan, lalu
//   simpan atau hapus) → admin/sj_catat.php (tanda terima). Yang menentukan
//   boleh tidaknya tetap server dan jembatan, bukan skrip ini.
// - Skripnya baru menyentuh 127.0.0.1 kalau izin loopback-network sudah
//   "granted" (kebijakan Chrome di PC kiosk), atau setelah tombol "Hubungkan"
//   diklik. Di peramban lain halaman ini tetap bisa dipakai untuk persetujuan
//   tanpa memunculkan permintaan izin jaringan lokal.
include 'partials/header.php';
require_once __DIR__ . '/../includes/sidik_jari.php';
require_once __DIR__ . '/../includes/status_harian.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Jari yang harus terdaftar supaya seseorang dianggap selesai.
const SJ_JARI_PER_ORANG = 2;
const SJ_MAKS_SEKALIGUS = 200;

if (empty($_SESSION['sidik_jari_csrf'])) {
    $_SESSION['sidik_jari_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['sidik_jari_csrf'];
$admin_id = (int)($_SESSION['admin_id'] ?? 0);

function amanSj($teks) {
    return htmlspecialchars((string)$teks, ENT_QUOTES, 'UTF-8');
}

// Dicocokkan bolak-balik: createFromFormat() menerima luapan, jadi tanpa
// pencocokan ini 30 Februari diam-diam menjadi 2 Maret.
function tanggalSjSah($teks) {
    $dt = is_string($teks) ? DateTime::createFromFormat('!Y-m-d', $teks) : false;
    return $dt !== false && $dt->format('Y-m-d') === $teks;
}

function tanggalSj($teks) {
    return $teks ? date('d/m/Y', strtotime($teks)) : '';
}

// identitas => ['nama', 'kelompok', 'pkl']: guru piket dulu, lalu siswa
// per kelas.
function orangSidikJari($conn) {
    $orang = [];
    $hasil = $conn->query("SELECT DISTINCT g.id, g.nama_guru FROM guru g JOIN jadwal_piket j ON j.guru_id = g.id AND j.status_jadwal = 'Aktif' ORDER BY g.nama_guru, g.id");
    while ($b = $hasil->fetch_assoc()) {
        $orang['guru:' . (int)$b['id']] = ['nama' => $b['nama_guru'], 'kelompok' => 'Guru piket', 'pkl' => false];
    }
    $pkl = daftarSiswaPkl($conn);
    $hasil = $conn->query("SELECT id, nama_siswa, kelas FROM siswa WHERE kelas <> 'Lulus / Alumni' ORDER BY kelas, nama_siswa, id");
    while ($b = $hasil->fetch_assoc()) {
        $orang['siswa:' . (int)$b['id']] = ['nama' => $b['nama_siswa'], 'kelompok' => $b['kelas'], 'pkl' => isset($pkl[(int)$b['id']])];
    }
    return $orang;
}

$masalah = [];
$konfigurasi = sjKonfigurasi(SJ_BERKAS_KONFIGURASI, $masalah);
// id => ['sidik' => [8 hex, ...], 'jari' => jumlah jari yang tercatat aktif].
// Keduanya untuk skrip: sidik dicocokkan dengan jembatan di PC kiosk, dan
// jumlah jari dengan jumlah templat yang disimpannya.
$perangkat_aktif = [];
foreach (array_keys($konfigurasi['perangkat'] ?? []) as $id) {
    $entri = sjPerangkat($konfigurasi, (string)$id);
    if ($entri !== null) {
        $perangkat_aktif[(string)$id] = ['sidik' => array_map('sjSidikKunci', $entri['kunci']), 'jari' => 0];
    }
}

$pesan = '';
$tipe_pesan = 'success';
$galat_data = '';
$orang = [];
$persetujuan = [];
$terdaftar = [];
$terdaftar_di = [];
$usang = 0;
$kelompok_ada = [];
$perlu_dicabut = [];
$hitung = ['orang' => 0, 'setuju' => 0, 'menolak' => 0, 'belum' => 0, 'lengkap' => 0, 'sebagian' => 0, 'tidak_terbaca' => 0];

try {
    $orang = orangSidikJari($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $aksi = $_POST['aksi'] ?? '';
        $token = $_POST['csrf_token'] ?? '';
        $tanggal = trim((string)($_POST['tanggal_surat'] ?? ''));

        if (!is_string($token) || !hash_equals($csrf, $token)) {
            $pesan = 'Formulir kedaluwarsa. Muat ulang halaman, lalu coba lagi.';
            $tipe_pesan = 'danger';
        } elseif ($admin_id <= 0) {
            $pesan = 'Sesi admin tidak lengkap. Keluar, lalu masuk lagi.';
            $tipe_pesan = 'danger';
        } elseif ($aksi === 'persetujuan') {
            $identitas = (string)($_POST['identitas'] ?? '');
            $status = (string)($_POST['status'] ?? '');
            $keterangan = trim((string)($_POST['keterangan'] ?? ''));
            $galat = '';
            if (!isset($orang[$identitas])) {
                $galat = 'Orang itu tidak ada di daftar.';
            } elseif (!in_array($status, ['setuju', 'menolak', 'belum'], true)) {
                $galat = 'Pilihan persetujuan tidak sah.';
            } elseif ($status === 'setuju' && $tanggal === '') {
                $galat = 'Tanggal surat wajib diisi untuk persetujuan.';
            } elseif ($tanggal !== '' && (!tanggalSjSah($tanggal) || $tanggal > date('Y-m-d'))) {
                $galat = 'Tanggal surat tidak sah, atau di masa depan.';
            } elseif (mb_strlen($keterangan) > 150) {
                $galat = 'Keterangan maksimal 150 karakter.';
            }
            if ($galat !== '') {
                $pesan = $galat;
                $tipe_pesan = 'danger';
            } elseif ($status === 'belum') {
                $stmt = $conn->prepare("DELETE FROM persetujuan_sidik_jari WHERE identitas = ?");
                $stmt->bind_param('s', $identitas);
                $stmt->execute();
                $stmt->close();
                $pesan = 'Catatan persetujuan ' . $orang[$identitas]['nama'] . ' dihapus.';
            } else {
                $kini = date('Y-m-d H:i:s');
                $tanggal_simpan = $tanggal === '' ? null : $tanggal;
                // Tanda "jari tidak terbaca" hanya bermakna selama orangnya
                // setuju, jadi penolakan mencabutnya.
                $stmt = $conn->prepare(
                    "INSERT INTO persetujuan_sidik_jari (identitas, status, tanggal_surat, keterangan, admin_id, dicatat)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE status = VALUES(status), tanggal_surat = VALUES(tanggal_surat),
                         keterangan = VALUES(keterangan), admin_id = VALUES(admin_id), dicatat = VALUES(dicatat),
                         tidak_terbaca = IF(VALUES(status) = 'setuju', tidak_terbaca, NULL),
                         tidak_terbaca_admin = IF(VALUES(status) = 'setuju', tidak_terbaca_admin, NULL)"
                );
                $stmt->bind_param('ssssis', $identitas, $status, $tanggal_simpan, $keterangan, $admin_id, $kini);
                $stmt->execute();
                $stmt->close();
                $pesan = 'Persetujuan ' . $orang[$identitas]['nama'] . ' dicatat: ' . $status . '.';
            }
        } elseif ($aksi === 'persetujuan_banyak') {
            $pilihan = $_POST['identitas'] ?? [];
            $pilihan = is_array($pilihan) ? array_values(array_unique(array_filter($pilihan, 'is_string'))) : [];
            if (!$pilihan) {
                $pesan = 'Belum ada yang dicentang.';
                $tipe_pesan = 'danger';
            } elseif (count($pilihan) > SJ_MAKS_SEKALIGUS) {
                $pesan = 'Paling banyak ' . SJ_MAKS_SEKALIGUS . ' orang sekaligus.';
                $tipe_pesan = 'danger';
            } elseif (array_diff($pilihan, array_keys($orang))) {
                $pesan = 'Ada pilihan yang tidak ada di daftar. Tidak ada yang disimpan.';
                $tipe_pesan = 'danger';
            } elseif (!tanggalSjSah($tanggal) || $tanggal > date('Y-m-d')) {
                $pesan = 'Tanggal surat wajib diisi, dan tidak boleh di masa depan.';
                $tipe_pesan = 'danger';
            } else {
                $kini = date('Y-m-d H:i:s');
                $dicatat = 0;
                $conn->begin_transaction();
                try {
                    // Yang sudah punya catatan dilewati, bukan ditimpa: pembaruannya
                    // tidak mengubah apa pun, jadi affected_rows-nya 0.
                    $stmt = $conn->prepare("INSERT INTO persetujuan_sidik_jari (identitas, status, tanggal_surat, keterangan, admin_id, dicatat) VALUES (?, 'setuju', ?, '', ?, ?) ON DUPLICATE KEY UPDATE identitas = identitas");
                    foreach ($pilihan as $identitas) {
                        $stmt->bind_param('ssis', $identitas, $tanggal, $admin_id, $kini);
                        $stmt->execute();
                        $dicatat += $stmt->affected_rows > 0 ? 1 : 0;
                    }
                    $stmt->close();
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
                $dilewati = count($pilihan) - $dicatat;
                $pesan = $dicatat . ' persetujuan dicatat.' . ($dilewati > 0 ? ' ' . $dilewati . ' dilewati karena sudah punya catatan.' : '');
            }
        } else {
            $pesan = 'Tindakan tidak dikenal.';
            $tipe_pesan = 'danger';
        }
    }

    $hasil = $conn->query("SELECT identitas, status, tanggal_surat, keterangan, tidak_terbaca FROM persetujuan_sidik_jari");
    while ($b = $hasil->fetch_assoc()) {
        $persetujuan[$b['identitas']] = $b;
    }
    // identitas => [jari => ['mutu', 'terdaftar']], hanya di perangkat yang
    // sekarang aktif. Catatan dari perangkat lain sudah tidak punya templat.
    // $terdaftar_di memuat hal yang sama per perangkat, untuk skrip: yang
    // bisa didaftarkan ulang atau dicabut hanya jari di PC kiosk itu sendiri.
    $hasil = $conn->query("SELECT identitas, jari, perangkat, mutu, terdaftar FROM pendaftaran_sidik_jari WHERE status = 'aktif' ORDER BY identitas, terdaftar");
    while ($b = $hasil->fetch_assoc()) {
        if (isset($perangkat_aktif[$b['perangkat']])) {
            $terdaftar[$b['identitas']][$b['jari']] = ['mutu' => (int)$b['mutu'], 'terdaftar' => $b['terdaftar']];
            $terdaftar_di[$b['identitas']][$b['perangkat']][$b['jari']] = ['mutu' => (int)$b['mutu'], 'tanggal' => tanggalSj($b['terdaftar'])];
            $perangkat_aktif[$b['perangkat']]['jari']++;
        } else {
            $usang++;
        }
    }

    // Keadaan tiap orang di daftar.
    foreach ($orang as $identitas => $o) {
        $p = $persetujuan[$identitas] ?? null;
        $jari = $terdaftar[$identitas] ?? [];
        $kelompok_ada[$o['kelompok']] = ($kelompok_ada[$o['kelompok']] ?? 0) + 1;
        $hitung['orang']++;
        $hitung[$p === null ? 'belum' : $p['status']]++;
        if (count($jari) >= SJ_JARI_PER_ORANG) {
            $hitung['lengkap']++;
        } elseif ($jari) {
            $hitung['sebagian']++;
        } elseif ($p !== null && $p['status'] === 'setuju' && $p['tidak_terbaca'] !== null) {
            $hitung['tidak_terbaca']++;
        }
    }

    // Jarinya masih terdaftar, padahal orangnya sudah tidak boleh atau tidak
    // lagi setuju.
    foreach ($terdaftar as $identitas => $jari) {
        $p = $persetujuan[$identitas] ?? null;
        $sebab = '';
        $nama = '';
        if (!isset($orang[$identitas])) {
            $siapa = sjOrang($conn, $identitas);
            $sebab = $siapa === null ? 'Orangnya sudah tidak ada di data.' : $siapa['alasan'];
            $nama = $siapa === null ? '' : $siapa['nama'];
        } elseif ($p === null || $p['status'] !== 'setuju') {
            $sebab = $p === null ? 'Persetujuannya tidak tercatat.' : 'Persetujuannya dicatat menolak.';
            $nama = $orang[$identitas]['nama'];
        }
        if ($sebab !== '') {
            $perlu_dicabut[$identitas] = ['nama' => $nama, 'sebab' => $sebab, 'jari' => array_keys($jari)];
        }
    }
} catch (mysqli_sql_exception $e) {
    if ($e->getCode() === 1146) {
        $tabel = preg_match("/\\.(\\w+)' doesn't exist/", $e->getMessage(), $m) === 1 ? 'Tabel ' . $m[1] : 'Sebuah tabel';
        $galat_data = $tabel . ' belum ada. Jalankan SQL sub-langkah 4.3 di phpMyAdmin.';
    } else {
        error_log('[sidik_jari] ' . get_class($e) . ': ' . $e->getMessage());
        $galat_data = 'Data sidik jari tidak bisa dibaca atau disimpan. Lihat error_log.';
    }
}

$kelompok = (string)($_GET['kelompok'] ?? '');
if (!isset($kelompok_ada[$kelompok])) {
    $kelompok = '';
}
$ubah = (string)($_GET['ubah'] ?? '');
if (!isset($orang[$ubah])) {
    $ubah = '';
}
$nama_jari = sjDaftarJari();
$hari_ini = date('Y-m-d');
$tautan = function ($k, $u = '') {
    $q = array_filter(['kelompok' => $k, 'ubah' => $u], fn($v) => $v !== '');
    return 'sidik_jari.php' . ($q ? '?' . http_build_query($q) : '');
};
?>

<h1 class="mb-1">Pendaftaran Sidik Jari</h1>
<p class="text-muted mb-4">
    Templat sidik jari hanya disimpan di PC kiosk. Halaman ini mencatat persetujuan, dan menampilkan jari yang sudah
    terdaftar menurut tanda terima dari PC kiosk. Sidik jari belum dipakai untuk absensi.
</p>

<?php if ($konfigurasi === null): ?>
    <div class="alert alert-warning">
        <strong>Sidik jari belum dikonfigurasi di server.</strong>
        Persetujuan tetap bisa dicatat. Rinciannya ada di Laporan → Kiosk Sidik Jari.
    </div>
<?php elseif (!$perangkat_aktif): ?>
    <div class="alert alert-warning">
        <strong>Belum ada kiosk yang aktif di konfigurasi server.</strong>
        Persetujuan tetap bisa dicatat, tetapi jari belum bisa didaftarkan.
    </div>
<?php endif; ?>

<?php if ($galat_data !== ''): ?>
    <div class="alert alert-danger"><?php echo amanSj($galat_data); ?></div>
<?php endif; ?>

<?php if ($pesan !== ''): ?>
    <div class="alert alert-<?php echo $tipe_pesan === 'danger' ? 'danger' : 'success'; ?>"><?php echo amanSj($pesan); ?></div>
<?php endif; ?>

<?php // Tanpa data yang utuh, ringkasan, daftar, dan formulirnya tidak ditampilkan. ?>
<?php if ($galat_data === ''): ?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h5 class="card-title">Ringkasan</h5>
        <div class="row text-center g-2">
            <div class="col"><div class="fs-4 fw-bold"><?php echo $hitung['orang']; ?></div><div class="small text-muted">orang di daftar</div></div>
            <div class="col"><div class="fs-4 fw-bold text-success"><?php echo $hitung['setuju']; ?></div><div class="small text-muted">setuju</div></div>
            <div class="col"><div class="fs-4 fw-bold text-danger"><?php echo $hitung['menolak']; ?></div><div class="small text-muted">menolak</div></div>
            <div class="col"><div class="fs-4 fw-bold text-secondary"><?php echo $hitung['belum']; ?></div><div class="small text-muted">belum dicatat</div></div>
            <div class="col"><div class="fs-4 fw-bold text-success"><?php echo $hitung['lengkap']; ?></div><div class="small text-muted"><?php echo SJ_JARI_PER_ORANG; ?> jari terdaftar</div></div>
            <div class="col"><div class="fs-4 fw-bold text-warning"><?php echo $hitung['sebagian']; ?></div><div class="small text-muted">baru 1 jari</div></div>
            <div class="col"><div class="fs-4 fw-bold text-warning"><?php echo $hitung['tidak_terbaca']; ?></div><div class="small text-muted">jari tidak terbaca</div></div>
        </div>
        <?php if ($usang > 0 && $konfigurasi !== null): ?>
            <p class="small text-muted mt-3 mb-0"><?php echo $usang; ?> catatan pendaftaran berasal dari perangkat yang tidak lagi aktif, dan tidak dihitung.</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($perangkat_aktif): ?>
    <?php
    // Nama dan kelompok tiap orang, untuk skrip: jembatan menyebut orang
    // lewat identitasnya.
    $peta_orang = [];
    foreach ($orang as $identitas => $o) {
        $peta_orang[$identitas] = [$o['nama'], $o['kelompok']];
    }
    $json_skrip = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    ?>
    <div class="card shadow-sm mb-4" id="sj-kiosk"
         data-csrf="<?php echo amanSj($csrf); ?>"
         data-perangkat="<?php echo amanSj(json_encode($perangkat_aktif, $json_skrip)); ?>"
         data-nama-jari="<?php echo amanSj(json_encode($nama_jari, $json_skrip)); ?>"
         data-orang="<?php echo amanSj(json_encode((object)$peta_orang, $json_skrip)); ?>">
        <div class="card-body">
            <h5 class="card-title">PC kiosk</h5>
            <p class="small text-muted">
                Mendaftarkan dan mencabut jari hanya bisa dikerjakan di Chrome PC kiosk, tempat pembaca sidik jari
                terpasang. Di komputer lain bagian ini tidak dipakai.
            </p>
            <table class="table table-sm mb-2">
                <tbody>
                    <tr><th scope="row" style="width: 8rem">Jembatan</th><td id="sj-k-jembatan" class="text-muted">Belum dihubungi.</td></tr>
                    <tr><th scope="row">Pembaca</th><td id="sj-k-pembaca" class="text-muted">—</td></tr>
                    <tr><th scope="row">Templat</th><td id="sj-k-galeri" class="text-muted">—</td></tr>
                </tbody>
            </table>
            <button type="button" class="btn btn-sm btn-outline-primary" id="sj-hubungkan">Hubungkan ke PC ini</button>
            <ul class="small list-unstyled mb-0 mt-2" id="sj-k-catatan" aria-live="polite"></ul>
        </div>
    </div>
<?php endif; ?>

<?php if ($perlu_dicabut): ?>
    <div class="card shadow-sm mb-4 border-danger">
        <div class="card-body">
            <h5 class="card-title text-danger">Perlu dicabut</h5>
            <p class="small text-muted">Jari orang-orang ini masih tersimpan di PC kiosk. Pencabutannya dikerjakan di PC kiosk.</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Orang</th><th>Sebab</th><th>Jari yang masih terdaftar</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($perlu_dicabut as $identitas => $c): ?>
                            <tr class="sj-perlu-dicabut" data-identitas="<?php echo amanSj($identitas); ?>">
                                <td><?php echo $c['nama'] !== '' ? amanSj($c['nama']) : '<span class="text-muted">tidak dikenal</span>'; ?> <code class="small"><?php echo amanSj($identitas); ?></code></td>
                                <td><?php echo amanSj($c['sebab']); ?></td>
                                <td><?php echo amanSj(implode(', ', array_map(fn($j) => $nama_jari[$j] ?? $j, $c['jari']))); ?></td>
                                <?php // Daftar ini hanya terisi kalau ada perangkat aktif, jadi panel kiosknya pasti ada. ?>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-danger sj-cabut" disabled>Cabut</button>
                                    <div class="small sj-cabut-hasil" aria-live="polite"></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($ubah !== ''): ?>
    <?php $p = $persetujuan[$ubah] ?? null; ?>
    <div class="card shadow-sm mb-4 border-primary">
        <div class="card-body">
            <h5 class="card-title">Persetujuan: <?php echo amanSj($orang[$ubah]['nama']); ?> <span class="text-muted small"><?php echo amanSj($orang[$ubah]['kelompok']); ?></span></h5>
            <form method="post" action="<?php echo amanSj($tautan($kelompok)); ?>" class="row g-3 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo amanSj($csrf); ?>">
                <input type="hidden" name="aksi" value="persetujuan">
                <input type="hidden" name="identitas" value="<?php echo amanSj($ubah); ?>">
                <div class="col-md-3">
                    <label class="form-label" for="status">Persetujuan</label>
                    <select class="form-select" id="status" name="status">
                        <option value="setuju" <?php echo $p !== null && $p['status'] === 'setuju' || $p === null ? 'selected' : ''; ?>>Setuju</option>
                        <option value="menolak" <?php echo $p !== null && $p['status'] === 'menolak' ? 'selected' : ''; ?>>Menolak</option>
                        <option value="belum">Belum dicatat (hapus catatan)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="tanggal_surat">Tanggal surat</label>
                    <input type="date" class="form-control" id="tanggal_surat" name="tanggal_surat" max="<?php echo amanSj($hari_ini); ?>" value="<?php echo amanSj($p['tanggal_surat'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="keterangan">Keterangan</label>
                    <input type="text" class="form-control" id="keterangan" name="keterangan" maxlength="150" value="<?php echo amanSj($p['keterangan'] ?? ''); ?>">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Simpan</button>
                    <a class="btn btn-outline-secondary" href="<?php echo amanSj($tautan($kelompok)); ?>">Batal</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?php echo $kelompok === '' ? 'active' : ''; ?>" href="sidik_jari.php">Semua (<?php echo $hitung['orang']; ?>)</a></li>
    <?php foreach ($kelompok_ada as $k => $jumlah): ?>
        <li class="nav-item"><a class="nav-link <?php echo $kelompok === $k ? 'active' : ''; ?>" href="<?php echo amanSj($tautan($k)); ?>"><?php echo amanSj($k); ?> (<?php echo $jumlah; ?>)</a></li>
    <?php endforeach; ?>
</ul>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <?php if (!$orang): ?>
            <p class="mb-0 text-muted">Belum ada guru piket atau siswa yang bisa ditampilkan.</p>
        <?php else: ?>
            <form method="post" action="<?php echo amanSj($tautan($kelompok)); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo amanSj($csrf); ?>">
                <input type="hidden" name="aksi" value="persetujuan_banyak">
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr><th></th><th>Nama</th><th>Kelompok</th><th>Persetujuan</th><th>Sidik jari</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orang as $identitas => $o): ?>
                                <?php
                                if ($kelompok !== '' && $o['kelompok'] !== $kelompok) {
                                    continue;
                                }
                                $p = $persetujuan[$identitas] ?? null;
                                $jari = $terdaftar[$identitas] ?? [];
                                ?>
                                <?php
                                $boleh_daftar = $perangkat_aktif && $p !== null && $p['status'] === 'setuju';
                                $tanda_tak_terbaca = $p !== null && $p['status'] === 'setuju' && $p['tidak_terbaca'] !== null ? tanggalSj($p['tidak_terbaca']) : '';
                                ?>
                                <tr class="sj-orang" data-identitas="<?php echo amanSj($identitas); ?>"
                                    data-jari="<?php echo amanSj(json_encode((object)($terdaftar_di[$identitas] ?? []))); ?>"
                                    data-tidak-terbaca="<?php echo amanSj($tanda_tak_terbaca); ?>">
                                    <td>
                                        <?php if ($p === null): ?>
                                            <input class="form-check-input" type="checkbox" name="identitas[]" value="<?php echo amanSj($identitas); ?>" aria-label="Pilih <?php echo amanSj($o['nama']); ?>">
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo amanSj($o['nama']); ?></td>
                                    <td>
                                        <?php echo amanSj($o['kelompok']); ?>
                                        <?php if ($o['pkl']): ?><span class="badge bg-info text-dark">PKL</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($p === null): ?>
                                            <span class="badge bg-secondary">belum dicatat</span>
                                        <?php else: ?>
                                            <span class="badge bg-<?php echo $p['status'] === 'setuju' ? 'success' : 'danger'; ?>"><?php echo amanSj($p['status']); ?></span>
                                            <?php if ($p['tanggal_surat']): ?><span class="small text-muted">surat <?php echo amanSj(tanggalSj($p['tanggal_surat'])); ?></span><?php endif; ?>
                                            <?php if ($p['keterangan'] !== ''): ?><div class="small text-muted"><?php echo amanSj($p['keterangan']); ?></div><?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="sj-sel-jari">
                                        <?php if ($jari): ?>
                                            <?php foreach ($jari as $j => $d): ?>
                                                <div><?php echo amanSj($nama_jari[$j] ?? $j); ?> <span class="small text-muted">mutu <?php echo (int)$d['mutu']; ?>, <?php echo amanSj(tanggalSj($d['terdaftar'])); ?></span></div>
                                            <?php endforeach; ?>
                                        <?php elseif ($p !== null && $p['status'] === 'setuju' && $p['tidak_terbaca'] !== null): ?>
                                            <span class="badge bg-warning text-dark">jari tidak terbaca</span>
                                            <span class="small text-muted"><?php echo amanSj(tanggalSj($p['tidak_terbaca'])); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">belum</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <?php if ($boleh_daftar): ?>
                                            <button type="button" class="btn btn-sm btn-primary sj-buka" disabled>Sidik jari</button>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-outline-primary" href="<?php echo amanSj($tautan($kelompok, $identitas)); ?>">Ubah</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label small mb-1" for="tanggal_banyak">Tanggal surat untuk yang dicentang</label>
                        <input type="date" class="form-control" id="tanggal_banyak" name="tanggal_surat" max="<?php echo amanSj($hari_ini); ?>">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-success">Catat setuju untuk yang dicentang</button>
                    </div>
                </div>
                <p class="small text-muted mt-2 mb-0">
                    Kotak centang hanya ada untuk yang belum punya catatan. Catatan yang sudah ada diubah lewat tombol Ubah.
                    Tombol Sidik jari hanya ada untuk yang setuju, dan baru aktif setelah PC kiosk terhubung.
                </p>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($perangkat_aktif): ?>
    <?php // Panel pendaftaran. Skrip memindahkannya ke bawah baris orang yang sedang dikerjakan. ?>
    <div id="sj-panel" class="border border-primary rounded p-3 bg-light text-start" hidden>
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div><strong id="sj-p-nama"></strong> <span class="text-muted small" id="sj-p-kelompok"></span></div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="sj-p-tutup">Tutup</button>
        </div>
        <div class="mb-2" id="sj-p-jari"></div>
        <div class="d-flex gap-3 align-items-center">
            <canvas id="sj-p-pratinjau" width="96" height="108" class="border bg-white flex-shrink-0" aria-label="Tempelan terakhir"></canvas>
            <div>
                <div class="fw-bold" id="sj-p-langkah" aria-live="polite"></div>
                <div class="small" id="sj-p-pesan" aria-live="polite"></div>
            </div>
        </div>
        <div class="mt-3 d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="sj-p-batal" hidden>Batalkan jari ini</button>
            <button type="button" class="btn btn-sm btn-primary" id="sj-p-kirim-ulang" hidden>Kirim ulang tanda terima</button>
            <button type="button" class="btn btn-sm btn-outline-warning" id="sj-p-tidak-terbaca" hidden>Tandai jari tidak terbaca</button>
            <button type="button" class="btn btn-sm btn-outline-danger" id="sj-p-cabut" hidden>Cabut semua jari orang ini</button>
        </div>
    </div>
<?php endif; ?>
<?php endif; ?>

<?php
if ($galat_data === '' && $perangkat_aktif) {
    // Modul tangkap dan skrip alurnya dikeluarkan lewat wadah skrip di
    // footer, sesudah Bootstrap. is_readable() dulu: tanpa modul itu halaman
    // tetap jalan, dan skripnya melaporkan bahwa modulnya tidak ada.
    $modul_tangkap = __DIR__ . '/../includes/sj_tangkap_klien.php';
    ob_start();
    if (is_readable($modul_tangkap)) {
        include $modul_tangkap;
    }
    $custom_script = ob_get_clean() . <<<'HTML'
<script>
(function () {
    'use strict';

    var JEMBATAN = 'http://127.0.0.1:47890';
    var TEMPLAT_PER_JARI = 4;
    var JARI_PER_ORANG = 2;
    var BATAS = 15000;          // batas waktu tiap permintaan
    var akar = document.getElementById('sj-kiosk');
    if (!akar) {
        return;
    }
    var CSRF = akar.dataset.csrf;
    var PERANGKAT = JSON.parse(akar.dataset.perangkat);     // id => { sidik: [...], jari: n }
    var NAMA_JARI = JSON.parse(akar.dataset.namaJari);      // kode => nama, berurutan seperti ditawarkan
    var ORANG = JSON.parse(akar.dataset.orang);             // identitas => [nama, kelompok]
    var URUTAN_JARI = Object.keys(NAMA_JARI);

    function el(id) {
        return document.getElementById(id);
    }
    var selJembatan = el('sj-k-jembatan');
    var selPembaca = el('sj-k-pembaca');
    var selGaleri = el('sj-k-galeri');
    var tombolHubung = el('sj-hubungkan');
    var daftarCatatan = el('sj-k-catatan');
    var panel = el('sj-panel');
    var kanvas = el('sj-p-pratinjau');

    var jembatan = null;        // isi /status terakhir yang lolos pemeriksaan
    var terbuka = null;         // identitas yang panelnya terbuka
    var sesi = null;            // { identitas, jari, id, tantangan, tahap, antre }
    var tertunda = null;        // tanda terima yang belum tercatat di server
    var sibukLain = false;      // pencabutan atau tanda "tidak terbaca" sedang berjalan
    var pembacaSiap = false;    // ADC terhubung dan ada pembaca yang terpasang
    var gagalTadi = {};         // identitas => { jari: true } sejak halaman dimuat
    var barisPanel = null;

    // ---------- Pembantu ----------

    function tulis(sel, teks, kelas) {
        sel.textContent = teks;
        sel.className = kelas || '';
    }

    var KELAS_JENIS = { galat: 'text-danger', peringatan: 'text-warning-emphasis', ok: 'text-success', info: 'text-muted' };

    function catatKiosk(teks, jenis) {
        var butir = document.createElement('li');
        butir.textContent = new Date().toLocaleTimeString('id-ID', { hour12: false }) + ' ' + teks;
        butir.className = KELAS_JENIS[jenis] || KELAS_JENIS.info;
        daftarCatatan.prepend(butir);
        while (daftarCatatan.children.length > 6) {
            daftarCatatan.lastElementChild.remove();
        }
    }

    function besarAwal(teks) {
        return teks.charAt(0).toUpperCase() + teks.slice(1);
    }

    function namaJari(jari) {
        return NAMA_JARI[jari] || jari;
    }

    function namaOrang(identitas) {
        return ORANG[identitas] ? ORANG[identitas][0] : identitas;
    }

    function tunda(ms) {
        return new Promise(function (selesai) { window.setTimeout(selesai, ms); });
    }

    // Jawaban beserta kodenya; isi null kalau jawabannya bukan JSON. Hasilnya
    // null kalau permintaannya tidak sampai atau melewati batas waktu.
    async function panggil(url, kiriman) {
        var pemutus = new AbortController();
        var pewaktu = window.setTimeout(function () { pemutus.abort(); }, BATAS);
        var opsi = { signal: pemutus.signal };
        if (kiriman !== undefined) {
            opsi.method = 'POST';
            opsi.headers = { 'Content-Type': 'application/json' };
            opsi.body = JSON.stringify(kiriman);
        }
        try {
            var jawaban = await fetch(url, opsi);
            var isi = null;
            try {
                isi = await jawaban.json();
            } catch (e) { /* bukan JSON */ }
            return { kode: jawaban.status, isi: isi };
        } catch (e) {
            return null;
        } finally {
            window.clearTimeout(pewaktu);
        }
    }

    // Alasan penolakan, sebagai kalimat utuh.
    function pesan(hasil) {
        if (hasil === null) {
            return 'Tidak ada jawaban.';
        }
        return hasil.isi && typeof hasil.isi.message === 'string' ? hasil.isi.message : 'Jawaban ' + hasil.kode + '.';
    }

    function sesiBerjalan() {
        return sesi !== null || sibukLain;
    }

    // ---------- Jembatan dan pembaca ----------

    function versiCukup(versi) {
        var m = /^(\d+)\.(\d+)\./.exec(String(versi));
        return !!m && (Number(m[1]) > 0 || Number(m[2]) >= 3);
    }

    function versiRingkas(versi) {
        return String(versi).split('+')[0];
    }

    // Isi /status kalau jembatan di PC ini bisa dipakai mendaftar; selain
    // itu null, dan sebabnya ditulis di baris Jembatan.
    async function muatStatus() {
        var status = await panggil(JEMBATAN + '/status');
        if (status === null) {
            tulis(selJembatan, 'Jembatan di PC ini tidak menjawab. Pendaftaran hanya bisa dikerjakan di PC kiosk: layanan '
                + 'JembatanSidikJari harus hidup, dan kebijakan Chrome untuk 127.0.0.1 harus terpasang.', 'text-danger');
            return null;
        }
        var s = status.isi;
        if (status.kode !== 200 || !s || typeof s.perangkat !== 'string' || !s.galeri) {
            tulis(selJembatan, 'Yang menjawab di ' + JEMBATAN + ' bukan jembatan sidik jari.', 'text-danger');
            return null;
        }
        if (!versiCukup(s.versi)) {
            tulis(selJembatan, 'Jembatan di PC ini versi ' + versiRingkas(s.versi) + '. Pendaftaran butuh versi 0.3.0 ke atas.', 'text-danger');
            return null;
        }
        var server = PERANGKAT[s.perangkat];
        if (!server) {
            tulis(selJembatan, 'Jembatan ' + s.perangkat + ' belum dipasangkan dengan server, atau tidak aktif. Lihat Laporan → Kiosk Sidik Jari.', 'text-danger');
            return null;
        }
        if (server.sidik.indexOf(s.sidik_kunci) < 0) {
            tulis(selJembatan, 'Kunci jembatan ' + s.perangkat + ' tidak sama dengan kunci di server. Lihat Laporan → Kiosk Sidik Jari.', 'text-danger');
            return null;
        }
        tulis(selJembatan, 'Terhubung: ' + s.perangkat + ', versi ' + versiRingkas(s.versi) + '.', 'text-success');
        return s;
    }

    // bandingkan: jumlah templat dicocokkan dengan catatan server. Hanya
    // bermakna tepat setelah halaman dimuat, selagi kedua angka sama barunya.
    function tampilkanGaleri(s, bandingkan) {
        var g = s.galeri;
        var teks = g.templat + ' templat dari ' + g.identitas + ' orang';
        var kelas = '';
        if (g.rekaman_tertolak > 0) {
            teks += ', dan ' + g.rekaman_tertolak + ' rekaman yang tidak bisa dibuka';
            kelas = 'text-danger';
        }
        if (bandingkan) {
            var tercatat = PERANGKAT[s.perangkat].jari;
            var harap = tercatat * TEMPLAT_PER_JARI;
            if (g.templat === harap) {
                teks += '. Sama dengan catatan server (' + tercatat + ' jari)';
            } else if (g.templat > harap) {
                teks += '. Server hanya mencatat ' + tercatat + ' jari (' + harap + ' templat): mungkin ada data uji yang belum '
                    + 'dihapus, atau pendaftaran yang belum tercatat';
                kelas = 'text-danger';
            } else {
                teks += '. Server mencatat ' + tercatat + ' jari (' + harap + ' templat): jari yang templatnya hilang harus '
                    + 'didaftarkan ulang';
                kelas = 'text-danger';
            }
        }
        tulis(selGaleri, teks + '.', kelas);
    }

    function tampilkanPembaca(k) {
        pembacaSiap = k.terhubung === true && k.pembaca > 0;
        if (k.terhubung === null) {
            tulis(selPembaca, 'Menghubungi Authentication Device Client…', 'text-muted');
        } else if (k.terhubung === false) {
            tulis(selPembaca, 'Authentication Device Client tidak terhubung.', 'text-danger');
            tombolHubung.hidden = false;
        } else if (k.pembaca === 0) {
            tulis(selPembaca, 'Tidak ada pembaca yang terpasang.', 'text-danger');
        } else if (!k.jendela_aktif) {
            tulis(selPembaca, 'Jendela ini sedang tidak aktif: klik halaman ini sebelum menempelkan jari.', 'text-warning-emphasis fw-bold');
        } else if (!k.menangkap) {
            tulis(selPembaca, 'Pembaca terpasang, penangkapan belum berjalan.', 'text-warning-emphasis');
        } else {
            tulis(selPembaca, 'Siap menerima tempelan.', 'text-success');
        }
    }

    function aturTombolBaris() {
        var mati = jembatan === null || sesiBerjalan() || tertunda !== null;
        document.querySelectorAll('.sj-buka, .sj-cabut').forEach(function (tombol) {
            tombol.disabled = mati;
        });
    }

    var menghubungkan = false;

    async function hubungkan() {
        if (menghubungkan) {
            return;
        }
        menghubungkan = true;
        tombolHubung.disabled = true;
        tulis(selJembatan, 'Menghubungi jembatan…', 'text-muted');
        try {
            jembatan = await muatStatus();
            aturTombolBaris();
            if (jembatan === null) {
                return;
            }
            tampilkanGaleri(jembatan, true);
            tombolHubung.hidden = true;
            if (!window.SjTangkap) {
                tulis(selPembaca, 'Modul penangkapan tidak ada di server (includes/sj_tangkap_klien.php).', 'text-danger');
                return;
            }
            tulis(selPembaca, 'Menghubungi Authentication Device Client…', 'text-muted');
            // Tidak ditunggu: ADC yang diam tidak boleh menahan tombol ini.
            // Keadaannya datang lewat tampilkanPembaca().
            window.SjTangkap.mulai({ sampel: terimaSampel, keadaan: tampilkanPembaca, catat: catatKiosk });
        } finally {
            menghubungkan = false;
            tombolHubung.disabled = false;
        }
    }

    // Setelah galeri berubah. Perbandingan dengan catatan server tidak
    // diulang: angka server di halaman ini sudah tidak baru.
    async function segarkanStatus() {
        var s = await muatStatus();
        if (s !== null) {
            jembatan = s;
            tampilkanGaleri(s, false);
        }
    }

    // ---------- Keadaan jari tiap orang ----------

    function barisOrang(identitas) {
        var semua = document.querySelectorAll('tr.sj-orang');
        for (var i = 0; i < semua.length; i++) {
            if (semua[i].dataset.identitas === identitas) {
                return semua[i];
            }
        }
        return null;
    }

    // perangkat => jari => { mutu, tanggal }, dibaca sekali dari baris lalu
    // diubah di tempat.
    function jariSemua(baris) {
        if (!baris.sjJari) {
            baris.sjJari = JSON.parse(baris.dataset.jari || '{}');
        }
        return baris.sjJari;
    }

    // Jari orang itu yang terdaftar di PC ini.
    function jariDiSini(identitas) {
        var baris = barisOrang(identitas);
        if (!baris || jembatan === null) {
            return {};
        }
        var semua = jariSemua(baris);
        if (!semua[jembatan.perangkat]) {
            semua[jembatan.perangkat] = {};
        }
        return semua[jembatan.perangkat];
    }

    function hariIni() {
        var kini = new Date();
        return ('0' + kini.getDate()).slice(-2) + '/' + ('0' + (kini.getMonth() + 1)).slice(-2) + '/' + kini.getFullYear();
    }

    // Kolom "Sidik jari" di baris orang itu, disusun ulang seperti keluaran
    // server.
    function gambarSelJari(identitas) {
        var baris = barisOrang(identitas);
        if (!baris) {
            return;
        }
        var sel = baris.querySelector('.sj-sel-jari');
        var semua = jariSemua(baris);
        sel.replaceChildren();
        Object.keys(semua).forEach(function (perangkat) {
            Object.keys(semua[perangkat]).forEach(function (jari) {
                var butir = document.createElement('div');
                var rinci = document.createElement('span');
                rinci.className = 'small text-muted';
                rinci.textContent = 'mutu ' + semua[perangkat][jari].mutu + ', ' + semua[perangkat][jari].tanggal;
                butir.append(namaJari(jari) + ' ', rinci);
                sel.appendChild(butir);
            });
        });
        if (sel.children.length > 0) {
            return;
        }
        if (baris.dataset.tidakTerbaca) {
            var lencana = document.createElement('span');
            lencana.className = 'badge bg-warning text-dark';
            lencana.textContent = 'jari tidak terbaca';
            var kapan = document.createElement('span');
            kapan.className = 'small text-muted';
            kapan.textContent = baris.dataset.tidakTerbaca;
            sel.append(lencana, ' ', kapan);
        } else {
            var belum = document.createElement('span');
            belum.className = 'text-muted';
            belum.textContent = 'belum';
            sel.appendChild(belum);
        }
    }

    // Jari berikutnya yang ditawarkan: kedua telunjuk dulu, jari tengah
    // sebagai pengganti jari yang gagal. null kalau dua jari sudah terdaftar
    // atau semuanya sudah dicoba.
    function jariSaran(identitas) {
        var punya = jariDiSini(identitas);
        var gagal = gagalTadi[identitas] || {};
        if (Object.keys(punya).length >= JARI_PER_ORANG) {
            return null;
        }
        for (var i = 0; i < URUTAN_JARI.length; i++) {
            if (!punya[URUTAN_JARI[i]] && !gagal[URUTAN_JARI[i]]) {
                return URUTAN_JARI[i];
            }
        }
        return null;
    }

    // ---------- Panel ----------

    function tampilLangkah(langkah, teks, jenis) {
        el('sj-p-langkah').textContent = langkah;
        tulis(el('sj-p-pesan'), teks || '', 'small ' + (KELAS_JENIS[jenis] || ''));
    }

    function tampilPesan(teks, jenis) {
        tulis(el('sj-p-pesan'), teks, 'small ' + (KELAS_JENIS[jenis] || ''));
    }

    function kosongkanPratinjau() {
        var ctx = kanvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, kanvas.width, kanvas.height);
    }

    function gambarTombolJari() {
        var wadah = el('sj-p-jari');
        var punya = jariDiSini(terbuka);
        var saran = jariSaran(terbuka);
        var gagal = gagalTadi[terbuka] || {};
        var kunci = sesiBerjalan() || tertunda !== null;
        wadah.replaceChildren();
        URUTAN_JARI.forEach(function (jari) {
            var tombol = document.createElement('button');
            tombol.type = 'button';
            tombol.dataset.jari = jari;
            tombol.disabled = kunci;
            tombol.className = 'btn btn-sm me-2 mb-2 ' + (punya[jari] ? 'btn-success' : jari === saran ? 'btn-primary' : 'btn-outline-primary');
            tombol.textContent = besarAwal(namaJari(jari)) + (punya[jari] ? ' ✓ mutu ' + punya[jari].mutu : gagal[jari] ? ' (gagal tadi)' : '');
            tombol.title = punya[jari] ? 'Sudah terdaftar. Klik untuk mendaftarkan ulang jari ini.' : 'Klik untuk mendaftarkan jari ini.';
            tombol.addEventListener('click', function () {
                mulaiJari(terbuka, jari);
            });
            wadah.appendChild(tombol);
        });
    }

    // Menyusun ulang panel sesuai keadaan sekarang.
    function gambarPanel() {
        if (terbuka === null) {
            return;
        }
        var punya = Object.keys(jariDiSini(terbuka)).length;
        var gagal = Object.keys(gagalTadi[terbuka] || {}).length;
        var diam = !sesiBerjalan() && tertunda === null;
        gambarTombolJari();
        el('sj-p-batal').hidden = !(sesi !== null && (sesi.tahap === 'tempel' || sesi.tahap === 'uji'));
        el('sj-p-kirim-ulang').hidden = !(tertunda !== null && tertunda.bisaUlang);
        el('sj-p-tidak-terbaca').hidden = !(diam && punya === 0 && gagal > 0);
        el('sj-p-cabut').hidden = !(diam && punya > 0);
        el('sj-p-tutup').disabled = sibukLain || (sesi !== null && sesi.tahap === 'menyimpan');
        aturTombolBaris();
    }

    function langkahDiam() {
        var punya = Object.keys(jariDiSini(terbuka)).length;
        var saran = jariSaran(terbuka);
        if (punya >= JARI_PER_ORANG) {
            return 'Dua jari sudah terdaftar. Orang ini selesai.';
        }
        if (saran !== null) {
            return 'Berikutnya: ' + namaJari(saran) + '. Klik tombolnya untuk memulai.';
        }
        return punya > 0 ? 'Baru satu jari yang terdaftar, dan jari lain sudah dicoba.' : 'Semua jari sudah dicoba tanpa hasil.';
    }

    function bukaPanel(baris) {
        if (sesiBerjalan() || tertunda !== null || jembatan === null) {
            return;
        }
        terbuka = baris.dataset.identitas;
        if (barisPanel === null) {
            barisPanel = document.createElement('tr');
            var sel = document.createElement('td');
            sel.colSpan = baris.children.length;
            sel.appendChild(panel);
            barisPanel.appendChild(sel);
        }
        baris.after(barisPanel);
        panel.hidden = false;
        el('sj-p-nama').textContent = namaOrang(terbuka);
        el('sj-p-kelompok').textContent = ORANG[terbuka] ? ORANG[terbuka][1] : '';
        kosongkanPratinjau();
        tampilLangkah(langkahDiam(), '');
        gambarPanel();
        panel.scrollIntoView({ block: 'nearest' });
    }

    function tutupPanel() {
        if (tertunda !== null) {
            catatKiosk('Tanda terima ' + namaJari(tertunda.jari || '') + ' ' + namaOrang(tertunda.identitas)
                + ' tidak tercatat di server. Ulangi untuk orang itu.', 'galat');
            tertunda = null;
        }
        sesi = null;
        terbuka = null;
        panel.hidden = true;
        if (barisPanel !== null) {
            barisPanel.remove();
        }
        kosongkanPratinjau();
        aturTombolBaris();
    }

    // Mengakhiri sesi di halaman. Sesi di jembatan dibiarkan kedaluwarsa.
    function akhiriSesi(teks, jenis) {
        sesi = null;
        tampilLangkah(langkahDiam(), teks, jenis);
        gambarPanel();
    }

    // ---------- Pendaftaran satu jari ----------

    async function mulaiJari(identitas, jari) {
        if (sesiBerjalan() || tertunda !== null || jembatan === null) {
            return;
        }
        // Tanpa pembaca, sesi dan izinnya hanya akan menunggu sampai kedaluwarsa.
        if (!pembacaSiap) {
            tampilPesan('Pembaca belum siap. Lihat baris Pembaca di bagian PC kiosk.', 'galat');
            return;
        }
        var ini = { identitas: identitas, jari: jari, id: null, tantangan: null, tahap: 'menyiapkan', antre: Promise.resolve() };
        sesi = ini;
        kosongkanPratinjau();
        tampilLangkah('Menyiapkan ' + namaJari(jari) + '…', 'Meminta sesi ke jembatan dan izin ke server.');
        gambarPanel();

        var mulai = await panggil(JEMBATAN + '/daftar/mulai', { identitas: identitas, jari: jari });
        if (sesi !== ini) {
            return;
        }
        if (mulai === null || mulai.kode !== 200 || !mulai.isi) {
            akhiriSesi('Jembatan tidak membuka sesi: ' + pesan(mulai), 'galat');
            return;
        }
        var izin = await panggil('sj_izin.php', {
            csrf: CSRF, aksi: 'daftar', perangkat: jembatan.perangkat, tantangan_jembatan: mulai.isi.sesi, identitas: identitas, jari: jari
        });
        if (sesi !== ini) {
            return;
        }
        if (izin === null || izin.kode !== 200 || !izin.isi) {
            akhiriSesi('Server tidak memberi izin: ' + pesan(izin), 'galat');
            return;
        }
        var diizinkan = await panggil(JEMBATAN + '/daftar/izin', { sesi: mulai.isi.sesi, izin: izin.isi.izin });
        if (sesi !== ini) {
            return;
        }
        if (diizinkan === null || diizinkan.kode !== 200) {
            akhiriSesi('Jembatan menolak izin server: ' + pesan(diizinkan), 'galat');
            return;
        }
        ini.id = mulai.isi.sesi;
        ini.tantangan = izin.isi.tantangan;
        ini.tahap = 'tempel';
        tampilLangkah('Tempelkan ' + namaJari(jari) + ' (1 dari ' + TEMPLAT_PER_JARI + ').', 'Letakkan jari di tengah pembaca, lalu angkat.');
        gambarPanel();
    }

    function tandaiGagal(ini) {
        if (!gagalTadi[ini.identitas]) {
            gagalTadi[ini.identitas] = {};
        }
        gagalTadi[ini.identitas][ini.jari] = true;
    }

    // Penolakan akhir dari jembatan, dengan nama orang dan jari yang dikenal
    // halaman ini.
    function pesanGagal(ini, hasil) {
        var rincian = hasil.isi && hasil.isi.rincian ? hasil.isi.rincian : {};
        if (typeof rincian.identitas_lain !== 'string') {
            return pesan(hasil);
        }
        if (rincian.identitas_lain === ini.identitas) {
            return 'Jari ini sudah terdaftar sebagai ' + namaJari(rincian.jari_lain) + ' milik orang yang sama. Pilih jari lain.';
        }
        return 'Jari ini terlalu mirip dengan ' + namaJari(rincian.jari_lain) + ' milik ' + namaOrang(rincian.identitas_lain)
            + ' (skor ' + rincian.skor + '), jadi tidak didaftarkan. Kemiripan seperti ini bisa kebetulan: coba jari lain.';
    }

    function terimaSampel(s) {
        if (sesi === null || (sesi.tahap !== 'tempel' && sesi.tahap !== 'uji')) {
            catatKiosk('Tempelan diabaikan: tidak ada jari yang sedang menunggu tempelan.', 'peringatan');
            return;
        }
        var ini = sesi;
        s.gambar(kanvas);
        // Templat hanya cocok kalau diekstrak dengan DPI yang sama. DPI alat
        // yang berbeda jauh dari DPI galeri berarti drivernya berganti.
        var g = jembatan.galeri;
        if (g.templat > 0 && s.dpi && Math.abs(s.dpi - g.dpi) / g.dpi > 0.05) {
            akhiriSesi('DPI pembaca (' + s.dpi + ') berbeda dari DPI templat yang tersimpan (' + g.dpi + '). Driver pembaca '
                + 'mungkin berganti. Pendaftaran dihentikan; lihat README jembatan.', 'galat');
            return;
        }
        // Tempelan diproses berurutan, satu permintaan pada satu waktu.
        ini.antre = ini.antre.then(function () {
            if (sesi === ini) {
                return kirimTempelan(ini, s);
            }
        });
    }

    async function kirimTempelan(ini, s) {
        tampilPesan('Memeriksa tempelan…', 'info');
        var hasil = await panggil(JEMBATAN + '/daftar/tempel', { sesi: ini.id, format: 'raw', sampel: s.teks });
        if (sesi !== ini) {
            return;
        }
        if (hasil === null) {
            tampilPesan('Jembatan tidak menjawab. Tempelkan jari lagi.', 'galat');
            return;
        }
        var isi = hasil.isi || {};
        if (hasil.kode === 200 && isi.tahap === 'tempel') {
            tampilLangkah('Tempelkan ' + namaJari(ini.jari) + ' (' + (isi.diterima + 1) + ' dari ' + isi.butuh + ').',
                isi.dibuang ? isi.alasan : 'Tempelan ke-' + isi.diterima + ' diterima. Angkat jari, lalu tempelkan lagi.',
                isi.dibuang ? 'peringatan' : 'ok');
            return;
        }
        if (hasil.kode === 200 && isi.tahap === 'uji') {
            ini.tahap = 'uji';
            tampilLangkah('Tempelan uji: tempelkan ' + namaJari(ini.jari) + ' sekali lagi.',
                isi.dikenali === false ? 'Tempelan uji belum dikenali. Sisa ' + isi.sisa + ' kali coba.' : 'Keempat tempelan serasi, mutu ' + isi.mutu + '.',
                isi.dikenali === false ? 'peringatan' : 'ok');
            gambarPanel();
            return;
        }
        if (hasil.kode === 200 && isi.tahap === 'siap') {
            await simpanJari(ini);
            return;
        }
        if (isi.rincian && isi.rincian.tahap === 'gagal') {
            tandaiGagal(ini);
            akhiriSesi(pesanGagal(ini, hasil), 'galat');
            return;
        }
        if (hasil.kode === 404) {
            akhiriSesi('Sesi pendaftaran berakhir atau kedaluwarsa. Mulai lagi jari ini.', 'galat');
            return;
        }
        // Misalnya sampel yang sama persis dengan sebelumnya: sesinya masih
        // ada, dan pesan jembatan sudah menyebut apa yang harus dilakukan.
        tampilPesan(pesan(hasil), 'peringatan');
    }

    async function simpanJari(ini) {
        ini.tahap = 'menyimpan';
        tampilLangkah('Menyimpan ' + namaJari(ini.jari) + '…', 'Tempelan uji dikenali.', 'ok');
        gambarPanel();
        var simpan = await panggil(JEMBATAN + '/daftar/selesai', { sesi: ini.id, tantangan: ini.tantangan });
        if (sesi !== ini) {
            return;
        }
        if (simpan === null) {
            akhiriSesi('Jembatan tidak menjawab saat menyimpan. Kalau jari ini ternyata tersimpan, baris Templat di atas '
                + 'akan berbeda dari catatan server setelah halaman dimuat ulang; daftarkan ulang jarinya.', 'galat');
            segarkanStatus();
            return;
        }
        if (simpan.kode !== 200 || !simpan.isi) {
            if (simpan.isi && simpan.isi.rincian && simpan.isi.rincian.tahap === 'gagal') {
                tandaiGagal(ini);
            }
            akhiriSesi(pesanGagal(ini, simpan), 'galat');
            return;
        }
        // Sejak ini templatnya ada di jembatan. Yang tersisa mencatatnya.
        sesi = null;
        tertunda = {
            jenis: 'daftar', identitas: ini.identitas, jari: ini.jari, mutu: simpan.isi.mutu, bisaUlang: false,
            kiriman: {
                csrf: CSRF, aksi: 'daftar', perangkat: simpan.isi.perangkat, tantangan: ini.tantangan, identitas: ini.identitas,
                jari: ini.jari, mutu: simpan.isi.mutu, tanda_tangan: simpan.isi.tanda_tangan
            }
        };
        await kirimTandaTerima();
    }

    // Mengirim tanda terima jembatan ke server. Galat sementara dicoba tiga
    // kali. 409 berarti tanda terima itu sudah tercatat oleh kiriman
    // sebelumnya yang jawabannya tidak sampai.
    async function catatDiServer(kiriman) {
        var hasil = null;
        for (var coba = 0; coba < 3; coba++) {
            if (coba > 0) {
                await tunda(1500);
            }
            hasil = await panggil('sj_catat.php', kiriman);
            if (hasil !== null && hasil.kode !== 500 && hasil.kode !== 503) {
                break;
            }
        }
        return { tercatat: hasil !== null && (hasil.kode === 200 || hasil.kode === 409), hasil: hasil };
    }

    // Boleh dicoba lagi: server tidak terjangkau, atau galat sementara.
    // Penolakan lain tidak akan berubah kalau dikirim ulang. Itu termasuk sesi
    // admin yang berakhir: token halaman ini ikut mati bersama sesinya.
    function bisaDikirimUlang(hasil) {
        return hasil === null || hasil.kode === 500 || hasil.kode === 503;
    }

    async function kirimTandaTerima() {
        var t = tertunda;
        if (t === null) {
            return;
        }
        t.bisaUlang = false;
        tampilLangkah('Mencatat ' + namaJari(t.jari) + ' di server…', '');
        gambarPanel();
        var c = await catatDiServer(t.kiriman);
        if (tertunda !== t) {
            return;
        }
        if (c.tercatat) {
            tertunda = null;
            jariDiSini(t.identitas)[t.jari] = { mutu: t.mutu, tanggal: hariIni() };
            if (gagalTadi[t.identitas]) {
                delete gagalTadi[t.identitas][t.jari];
            }
            var baris = barisOrang(t.identitas);
            if (baris) {
                baris.dataset.tidakTerbaca = '';
            }
            gambarSelJari(t.identitas);
            tampilLangkah(langkahDiam(), besarAwal(namaJari(t.jari)) + ' terdaftar, mutu ' + t.mutu + '.', 'ok');
        } else if (bisaDikirimUlang(c.hasil)) {
            t.bisaUlang = true;
            tampilLangkah(besarAwal(namaJari(t.jari)) + ' sudah tersimpan di PC ini, tetapi belum tercatat di server.',
                pesan(c.hasil) + ' Periksa sambungan, lalu klik "Kirim ulang tanda terima".', 'galat');
        } else {
            tertunda = null;
            tampilLangkah(langkahDiam(), besarAwal(namaJari(t.jari)) + ' tersimpan di PC ini, tetapi server menolak mencatatnya: '
                + pesan(c.hasil) + (c.hasil.kode === 401 ? ' Muat ulang halaman ini, masuk lagi, lalu daftarkan ulang jari ini.'
                    : ' Daftarkan ulang jari ini.'), 'galat');
        }
        gambarPanel();
        segarkanStatus();
    }

    // ---------- Pencabutan dan tanda "tidak terbaca" ----------

    // lapor(teks, jenis) menulis kemajuannya. Hasilnya benar kalau templat
    // sudah terhapus di PC ini dan server mencatatnya.
    async function cabutOrang(identitas, lapor) {
        lapor('Meminta sesi pencabutan…', 'info');
        var mulai = await panggil(JEMBATAN + '/cabut/mulai', { identitas: identitas });
        if (mulai === null || mulai.kode !== 200 || !mulai.isi) {
            lapor('Jembatan tidak membuka sesi: ' + pesan(mulai), 'galat');
            return false;
        }
        var izin = await panggil('sj_izin.php', {
            csrf: CSRF, aksi: 'cabut', perangkat: jembatan.perangkat, tantangan_jembatan: mulai.isi.sesi, identitas: identitas
        });
        if (izin === null || izin.kode !== 200 || !izin.isi) {
            lapor('Server tidak memberi izin: ' + pesan(izin), 'galat');
            return false;
        }
        var cabut = await panggil(JEMBATAN + '/cabut', { sesi: mulai.isi.sesi, izin: izin.isi.izin, tantangan: izin.isi.tantangan });
        if (cabut === null || cabut.kode !== 200 || !cabut.isi) {
            lapor('Jembatan tidak mencabut: ' + pesan(cabut), 'galat');
            return false;
        }
        var c = await catatDiServer({
            csrf: CSRF, aksi: 'cabut', perangkat: cabut.isi.perangkat, tantangan: izin.isi.tantangan, identitas: identitas,
            jumlah: cabut.isi.jumlah, tanda_tangan: cabut.isi.tanda_tangan
        });
        segarkanStatus();
        if (!c.tercatat) {
            // Mengulang pencabutan aman: jembatan menjawab 0 templat, dan
            // tanda terima yang baru membereskan catatan server.
            lapor(cabut.isi.jumlah + ' templat sudah dihapus dari PC ini, tetapi server belum mencatatnya: ' + pesan(c.hasil)
                + ' Cabut sekali lagi.', 'galat');
            return false;
        }
        lapor(cabut.isi.jumlah + ' templat dihapus dari PC ini, dan catatan server diperbarui.', 'ok');
        return true;
    }

    // Tombol berbahaya: klik pertama hanya mengubah tulisannya.
    function duaKali(tombol, aksi) {
        if (tombol.dataset.yakin === '1') {
            window.clearTimeout(Number(tombol.dataset.pewaktu));
            tombol.dataset.yakin = '';
            tombol.textContent = tombol.dataset.asli;
            aksi();
            return;
        }
        tombol.dataset.yakin = '1';
        tombol.dataset.asli = tombol.textContent;
        tombol.textContent = 'Klik lagi untuk memastikan';
        tombol.dataset.pewaktu = String(window.setTimeout(function () {
            tombol.dataset.yakin = '';
            tombol.textContent = tombol.dataset.asli;
        }, 4000));
    }

    async function cabutDariDaftar(tombol) {
        var baris = tombol.closest('tr');
        var hasil = baris.querySelector('.sj-cabut-hasil');
        if (sesiBerjalan() || tertunda !== null || jembatan === null) {
            return;
        }
        sibukLain = true;
        gambarPanel();
        aturTombolBaris();
        try {
            var beres = await cabutOrang(baris.dataset.identitas, function (teks, jenis) {
                tulis(hasil, teks, 'small sj-cabut-hasil ' + (KELAS_JENIS[jenis] || ''));
            });
            if (beres) {
                tombol.hidden = true;
                baris.classList.add('text-muted');
                // Orang yang sama bisa juga ada di daftar utama.
                var utama = barisOrang(baris.dataset.identitas);
                if (utama) {
                    jariSemua(utama)[jembatan.perangkat] = {};
                    gambarSelJari(baris.dataset.identitas);
                }
            }
        } finally {
            sibukLain = false;
            gambarPanel();
            aturTombolBaris();
        }
    }

    async function cabutDariPanel() {
        var identitas = terbuka;
        if (identitas === null || sesiBerjalan() || tertunda !== null) {
            return;
        }
        sibukLain = true;
        tampilLangkah('Mencabut semua jari ' + namaOrang(identitas) + '…', '');
        gambarPanel();
        var teksAkhir = '';
        var jenisAkhir = 'info';
        try {
            var beres = await cabutOrang(identitas, function (teks, jenis) {
                teksAkhir = teks;
                jenisAkhir = jenis;
                tampilPesan(teks, jenis);
            });
            if (beres) {
                jariSemua(barisOrang(identitas))[jembatan.perangkat] = {};
                gambarSelJari(identitas);
            }
        } finally {
            sibukLain = false;
            if (terbuka === identitas) {
                tampilLangkah(langkahDiam(), teksAkhir, jenisAkhir);
            }
            gambarPanel();
        }
    }

    async function tandaiTidakTerbaca() {
        var identitas = terbuka;
        if (identitas === null || sesiBerjalan() || tertunda !== null) {
            return;
        }
        sibukLain = true;
        gambarPanel();
        try {
            var hasil = await panggil('sj_catat.php', { csrf: CSRF, aksi: 'tidak-terbaca', identitas: identitas });
            if (hasil !== null && hasil.kode === 200) {
                barisOrang(identitas).dataset.tidakTerbaca = hariIni();
                gambarSelJari(identitas);
                tampilPesan('Dicatat: jari orang ini tidak terbaca.', 'ok');
            } else {
                tampilPesan('Tidak tercatat: ' + pesan(hasil), 'galat');
            }
        } finally {
            sibukLain = false;
            gambarPanel();
        }
    }

    // ---------- Mulai ----------

    tombolHubung.addEventListener('click', hubungkan);
    el('sj-p-tutup').addEventListener('click', tutupPanel);
    el('sj-p-batal').addEventListener('click', function () {
        if (sesi !== null) {
            akhiriSesi('Pendaftaran ' + namaJari(sesi.jari) + ' dibatalkan.', 'peringatan');
        }
    });
    el('sj-p-kirim-ulang').addEventListener('click', kirimTandaTerima);
    el('sj-p-tidak-terbaca').addEventListener('click', function () {
        duaKali(el('sj-p-tidak-terbaca'), tandaiTidakTerbaca);
    });
    el('sj-p-cabut').addEventListener('click', function () {
        duaKali(el('sj-p-cabut'), cabutDariPanel);
    });
    document.addEventListener('click', function (e) {
        var tombol = e.target instanceof Element ? e.target.closest('button') : null;
        if (tombol === null || tombol.disabled) {
            return;
        }
        if (tombol.classList.contains('sj-buka')) {
            bukaPanel(tombol.closest('tr'));
        } else if (tombol.classList.contains('sj-cabut')) {
            duaKali(tombol, function () {
                cabutDariDaftar(tombol);
            });
        }
    });

    // Templat yang sudah tersimpan tetapi belum tercatat hilang jejaknya
    // kalau halaman ditutup sekarang.
    window.addEventListener('beforeunload', function (e) {
        if (tertunda !== null) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Di PC kiosk izinnya datang dari kebijakan Chrome, jadi halaman langsung
    // terhubung. Menanyakan izin tidak memunculkan permintaan izin; yang
    // memunculkannya permintaan ke 127.0.0.1 itu sendiri.
    (async function () {
        try {
            var izin = await navigator.permissions.query({ name: 'loopback-network' });
            if (izin.state === 'granted') {
                hubungkan();
            }
        } catch (e) { /* peramban ini tidak mengenal izin itu: tunggu tombol */ }
    })();
})();
</script>
HTML;
}
include 'partials/footer.php';
