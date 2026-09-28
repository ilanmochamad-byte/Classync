<?php
// kalender_sekolah.php — admin/TU menandai tanggal Libur atau Pulang Cepat.
//
// Aturan jam pulang per tanggal dihitung di includes/kalender_sekolah.php
// (infoHariSekolah()); halaman ini hanya mengisi tabel kalender_sekolah.
// - Satu rentang tanggal bisa diisi sekaligus (libur semester), maksimal
//   KALENDER_MAKS_HARI hari. Hari Minggu dilewati karena memang tidak masuk
//   sekolah.
// - Jam Pulang Cepat harus setelah jam masuk dan sebelum jam pulang normal
//   hari itu, supaya salah ketik seperti 23.00 tertolak.
// - Mengisi ulang tanggal yang sudah ada akan menimpanya (ON DUPLICATE KEY
//   UPDATE). Satu rentang disimpan dalam satu transaksi: semua atau tidak
//   sama sekali.
// - Hapus lewat POST + token CSRF, bukan ?hapus= lewat GET seperti halaman
//   jadwal, yang bisa dipicu tautan dari luar.
include 'partials/header.php';
require_once __DIR__ . '/../includes/kalender_sekolah.php';

const KALENDER_MAKS_HARI = 62;

if (empty($_SESSION['kalender_csrf'])) {
    $_SESSION['kalender_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['kalender_csrf'];

// Dicocokkan bolak-balik: createFromFormat() menerima luapan, jadi tanpa
// pencocokan ini 30 Februari diam-diam menjadi 2 Maret.
function tanggalKalenderSah($teks) {
    $dt = is_string($teks) ? DateTime::createFromFormat('!Y-m-d', $teks) : false;
    return $dt !== false && $dt->format('Y-m-d') === $teks;
}

function formatTanggalKalender($tanggal) {
    return getNamaHariIndonesia(date('l', strtotime($tanggal))) . ', ' . date('d/m/Y', strtotime($tanggal));
}

$pesan      = '';
$tipe_pesan = 'success';
// Isian formulir yang dikembalikan ke layar kalau penyimpanan ditolak.
$isian = ['tanggal_mulai' => '', 'tanggal_selesai' => '', 'jenis' => 'Libur', 'jam_pulang' => '', 'keterangan' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $pesan      = 'Formulir kedaluwarsa. Muat ulang halaman, lalu coba lagi.';
        $tipe_pesan = 'danger';
    } elseif ($aksi === 'simpan') {
        $mulai      = trim((string)($_POST['tanggal_mulai'] ?? ''));
        $selesai    = trim((string)($_POST['tanggal_selesai'] ?? ''));
        $jenis      = (string)($_POST['jenis'] ?? '');
        $jam_input  = trim((string)($_POST['jam_pulang'] ?? ''));
        $keterangan = trim((string)($_POST['keterangan'] ?? ''));
        $isian = ['tanggal_mulai' => $mulai, 'tanggal_selesai' => $selesai, 'jenis' => $jenis,
                  'jam_pulang' => $jam_input, 'keterangan' => $keterangan];
        if ($selesai === '') {
            $selesai = $mulai;
        }

        $galat = '';
        if (!tanggalKalenderSah($mulai) || !tanggalKalenderSah($selesai)) {
            $galat = 'Tanggal tidak sah.';
        } elseif ($selesai < $mulai) {
            $galat = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
        } elseif ((new DateTime($mulai))->diff(new DateTime($selesai))->days + 1 > KALENDER_MAKS_HARI) {
            $galat = 'Rentang tanggal maksimal ' . KALENDER_MAKS_HARI . ' hari.';
        } elseif (!in_array($jenis, ['Libur', 'Pulang Cepat'], true)) {
            $galat = 'Jenis harus Libur atau Pulang Cepat.';
        } elseif ($keterangan === '' || mb_strlen($keterangan) > 150) {
            $galat = 'Keterangan wajib diisi, maksimal 150 karakter.';
        } elseif ($jenis === 'Pulang Cepat' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $jam_input)) {
            $galat = 'Jam pulang wajib diisi untuk Pulang Cepat.';
        }

        $jam_pulang    = $jenis === 'Pulang Cepat' ? $jam_input . ':00' : null;
        $akan_disimpan = [];
        if ($galat === '') {
            for ($t = $mulai; $t <= $selesai; $t = date('Y-m-d', strtotime($t . ' +1 day'))) {
                $normal = infoHariSekolah($conn, $t, false);
                if ($normal['hari'] === 'Minggu') {
                    continue;
                }
                if ($jam_pulang !== null && ($jam_pulang <= $normal['jam_masuk'] || $jam_pulang >= $normal['jam_pulang'])) {
                    $galat = 'Jam pulang cepat pada ' . formatTanggalKalender($t) . ' harus setelah jam masuk '
                           . date('H.i', strtotime($normal['jam_masuk'])) . ' dan sebelum jam pulang normal '
                           . date('H.i', strtotime($normal['jam_pulang'])) . '.';
                    break;
                }
                $akan_disimpan[] = $t;
            }
            if ($galat === '' && $akan_disimpan === []) {
                $galat = 'Tidak ada tanggal yang disimpan: rentang itu hanya berisi hari Minggu.';
            }
        }

        if ($galat === '') {
            $admin_id = (int)$_SESSION['admin_id'];
            $sekarang = date('Y-m-d H:i:s');
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("INSERT INTO kalender_sekolah (tanggal, jenis, jam_pulang, keterangan, admin_id, diubah_pada) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE jenis = VALUES(jenis), jam_pulang = VALUES(jam_pulang), keterangan = VALUES(keterangan), admin_id = VALUES(admin_id), diubah_pada = VALUES(diubah_pada)");
                foreach ($akan_disimpan as $t) {
                    $stmt->bind_param('ssssis', $t, $jenis, $jam_pulang, $keterangan, $admin_id, $sekarang);
                    $stmt->execute();
                }
                $stmt->close();
                $conn->commit();
                $pesan = count($akan_disimpan) . ' tanggal disimpan sebagai ' . $jenis . '.';
                $isian = ['tanggal_mulai' => '', 'tanggal_selesai' => '', 'jenis' => 'Libur', 'jam_pulang' => '', 'keterangan' => ''];
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('[kalender_sekolah] gagal menyimpan: ' . $e->getMessage());
                $galat = 'Gagal menyimpan kalender. Tidak ada tanggal yang berubah.';
            }
        }
        if ($galat !== '') {
            $pesan      = $galat;
            $tipe_pesan = 'danger';
        }
    } elseif ($aksi === 'hapus') {
        $tanggal = (string)($_POST['tanggal'] ?? '');
        if (!tanggalKalenderSah($tanggal)) {
            $pesan      = 'Tanggal tidak sah.';
            $tipe_pesan = 'danger';
        } else {
            $stmt = $conn->prepare("DELETE FROM kalender_sekolah WHERE tanggal = ?");
            $stmt->bind_param('s', $tanggal);
            $stmt->execute();
            $terhapus = $stmt->affected_rows;
            $stmt->close();
            if ($terhapus > 0) {
                $pesan = formatTanggalKalender($tanggal) . ' dihapus dari kalender.';
            } else {
                $pesan      = 'Tanggal itu tidak ada di kalender.';
                $tipe_pesan = 'warning';
            }
        }
    }
}

