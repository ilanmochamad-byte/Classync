<?php
// kiosk_sidik_jari.php — keadaan kiosk sidik jari menurut detaknya.
//
// Jembatan di PC kiosk menandatangani detak (lihat includes/sidik_jari.php),
// dan api/sj_detak.php mencatat yang sah ke detak_kiosk, satu baris per
// detak. Halaman ini menampilkan masalah di berkas konfigurasi, perangkat
// yang terdaftar beserta detak terakhirnya, dan ringkasan per hari.
//
// Tombol "Uji rantai" menjalankan satu detak dari peramban ini: jembatan,
// tantangan dari server, tanda tangan jembatan, lalu pemeriksaan server.
// Hanya berhasil di PC kiosk, karena jembatan hanya mendengar di 127.0.0.1.
//
// Kunci tidak pernah ditampilkan. Yang tampil hanya sidiknya (8 karakter),
// untuk dicocokkan dengan keluaran jembatan saat dipasangkan.
include 'partials/header.php';
require_once __DIR__ . '/../includes/sidik_jari.php';

// Detak datang tiap 60 detik. Lewat dari ini kiosk dianggap diam, dan selang
// antar-detak sepanjang ini dihitung sebagai jeda.
const KIOSK_BATAS_DIAM = 180;
const KIOSK_HARI_RIWAYAT = 7;

function aman($teks) {
    return htmlspecialchars((string)$teks, ENT_QUOTES, 'UTF-8');
}

function lamaKiosk($detik) {
    $detik = max(0, (int)$detik);
    if ($detik < 60) {
        return $detik . ' detik';
    }
    if ($detik < 3600) {
        return intdiv($detik, 60) . ' menit';
    }
    if ($detik < 86400) {
        $menit = intdiv($detik % 3600, 60);
        return intdiv($detik, 3600) . ' jam' . ($menit > 0 ? ' ' . $menit . ' menit' : '');
    }
    $jam = intdiv($detik % 86400, 3600);
    return intdiv($detik, 86400) . ' hari' . ($jam > 0 ? ' ' . $jam . ' jam' : '');
}

function tanggalKiosk($cap) {
    return getNamaHariIndonesia(date('l', $cap)) . ', ' . date('d/m/Y', $cap);
}

// Versi jembatan diakhiri hash commit penuh di belakang "+". Di tabel cukup
// tujuh karakter pertamanya; yang lengkap ada di atribut title.
function versiRingkasKiosk($versi) {
    return preg_replace('/\+([0-9a-f]{7})[0-9a-f]+\z/', '+$1', (string)$versi);
}

// Ringkasan per hari dari baris detak yang sudah urut waktu:
//   [tanggal => ['pertama', 'terakhir' (cap waktu), 'jumlah', 'jeda' (kali),
//                'detik_jeda', 'jeda_terpanjang' ([mulai, selesai] atau null),
//                'tanpa_alat' (jumlah detak dengan alat 0)]]
// Jeda hanya dihitung di antara dua detak pada hari yang sama. Malam hari,
// saat PC kiosk mati, bukan jeda.
function ringkasHariKiosk(array $baris) {
    $hari = [];
    $sebelum = null;
    foreach ($baris as $b) {
        $cap = strtotime($b['waktu']);
        $tanggal = date('Y-m-d', $cap);
        if (!isset($hari[$tanggal])) {
            $hari[$tanggal] = ['pertama' => $cap, 'terakhir' => $cap, 'jumlah' => 0, 'jeda' => 0,
                               'detik_jeda' => 0, 'jeda_terpanjang' => null, 'tanpa_alat' => 0];
            $sebelum = null;
        }
        $hari[$tanggal]['jumlah']++;
        $hari[$tanggal]['terakhir'] = $cap;
        if ((int)$b['alat'] === 0) {
            $hari[$tanggal]['tanpa_alat']++;
        }
        if ($sebelum !== null && $cap - $sebelum > KIOSK_BATAS_DIAM) {
            $hari[$tanggal]['jeda']++;
            $hari[$tanggal]['detik_jeda'] += $cap - $sebelum;
            $terpanjang = $hari[$tanggal]['jeda_terpanjang'];
            if ($terpanjang === null || $cap - $sebelum > $terpanjang[1] - $terpanjang[0]) {
                $hari[$tanggal]['jeda_terpanjang'] = [$sebelum, $cap];
            }
        }
        $sebelum = $cap;
    }
    return $hari;
}

$kini = time();
$masalah = [];
$konfigurasi = sjKonfigurasi(SJ_BERKAS_KONFIGURASI, $masalah);

