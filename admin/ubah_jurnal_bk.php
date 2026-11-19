<?php
// Koreksi isian jurnal BK (tabel jurnal_bk) dari panel admin.
//
// Honor BK dihitung per baris absensi bertipe 'bimbingan'
// (keuangan_helper.php), bukan dari isian jurnal_bk. Halaman ini hanya
// mengubah jurnal_bk; guru, waktu, status, dan foto di baris absensi tidak
// bisa diubah dari sini, jadi honor dan bulannya tidak ikut berubah.
//
// Kunci duplikat BK (guru + tanggal + topik_tema + sasaran_layanan, lihat
// proses_absen_bk.php di repo API) tetap dijaga: koreksi yang membuat
// kombinasi itu sama dengan jurnal lain ditolak.
//
// Setiap perubahan dicatat di log_koreksi_bk dalam transaksi yang sama,
// termasuk salinan isi sebelum diubah supaya bisa dipulihkan. Tabelnya dibuat
// manual di phpMyAdmin (CLAUDE.md).
include 'partials/header.php';

if (empty($_SESSION['koreksi_bk_csrf'])) {
    $_SESSION['koreksi_bk_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['koreksi_bk_csrf'];
$admin_id = (int)($_SESSION['admin_id'] ?? 0);

function ubj_e($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

// kolom => [label, panjang maksimum (null = text), wajib, textarea]
$isian = [
    'komponen_layanan' => ['Komponen Layanan', 150, false, false],
    'bidang_layanan'   => ['Bidang Layanan', 150, false, false],
    'topik_tema'       => ['Topik / Tema', null, true, true],
    'fungsi_layanan'   => ['Fungsi Layanan', 150, false, false],
    'sasaran_layanan'  => ['Sasaran Layanan', 150, true, false],
    'materi_layanan'   => ['Materi Layanan', null, false, true],
    'waktu'            => ['Alokasi Waktu', 50, false, false],
    'sumber'           => ['Sumber', null, false, true],
    'metode_teknik'    => ['Metode / Teknik', 150, false, false],
    'media_alat'       => ['Media / Alat', 255, false, false],
];

$absensi_id = filter_var($_POST['absensi_id'] ?? $_GET['absensi_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

function ubj_muat($conn, $absensi_id) {
    $stmt = $conn->prepare(
        "SELECT a.id AS absensi_id, a.guru_id, a.waktu_absensi, g.nama_guru, jb.*
         FROM absensi a
         JOIN jurnal_bk jb ON jb.absensi_guru_id = a.id
         LEFT JOIN guru g ON g.id = a.guru_id
         WHERE a.id = ? AND a.tipe_absensi = 'bimbingan'
         ORDER BY jb.id ASC LIMIT 1");
    $stmt->bind_param("i", $absensi_id);
    $stmt->execute();
    $baris = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $baris;
}

$jurnal = $absensi_id !== false ? ubj_muat($conn, $absensi_id) : null;
if (!$jurnal) {
    echo "<div class='alert alert-warning mt-3'>Jurnal BK tidak ditemukan.</div>";
    echo "<a href='pusat_layanan_bk.php' class='btn btn-secondary'>Kembali ke Pusat Layanan BK</a>";
    include 'partials/footer.php';
    exit;
}

$pesan = '';
$tipe_pesan = '';
$nilai = [];
foreach ($isian as $kolom => $_) {
    $nilai[$kolom] = (string)$jurnal[$kolom];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $galat = [];
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($csrf, $token)) {
        $galat[] = 'Sesi formulir sudah berakhir. Muat ulang halaman, lalu ulangi.';
    } elseif ($admin_id <= 0) {
        $galat[] = 'Sesi admin tidak dikenali. Silakan login ulang.';
    }

    if (!$galat) {
        foreach ($isian as $kolom => [$label, $maks, $wajib]) {
            $kiriman = $_POST[$kolom] ?? '';
            if (!is_string($kiriman)) {
                $galat[] = "Isian $label tidak sah.";
                continue;
            }
            // Peramban mengirim baris baru textarea sebagai CRLF; aplikasi
            // menyimpan LF. Disamakan supaya isian yang tidak disentuh tidak
            // terbaca berubah.
            $kiriman = trim(str_replace("\r\n", "\n", $kiriman));
            if ($wajib && $kiriman === '') {
                $galat[] = "$label wajib diisi.";
            } elseif ($maks !== null && mb_strlen($kiriman, 'UTF-8') > $maks) {
                $galat[] = "$label paling banyak $maks karakter.";
            }
            $nilai[$kolom] = $kiriman;
        }
    }

    if (!$galat) {
        try {
            $conn->begin_transaction();

            // Baca ulang di dalam transaksi, dikunci lewat PK jurnal_bk.
            $stmt = $conn->prepare("SELECT * FROM jurnal_bk WHERE id = ? FOR UPDATE");
            $jurnal_id = (int)$jurnal['id'];
            $stmt->bind_param("i", $jurnal_id);
            $stmt->execute();
            $lama = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$lama || (int)$lama['absensi_guru_id'] !== (int)$jurnal['absensi_id']) {
                throw new DomainException('Jurnal BK tidak ditemukan.');
            }

            // Beda yang hanya spasi di tepi atau CRLF tidak dihitung sebagai
            // perubahan, dan nilai lamanya dipertahankan apa adanya.
            $diubah = [];
            foreach ($isian as $kolom => $_) {
                $lama_rapi = trim(str_replace("\r\n", "\n", (string)$lama[$kolom]));
                if ($lama_rapi === $nilai[$kolom]) {
                    $nilai[$kolom] = (string)$lama[$kolom];
                } else {
                    $diubah[] = $kolom;
                }
            }

            if (!$diubah) {
                $conn->rollback();
                $pesan = 'Tidak ada perubahan yang disimpan.';
                $tipe_pesan = 'info';
            } else {
                // Kunci duplikat BK: guru + tanggal + topik + sasaran.
                $tanggal = date('Y-m-d', strtotime($jurnal['waktu_absensi']));
                $besok = date('Y-m-d', strtotime($tanggal . ' +1 day'));
                $stmt = $conn->prepare(
                    "SELECT a.id FROM absensi a
                     JOIN jurnal_bk j ON j.absensi_guru_id = a.id
                     WHERE a.guru_id = ? AND a.tipe_absensi = 'bimbingan'
                       AND a.waktu_absensi >= ? AND a.waktu_absensi < ?
                       AND j.topik_tema = ? AND j.sasaran_layanan = ?
                       AND a.id <> ?
                     LIMIT 1");
                $guru_id = (int)$jurnal['guru_id'];
                $absensi_ini = (int)$jurnal['absensi_id'];
                $stmt->bind_param("issssi", $guru_id, $tanggal, $besok, $nilai['topik_tema'], $nilai['sasaran_layanan'], $absensi_ini);
                $stmt->execute();
                $bentrok = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                if ($bentrok) {
                    throw new DomainException('Guru ini sudah punya jurnal BK lain pada tanggal yang sama dengan topik dan sasaran yang persis sama. Ubah topik atau sasarannya.');
                }

                $stmt = $conn->prepare(
                    "UPDATE jurnal_bk SET komponen_layanan = ?, bidang_layanan = ?, topik_tema = ?, fungsi_layanan = ?,
                         sasaran_layanan = ?, materi_layanan = ?, waktu = ?, sumber = ?, metode_teknik = ?, media_alat = ?
                     WHERE id = ?");
                $stmt->bind_param("ssssssssssi",
                    $nilai['komponen_layanan'], $nilai['bidang_layanan'], $nilai['topik_tema'], $nilai['fungsi_layanan'],
                    $nilai['sasaran_layanan'], $nilai['materi_layanan'], $nilai['waktu'], $nilai['sumber'],
                    $nilai['metode_teknik'], $nilai['media_alat'], $jurnal_id);
                $stmt->execute();
                $stmt->close();

                $sebelum = [];
                foreach ($isian as $kolom => $_) {
                    $sebelum[$kolom] = $lama[$kolom];
                }
                $sebelum['jurnal_bk_id'] = $jurnal_id;
                $json_sebelum = json_encode($sebelum, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $kolom_diubah = implode(',', $diubah);
                $waktu_log = date('Y-m-d H:i:s');
                $stmt = $conn->prepare(
                    "INSERT INTO log_koreksi_bk (waktu, admin_id, aksi, absensi_id, kolom_diubah, sebelum)
                     VALUES (?, ?, 'ubah_jurnal', ?, ?, ?)");
                $stmt->bind_param("siiss", $waktu_log, $admin_id, $absensi_ini, $kolom_diubah, $json_sebelum);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                $pesan = 'Jurnal BK berhasil diperbarui (' . count($diubah) . ' isian). Honor tidak berubah.';
                $tipe_pesan = 'success';
            }
        } catch (DomainException $e) {
            $conn->rollback();
            $pesan = $e->getMessage();
            $tipe_pesan = 'danger';
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[ubah_jurnal_bk] absensi ' . (int)$jurnal['absensi_id'] . ': ' . $e->getMessage());
            $pesan = 'Gagal menyimpan. Tidak ada yang berubah. Coba lagi, atau hubungi pengelola sistem.';
            $tipe_pesan = 'danger';
        }
    } else {
        $pesan = implode(' ', $galat);
        $tipe_pesan = 'danger';
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="fw-bold mb-0">Ubah Jurnal BK</h3>
    <a href="pusat_layanan_bk.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Kembali</a>
</div>

<?php if ($pesan): ?>
    <div class="alert alert-<?php echo $tipe_pesan; ?>"><?php echo ubj_e($pesan); ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-3">
    <div class="card-body small">
        <strong>Konselor:</strong> <?php echo ubj_e($jurnal['nama_guru'] ?? '-'); ?>
        &nbsp;|&nbsp; <strong>Waktu absensi:</strong> <?php echo ubj_e(date('d/m/Y H:i', strtotime($jurnal['waktu_absensi']))); ?>
        <div class="text-muted mt-1">Guru, waktu, status, dan foto absensi tidak bisa diubah di sini. Mengubah isian jurnal tidak memengaruhi honor.</div>
    </div>
</div>

<form method="POST" class="card shadow-sm mb-4">
    <div class="card-body">
        <input type="hidden" name="csrf_token" value="<?php echo ubj_e($csrf); ?>">
        <input type="hidden" name="absensi_id" value="<?php echo (int)$jurnal['absensi_id']; ?>">
        <div class="row">
            <?php foreach ($isian as $kolom => [$label, $maks, $wajib, $textarea]): ?>
                <div class="<?php echo $textarea ? 'col-12' : 'col-md-6'; ?> mb-3">
                    <label class="form-label fw-semibold" for="<?php echo $kolom; ?>"><?php echo $label; ?><?php echo $wajib ? ' *' : ''; ?></label>
                    <?php if ($textarea): ?>
                        <textarea class="form-control" id="<?php echo $kolom; ?>" name="<?php echo $kolom; ?>" rows="3"<?php echo $wajib ? ' required' : ''; ?>><?php echo ubj_e($nilai[$kolom]); ?></textarea>
                    <?php else: ?>
                        <input type="text" class="form-control" id="<?php echo $kolom; ?>" name="<?php echo $kolom; ?>" maxlength="<?php echo (int)$maks; ?>" value="<?php echo ubj_e($nilai[$kolom]); ?>"<?php echo $wajib ? ' required' : ''; ?>>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-success"><i class="bi bi-save"></i> Simpan Perubahan</button>
        </div>
    </div>
</form>

<?php include 'partials/footer.php'; ?>
