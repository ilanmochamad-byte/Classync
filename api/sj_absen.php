<?php
// sj_absen.php — absen siswa lewat sidik jari di kiosk.
//
// Halaman kiosk meminta tantangan (api/sj_tantangan.php, tujuan absen),
// mengirim sidik jari yang ditangkapnya ke jembatan di PC kiosk, lalu
// meneruskan jawaban jembatan ke sini. Jembatan yang mencocokkan jarinya, dan
// jembatan yang menandatangani hasilnya:
//   dikenali        SJ1|absen|<perangkat>|<tantangan>|<identitas>|<skor>
//   tidak dikenali  SJ1|tolak|<perangkat>|<tantangan>|<skor>
// Sidik jarinya sendiri tidak pernah sampai ke server. Yang dipercaya hanya
// tanda tangan itu: tanpa kunci perangkat, tidak ada yang tercatat dari sini.
//
// Kiriman: POST JSON
//   {"perangkat": "kiosk-xxxxxx", "tantangan": "<64 hex>",
//    "diterima": true atau false, "identitas": "siswa:<id>" kalau diterima,
//    "skor": bilangan bulat, "tanda_tangan": "<64 hex>",
//    "foto_base64": "<foto webcam kiosk, boleh tidak ada>"}
// skor harus bilangan JSON, bukan teks: ia ikut ditandatangani. Kiriman paling
// besar 1 MB dan harus berupa objek datar. Di atas itu dijawab 413 sebelum
// tantangannya dipakai, jadi tanda terima yang sama masih bisa dikirim ulang
// tanpa foto.
//
// Jawaban 200: {"status": "ok", "hasil": ..., "message": "<kalimat untuk
// layar kiosk>"}, dengan hasil:
//   'masuk', 'pulang'  absensinya tercatat. "data" sama isinya dengan jawaban
//                      api/proses_absen_siswa.php;
//   'ditolak'          jarinya dikenali, tetapi absensinya tidak dicatat;
//   'tidak_dikenali'   jembatan tidak mengenali jarinya;
//   'bukan_siswa'      jari guru. Kiosk ini tidak mencatat absensi guru.
// "orang" (nama dan kelompok) ikut kalau orangnya ada di data sekolah.
// Selain 200 tidak ada absensi yang tercatat: kirimannya ditolak (4xx), atau
// server gagal mencatatnya (5xx).
//
// Masuk atau pulang diputuskan server, bukan halaman: pulang kalau siswa
// sudah absen masuk hari ini, atau kalau jam pulang hari sekolah itu sudah
// lewat; selain itu masuk. Siswa yang belum absen masuk dan baru menempel
// setelah jam pulang ditolak, seperti di kiosk QR/NISN yang sudah berpindah
// ke mode PULANG. Aturan dan pesan penolakannya sama dengan jalur QR/NISN,
// dari includes/absen_siswa.php.
//
// Absensi hanya dicatat kalau orangnya siswa yang belum lulus,
// persetujuannya 'setuju', dan jarinya tercatat aktif di perangkat itu.
// Setiap tempelan yang tanda tangannya sah masuk ke log_absen_sidik_jari,
// termasuk yang tidak dikenali dan yang ditolak.
//
// Terbuka untuk umum, karena halaman kiosk tidak punya sesi. Basis data,
// folder foto, dan error_log baru disentuh setelah tanda tangannya terbukti
// sah. Selama $sj_absen_kiosk di berkas konfigurasi bukan true, semua kiriman
// ditolak.

ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/sidik_jari.php';

// Foto webcam kiosk ikut di dalam kiriman sebagai base64, jadi batasnya jauh
// di atas endpoint sidik jari yang lain. Foto kiosk sendiri sekitar 0,1 MB.
const SJ_ABSEN_MAKS_KIRIMAN = 1024 * 1024;

// Mengirim jawaban, menutup sambungannya, lalu berhenti. WA untuk orang tua
// dikirim sesudahnya lewat fungsi shutdown, dan gateway WA bisa butuh puluhan
// detik: kiosk tidak boleh menunggunya. Content-Length memberi tahu kiosk
// bahwa jawabannya sudah lengkap.
function sjAbsenJawab($isi) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $badan = json_encode($isi, JSON_INVALID_UTF8_SUBSTITUTE);
    header('Content-Length: ' . strlen($badan));
    echo $badan;
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
    exit;
}

// Foto dari halaman kiosk sebagai base64 polos, atau null kalau isinya bukan
// JPEG. Foto hanya pelengkap: absennya tetap dicatat tanpa foto.
function sjAbsenFoto($foto) {
    if (!is_string($foto) || $foto === '') {
        return null;
    }
    $awal = strpos($foto, 'base64,');
    if ($awal !== false) {
        $foto = substr($foto, $awal + strlen('base64,'));
    }
    $biner = base64_decode($foto, true);
    if ($biner === false || $biner === '') {
        return null;
    }
    $info = @getimagesizefromstring($biner);
    if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
        return null;
    }
    return base64_encode($biner);
}