$hari_ini = infoHariSekolah($conn);
$tanggal_hari_ini = date('Y-m-d');

$dari   = date('Y-m-d', strtotime('-30 days'));
$sampai = date('Y-m-d', strtotime('+365 days'));
$stmt = $conn->prepare("SELECT k.tanggal, k.jenis, k.jam_pulang, k.keterangan, k.diubah_pada, a.username FROM kalender_sekolah k LEFT JOIN admin a ON a.id = k.admin_id WHERE k.tanggal BETWEEN ? AND ? ORDER BY k.tanggal");
$stmt->bind_param('ss', $dari, $sampai);
$stmt->execute();
$daftar = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if ($hari_ini['jenis'] === 'Minggu') {
    $ringkasan_hari_ini = 'hari Minggu, tidak ada sekolah.';
} elseif ($hari_ini['jenis'] === 'Libur') {
    $ringkasan_hari_ini = 'libur — ' . $hari_ini['keterangan'] . '.';
} elseif ($hari_ini['jenis'] === 'Pulang Cepat') {
    $ringkasan_hari_ini = 'pulang cepat pukul ' . date('H.i', strtotime($hari_ini['jam_pulang'])) . ' — ' . $hari_ini['keterangan'] . '.';
} else {
    $ringkasan_hari_ini = 'sekolah biasa, masuk ' . date('H.i', strtotime($hari_ini['jam_masuk']))
                        . ', pulang ' . date('H.i', strtotime($hari_ini['jam_pulang'])) . '.';
}
?>

<h1 class="mb-4">Kalender Sekolah</h1>

<div class="alert alert-info">
    <i class="bi bi-calendar-day"></i>
    <strong><?php echo htmlspecialchars(formatTanggalKalender($tanggal_hari_ini), ENT_QUOTES, 'UTF-8'); ?>:</strong>
    <?php echo htmlspecialchars($ringkasan_hari_ini, ENT_QUOTES, 'UTF-8'); ?>
</div>

