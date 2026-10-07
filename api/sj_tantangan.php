<?php
// sj_tantangan.php — menerbitkan tantangan sekali pakai untuk jembatan sidik jari.
//
// Dipanggil halaman kiosk dan panel admin (admin/kiosk_sidik_jari.php)
// sebelum meminta jembatan menandatangani sesuatu. Tantangannya ikut di dalam
// pesan bertanda tangan, lalu penerimanya (api/sj_detak.php atau
// api/sj_absen.php) memastikan tantangan itu terbitan server ini untuk tujuan
// dan perangkat itu, belum kedaluwarsa, dan belum pernah dipakai.
//
// Terbuka untuk umum, dan sengaja tidak menyentuh basis data: tantangan
// memuat tandanya sendiri (lihat includes/sidik_jari.php). Tanpa kunci
// perangkat, tantangan tidak berguna bagi pemanggilnya.
//
// Kiriman: POST JSON {"tujuan": "detak" atau "absen", "perangkat": "kiosk-xxxxxx"}.
// Jawaban: {"status": "ok", "tantangan": "<64 hex>", "berlaku_detik": 120}.
//
// Tantangan absen hanya diterbitkan selama saklar $sj_absen_kiosk di berkas
// konfigurasi bernilai true. Tujuan daftar dan cabut tidak dibuka di sini:
// tantangannya diterbitkan admin/sj_izin.php untuk admin yang sedang login.

ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/sidik_jari.php';

$isi = sjIsiPermintaan();

$tujuan = $isi['tujuan'] ?? null;
$perangkat = $isi['perangkat'] ?? null;
if (($tujuan !== 'detak' && $tujuan !== 'absen') || !sjBentukPerangkat($perangkat)) {
    sjKirim(400, ['status' => 'error', 'message' => 'Tujuan atau perangkat tidak sah.']);
}

$konfigurasi = sjKonfigurasi();
if ($konfigurasi === null) {
    sjKirim(503, ['status' => 'error', 'message' => 'Sidik jari belum dikonfigurasi di server.']);
}
if (sjPerangkat($konfigurasi, $perangkat) === null) {
    sjKirim(403, ['status' => 'error', 'message' => 'Perangkat tidak terdaftar atau tidak aktif.']);
}
if ($tujuan === 'absen' && !$konfigurasi['absen_kiosk']) {
    sjKirim(403, ['status' => 'error', 'message' => 'Absen lewat sidik jari belum dibuka.']);
}

try {
    $tantangan = sjTantanganBaru($konfigurasi, $tujuan, $perangkat);
} catch (Throwable $e) {
    error_log('[sj_tantangan] ' . get_class($e) . ': ' . $e->getMessage());
    sjKirim(500, ['status' => 'error', 'message' => 'Tantangan tidak bisa dibuat.']);
}

sjKirim(200, ['status' => 'ok', 'tantangan' => $tantangan, 'berlaku_detik' => SJ_UMUR_TANTANGAN]);