// Hanya objek datar yang diurai. Tanpa batas kedalaman, kiriman bersarang
// sebesar ini menghabiskan memori sebelum tanda tangannya sempat diperiksa.
$isi = sjIsiPermintaan(SJ_ABSEN_MAKS_KIRIMAN, 2);

$perangkat = $isi['perangkat'] ?? null;
$tantangan = $isi['tantangan'] ?? null;
$diterima = $isi['diterima'] ?? null;
$identitas = $isi['identitas'] ?? null;
$skor = $isi['skor'] ?? null;
$tanda_tangan = $isi['tanda_tangan'] ?? null;
$foto = $isi['foto_base64'] ?? null;

if ($diterima === true) {
    $pesan = sjPesanAbsen($perangkat, $tantangan, $identitas, $skor);
} elseif ($diterima === false && $identitas === null) {
    $pesan = sjPesanTolak($perangkat, $tantangan, $skor);
} else {
    $pesan = null;
}
if ($pesan === null || !sjBentukTandaTangan($tanda_tangan) || ($foto !== null && !is_string($foto))) {
    sjKirim(400, ['status' => 'error', 'message' => 'Kiriman absen tidak lengkap atau bentuknya salah.']);
}

$konfigurasi = sjKonfigurasi();
if ($konfigurasi === null) {
    sjKirim(503, ['status' => 'error', 'message' => 'Sidik jari belum dikonfigurasi di server.']);
}
if (!$konfigurasi['absen_kiosk']) {
    sjKirim(403, ['status' => 'error', 'message' => 'Absen lewat sidik jari belum dibuka.']);
}
$entri = sjPerangkat($konfigurasi, $perangkat);
if ($entri === null) {
    sjKirim(403, ['status' => 'error', 'message' => 'Perangkat tidak terdaftar atau tidak aktif.']);
}

$keadaan = sjPeriksaTantangan($konfigurasi, $tantangan, 'absen', $perangkat);
if ($keadaan === 'kedaluwarsa') {
    sjKirim(403, ['status' => 'error', 'message' => 'Tantangan sudah kedaluwarsa. Tempelkan jari lagi.']);
}
if ($keadaan !== 'sah') {
    sjKirim(403, ['status' => 'error', 'message' => 'Tantangan bukan terbitan server ini untuk absen perangkat itu.']);
}
if (!sjTandaTanganSah($entri, $pesan, $tanda_tangan)) {
    sjKirim(403, ['status' => 'error', 'message' => 'Tanda tangan tidak cocok dengan kunci perangkat di server.']);
}

// includes/db.php berhenti sendiri dengan teks biasa kalau konfigurasinya
// tidak ada. Kode 503 dipasang lebih dulu supaya jawaban itu tidak keluar
// sebagai 200.
http_response_code(503);
try {
    require __DIR__ . '/../includes/db.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once __DIR__ . '/../includes/kalender_sekolah.php';
    require_once __DIR__ . '/../includes/absen_siswa.php';
} catch (Throwable $e) {
    error_log('[sj_absen] basis data tidak bisa dibuka: ' . get_class($e) . ': ' . $e->getMessage());
    sjKirim(503, ['status' => 'error', 'message' => 'Basis data tidak bisa dibuka.']);
}

// Tantangannya dipakai lebih dulu. Setelah titik ini kiriman yang sama tidak
// bisa diulang, apa pun hasil absennya.
try {
    if (!sjPakaiTantangan($conn, $tantangan, 'absen', $perangkat)) {
        sjKirim(409, ['status' => 'error', 'message' => 'Tempelan ini sudah pernah dikirim. Tempelkan jari lagi.']);
    }
} catch (Throwable $e) {
    error_log('[sj_absen] tantangan ' . $perangkat . ' tidak tercatat: ' . get_class($e) . ': ' . $e->getMessage());
    sjKirim(500, ['status' => 'error', 'message' => 'Absen tidak bisa dicatat. Tempelkan jari lagi.']);
}

// Log tempelan hanya untuk mengukur. Kegagalannya tidak boleh menggagalkan
// absensinya.
$catat_log = function ($identitas, $hasil, $keterangan = '') use ($conn, $perangkat, $skor) {
    try {
        sjCatatLogAbsen($conn, $perangkat, $identitas, $skor, $hasil, $keterangan);
    } catch (Throwable $e) {
        error_log('[sj_absen] log tempelan ' . $perangkat . ' tidak tercatat: ' . get_class($e) . ': ' . $e->getMessage());
    }
};

if ($diterima === false) {
    $catat_log(null, 'tidak_dikenali');
    sjAbsenJawab(['status' => 'ok', 'hasil' => 'tidak_dikenali', 'message' => 'Sidik jari tidak dikenali. Tempelkan jari lagi.']);
}

