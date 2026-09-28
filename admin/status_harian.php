<?php
// status_harian.php — pantau status harian siswa per tanggal.
//
// Penggolongannya ditetapkan cron sore pukul 17.00 (cron_status_harian.php)
// lewat includes/status_harian.php. Halaman ini menampilkan hasilnya dan
// menyediakan tombol "Hitung ulang" untuk uji coba atau kalau cron gagal.
//
// Selama mode senyap, hanya di halaman inilah golongan Pulang Lebih Awal
// terlihat: belum ada laporan yang membaca status_harian dan belum ada WA.
// Tren absen pulang di bagian bawah menentukan kapan WA boleh dinyalakan.
include 'partials/header.php';
require_once __DIR__ . '/../includes/status_harian.php';

// Jumlah hari sekolah terakhir di tren absen pulang.
const PANTAU_HARI_TREN = 14;
// Batas mundur mencari hari sekolah untuk tren, supaya libur panjang tidak
// membuat halaman memeriksa tanggal tanpa akhir.
const PANTAU_MAKS_MUNDUR = 45;

if (empty($_SESSION['status_harian_csrf'])) {
    $_SESSION['status_harian_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['status_harian_csrf'];

// Dicocokkan bolak-balik: createFromFormat() menerima luapan, jadi tanpa
// pencocokan ini 30 Februari diam-diam menjadi 2 Maret.
function tanggalPantauSah($teks) {
    $dt = is_string($teks) ? DateTime::createFromFormat('!Y-m-d', $teks) : false;
    return $dt !== false && $dt->format('Y-m-d') === $teks;
}

function formatTanggalPantau($tanggal) {
    return getNamaHariIndonesia(date('l', strtotime($tanggal))) . ', ' . date('d/m/Y', strtotime($tanggal));
}

function jamPantau($waktu) {
    return $waktu !== null && $waktu !== '' ? date('H.i', strtotime($waktu)) : '—';
}

function aman($teks) {
    return htmlspecialchars((string)$teks, ENT_QUOTES, 'UTF-8');
}

// Tabel siswa untuk satu golongan. $dengan_jam false untuk siswa yang tidak
// punya baris absensi sama sekali.
function tabelSiswaPantau(array $daftar, $dengan_jam = true) {
    echo '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">';
    echo '<thead><tr><th>No</th><th>Nama</th><th>Kelas</th>';
    if ($dengan_jam) {
        echo '<th>Masuk</th><th>Status masuk</th><th>Pulang</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($daftar as $i => $s) {
        echo '<tr><td>' . ($i + 1) . '</td><td>' . aman($s['nama_siswa']) . '</td><td>' . aman($s['kelas']) . '</td>';
        if ($dengan_jam) {
            echo '<td>' . jamPantau($s['waktu_masuk']) . '</td><td>' . aman($s['status_masuk'] ?? '—')
               . '</td><td>' . jamPantau($s['waktu_pulang']) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

$hari_ini   = date('Y-m-d');
$pesan      = '';
$tipe_pesan = 'success';

// Tanggal yang dipantau: dari formulir "Hitung ulang" atau dari alamat.
$diminta = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['tanggal'] ?? '') : ($_GET['tanggal'] ?? $hari_ini);
if (tanggalPantauSah($diminta) && $diminta <= $hari_ini) {
    $tanggal = $diminta;
} else {
    $tanggal    = $hari_ini;
    $pesan      = 'Tanggal tidak sah atau belum terjadi. Yang ditampilkan hari ini.';
    $tipe_pesan = 'warning';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'hitung' && $pesan === '') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($csrf, $token)) {
        $pesan      = 'Formulir kedaluwarsa. Muat ulang halaman, lalu coba lagi.';
        $tipe_pesan = 'danger';
    } else {
        $hasil = tetapkanStatusHarian($conn, $tanggal, 'admin', $_SESSION['admin_id'] ?? null);
        if ($hasil['hasil'] === 'selesai') {
            $pesan = 'Status harian ' . formatTanggalPantau($tanggal) . ' dihitung. ' . $hasil['pesan'];
        } elseif ($hasil['hasil'] === 'dilewati') {
            $pesan      = 'Tidak dihitung: ' . $hasil['pesan'];
            $tipe_pesan = 'warning';
        } else {
            $pesan      = $hasil['pesan'];
            $tipe_pesan = 'danger';
        }
    }
}

$info = infoHariSekolah($conn, $tanggal);
$pkl  = daftarSiswaPkl($conn);

// Jalan terakhir untuk tanggal ini, apa pun hasilnya.
$stmt = $conn->prepare("SELECT l.dijalankan, l.pemicu, l.admin_id, l.hasil, l.keterangan, a.username FROM log_status_harian l LEFT JOIN admin a ON a.id = l.admin_id WHERE l.tanggal = ? ORDER BY l.id DESC LIMIT 1");
$stmt->bind_param('s', $tanggal);
$stmt->execute();
$jalan_terakhir = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Sudah ditetapkan kalau pernah ada jalan yang benar-benar menggolongkan.
$stmt = $conn->prepare("SELECT COUNT(*) AS jumlah FROM log_status_harian WHERE tanggal = ? AND hasil = 'selesai'");
$stmt->bind_param('s', $tanggal);
$stmt->execute();
$sudah_dihitung = (int)$stmt->get_result()->fetch_assoc()['jumlah'] > 0;
$stmt->close();

// Golongan tiap siswa. Sebelum ditetapkan, golongannya dihitung dari data
// saat ini dan ditandai "sementara". Sesudahnya yang tampil nilai tersimpan,
// dan baris yang datanya berubah sejak itu dihitung terpisah.
$golongan = ['Hadir' => [], 'Pulang Lebih Awal' => [], 'Izin Pulang' => [], 'Izin' => [],
             'Sakit' => [], 'Alpa' => [], 'Tidak tergolong' => []];
$jumlah_pkl    = 0;
$berubah       = 0;
$tanpa_catatan = [];
if ($info['masuk_sekolah']) {
    $stmt = $conn->prepare("SELECT a.siswa_id, s.nama_siswa, s.kelas, a.waktu_masuk, a.waktu_pulang, a.status_masuk, a.status_harian FROM absensi_siswa a JOIN siswa s ON s.id = a.siswa_id WHERE a.tanggal = ? ORDER BY s.kelas, s.nama_siswa");
    $stmt->bind_param('s', $tanggal);
    $stmt->execute();
    $semua_baris = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($semua_baris as $baris) {
        if (isset($pkl[(int)$baris['siswa_id']])) {
            $jumlah_pkl++;
            continue;
        }
        $jika_dihitung = golonganStatusHarian($baris);
        if ($sudah_dihitung) {
            $gol = $baris['status_harian'];
            if ($jika_dihitung !== $gol) {
                $berubah++;
            }
        } else {
            $gol = $jika_dihitung;
        }
        if ($gol === null) {
            $kunci = 'Tidak tergolong';
        } elseif ($gol === 'Izin' && !empty($baris['waktu_masuk'])) {
            $kunci = 'Izin Pulang';
        } else {
            $kunci = $gol;
        }
        $golongan[$kunci][] = $baris;
    }

    // Siswa aktif yang tidak punya baris sama sekali. Kelas 'Lulus / Alumni'
    // diberikan admin/mutasi_siswa.php saat kelulusan.
    $stmt = $conn->prepare("SELECT s.id, s.nama_siswa, s.kelas FROM siswa s LEFT JOIN absensi_siswa a ON a.siswa_id = s.id AND a.tanggal = ? WHERE a.id IS NULL AND s.kelas <> 'Lulus / Alumni' ORDER BY s.kelas, s.nama_siswa");
    $stmt->bind_param('s', $tanggal);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $siswa) {
        if (!isset($pkl[(int)$siswa['id']])) {
            $tanpa_catatan[] = $siswa;
        }
    }
    $stmt->close();
}
$hadir_terlambat = count(array_filter($golongan['Hadir'], function ($b) {
    return $b['status_masuk'] === 'Terlambat';
}));

// Tren absen pulang: dari data mentah, jadi tanggal sebelum aturan pulang
// berlaku pun terlihat. Yang dihitung hanya baris yang punya absen masuk;
// siswa PKL dan izin pulang tidak ikut, karena mereka memang tidak wajib
// absen pulang di kiosk.
$tren = [];
$t    = $tanggal;
for ($i = 0; $i < PANTAU_MAKS_MUNDUR && count($tren) < PANTAU_HARI_TREN; $i++) {
    $info_t = infoHariSekolah($conn, $t);
    if ($info_t['masuk_sekolah']) {
        $tren[$t] = [
            'masuk'       => 0,
            'pulang'      => 0,
            'izin_pulang' => 0,
            'berlangsung' => $t === $hari_ini && date('H:i:s') < $info_t['jam_pulang'],
        ];
    }
    $t = date('Y-m-d', strtotime($t . ' -1 day'));
}
if ($tren) {
    $dari = min(array_keys($tren));
    $stmt = $conn->prepare("SELECT siswa_id, tanggal, waktu_pulang, status_harian FROM absensi_siswa WHERE tanggal BETWEEN ? AND ? AND waktu_masuk IS NOT NULL");
    $stmt->bind_param('ss', $dari, $tanggal);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $baris) {
        $tgl = $baris['tanggal'];
        if (!isset($tren[$tgl]) || isset($pkl[(int)$baris['siswa_id']])) {
            continue;
        }
        if ($baris['status_harian'] === 'Izin') {
            $tren[$tgl]['izin_pulang']++;
            continue;
        }
        $tren[$tgl]['masuk']++;
        if ($baris['waktu_pulang'] !== null) {
            $tren[$tgl]['pulang']++;
        }
    }
    $stmt->close();
}
$total_masuk  = 0;
$total_pulang = 0;
$hari_selesai = 0;
foreach ($tren as $isi) {
    if (!$isi['berlangsung']) {
        $total_masuk  += $isi['masuk'];
        $total_pulang += $isi['pulang'];
        $hari_selesai++;
    }
}
$persen_rata = $total_masuk > 0 ? (int)round($total_pulang / $total_masuk * 100) : null;

