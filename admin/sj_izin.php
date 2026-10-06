<?php
// sj_izin.php — izin server untuk mendaftarkan atau mencabut sidik jari di jembatan.
//
// Dipanggil halaman admin/sidik_jari.php, yang dibuka admin di PC kiosk.
// Jembatan hanya mau menyimpan atau menghapus templat kalau kirimannya membawa
// izin bertanda tangan server atas tantangan yang baru diterbitkan jembatan
// itu sendiri. Izin ditandatangani dengan kunci perangkat (lihat
// includes/sidik_jari.php), dan hanya keluar dari sini.
//
// Kiriman: POST JSON
//   {"csrf": "...", "aksi": "daftar", "perangkat": "kiosk-xxxxxx",
//    "tantangan_jembatan": "<64 hex>", "identitas": "siswa:12",
//    "jari": "telunjuk-kanan"}
//   {"csrf": "...", "aksi": "cabut", "perangkat": "kiosk-xxxxxx",
//    "tantangan_jembatan": "<64 hex>", "identitas": "siswa:12"}
// Jawaban:
//   {"status": "ok", "izin": ["<64 hex>", ...], "tantangan": "<64 hex>",
//    "berlaku_detik": 900}
// izin berisi satu tanda tangan per kunci perangkat. tantangan diterbitkan
// server, untuk tanda terima yang nanti dikirim ke admin/sj_catat.php.
//
// Menuntut sesi admin dan token CSRF halaman pendaftaran. Izin daftar hanya
// diberikan untuk orang yang ada, boleh didaftarkan, dan persetujuannya
// tercatat 'setuju'. Izin cabut tidak memeriksa itu: templat orang yang sudah
// lulus, sudah dihapus, atau menarik persetujuannya justru harus bisa dicabut.
//
// Tidak ada yang ditulis ke basis data di sini.

ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/sidik_jari.php';

$isi = sjIsiPermintaan();
$admin_id = sjWajibAdmin($isi);

$aksi = $isi['aksi'] ?? null;
$perangkat = $isi['perangkat'] ?? null;
$tantangan_jembatan = $isi['tantangan_jembatan'] ?? null;
$identitas = $isi['identitas'] ?? null;

if ($aksi === 'daftar') {
    $pesan = sjPesanIzinDaftar($perangkat, $tantangan_jembatan, $identitas, $isi['jari'] ?? null);
} elseif ($aksi === 'cabut') {
    $pesan = sjPesanIzinCabut($perangkat, $tantangan_jembatan, $identitas);
} else {
    $pesan = null;
}
if ($pesan === null) {
    sjKirim(400, ['status' => 'error', 'message' => 'Kiriman izin tidak lengkap atau bentuknya salah.']);
}

$konfigurasi = sjKonfigurasi();
if ($konfigurasi === null) {
    sjKirim(503, ['status' => 'error', 'message' => 'Sidik jari belum dikonfigurasi di server.']);
}
$entri = sjPerangkat($konfigurasi, $perangkat);
if ($entri === null) {
    sjKirim(403, ['status' => 'error', 'message' => 'Perangkat tidak terdaftar atau tidak aktif.']);
}

if ($aksi === 'daftar') {
    // includes/db.php berhenti sendiri dengan teks biasa kalau konfigurasinya
    // tidak ada. Kode 503 dipasang lebih dulu supaya jawaban itu tidak keluar
    // sebagai 200.
    http_response_code(503);
    try {
        require __DIR__ . '/../includes/db.php';
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    } catch (Throwable $e) {
        error_log('[sj_izin] basis data tidak bisa dibuka: ' . get_class($e) . ': ' . $e->getMessage());
        sjKirim(503, ['status' => 'error', 'message' => 'Basis data tidak bisa dibuka.']);
    }
    try {
        $orang = sjOrang($conn, $identitas);
        $persetujuan = $orang === null ? null : sjPersetujuan($conn, $identitas);
    } catch (Throwable $e) {
        error_log('[sj_izin] data ' . $identitas . ' tidak bisa dibaca: ' . get_class($e) . ': ' . $e->getMessage());
        sjKirim(500, ['status' => 'error', 'message' => 'Data orang itu tidak bisa dibaca.']);
    }
    if ($orang === null) {
        sjKirim(404, ['status' => 'error', 'message' => 'Orang itu tidak ditemukan.']);
    }
    if (!$orang['boleh']) {
        sjKirim(409, ['status' => 'error', 'message' => $orang['alasan']]);
    }
    if ($persetujuan === null || $persetujuan['status'] !== 'setuju') {
        sjKirim(409, ['status' => 'error', 'message' => 'Persetujuan orang itu belum tercatat.']);
    }
}

try {
    $tantangan = sjTantanganBaru($konfigurasi, $aksi, $perangkat);
    $izin = sjTandaIzin($entri, $pesan);
} catch (Throwable $e) {
    error_log('[sj_izin] ' . get_class($e) . ': ' . $e->getMessage());
    sjKirim(500, ['status' => 'error', 'message' => 'Izin tidak bisa dibuat.']);
}

sjKirim(200, ['status' => 'ok', 'izin' => $izin, 'tantangan' => $tantangan, 'berlaku_detik' => SJ_UMUR_IZIN]);