// id => ['terdaftar', 'aktif', 'sidik' => [8 hex, ...], 'terakhir' => baris
// atau null, 'hari' => ringkasHariKiosk()]
$perangkat = [];
foreach ($konfigurasi['perangkat'] ?? [] as $id => $entri) {
    $perangkat[$id] = ['terdaftar' => true, 'aktif' => $entri['aktif'],
                       'sidik' => array_map('sjSidikKunci', $entri['kunci']), 'terakhir' => null, 'hari' => []];
}

$sejak = date('Y-m-d 00:00:00', strtotime('-' . (KIOSK_HARI_RIWAYAT - 1) . ' days', $kini));
$galat_data = '';
try {
    // Perangkat yang masih punya detak tetapi sudah dicabut dari konfigurasi
    // tetap ditampilkan, supaya riwayatnya tidak hilang begitu saja.
    $stmt = $conn->prepare("SELECT DISTINCT perangkat FROM detak_kiosk WHERE waktu >= ?");
    $stmt->bind_param('s', $sejak);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $b) {
        if (!isset($perangkat[$b['perangkat']])) {
            $perangkat[$b['perangkat']] = ['terdaftar' => false, 'aktif' => false, 'sidik' => [], 'terakhir' => null, 'hari' => []];
        }
    }
    $stmt->close();

    foreach (array_keys($perangkat) as $id) {
        $id_teks = (string)$id;
        $stmt = $conn->prepare("SELECT waktu, alat, versi FROM detak_kiosk WHERE perangkat = ? ORDER BY waktu DESC, id DESC LIMIT 1");
        $stmt->bind_param('s', $id_teks);
        $stmt->execute();
        $perangkat[$id]['terakhir'] = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $conn->prepare("SELECT waktu, alat FROM detak_kiosk WHERE perangkat = ? AND waktu >= ? ORDER BY waktu, id");
        $stmt->bind_param('ss', $id_teks, $sejak);
        $stmt->execute();
        $perangkat[$id]['hari'] = ringkasHariKiosk($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();
    }
} catch (mysqli_sql_exception $e) {
    if ($e->getCode() === 1146) {
        $galat_data = 'Tabel detak_kiosk belum ada. Jalankan SQL sub-langkah 4.2 di phpMyAdmin.';
    } else {
        error_log('[kiosk_sidik_jari] data detak tidak bisa dibaca: ' . $e->getMessage());
        $galat_data = 'Data detak tidak bisa dibaca. Rinciannya ada di error_log.';
    }
}

// Sidik kunci per perangkat terdaftar, untuk dibandingkan skrip "Uji rantai"
// dengan sidik yang dilaporkan jembatan.
$peta_sidik = [];
foreach ($perangkat as $id => $p) {
    if ($p['terdaftar']) {
        $peta_sidik[$id] = $p['sidik'];
    }
}
?>

<h1 class="mb-2">Kiosk Sidik Jari</h1>
<p class="text-muted">
    Sidik jari belum dipakai untuk absensi. Detak di halaman ini hanya menunjukkan apakah jembatan di PC kiosk
    hidup dan kuncinya cocok dengan yang terdaftar di server.
</p>

<?php if ($konfigurasi === null): ?>
    <div class="alert alert-warning">
        <strong>Sidik jari belum dikonfigurasi di server.</strong>
        <?php foreach ($masalah as $m): ?>
            <div><?php echo aman($m); ?></div>
        <?php endforeach; ?>
        <div class="small mt-1">Berkasnya: <code><?php echo aman(SJ_BERKAS_KONFIGURASI); ?></code></div>
    </div>