function warnaPersenPantau($persen) {
    return $persen >= 90 ? 'success' : ($persen >= 50 ? 'warning' : 'danger');
}

if ($info['jenis'] === 'Minggu') {
    $ringkasan_hari = 'Hari Minggu, tidak ada sekolah.';
} elseif ($info['jenis'] === 'Libur') {
    $ringkasan_hari = 'Libur — ' . $info['keterangan'] . '.';
} elseif ($info['jenis'] === 'Pulang Cepat') {
    $ringkasan_hari = 'Pulang cepat pukul ' . jamPantau($info['jam_pulang']) . ' — ' . $info['keterangan'] . '.';
} else {
    $ringkasan_hari = 'Sekolah biasa, masuk ' . jamPantau($info['jam_masuk']) . ', pulang ' . jamPantau($info['jam_pulang']) . '.';
}

if ($jalan_terakhir === null) {
    $ringkasan_jalan = 'Belum pernah dihitung.';
} else {
    $oleh = $jalan_terakhir['pemicu'] === 'cron' ? 'cron'
          : ($jalan_terakhir['username'] !== null ? $jalan_terakhir['username'] : 'admin #' . $jalan_terakhir['admin_id']);
    $ringkasan_jalan = 'Jalan terakhir ' . date('d/m/Y H.i', strtotime($jalan_terakhir['dijalankan'])) . ' oleh ' . $oleh
                     . ' — ' . $jalan_terakhir['hasil'] . ': ' . $jalan_terakhir['keterangan'];
}