<?php if ($pesan !== ''): ?>
    <div class="alert alert-<?php echo $tipe_pesan; ?>"><?php echo htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-calendar-plus"></i> Tambah atau Ubah Tanggal</h5>
    </div>
    <div class="card-body">
        <form method="POST" id="form-kalender" class="row g-3">
            <input type="hidden" name="aksi" value="simpan">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="col-md-3">
                <label for="tanggal_mulai" class="form-label">Tanggal</label>
                <input type="date" class="form-control" id="tanggal_mulai" name="tanggal_mulai" required
                       value="<?php echo htmlspecialchars($isian['tanggal_mulai'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3">
                <label for="tanggal_selesai" class="form-label">Sampai tanggal (opsional)</label>
                <input type="date" class="form-control" id="tanggal_selesai" name="tanggal_selesai"
                       value="<?php echo htmlspecialchars($isian['tanggal_selesai'], ENT_QUOTES, 'UTF-8'); ?>">
                <small class="text-muted">Untuk beberapa hari sekaligus. Hari Minggu dilewati.</small>
            </div>
            <div class="col-md-3">
                <label for="jenis" class="form-label">Jenis</label>
                <select class="form-select" id="jenis" name="jenis">
                    <option value="Libur" <?php echo $isian['jenis'] !== 'Pulang Cepat' ? 'selected' : ''; ?>>Libur</option>
                    <option value="Pulang Cepat" <?php echo $isian['jenis'] === 'Pulang Cepat' ? 'selected' : ''; ?>>Pulang Cepat</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="jam_pulang" class="form-label">Jam pulang</label>
                <input type="time" class="form-control" id="jam_pulang" name="jam_pulang"
                       value="<?php echo htmlspecialchars($isian['jam_pulang'], ENT_QUOTES, 'UTF-8'); ?>">
                <small class="text-muted">Hanya untuk Pulang Cepat.</small>
            </div>
            <div class="col-12">
                <label for="keterangan" class="form-label">Keterangan</label>
                <input type="text" class="form-control" id="keterangan" name="keterangan" maxlength="150" required
                       placeholder="Contoh: Maulid Nabi, Rapat guru"
                       value="<?php echo htmlspecialchars($isian['keterangan'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-12 text-end">
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-calendar3"></i> Daftar Tanggal (30 hari lalu sampai setahun ke depan)</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Jenis</th>
                    <th>Jam pulang</th>
                    <th>Keterangan</th>
                    <th>Diubah</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($daftar)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Belum ada tanggal di kalender.</td></tr>
            <?php else: ?>
                <?php foreach ($daftar as $baris): ?>
                    <?php
                        $kelas_baris = $baris['tanggal'] === $tanggal_hari_ini ? 'table-warning'
                                     : ($baris['tanggal'] < $tanggal_hari_ini ? 'text-muted' : '');
                        $jam_baris   = $baris['jam_pulang'] !== null ? date('H:i', strtotime($baris['jam_pulang'])) : '';
                    ?>
                    <tr class="<?php echo $kelas_baris; ?>">
                        <td><?php echo htmlspecialchars(formatTanggalKalender($baris['tanggal']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if ($baris['jenis'] === 'Libur'): ?>
                                <span class="badge bg-danger">Libur</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Pulang Cepat</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $jam_baris !== '' ? str_replace(':', '.', $jam_baris) : '—'; ?></td>
                        <td><?php echo htmlspecialchars($baris['keterangan'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="small text-muted">
                            <?php echo date('d/m/Y H.i', strtotime($baris['diubah_pada'])); ?>
                            <?php if ($baris['username'] !== null): ?>
                                · <?php echo htmlspecialchars($baris['username'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-primary tombol-ubah"
                                    data-tanggal="<?php echo htmlspecialchars($baris['tanggal'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-jenis="<?php echo htmlspecialchars($baris['jenis'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-jam="<?php echo htmlspecialchars($jam_baris, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-keterangan="<?php echo htmlspecialchars($baris['keterangan'], ENT_QUOTES, 'UTF-8'); ?>">
                                Ubah
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus tanggal ini dari kalender?');">
                                <input type="hidden" name="aksi" value="hapus">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="tanggal" value="<?php echo htmlspecialchars($baris['tanggal'], ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
// Nilai dibaca dari atribut data-*, bukan disisipkan ke teks JavaScript,
// jadi keterangan yang berisi tanda kutip tidak bisa memecah skripnya.
$custom_script = <<<'HTML'
<script>
(function () {
    var jenis = document.getElementById('jenis');
    var jam = document.getElementById('jam_pulang');

    function aturJam() {
        var cepat = jenis.value === 'Pulang Cepat';
        jam.disabled = !cepat;
        jam.required = cepat;
        if (!cepat) {
            jam.value = '';
        }
    }
    jenis.addEventListener('change', aturJam);
    aturJam();

    document.querySelectorAll('.tombol-ubah').forEach(function (tombol) {
        tombol.addEventListener('click', function () {
            document.getElementById('tanggal_mulai').value = tombol.dataset.tanggal;
            document.getElementById('tanggal_selesai').value = '';
            jenis.value = tombol.dataset.jenis;
            aturJam();
            jam.value = tombol.dataset.jam;
            document.getElementById('keterangan').value = tombol.dataset.keterangan;
            document.getElementById('form-kalender').scrollIntoView({ behavior: 'smooth' });
        });
    });
})();
</script>
HTML;

include 'partials/footer.php';