<?php elseif ($masalah): ?>
    <div class="alert alert-warning">
        <strong>Ada isi berkas konfigurasi yang dilewati:</strong>
        <ul class="mb-0">
            <?php foreach ($masalah as $m): ?>
                <li><?php echo aman($m); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($galat_data !== ''): ?>
    <div class="alert alert-danger"><?php echo aman($galat_data); ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h5 class="card-title">Perangkat</h5>
        <?php if (!$perangkat): ?>
            <p class="mb-0 text-muted">Belum ada perangkat yang terdaftar.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr><th>Perangkat</th><th>Terdaftar</th><th>Sidik kunci</th><th>Detak terakhir</th><th>Alat</th><th>Versi jembatan</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($perangkat as $id => $p): ?>
                            <?php
                            $terakhir = $p['terakhir'];
                            $umur = $terakhir ? $kini - strtotime($terakhir['waktu']) : null;
                            ?>
                            <tr>
                                <td><code><?php echo aman($id); ?></code></td>
                                <td>
                                    <?php if (!$p['terdaftar']): ?>
                                        <span class="badge bg-secondary">tidak terdaftar</span>
                                    <?php elseif (!$p['sidik']): ?>
                                        <span class="badge bg-danger">tanpa kunci</span>
                                    <?php elseif ($p['aktif']): ?>
                                        <span class="badge bg-success">aktif</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$p['sidik']): ?>
                                        —
                                    <?php endif; ?>
                                    <?php foreach ($p['sidik'] as $sidik): ?>
                                        <code><?php echo aman($sidik); ?></code>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php if ($terakhir === null): ?>
                                        <span class="badge bg-secondary">belum pernah</span>
                                    <?php else: ?>
                                        <span class="badge bg-<?php echo $umur <= KIOSK_BATAS_DIAM ? 'success' : 'danger'; ?>"><?php echo $umur <= KIOSK_BATAS_DIAM ? 'hidup' : 'diam'; ?></span>
                                        <?php echo aman(date('d/m/Y H.i', strtotime($terakhir['waktu']))); ?>
                                        <span class="text-muted">(<?php echo aman(lamaKiosk($umur)); ?> lalu)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($terakhir === null): ?>
                                        —
                                    <?php elseif ((int)$terakhir['alat'] === 1): ?>
                                        terpasang
                                    <?php else: ?>
                                        <span class="text-danger fw-bold">tidak terpasang</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($terakhir !== null && $terakhir['versi'] !== ''): ?>
                                        <span title="<?php echo aman($terakhir['versi']); ?>"><?php echo aman(versiRingkasKiosk($terakhir['versi'])); ?></span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mt-2 mb-0">
                "Hidup" berarti detak terakhir belum lewat <?php echo (int)(KIOSK_BATAS_DIAM / 60); ?> menit.
                "Alat" adalah pembaca sidik jari menurut Windows di PC kiosk.
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h5 class="card-title">Uji rantai dari PC ini</h5>
        <p class="small text-muted">
            Menjalankan satu detak: jembatan di PC ini, tantangan dari server, tanda tangan jembatan, lalu pemeriksaan
            server. Hanya berhasil kalau halaman ini dibuka di PC kiosk.
        </p>
        <button type="button" class="btn btn-primary" id="uji-rantai"
                data-sidik="<?php echo aman(json_encode($peta_sidik)); ?>">
            <i class="bi bi-activity"></i> Uji rantai
        </button>
        <ol class="mt-3 mb-0" id="hasil-rantai"></ol>
    </div>
</div>

<?php foreach ($perangkat as $id => $p): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h5 class="card-title"><?php echo KIOSK_HARI_RIWAYAT; ?> hari terakhir: <code><?php echo aman($id); ?></code></h5>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr><th>Tanggal</th><th>Detak pertama</th><th>Detak terakhir</th><th>Jumlah detak</th><th>Jeda di atas <?php echo (int)(KIOSK_BATAS_DIAM / 60); ?> menit</th><th>Detak tanpa alat</th></tr>
                    </thead>
                    <tbody>
                        <?php for ($i = 0; $i < KIOSK_HARI_RIWAYAT; $i++): ?>
                            <?php
                            $cap_hari = strtotime('-' . $i . ' days', $kini);
                            $h = $p['hari'][date('Y-m-d', $cap_hari)] ?? null;
                            ?>
                            <tr>
                                <td><?php echo aman(tanggalKiosk($cap_hari)); ?></td>
                                <?php if ($h === null): ?>
                                    <td colspan="5" class="text-muted">tidak ada detak</td>
                                <?php else: ?>
                                    <td><?php echo aman(date('H.i', $h['pertama'])); ?></td>
                                    <td><?php echo aman(date('H.i', $h['terakhir'])); ?></td>
                                    <td><?php echo (int)$h['jumlah']; ?></td>
                                    <td>
                                        <?php if ($h['jeda'] === 0): ?>
                                            —
                                        <?php else: ?>
                                            <span class="text-danger"><?php echo (int)$h['jeda']; ?> kali, total <?php echo aman(lamaKiosk($h['detik_jeda'])); ?></span>;
                                            terpanjang <?php echo aman(date('H.i', $h['jeda_terpanjang'][0]) . '–' . date('H.i', $h['jeda_terpanjang'][1])); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $h['tanpa_alat'] > 0 ? '<span class="text-danger">' . (int)$h['tanpa_alat'] . '</span>' : '—'; ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php