$bisa_dihitung = $info['masuk_sekolah'] && ($tanggal < $hari_ini || date('H:i:s') >= $info['jam_pulang']);
$kemarin       = date('Y-m-d', strtotime($tanggal . ' -1 day'));
$besok         = date('Y-m-d', strtotime($tanggal . ' +1 day'));

$kartu = [
    ['Hadir', 'success', count($golongan['Hadir']), $hadir_terlambat > 0 ? $hadir_terlambat . ' terlambat' : 'masuk dan pulang'],
    ['Pulang Lebih Awal', 'danger', count($golongan['Pulang Lebih Awal']), 'masuk tanpa pulang'],
    ['Izin Pulang', 'primary', count($golongan['Izin Pulang']), 'dicatat guru piket'],
    ['Izin', 'primary', count($golongan['Izin']), 'sehari penuh'],
    ['Sakit', 'warning', count($golongan['Sakit']), 'dari absen manual'],
    ['Alpa', 'dark', count($golongan['Alpa']), 'dari absen manual'],
    ['Tanpa catatan', 'secondary', count($tanpa_catatan), 'belum ada Alpa otomatis'],
    ['PKL', 'secondary', $jumlah_pkl, 'tidak disentuh'],
];
if (count($golongan['Tidak tergolong']) > 0) {
    $kartu[] = ['Tidak tergolong', 'secondary', count($golongan['Tidak tergolong']), 'periksa datanya'];
}
?>

