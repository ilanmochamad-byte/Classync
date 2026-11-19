<?php
// Detail satu laporan konseling (individu atau kelompok) beserta jurnal BK
// yang menjadi sumbernya. Hanya membaca; bisa dicetak lewat tombol Cetak.
//
// jurnal_bk_id di kedua tabel konseling sebenarnya berisi absensi.id, bukan
// jurnal_bk.id — aplikasi mengirim item.id dari get_riwayat_jurnal.php.
// Satu jurnal seharusnya punya paling banyak satu laporan (individu ATAU
// kelompok); kalau lebih, halaman ini memperingatkannya.
include 'partials/header.php';

function bk_e($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function bk_tanggal_indo($datetime) {
    $ts = strtotime((string)$datetime);
    if ($ts === false) {
        return '-';
    }
    $hari  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
              'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return $hari[(int)date('w', $ts)] . ', ' . date('j', $ts) . ' ' . $bulan[(int)date('n', $ts)]
         . ' ' . date('Y', $ts) . ', pukul ' . date('H.i', $ts);
}

// Nama tabel hanya dari peta ini, tidak pernah dari URL.
$peta = [
    'individu' => [
        'tabel'     => 'konseling_individu',
        'judul'     => 'Laporan Konseling Individu',
        'tanggal'   => 'tanggal_konseling',
        'identitas' => [
            ['Nama Konseli', 'nama_konseli'],
            ['Kelas', 'kelas_konseli'],
            ['Tanggal Konseling', 'tanggal_konseling'],
            ['Jam', 'jam_konseling'],
        ],
        'bagian' => [
            ['B. Latar Belakang & Deskripsi Masalah', [
                ['Latar Belakang', 'latar_belakang'],
                ['Deskripsi Masalah', 'deskripsi_masalah'],
            ]],
            ['C. Hasil Asesmen & Analisis', [
                ['Asesmen Pribadi', 'asesmen_pribadi'],
                ['Asesmen Sosial', 'asesmen_sosial'],
                ['Asesmen Belajar', 'asesmen_belajar'],
                ['Asesmen Karir', 'asesmen_karir'],
                ['Analisis Masalah', 'analisis_masalah'],
            ]],
            ['D. Tujuan & Pendekatan Konseling', [
                ['Tujuan Jangka Pendek', 'tujuan_pendek'],
                ['Tujuan Jangka Panjang', 'tujuan_panjang'],
                ['Pendekatan / Teknik', 'pendekatan_teknik'],
            ]],
            ['E. Proses Pelaksanaan Konseling', [
                ['Tahap Awal', 'proses_awal'],
                ['Tahap Inti', 'proses_inti'],
                ['Tahap Akhir', 'proses_akhir'],
            ]],
            ['F. Hasil, Evaluasi & Tindak Lanjut', [
                ['Hasil Konseling', 'hasil_konseling'],
                ['Evaluasi Proses', 'evaluasi_proses'],
                ['Evaluasi Hasil', 'evaluasi_hasil'],
                ['Rencana Tindak Lanjut', 'rtl_konselor'],
            ]],
        ],
    ],
    'kelompok' => [
        'tabel'     => 'konseling_kelompok',
        'judul'     => 'Laporan Konseling Kelompok',
        'tanggal'   => 'tanggal_pelaksanaan',
        'identitas' => [
            ['Nama Kegiatan', 'nama_kegiatan'],
            ['Tanggal Pelaksanaan', 'tanggal_pelaksanaan'],
            ['Waktu', 'waktu'],
            ['Tempat', 'tempat'],
        ],
        'bagian' => [
            ['B. Anggota Kelompok', [
                ['Anggota Kelompok', 'anggota_kelompok'],
            ]],
            ['C. Latar Belakang & Tujuan', [
                ['Latar Belakang', 'latar_belakang'],
                ['Tujuan Umum', 'tujuan_umum'],
                ['Tujuan Khusus', 'tujuan_khusus'],
            ]],
            ['D. Tahapan & Proses Pelaksanaan', [
                ['Tahap Pembentukan', 'tahap_pembentukan'],
                ['Tahap Peralihan', 'tahap_peralihan'],
                ['Tahap Kegiatan (Inti)', 'tahap_kegiatan'],
                ['Tahap Pengakhiran', 'tahap_pengakhiran'],
                ['Proses Jalannya Kegiatan', 'proses_pelaksanaan'],
            ]],
            ['E. Hasil, Evaluasi & Tindak Lanjut', [
                ['Hasil Kegiatan', 'hasil_kegiatan'],
                ['Evaluasi Proses', 'evaluasi_proses'],
                ['Evaluasi Hasil', 'evaluasi_hasil'],
                ['Rencana Tindak Lanjut', 'rtl_konselor'],
            ]],
        ],
    ],
];

$jenis = is_string($_GET['jenis'] ?? null) ? $_GET['jenis'] : '';
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$laporan = null;
if (isset($peta[$jenis]) && $id !== false) {
    $konf = $peta[$jenis];
    // jb.waktu dan a.status diberi alias supaya tidak menimpa kolom laporan
    // (konseling_kelompok juga punya kolom waktu).
    $sql = "SELECT k.*,
                   a.id AS absensi_id, a.waktu_absensi, a.tipe_absensi, a.status AS status_absensi,
                   g.nama_guru,
                   jb.id AS jurnal_id, jb.komponen_layanan, jb.bidang_layanan, jb.topik_tema,
                   jb.fungsi_layanan, jb.sasaran_layanan, jb.materi_layanan,
                   jb.waktu AS waktu_layanan, jb.sumber, jb.metode_teknik, jb.media_alat
            FROM {$konf['tabel']} k
            LEFT JOIN absensi a ON a.id = k.jurnal_bk_id
            LEFT JOIN guru g ON g.id = a.guru_id
            LEFT JOIN jurnal_bk jb ON jb.absensi_guru_id = a.id
            WHERE k.id = ?
            ORDER BY jb.id ASC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
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

// Laporan lain pada jurnal yang sama, lintas jenis.
$laporan_lain = [];
if ($laporan['absensi_id'] !== null) {
    $stmt = $conn->prepare(
        "SELECT 'individu' AS jenis, id, tanggal_konseling AS tanggal FROM konseling_individu WHERE jurnal_bk_id = ?
         UNION ALL
         SELECT 'kelompok', id, tanggal_pelaksanaan FROM konseling_kelompok WHERE jurnal_bk_id = ?
         ORDER BY jenis, id");
    $absensi_id = (int)$laporan['absensi_id'];
    $stmt->bind_param("ii", $absensi_id, $absensi_id);
    $stmt->execute();
    $laporan_lain = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$terhubung = $laporan['absensi_id'] !== null;
$konselor = $terhubung && $laporan['nama_guru'] !== null ? $laporan['nama_guru'] : '-';
?>

<style>
    .bk-bagian { font-weight: 700; color: #FF6B00; border-bottom: 2px solid #f1f2f6; padding-bottom: 6px; margin: 22px 0 12px; }
    .bk-label { font-size: 0.85rem; color: #6c757d; font-weight: 600; margin-bottom: 2px; }
    .bk-isi { white-space: pre-wrap; word-break: break-word; margin-bottom: 12px; }
    .bk-cetak-saja { display: none; }
    @media print {
        nav.navbar, .bk-tanpa-cetak { display: none !important; }
        .bk-cetak-saja { display: block; }
        .container { max-width: 100% !important; margin-top: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        .bk-bagian { color: #000; border-bottom-color: #000; break-after: avoid; }
        .bk-ttd { break-inside: avoid; }
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 bk-tanpa-cetak">
    <a href="pusat_layanan_bk.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Kembali</a>
    <div class="d-flex gap-2">
        <a href="ubah_konseling.php?jenis=<?php echo $jenis; ?>&amp;id=<?php echo (int)$id; ?>" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> Ubah</a>
        <a href="ubah_konseling.php?jenis=<?php echo $jenis; ?>&amp;id=<?php echo (int)$id; ?>#hapus" class="btn btn-outline-danger"><i class="bi bi-trash"></i> Hapus</a>
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Cetak</button>
    </div>
</div>

<div class="bk-cetak-saja text-center mb-3">
    <div class="fw-bold">SMK TERPADU AL HASAN</div>
    <div>Layanan Bimbingan dan Konseling</div>
    <hr>
</div>

<h3 class="fw-bold mb-3"><?php echo bk_e($konf['judul']); ?></h3>

<?php if (!$terhubung): ?>
    <div class="alert alert-warning bk-tanpa-cetak">
        Laporan ini tidak terhubung ke jurnal BK mana pun (nomor jurnal tersimpan:
        <?php echo (int)$laporan['jurnal_bk_id']; ?>), sehingga konselor dan jurnalnya tidak diketahui.
    </div>
<?php elseif ($laporan['tipe_absensi'] !== 'bimbingan'): ?>
    <div class="alert alert-warning bk-tanpa-cetak">
        Laporan ini terhubung ke absensi yang bukan jurnal BK (jenis: <?php echo bk_e($laporan['tipe_absensi']); ?>).
    </div>
<?php endif; ?>

<?php if (count($laporan_lain) > 1): ?>
    <div class="alert alert-danger bk-tanpa-cetak">
        <strong>Jurnal ini punya <?php echo count($laporan_lain); ?> laporan</strong>, padahal satu jurnal
        seharusnya hanya punya satu laporan (individu atau kelompok). Guru BK perlu menentukan mana yang dipakai.
        <ul class="mb-0 mt-2">
            <?php foreach ($laporan_lain as $lain): ?>
                <li>
                    <?php $ini = ($lain['jenis'] === $jenis && (int)$lain['id'] === (int)$id); ?>
                    <?php if ($ini): ?>
                        <strong>Laporan <?php echo bk_e($lain['jenis']); ?> #<?php echo (int)$lain['id']; ?> (yang sedang dibuka)</strong>
                    <?php else: ?>
                        <a href="detail_konseling.php?jenis=<?php echo bk_e($lain['jenis']); ?>&amp;id=<?php echo (int)$lain['id']; ?>">Laporan <?php echo bk_e($lain['jenis']); ?> #<?php echo (int)$lain['id']; ?></a>
                    <?php endif; ?>
                    — tanggal: <?php echo bk_e($lain['tanggal'] !== '' ? $lain['tanggal'] : '-'); ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="bk-bagian mt-0">A. Identitas</div>
        <div class="row">
            <?php foreach ($konf['identitas'] as [$label, $kolom]): ?>
                <div class="col-md-6">
                    <div class="bk-label"><?php echo bk_e($label); ?></div>
                    <div class="bk-isi"><?php echo bk_e(trim((string)$laporan[$kolom]) !== '' ? $laporan[$kolom] : '-'); ?></div>
                </div>
            <?php endforeach; ?>
            <div class="col-md-6">
                <div class="bk-label">Konselor</div>
                <div class="bk-isi"><?php echo bk_e($konselor); ?></div>
            </div>
        </div>

        <?php foreach ($konf['bagian'] as [$judul_bagian, $isian]): ?>
            <div class="bk-bagian"><?php echo bk_e($judul_bagian); ?></div>
            <?php foreach ($isian as [$label, $kolom]): ?>
                <div class="bk-label"><?php echo bk_e($label); ?></div>
                <div class="bk-isi"><?php echo bk_e(trim((string)$laporan[$kolom]) !== '' ? $laporan[$kolom] : '-'); ?></div>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="bk-bagian">Jurnal BK Terkait</div>
        <?php if ($terhubung): ?>
            <div class="row">
                <div class="col-md-6">
                    <div class="bk-label">Waktu Absensi</div>
                    <div class="bk-isi"><?php echo bk_e(bk_tanggal_indo($laporan['waktu_absensi'])); ?></div>
                </div>
                <div class="col-md-6">
                    <div class="bk-label">Status Absensi</div>
                    <div class="bk-isi"><?php echo bk_e($laporan['status_absensi']); ?></div>
                </div>
            </div>
            <?php if ($laporan['jurnal_id'] !== null): ?>
                <?php
                $isian_jurnal = [
                    ['Komponen Layanan', 'komponen_layanan'], ['Bidang Layanan', 'bidang_layanan'],
                    ['Topik / Tema', 'topik_tema'], ['Fungsi Layanan', 'fungsi_layanan'],
                    ['Sasaran Layanan', 'sasaran_layanan'], ['Materi Layanan', 'materi_layanan'],
                    ['Waktu', 'waktu_layanan'], ['Sumber', 'sumber'],
                    ['Metode / Teknik', 'metode_teknik'], ['Media / Alat', 'media_alat'],
                ];
                ?>
                <div class="row">
                    <?php foreach ($isian_jurnal as [$label, $kolom]): ?>
                        <div class="col-md-6">
                            <div class="bk-label"><?php echo bk_e($label); ?></div>
                            <div class="bk-isi"><?php echo bk_e(trim((string)$laporan[$kolom]) !== '' ? $laporan[$kolom] : '-'); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted">Isian jurnal BK untuk absensi ini tidak ditemukan.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-muted">Tidak terhubung ke jurnal BK.</p>
        <?php endif; ?>

        <div class="bk-ttd text-end mt-5">
            <p class="mb-1">Konselor,</p>
            <br><br><br>
            <p class="fw-bold mb-0"><?php echo bk_e($konselor); ?></p>
        </div>
    </div>
</div>

<?php include 'partials/footer.php'; ?>