try {
    $orang = sjOrang($conn, $identitas);
    $persetujuan = $orang === null ? null : sjPersetujuan($conn, $identitas);
    $jari_aktif = $orang === null ? 0 : sjPendaftaranAktif($conn, $identitas, $perangkat);
    $siswa = null;
    if ($orang !== null && $orang['jenis'] === 'siswa') {
        $siswa_id = (int)substr($identitas, strlen('siswa:'));
        $stmt = $conn->prepare("SELECT id, nisn, nama_siswa, kelas, kontak_ortu FROM siswa WHERE id = ?");
        $stmt->bind_param('i', $siswa_id);
        $stmt->execute();
        $siswa = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log('[sj_absen] data ' . $identitas . ' tidak bisa dibaca: ' . get_class($e) . ': ' . $e->getMessage());
    $catat_log($identitas, 'ditolak', 'galat_server');
    sjKirim(500, ['status' => 'error', 'message' => 'Absen tidak bisa dicatat. Tempelkan jari lagi.']);
}

$info_orang = $orang === null ? null : ['nama' => $orang['nama'], 'kelompok' => $orang['kelompok']];
$tolak = function ($kode, $kalimat) use ($catat_log, $identitas, $info_orang) {
    $catat_log($identitas, 'ditolak', $kode);
    $jawaban = ['status' => 'ok', 'hasil' => 'ditolak', 'message' => $kalimat];
    if ($info_orang !== null) {
        $jawaban['orang'] = $info_orang;
    }
    sjAbsenJawab($jawaban);
};

if ($orang === null || ($orang['jenis'] === 'siswa' && !$siswa)) {
    $tolak('orang_tidak_ada', 'Sidik jari ini terdaftar atas nama yang sudah tidak ada di data sekolah. Hubungi admin.');
}
if ($persetujuan === null || $persetujuan['status'] !== 'setuju') {
    $tolak('tanpa_persetujuan', 'Persetujuan sidik jari untuk nama ini tidak tercatat. Hubungi admin.');
}
if ($jari_aktif === 0) {
    $tolak('tidak_tercatat', 'Sidik jari ini belum tercatat di server. Minta admin mendaftarkannya ulang.');
}
if ($orang['jenis'] !== 'siswa') {
    $catat_log($identitas, 'bukan_siswa');
    sjAbsenJawab(['status' => 'ok', 'hasil' => 'bukan_siswa', 'message' => 'Sidik jari guru dikenali. Tidak ada absensi yang dicatat.', 'orang' => $info_orang]);
}
if (!$orang['boleh']) {
    $tolak('lulus', 'Siswa ini sudah lulus. Minta admin mencabut sidik jarinya.');
}

try {
    $hasil = catatAbsenSiswa($conn, $siswa, 'otomatis', sjAbsenFoto($foto));
} catch (Throwable $e) {
    error_log('[sj_absen] absen ' . $identitas . ' di ' . $perangkat . ' tidak tercatat: ' . get_class($e) . ': ' . $e->getMessage());
    $catat_log($identitas, 'ditolak', 'galat_server');
    sjKirim(500, ['status' => 'error', 'message' => 'Absen tidak bisa dicatat. Tempelkan jari lagi.']);
}
if (!$hasil['tercatat']) {
    $tolak($hasil['kode'], $hasil['pesan']);
}
$catat_log($identitas, $hasil['mode'], $hasil['kode'] === $hasil['mode'] ? '' : $hasil['kode']);

// WA untuk orang tua, sama dengan jalur QR/NISN. Dikirim setelah jawaban
// sampai ke kiosk, dan tidak boleh menggagalkan absen yang sudah tercatat.
if (!empty($siswa['kontak_ortu'])) {
    try {
        $wa_sender = __DIR__ . '/../includes/wa_sender.php';
        if (is_readable($wa_sender)) {
            ob_start();
            try {
                require_once $wa_sender;
            } finally {
                ob_end_clean();
            }
            if (function_exists('kirimNotifikasiWA') && function_exists('formatNomorWA')) {
                $nomor_wa = formatNomorWA($siswa['kontak_ortu']);
                $pesan_wa = $hasil['wa'];
                register_shutdown_function(function () use ($nomor_wa, $pesan_wa) {
                    try {
                        kirimNotifikasiWA($nomor_wa, $pesan_wa);
                    } catch (Throwable $e) {
                        error_log('[sj_absen] WA tidak terkirim: ' . get_class($e) . ': ' . $e->getMessage());
                    }
                });
            }
        }
    } catch (Throwable $e) {
        error_log('[sj_absen] WA tidak bisa disiapkan: ' . get_class($e) . ': ' . $e->getMessage());
    }
}

sjAbsenJawab(['status' => 'ok', 'hasil' => $hasil['mode'], 'message' => $hasil['pesan'], 'orang' => $info_orang, 'data' => $hasil['data']]);