<h1 class="mb-4">Status Harian Siswa</h1>

<form method="GET" class="row g-2 align-items-end mb-3">
    <div class="col-auto">
        <label for="tanggal" class="form-label">Tanggal</label>
        <input type="date" class="form-control" id="tanggal" name="tanggal" max="<?php echo aman($hari_ini); ?>" value="<?php echo aman($tanggal); ?>">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary">Tampilkan</button>
    </div>
    <div class="col-auto">
        <a class="btn btn-outline-secondary" href="?tanggal=<?php echo aman($kemarin); ?>">&lsaquo; Sebelumnya</a>
        <?php if ($tanggal < $hari_ini): ?>
            <a class="btn btn-outline-secondary" href="?tanggal=<?php echo aman($besok); ?>">Berikutnya &rsaquo;</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($pesan !== ''): ?>
    <div class="alert alert-<?php echo $tipe_pesan; ?>"><?php echo aman($pesan); ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h5 class="card-title mb-1"><?php echo aman(formatTanggalPantau($tanggal)); ?></h5>
        <p class="mb-1"><?php echo aman($ringkasan_hari); ?></p>
        <p class="small text-muted mb-2"><?php echo aman($ringkasan_jalan); ?></p>
        <?php if ($sudah_dihitung && $berubah > 0): ?>
            <div class="alert alert-warning py-2 small mb-2">
                <?php echo $berubah; ?> baris berubah sejak dihitung, misalnya absen pulang setelah cron berjalan.
                Tekan <strong>Hitung ulang</strong> untuk memperbaruinya.
            </div>
        <?php endif; ?>
        <?php if ($bisa_dihitung): ?>
            <form method="POST" class="d-inline">
                <input type="hidden" name="aksi" value="hitung">
                <input type="hidden" name="tanggal" value="<?php echo aman($tanggal); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo aman($csrf); ?>">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-arrow-repeat"></i> <?php echo $sudah_dihitung ? 'Hitung ulang' : 'Hitung sekarang'; ?>
                </button>
            </form>
        <?php elseif ($info['masuk_sekolah']): ?>
            <p class="small mb-0">
                Bisa dihitung mulai pukul <?php echo jamPantau($info['jam_pulang']); ?>.
                Cron menghitungnya otomatis pukul 17.00.
            </p>
        <?php endif; ?>
    </div>
</div>

