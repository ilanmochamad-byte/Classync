<?php
// sj_detak.php — menerima detak bertanda tangan dari jembatan sidik jari.
//
// Halaman kiosk meminta tantangan (api/sj_tantangan.php), meneruskannya ke
// jembatan di PC kiosk, lalu mengirim hasilnya ke sini. Detak yang sah
// membuktikan seluruh rantainya hidup: halaman kiosk, jembatan, dan kunci
// perangkat yang sama dengan yang terdaftar di server. Hasilnya satu baris di
// detak_kiosk, yang dibaca admin/kiosk_sidik_jari.php.
//
// Kiriman: POST JSON
//   {"perangkat": "kiosk-xxxxxx", "tantangan": "<64 hex>", "alat": 0 atau 1,
//    "tanda_tangan": "<64 hex>", "versi": "<versi jembatan, boleh kosong>"}
// Jawaban: {"status": "ok", "waktu": "<waktu server>"}.
//
// Pesan yang diperiksa disusun server sendiri dari perangkat, tantangan, dan
// alat. versi hanya keterangan dan tidak ikut ditandatangani.
//
// Terbuka untuk umum. Basis data baru dibuka setelah tanda tangannya terbukti
// sah, jadi kiriman tanpa kunci perangkat tidak pernah sampai ke sana.

ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/sidik_jari.php';

$isi = sjIsiPermintaan();

$perangkat = $isi['perangkat'] ?? null;
$tantangan = $isi['tantangan'] ?? null;
$alat = $isi['alat'] ?? null;
$tanda_tangan = $isi['tanda_tangan'] ?? null;
$pesan = sjPesanDetak($perangkat, $tantangan, $alat);
if ($pesan === null || !sjBentukTandaTangan($tanda_tangan)) {
    sjKirim(400, ['status' => 'error', 'message' => 'Kiriman detak tidak lengkap atau bentuknya salah.']);
}

$konfigurasi = sjKonfigurasi();
if ($konfigurasi === null) {
    sjKirim(503, ['status' => 'error', 'message' => 'Sidik jari belum dikonfigurasi di server.']);
}
$entri = sjPerangkat($konfigurasi, $perangkat);
if ($entri === null) {
    sjKirim(403, ['status' => 'error', 'message' => 'Perangkat tidak terdaftar atau tidak aktif.']);
}

$keadaan = sjPeriksaTantangan($konfigurasi, $tantangan, 'detak', $perangkat);
if ($keadaan === 'kedaluwarsa') {
    sjKirim(403, ['status' => 'error', 'message' => 'Tantangan sudah kedaluwarsa.']);
}
if ($keadaan !== 'sah') {
    sjKirim(403, ['status' => 'error', 'message' => 'Tantangan bukan terbitan server ini untuk detak perangkat itu.']);
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
} catch (Throwable $e) {
    error_log('[sj_detak] basis data tidak bisa dibuka: ' . get_class($e) . ': ' . $e->getMessage());
    sjKirim(503, ['status' => 'error', 'message' => 'Basis data tidak bisa dibuka.']);
}

try {
    if (!sjPakaiTantangan($conn, $tantangan, 'detak', $perangkat)) {
        sjKirim(409, ['status' => 'error', 'message' => 'Tantangan sudah pernah dipakai.']);
    }
    sjCatatDetak($conn, $perangkat, $alat, $isi['versi'] ?? '');
} catch (Throwable $e) {
    error_log('[sj_detak] detak ' . $perangkat . ' tidak tercatat: ' . get_class($e) . ': ' . $e->getMessage());
    sjKirim(500, ['status' => 'error', 'message' => 'Detak tidak bisa dicatat.']);
}

sjKirim(200, ['status' => 'ok', 'waktu' => date('Y-m-d H:i:s')]);
