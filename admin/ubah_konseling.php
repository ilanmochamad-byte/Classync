<?php
// Koreksi atau hapus satu laporan konseling (individu atau kelompok) dari
// panel admin.
//
// Laporan konseling tidak dihitung honor: honor BK dihitung per baris absensi
// (keuangan_helper.php). Jadi mengubah maupun menghapus laporan di sini tidak
// mengubah honor. jurnal_bk_id tidak bisa diubah.
//
// Menghapus laporan dipakai antara lain untuk menegakkan "satu jurnal satu
// laporan" (individu ATAU kelompok) pada jurnal yang punya lebih dari satu.
// Aplikasi menyimpan tanda "laporan sudah dibuat" di HP (AsyncStorage
// submitted_ind_/submitted_kel_), jadi guru BK belum bisa membuat ulang
// laporan yang dihapus lewat HP sampai aplikasi membaca statusnya dari server.
//
// Setiap perubahan dan penghapusan dicatat di log_koreksi_bk dalam transaksi
// yang sama, beserta salinan isi sebelumnya supaya bisa dipulihkan.
include 'partials/header.php';

if (empty($_SESSION['koreksi_bk_csrf'])) {
    $_SESSION['koreksi_bk_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['koreksi_bk_csrf'];
$admin_id = (int)($_SESSION['admin_id'] ?? 0);

function ubk_e($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

// kolom => [label, panjang maksimum (null = text), wajib]. Nama tabel dan
// kolom hanya dari peta ini, tidak pernah dari kiriman.
$peta = [
    'individu' => [
        'tabel' => 'konseling_individu',
        'judul' => 'Laporan Konseling Individu',
        'isian' => [
            'nama_konseli'      => ['Nama Konseli', 150, true],
            'kelas_konseli'     => ['Kelas', 50, false],
            'tanggal_konseling' => ['Tanggal Konseling', 50, false],
            'jam_konseling'     => ['Jam', 50, false],
            'latar_belakang'    => ['Latar Belakang', null, false],
            'deskripsi_masalah' => ['Deskripsi Masalah', null, false],
            'asesmen_pribadi'   => ['Asesmen Pribadi', null, false],
            'asesmen_sosial'    => ['Asesmen Sosial', null, false],
            'asesmen_belajar'   => ['Asesmen Belajar', null, false],
            'asesmen_karir'     => ['Asesmen Karir', null, false],
            'analisis_masalah'  => ['Analisis Masalah', null, false],
            'tujuan_pendek'     => ['Tujuan Jangka Pendek', null, false],
            'tujuan_panjang'    => ['Tujuan Jangka Panjang', null, false],
            'pendekatan_teknik' => ['Pendekatan / Teknik', null, false],
            'proses_awal'       => ['Tahap Awal', null, false],
            'proses_inti'       => ['Tahap Inti', null, false],
            'proses_akhir'      => ['Tahap Akhir', null, false],
            'hasil_konseling'   => ['Hasil Konseling', null, false],
            'evaluasi_proses'   => ['Evaluasi Proses', null, false],
            'evaluasi_hasil'    => ['Evaluasi Hasil', null, false],
            'rtl_konselor'      => ['Rencana Tindak Lanjut', null, false],
        ],
    ],
    'kelompok' => [
        'tabel' => 'konseling_kelompok',
        'judul' => 'Laporan Konseling Kelompok',
        'isian' => [
            'nama_kegiatan'       => ['Nama Kegiatan', 150, true],
            'tanggal_pelaksanaan' => ['Tanggal Pelaksanaan', 50, false],
            'waktu'               => ['Waktu', 50, false],
            'tempat'              => ['Tempat', 100, false],
            'anggota_kelompok'    => ['Anggota Kelompok', null, false],
            'latar_belakang'      => ['Latar Belakang', null, false],
            'tujuan_umum'         => ['Tujuan Umum', null, false],
            'tujuan_khusus'       => ['Tujuan Khusus', null, false],
            'tahap_pembentukan'   => ['Tahap Pembentukan', null, false],
            'tahap_peralihan'     => ['Tahap Peralihan', null, false],
            'tahap_kegiatan'      => ['Tahap Kegiatan (Inti)', null, false],
            'tahap_pengakhiran'   => ['Tahap Pengakhiran', null, false],
            'proses_pelaksanaan'  => ['Proses Jalannya Kegiatan', null, false],
            'hasil_kegiatan'      => ['Hasil Kegiatan', null, false],
            'evaluasi_proses'     => ['Evaluasi Proses', null, false],
            'evaluasi_hasil'      => ['Evaluasi Hasil', null, false],
            'rtl_konselor'        => ['Rencana Tindak Lanjut', null, false],
        ],
    ],
];

$jenis_kiriman = $_POST['jenis'] ?? $_GET['jenis'] ?? '';
$jenis = is_string($jenis_kiriman) && isset($peta[$jenis_kiriman]) ? $jenis_kiriman : null;
$id = filter_var($_POST['id'] ?? $_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$laporan = null;
if ($jenis !== null && $id !== false) {
    $konf = $peta[$jenis];
    $stmt = $conn->prepare("SELECT * FROM {$konf['tabel']} WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $laporan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$laporan) {
    echo "<div class='alert alert-warning mt-3'>Laporan tidak ditemukan.</div>";
    echo "<a href='pusat_layanan_bk.php' class='btn btn-secondary'>Kembali ke Pusat Layanan BK</a>";
    include 'partials/footer.php';
    exit;
}

$pesan = '';
$tipe_pesan = '';
$terhapus = false;
$nilai = [];
foreach ($konf['isian'] as $kolom => $_) {
    $nilai[$kolom] = (string)$laporan[$kolom];
}

// Salinan baris lengkap (selain id) untuk kolom sebelum di log.
function ubk_salinan($baris) {
    return json_encode($baris, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function ubk_catat($conn, $admin_id, $aksi, $absensi_id, $jenis, $id, $kolom_diubah, $sebelum) {
    // kolom_diubah VARCHAR(255): daftar 21 kolom bisa lebih panjang. Isi
    // lengkapnya tetap ada di kolom sebelum.
    if (strlen($kolom_diubah) > 255) {
        $kolom_diubah = substr($kolom_diubah, 0, strrpos(substr($kolom_diubah, 0, 250), ',')) . ',...';
    }
    $waktu_log = date('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        "INSERT INTO log_koreksi_bk (waktu, admin_id, aksi, absensi_id, laporan_jenis, laporan_id, kolom_diubah, sebelum)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sisisiss", $waktu_log, $admin_id, $aksi, $absensi_id, $jenis, $id, $kolom_diubah, $sebelum);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';
    $galat = [];
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($csrf, $token)) {
        $galat[] = 'Sesi formulir sudah berakhir. Muat ulang halaman, lalu ulangi.';
    } elseif ($admin_id <= 0) {
        $galat[] = 'Sesi admin tidak dikenali. Silakan login ulang.';
    } elseif ($aksi !== 'simpan' && $aksi !== 'hapus') {
        $galat[] = 'Permintaan tidak dikenali.';
    } elseif ($aksi === 'hapus' && ($_POST['konfirmasi_hapus'] ?? '') !== '1') {
        $galat[] = 'Centang konfirmasi lebih dulu untuk menghapus laporan.';
    }

    if (!$galat && $aksi === 'simpan') {
        foreach ($konf['isian'] as $kolom => [$label, $maks, $wajib]) {
            $kiriman = $_POST[$kolom] ?? '';
            if (!is_string($kiriman)) {
                $galat[] = "Isian $label tidak sah.";
                continue;
            }
            // Peramban mengirim baris baru textarea sebagai CRLF; aplikasi
            // menyimpan LF.
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
            $stmt = $conn->prepare("SELECT * FROM {$konf['tabel']} WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $lama = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$lama) {
                throw new DomainException('Laporan tidak ditemukan; mungkin baru saja dihapus.');
            }
            $absensi_id = (int)$lama['jurnal_bk_id'];

            if ($aksi === 'hapus') {
                $stmt = $conn->prepare("DELETE FROM {$konf['tabel']} WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
                ubk_catat($conn, $admin_id, 'hapus_laporan', $absensi_id, $jenis, $id, '', ubk_salinan($lama));
                $conn->commit();
                $terhapus = true;
                $pesan = 'Laporan ' . $jenis . ' #' . $id . ' sudah dihapus. Honor tidak berubah.';
                $tipe_pesan = 'success';
            } else {
                // Beda yang hanya spasi di tepi atau CRLF (juga NULL lawan
                // kosong) tidak dihitung, dan nilai lamanya dipertahankan.
                $diubah = [];
                foreach ($konf['isian'] as $kolom => $_) {
                    if (trim(str_replace("\r\n", "\n", (string)$lama[$kolom])) === $nilai[$kolom]) {
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
                    // Hanya kolom yang berubah yang ditulis; namanya dari peta.
                    $set = implode(', ', array_map(fn($k) => "$k = ?", $diubah));
                    $param = array_map(fn($k) => $nilai[$k], $diubah);
                    $param[] = $id;
                    $stmt = $conn->prepare("UPDATE {$konf['tabel']} SET $set WHERE id = ?");
                    $stmt->bind_param(str_repeat('s', count($diubah)) . 'i', ...$param);
                    $stmt->execute();
                    $stmt->close();
                    ubk_catat($conn, $admin_id, 'ubah_laporan', $absensi_id, $jenis, $id, implode(',', $diubah), ubk_salinan($lama));
                    $conn->commit();
                    $pesan = 'Laporan berhasil diperbarui (' . count($diubah) . ' isian). Honor tidak berubah.';
                    $tipe_pesan = 'success';
                }
            }
        } catch (DomainException $e) {
            $conn->rollback();
            $pesan = $e->getMessage();
            $tipe_pesan = 'danger';
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[ubah_konseling] ' . $jenis . ' #' . $id . ': ' . $e->getMessage());
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
    <h3 class="fw-bold mb-0">Ubah <?php echo ubk_e($konf['judul']); ?> #<?php echo (int)$id; ?></h3>
    <?php if ($terhapus): ?>
        <a href="pusat_layanan_bk.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Kembali</a>
    <?php else: ?>
        <a href="detail_konseling.php?jenis=<?php echo $jenis; ?>&amp;id=<?php echo (int)$id; ?>" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Kembali ke detail</a>
    <?php endif; ?>
</div>

<?php if ($pesan): ?>
    <div class="alert alert-<?php echo $tipe_pesan; ?>"><?php echo ubk_e($pesan); ?></div>
<?php endif; ?>

<?php if ($terhapus): ?>
    <p class="text-muted">Salinan isinya tersimpan di log koreksi BK. Guru BK belum bisa membuat ulang laporan untuk jurnal ini lewat aplikasi di HP yang dipakai menyimpannya.</p>
<?php else: ?>

<form method="POST" class="card shadow-sm mb-4">
    <div class="card-body">
        <p class="small text-muted">Mengubah laporan konseling tidak memengaruhi honor. Jurnal yang terhubung tidak bisa diubah di sini.</p>
        <input type="hidden" name="csrf_token" value="<?php echo ubk_e($csrf); ?>">
        <input type="hidden" name="jenis" value="<?php echo $jenis; ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <input type="hidden" name="aksi" value="simpan">
        <div class="row">
            <?php foreach ($konf['isian'] as $kolom => [$label, $maks, $wajib]): ?>
                <div class="<?php echo $maks === null ? 'col-12' : 'col-md-6'; ?> mb-3">
                    <label class="form-label fw-semibold" for="<?php echo $kolom; ?>"><?php echo $label; ?><?php echo $wajib ? ' *' : ''; ?></label>
                    <?php if ($maks === null): ?>
                        <textarea class="form-control" id="<?php echo $kolom; ?>" name="<?php echo $kolom; ?>" rows="3"><?php echo ubk_e($nilai[$kolom]); ?></textarea>
                    <?php else: ?>
                        <input type="text" class="form-control" id="<?php echo $kolom; ?>" name="<?php echo $kolom; ?>" maxlength="<?php echo (int)$maks; ?>" value="<?php echo ubk_e($nilai[$kolom]); ?>"<?php echo $wajib ? ' required' : ''; ?>>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-success"><i class="bi bi-save"></i> Simpan Perubahan</button>
        </div>
    </div>
</form>

<form method="POST" class="card border-danger shadow-sm mb-5" id="hapus">
    <div class="card-body">
        <h5 class="text-danger fw-bold">Hapus laporan ini</h5>
        <p class="small mb-2">
            Hanya laporan ini yang dihapus; jurnal BK dan honornya tidak berubah. Salinan isinya disimpan di log
            koreksi BK. Guru BK belum bisa membuat ulang laporan untuk jurnal ini lewat aplikasi di HP yang dipakai
            menyimpannya.
        </p>
        <input type="hidden" name="csrf_token" value="<?php echo ubk_e($csrf); ?>">
        <input type="hidden" name="jenis" value="<?php echo $jenis; ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <input type="hidden" name="aksi" value="hapus">
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="konfirmasi_hapus" value="1" id="konfirmasi_hapus" required>
            <label class="form-check-label" for="konfirmasi_hapus">Saya yakin ingin menghapus laporan <?php echo ubk_e($jenis); ?> #<?php echo (int)$id; ?>.</label>
        </div>
        <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> Hapus Laporan</button>
    </div>
</form>

<?php endif; ?>

<?php include 'partials/footer.php'; ?>
