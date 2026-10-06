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
//   terdaftar tanpa persetujuan masuk daftar "Perlu dicabut"; pencabutannya
//   dikerjakan di PC kiosk.
// - Semua perubahan lewat POST + token CSRF. Token yang sama dipakai
//   admin/sj_izin.php dan admin/sj_catat.php.
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
$perangkat_aktif = [];
foreach (array_keys($konfigurasi['perangkat'] ?? []) as $id) {
    if (sjPerangkat($konfigurasi, (string)$id) !== null) {
        $perangkat_aktif[(string)$id] = true;
    }
}

$pesan = '';
$tipe_pesan = 'success';
$galat_data = '';
$orang = [];
$persetujuan = [];
$terdaftar = [];
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
    $hasil = $conn->query("SELECT identitas, jari, perangkat, mutu, terdaftar FROM pendaftaran_sidik_jari WHERE status = 'aktif' ORDER BY identitas, terdaftar");
    while ($b = $hasil->fetch_assoc()) {
        if (isset($perangkat_aktif[$b['perangkat']])) {
            $terdaftar[$b['identitas']][$b['jari']] = ['mutu' => (int)$b['mutu'], 'terdaftar' => $b['terdaftar']];
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

<?php if ($perlu_dicabut): ?>
    <div class="card shadow-sm mb-4 border-danger">
        <div class="card-body">
            <h5 class="card-title text-danger">Perlu dicabut</h5>
            <p class="small text-muted">Jari orang-orang ini masih tersimpan di PC kiosk. Pencabutannya dikerjakan di PC kiosk.</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Orang</th><th>Sebab</th><th>Jari yang masih terdaftar</th></tr></thead>
                    <tbody>
                        <?php foreach ($perlu_dicabut as $identitas => $c): ?>
                            <tr data-identitas="<?php echo amanSj($identitas); ?>">
                                <td><?php echo $c['nama'] !== '' ? amanSj($c['nama']) : '<span class="text-muted">tidak dikenal</span>'; ?> <code class="small"><?php echo amanSj($identitas); ?></code></td>
                                <td><?php echo amanSj($c['sebab']); ?></td>
                                <td><?php echo amanSj(implode(', ', array_map(fn($j) => $nama_jari[$j] ?? $j, $c['jari']))); ?></td>
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
                                <tr data-identitas="<?php echo amanSj($identitas); ?>">
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
                                    <td>
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
                                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?php echo amanSj($tautan($kelompok, $identitas)); ?>">Ubah</a></td>
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
                </p>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php include 'partials/footer.php'; ?>