$custom_script = <<<'HTML'
<script>
(function () {
    var JEMBATAN = 'http://127.0.0.1:47890';
    var SERVER_PUTUS = 'Server tidak bisa dihubungi dari peramban ini.';
    var tombol = document.getElementById('uji-rantai');
    var daftar = document.getElementById('hasil-rantai');
    var sidikServer = JSON.parse(tombol.dataset.sidik || '{}');

    function catat(teks, lulus) {
        var butir = document.createElement('li');
        butir.textContent = teks;
        butir.className = lulus ? 'text-success' : 'text-danger fw-bold';
        daftar.appendChild(butir);
    }

    // Jawaban beserta kodenya; isi null kalau jawabannya bukan JSON. Hasilnya
    // null kalau permintaannya tidak sampai: tujuannya mati, jaringan putus,
    // atau peramban menolaknya (CORS).
    async function panggil(url, kiriman) {
        var opsi = kiriman === undefined ? {} : {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(kiriman)
        };
        var jawaban;
        try {
            jawaban = await fetch(url, opsi);
        } catch (e) {
            return null;
        }
        var isi = null;
        try {
            isi = await jawaban.json();
        } catch (e) { /* bukan JSON */ }
        return { kode: jawaban.status, isi: isi };
    }

    // Alasan penolakan, sebagai kalimat utuh.
    function pesan(hasil) {
        return hasil.isi && typeof hasil.isi.message === 'string' ? hasil.isi.message : 'jawaban ' + hasil.kode + '.';
    }

    async function jalankan() {
        daftar.replaceChildren();

        var status = await panggil(JEMBATAN + '/status');
        if (status === null) {
            catat('Jembatan di PC ini tidak menjawab. Uji ini hanya berjalan di PC kiosk: layanan JembatanSidikJari '
                + 'harus hidup, kebijakan Chrome untuk 127.0.0.1 harus terpasang, dan halaman ini harus dibuka dari '
                + 'https://smkt.alhasan.co.id.', false);
            return;
        }
        if (status.kode !== 200) {
            catat('Jembatan menolak permintaan status: ' + pesan(status), false);
            return;
        }
        if (!status.isi || typeof status.isi.perangkat !== 'string') {
            catat('Yang menjawab di ' + JEMBATAN + ' bukan jembatan sidik jari.', false);
            return;
        }
        var id = status.isi.perangkat;
        catat('Jembatan ' + status.isi.versi + ' (' + status.isi.mode + ') menjawab sebagai ' + id + '.', true);

        if (typeof status.isi.sidik_kunci === 'string') {
            var urutan = (sidikServer[id] || []).indexOf(status.isi.sidik_kunci);
            if (urutan >= 0) {
                catat('Sidik kunci jembatan ' + status.isi.sidik_kunci + ' sama dengan kunci ke-' + (urutan + 1) + ' di server.', true);
            } else {
                catat('Sidik kunci jembatan ' + status.isi.sidik_kunci + ' tidak ada di server untuk ' + id
                    + '. Pasangkan: salin ID dan kunci dari jembatan ke berkas konfigurasi.', false);
            }
        }

        var tantangan = await panggil('../api/sj_tantangan.php', { tujuan: 'detak', perangkat: id });
        if (tantangan === null) {
            catat(SERVER_PUTUS, false);
            return;
        }
        if (tantangan.kode !== 200 || !tantangan.isi) {
            catat('Server tidak memberi tantangan: ' + pesan(tantangan), false);
            return;
        }
        catat('Server memberi tantangan.', true);

        var detak = await panggil(JEMBATAN + '/detak', { tantangan: tantangan.isi.tantangan });
        if (detak === null) {
            catat('Jembatan tidak menjawab permintaan detak.', false);
            return;
        }
        if (detak.kode !== 200 || !detak.isi) {
            catat('Jembatan menolak menandatangani detak: ' + pesan(detak), false);
            return;
        }
        catat('Jembatan menandatangani detak. ' + detak.isi.keterangan_alat, detak.isi.alat === 1);

        var hasil = await panggil('../api/sj_detak.php', {
            perangkat: detak.isi.perangkat,
            tantangan: tantangan.isi.tantangan,
            alat: detak.isi.alat,
            tanda_tangan: detak.isi.tanda_tangan,
            versi: status.isi.versi
        });
        if (hasil === null) {
            catat(SERVER_PUTUS, false);
            return;
        }
        if (hasil.kode !== 200 || !hasil.isi) {
            catat('Server menolak detak: ' + pesan(hasil), false);
            return;
        }
        catat('Server menerima detak pada ' + hasil.isi.waktu + '. Rantai utuh; muat ulang halaman untuk melihatnya di tabel.', true);
    }

    tombol.addEventListener('click', function () {
        tombol.disabled = true;
        jalankan().catch(function (e) {
            catat('Uji terhenti: ' + e.message, false);
        }).finally(function () {
            tombol.disabled = false;
        });
    });
})();
</script>
HTML;

include 'partials/footer.php';