<?php if ($info['masuk_sekolah']): ?>
    <h5 class="mb-3">
        Jumlah per golongan
        <?php if (!$sudah_dihitung): ?>
            <span class="badge bg-warning text-dark align-middle">sementara</span>
            <small class="text-muted fs-6">dihitung dari data saat ini, belum ditetapkan</small>
        <?php endif; ?>
    </h5>
    <div class="row g-3 mb-4">
        <?php foreach ($kartu as [$judul, $warna, $angka, $keterangan_kartu]): ?>
            <div class="col-6 col-md-3">
                <div class="card h-100 border-<?php echo $warna; ?>">
                    <div class="card-body py-2">
                        <div class="small text-muted"><?php echo aman($judul); ?></div>
                        <div class="fs-3 fw-bold text-<?php echo $warna; ?>"><?php echo (int)$angka; ?></div>
                        <div class="small text-muted"><?php echo aman($keterangan_kartu); ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-door-open"></i> Pulang Lebih Awal (<?php echo count($golongan['Pulang Lebih Awal']); ?>)</h6>
        </div>
        <?php if (empty($golongan['Pulang Lebih Awal'])): ?>
            <div class="card-body text-muted">Tidak ada.</div>
        <?php else: ?>
            <?php tabelSiswaPantau($golongan['Pulang Lebih Awal']); ?>
        <?php endif; ?>
    </div>

    <?php foreach (['Izin Pulang', 'Tidak tergolong'] as $judul): ?>
        <?php if (!empty($golongan[$judul])): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header"><h6 class="mb-0"><?php echo aman($judul); ?> (<?php echo count($golongan[$judul]); ?>)</h6></div>
                <?php tabelSiswaPantau($golongan[$judul]); ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if (!empty($tanpa_catatan)): ?>
        <details class="card shadow-sm mb-4">
            <summary class="card-header">Tanpa catatan (<?php echo count($tanpa_catatan); ?>) — tidak absen masuk dan tidak dicatat guru piket</summary>
            <?php tabelSiswaPantau($tanpa_catatan, false); ?>
        </details>
    <?php endif; ?>
<?php else: ?>
    <div class="alert alert-secondary">Status harian tidak dihitung pada hari tanpa sekolah.</div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header">
        <h6 class="mb-0"><i class="bi bi-graph-up"></i> Tren absen pulang, <?php echo count($tren); ?> hari sekolah terakhir</h6>
    </div>
    <div class="card-body pb-0">
        <p class="mb-1">
            <?php if ($persen_rata === null): ?>
                Belum ada absen masuk pada rentang ini.
            <?php else: ?>
                Rata-rata <?php echo $hari_selesai; ?> hari sekolah:
                <strong class="text-<?php echo warnaPersenPantau($persen_rata); ?>-emphasis"><?php echo $persen_rata; ?>%</strong>
                absen masuk diikuti absen pulang (<?php echo $total_pulang; ?> dari <?php echo $total_masuk; ?>).
            <?php endif; ?>
        </p>
        <p class="small text-muted">
            WA Pulang Lebih Awal baru dinyalakan setelah angka ini di atas 90%. Dihitung dari data absen masuk
            dan pulang, jadi tanggal sebelum aturan pulang berlaku ikut terlihat. Siswa PKL dan izin pulang tidak dihitung.
        </p>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Tanggal</th><th>Masuk</th><th>Pulang</th><th style="width: 40%">Absen pulang</th></tr></thead>
            <tbody>
            <?php foreach ($tren as $tgl => $isi): ?>
                <?php $persen = $isi['masuk'] > 0 ? (int)round($isi['pulang'] / $isi['masuk'] * 100) : null; ?>
                <tr class="<?php echo $tgl === $tanggal ? 'table-active' : ''; ?>">
                    <td><a href="?tanggal=<?php echo aman($tgl); ?>"><?php echo aman(formatTanggalPantau($tgl)); ?></a></td>
                    <td><?php echo $isi['masuk']; ?></td>
                    <td>
                        <?php echo $isi['pulang']; ?>
                        <?php if ($isi['izin_pulang'] > 0): ?>
                            <span class="small text-muted">(+<?php echo $isi['izin_pulang']; ?> izin pulang)</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isi['berlangsung']): ?>
                            <span class="small text-muted">belum lewat jam pulang</span>
                        <?php elseif ($persen === null): ?>
                            <span class="small text-muted">—</span>
                        <?php else: ?>
                            <div class="progress" style="height: 1.25rem;">
                                <div class="progress-bar bg-<?php echo warnaPersenPantau($persen); ?><?php echo warnaPersenPantau($persen) === 'warning' ? ' text-dark' : ''; ?>" role="progressbar"
                                     style="width: <?php echo max($persen, 8); ?>%;" aria-valuenow="<?php echo $persen; ?>"
                                     aria-valuemin="0" aria-valuemax="100"><?php echo $persen; ?>%</div>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'partials/footer.php'; ?>
