<?php
// Hapus satu jurnal BK dari panel admin. INI MENGURANGI HONOR.
//
// Honor BK dihitung per baris absensi bertipe 'bimbingan'
// (hitungHonorBulan() di keuangan_helper.php): Hadir/Sakit dibayar penuh,
// Izin 75%. Menghapus jurnal berarti menghapus baris absensi itu, jadi honor
// bulannya berkurang satu kali (keputusan kepala sekolah, November 2026).
//
// Yang ikut terhapus dalam satu transaksi:
// - laporan konseling individu dan kelompok yang menunjuk absensi ini
//   (jurnal_bk_id berisi absensi.id);
// - baris jurnal_bk;
// - likes, dislikes, dan comments galeri untuk absensi ini. Ketiganya
//   (dan jurnal_bk) ber-FK ke absensi dengan ON DELETE CASCADE, jadi
//   basis data menghapusnya sendiri; isinya disalin lebih dulu ke log.
// Berkas foto bukti TIDAK dihapus: foto itu bukti honor. Jalurnya ada di log.
//
// Salinan semua baris yang terhapus disimpan di log_koreksi_bk.sebelum,
// dalam transaksi yang sama, supaya penghapusan yang keliru bisa dipulihkan.
include 'partials/header.php';

if (empty($_SESSION['koreksi_bk_csrf'])) {
    $_SESSION['koreksi_bk_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['koreksi_bk_csrf'];
$admin_id = (int)($_SESSION['admin_id'] ?? 0);

function hjb_e($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function hjb_bulan($datetime) {
    $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
              'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $ts = strtotime((string)$datetime);
    return $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

// Bagian honor yang hilang bila baris ini dihapus, mengikuti hitungHonorBulan().
function hjb_porsi_honor($status) {
    if ($status === 'Hadir' || $status === 'Sakit') return 'satu kali honor BK penuh';
    if ($status === 'Izin') return '75% dari satu kali honor BK';
    return null;
}

function hjb_baris($conn, $sql, $id) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $hasil = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $hasil;
}

$absensi_id = filter_var($_POST['absensi_id'] ?? $_GET['absensi_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$absensi = null;
if ($absensi_id !== false) {
    $stmt = $conn->prepare(
        "SELECT a.*, g.nama_guru FROM absensi a LEFT JOIN guru g ON g.id = a.guru_id
         WHERE a.id = ? AND a.tipe_absensi = 'bimbingan'");
    $stmt->bind_param("i", $absensi_id);
    $stmt->execute();
    $absensi = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$pesan = '';
$tipe_pesan = '';
$terhapus = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $absensi) {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($csrf, $token)) {
        $pesan = 'Sesi formulir sudah berakhir. Muat ulang halaman, lalu ulangi.';
        $tipe_pesan = 'danger';
    } elseif ($admin_id <= 0) {
        $pesan = 'Sesi admin tidak dikenali. Silakan login ulang.';
        $tipe_pesan = 'danger';
    } elseif (($_POST['konfirmasi_honor'] ?? '') !== '1') {
        $pesan = 'Centang konfirmasi bahwa honor berkurang lebih dulu.';
        $tipe_pesan = 'danger';
    } else {
        try {
            $conn->begin_transaction();
            $id = (int)$absensi['id'];

            // Kunci baris absensi lewat PK, lalu baca ulang semua yang akan
            // terhapus. Tabel konseling kecil dan jarang ditulis, jadi
            // FOR UPDATE pada kolom tanpa indeks di sana tidak menahan apa pun.
            $lama = hjb_baris($conn, "SELECT * FROM absensi WHERE id = ? FOR UPDATE", $id);
            if (!$lama || $lama[0]['tipe_absensi'] !== 'bimbingan') {
                throw new DomainException('Jurnal BK tidak ditemukan; mungkin baru saja dihapus.');
            }
            $salinan = [
                'absensi'            => $lama[0],
                'jurnal_bk'          => hjb_baris($conn, "SELECT * FROM jurnal_bk WHERE absensi_guru_id = ? FOR UPDATE", $id),
                'konseling_individu' => hjb_baris($conn, "SELECT * FROM konseling_individu WHERE jurnal_bk_id = ? FOR UPDATE", $id),
                'konseling_kelompok' => hjb_baris($conn, "SELECT * FROM konseling_kelompok WHERE jurnal_bk_id = ? FOR UPDATE", $id),
                'likes'              => hjb_baris($conn, "SELECT * FROM likes WHERE absensi_id = ? FOR UPDATE", $id),
                'dislikes'           => hjb_baris($conn, "SELECT * FROM dislikes WHERE absensi_id = ? FOR UPDATE", $id),
                'comments'           => hjb_baris($conn, "SELECT * FROM comments WHERE absensi_id = ? FOR UPDATE", $id),
            ];

            foreach (["DELETE FROM konseling_individu WHERE jurnal_bk_id = ?",
                      "DELETE FROM konseling_kelompok WHERE jurnal_bk_id = ?",
                      "DELETE FROM jurnal_bk WHERE absensi_guru_id = ?"] as $sql) {
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
            }
            // likes, dislikes, dan comments terhapus lewat ON DELETE CASCADE.
            $stmt = $conn->prepare("DELETE FROM absensi WHERE id = ? AND tipe_absensi = 'bimbingan'");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $terhapus_baris = $stmt->affected_rows;
            $stmt->close();
            if ($terhapus_baris !== 1) {
                throw new DomainException('Jurnal BK tidak ditemukan; mungkin baru saja dihapus.');
            }

            $ringkasan = 'laporan individu ' . count($salinan['konseling_individu'])
                       . ', laporan kelompok ' . count($salinan['konseling_kelompok'])
                       . ', like ' . count($salinan['likes'])
                       . ', dislike ' . count($salinan['dislikes'])
                       . ', komentar ' . count($salinan['comments']);
            $json = json_encode($salinan, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $waktu_log = date('Y-m-d H:i:s');
            $stmt = $conn->prepare(
                "INSERT INTO log_koreksi_bk (waktu, admin_id, aksi, absensi_id, kolom_diubah, sebelum)
                 VALUES (?, ?, 'hapus_jurnal', ?, ?, ?)");
            $stmt->bind_param("siiss", $waktu_log, $admin_id, $id, $ringkasan, $json);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $terhapus = [
                'bulan'   => hjb_bulan($lama[0]['waktu_absensi']),
                'porsi'   => hjb_porsi_honor($lama[0]['status']),
                'laporan' => count($salinan['konseling_individu']) + count($salinan['konseling_kelompok']),
            ];
        } catch (DomainException $e) {
            $conn->rollback();
            $pesan = $e->getMessage();
            $tipe_pesan = 'danger';
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[hapus_jurnal_bk] absensi ' . (int)$absensi['id'] . ': ' . $e->getMessage());
            $pesan = 'Gagal menghapus. Tidak ada yang berubah. Coba lagi, atau hubungi pengelola sistem.';
            $tipe_pesan = 'danger';
        }
    }
}

if ($terhapus) {
    ?>
    <h3 class="fw-bold mb-3">Hapus Jurnal BK</h3>
    <div class="alert alert-success">
        Jurnal BK sudah dihapus<?php echo $terhapus['laporan'] ? ', bersama ' . (int)$terhapus['laporan'] . ' laporan konselingnya' : ''; ?>.
        <?php if ($terhapus['porsi']): ?>
            Honor BK <?php echo hjb_e($terhapus['bulan']); ?> berkurang <?php echo hjb_e($terhapus['porsi']); ?>.
        <?php endif; ?>
    </div>
    <p class="text-muted">Salinan semua baris yang terhapus tersimpan di log koreksi BK. Berkas foto buktinya tidak dihapus.</p>
    <a href="pusat_layanan_bk.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Kembali ke Pusat Layanan BK</a>
    <?php
    include 'partials/footer.php';
    exit;
}

if (!$absensi) {
    echo "<div class='alert alert-warning mt-3'>Jurnal BK tidak ditemukan.</div>";
    echo "<a href='pusat_layanan_bk.php' class='btn btn-secondary'>Kembali ke Pusat Layanan BK</a>";
    include 'partials/footer.php';
    exit;
}

// Halaman konfirmasi.
$id = (int)$absensi['id'];
$jurnal = hjb_baris($conn, "SELECT topik_tema, sasaran_layanan, komponen_layanan FROM jurnal_bk WHERE absensi_guru_id = ? ORDER BY id", $id);
$laporan = array_merge(
    array_map(fn($r) => ['individu', (int)$r['id']], hjb_baris($conn, "SELECT id FROM konseling_individu WHERE jurnal_bk_id = ? ORDER BY id", $id)),
    array_map(fn($r) => ['kelompok', (int)$r['id']], hjb_baris($conn, "SELECT id FROM konseling_kelompok WHERE jurnal_bk_id = ? ORDER BY id", $id))
);
$porsi = hjb_porsi_honor($absensi['status']);
$bulan_lewat = date('Y-m', strtotime($absensi['waktu_absensi'])) < date('Y-m');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="fw-bold mb-0">Hapus Jurnal BK</h3>
    <a href="pusat_layanan_bk.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Batal</a>
</div>

<?php if ($pesan): ?>
    <div class="alert alert-<?php echo $tipe_pesan; ?>"><?php echo hjb_e($pesan); ?></div>
<?php endif; ?>

<div class="card border-danger shadow-sm mb-4">
    <div class="card-body">
        <table class="table table-sm mb-3">
            <tr><th style="width: 30%">Konselor</th><td><?php echo hjb_e($absensi['nama_guru'] ?? '-'); ?></td></tr>
            <tr><th>Waktu absensi</th><td><?php echo hjb_e(date('d/m/Y H:i', strtotime($absensi['waktu_absensi']))); ?></td></tr>
            <tr><th>Status absensi</th><td><?php echo hjb_e($absensi['status']); ?></td></tr>
            <?php foreach ($jurnal as $j): ?>
                <tr><th>Jurnal</th><td><?php echo hjb_e($j['komponen_layanan']); ?> — <?php echo hjb_e($j['topik_tema']); ?> (sasaran: <?php echo hjb_e($j['sasaran_layanan']); ?>)</td></tr>
            <?php endforeach; ?>
            <tr>
                <th>Laporan yang ikut terhapus</th>
                <td>
                    <?php foreach ($laporan as [$jenis_lap, $id_lap]): ?>
                        <a href="detail_konseling.php?jenis=<?php echo $jenis_lap; ?>&amp;id=<?php echo $id_lap; ?>"><?php echo ucfirst($jenis_lap); ?> #<?php echo $id_lap; ?></a><br>
                    <?php endforeach; ?>
                    <?php if (!$laporan): ?>Tidak ada<?php endif; ?>
                </td>
            </tr>
        </table>

        <?php if ($porsi): ?>
            <div class="alert alert-danger">
                <strong>Honor berkurang.</strong> Menghapus jurnal ini mengurangi honor BK
                <strong><?php echo hjb_e(hjb_bulan($absensi['waktu_absensi'])); ?></strong>
                sebesar <?php echo hjb_e($porsi); ?>.
                <?php if ($bulan_lewat): ?>
                    <br><strong>Bulan itu sudah lewat; honornya mungkin sudah dibayar.</strong> Koordinasikan dengan bendahara sebelum menghapus.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                Status absensi ini "<?php echo hjb_e($absensi['status']); ?>" tidak dibayar, jadi honor tidak berubah.
            </div>
        <?php endif; ?>

        <p class="small text-muted">
            Ikut terhapus: laporan konseling di atas, serta like dan komentar galeri untuk foto absensi ini.
            Berkas foto bukti tidak dihapus. Salinan semua baris yang terhapus disimpan di log koreksi BK.
        </p>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo hjb_e($csrf); ?>">
            <input type="hidden" name="absensi_id" value="<?php echo $id; ?>">
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="konfirmasi_honor" value="1" id="konfirmasi_honor" required>
                <label class="form-check-label" for="konfirmasi_honor">
                    Saya mengerti jurnal ini dihapus<?php echo $porsi ? ' dan honor ' . hjb_e(hjb_bulan($absensi['waktu_absensi'])) . ' berkurang' : ''; ?>.
                </label>
            </div>
            <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> Hapus Jurnal</button>
        </form>
    </div>
</div>

<?php include 'partials/footer.php'; ?>
