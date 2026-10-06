<?php
// sj_catat.php — mencatat tanda terima jembatan: jari yang terdaftar atau dicabut.
//
// Dipanggil halaman admin/sidik_jari.php setelah jembatan menyimpan atau
// menghapus templat. Catatan pendaftaran hanya ditulis dari tanda terima
// bertanda tangan jembatan, yang memuat tantangan terbitan admin/sj_izin.php.
// Jadi isi pendaftaran_sidik_jari mengikuti apa yang benar-benar terjadi di
// jembatan, bukan apa yang dikatakan halaman.
//
// Kiriman: POST JSON, selalu dengan "csrf" dan "aksi":
//   daftar         {"perangkat", "tantangan", "identitas", "jari", "mutu",
//                   "tanda_tangan"}
//   cabut          {"perangkat", "tantangan", "identitas", "jumlah",
//                   "tanda_tangan"}
//   tidak-terbaca  {"identitas"}
// Jawaban: {"status": "ok", ...}.
//
// mutu dan jumlah harus bilangan JSON, bukan teks: keduanya ikut
// ditandatangani.
//
// tidak-terbaca bukan tanda terima. Admin yang menyatakan bahwa jari orang itu
// tidak bisa didaftarkan setelah dicoba. Hanya untuk orang yang persetujuannya
// 'setuju', dan dicabut sendiri oleh pendaftaran yang berhasil.
//
// Menuntut sesi admin dan token CSRF halaman pendaftaran. Tantangan hanya bisa
// dipakai sekali. Pemakaiannya satu transaksi dengan pencatatannya, jadi kalau
// pencatatan gagal, tanda terima yang sama masih bisa dikirim ulang.

ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/sidik_jari.php';

$isi = sjIsiPermintaan();
$admin_id = sjWajibAdmin($isi);

$aksi = $isi['aksi'] ?? null;
$perangkat = $isi['perangkat'] ?? null;
$tantangan = $isi['tantangan'] ?? null;
$identitas = $isi['identitas'] ?? null;
$jari = $isi['jari'] ?? null;
$mutu = $isi['mutu'] ?? null;
$jumlah = $isi['jumlah'] ?? null;
$tanda_tangan = $isi['tanda_tangan'] ?? null;

if ($aksi === 'tidak-terbaca') {
    if (!sjBentukIdentitas($identitas)) {
        sjKirim(400, ['status' => 'error', 'message' => 'Identitas tidak sah.']);
    }
} else {
    if ($aksi === 'daftar') {
        $pesan = sjPesanTerdaftar($perangkat, $tantangan, $identitas, $jari, $mutu);
    } elseif ($aksi === 'cabut') {
        $pesan = sjPesanDicabut($perangkat, $tantangan, $identitas, $jumlah);
    } else {
        $pesan = null;
    }
    if ($pesan === null || !sjBentukTandaTangan($tanda_tangan)) {
        sjKirim(400, ['status' => 'error', 'message' => 'Tanda terima tidak lengkap atau bentuknya salah.']);
    }

    $konfigurasi = sjKonfigurasi();
    if ($konfigurasi === null) {
        sjKirim(503, ['status' => 'error', 'message' => 'Sidik jari belum dikonfigurasi di server.']);
    }
    $entri = sjPerangkat($konfigurasi, $perangkat);
    if ($entri === null) {
        sjKirim(403, ['status' => 'error', 'message' => 'Perangkat tidak terdaftar atau tidak aktif.']);
    }
    $keadaan = sjPeriksaTantangan($konfigurasi, $tantangan, $aksi, $perangkat, null, SJ_UMUR_IZIN);
    if ($keadaan === 'kedaluwarsa') {
        sjKirim(403, ['status' => 'error', 'message' => 'Tantangan sudah kedaluwarsa. Ulangi dari awal untuk orang itu.']);
    }
    if ($keadaan !== 'sah') {
        sjKirim(403, ['status' => 'error', 'message' => 'Tantangan bukan terbitan server ini untuk tindakan dan perangkat itu.']);
    }
    if (!sjTandaTanganSah($entri, $pesan, $tanda_tangan)) {
        sjKirim(403, ['status' => 'error', 'message' => 'Tanda tangan tidak cocok dengan kunci perangkat di server.']);
    }
}

// includes/db.php berhenti sendiri dengan teks biasa kalau konfigurasinya
// tidak ada. Kode 503 dipasang lebih dulu supaya jawaban itu tidak keluar
// sebagai 200.
http_response_code(503);
try {
    require __DIR__ . '/../includes/db.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
} catch (Throwable $e) {
    error_log('[sj_catat] basis data tidak bisa dibuka: ' . get_class($e) . ': ' . $e->getMessage());
    sjKirim(503, ['status' => 'error', 'message' => 'Basis data tidak bisa dibuka.']);
}

if ($aksi === 'tidak-terbaca') {
    try {
        $persetujuan = sjPersetujuan($conn, $identitas);
        if ($persetujuan !== null && $persetujuan['status'] === 'setuju') {
            sjTandaiTidakTerbaca($conn, $identitas, $admin_id);
        }
    } catch (Throwable $e) {
        error_log('[sj_catat] tanda tidak terbaca ' . $identitas . ' gagal: ' . get_class($e) . ': ' . $e->getMessage());
        sjKirim(500, ['status' => 'error', 'message' => 'Catatan tidak bisa disimpan.']);
    }
    if ($persetujuan === null || $persetujuan['status'] !== 'setuju') {
        sjKirim(409, ['status' => 'error', 'message' => 'Persetujuan orang itu belum tercatat.']);
    }
    sjKirim(200, ['status' => 'ok', 'identitas' => $identitas]);
}

$sudah_dipakai = false;
$berubah = 0;
try {
    $conn->begin_transaction();
    if (!sjPakaiTantangan($conn, $tantangan, $aksi, $perangkat)) {
        $sudah_dipakai = true;
        $conn->rollback();
    } else {
        if ($aksi === 'daftar') {
            sjCatatPendaftaran($conn, $perangkat, $identitas, $jari, $mutu, $admin_id);
        } else {
            $berubah = sjCatatPencabutan($conn, $perangkat, $identitas, $admin_id);
        }
        $conn->commit();
    }
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $e2) {
        // Koneksinya sudah putus; transaksinya batal dengan sendirinya.
    }
    error_log('[sj_catat] ' . $aksi . ' ' . $identitas . ' di ' . $perangkat . ' tidak tercatat: ' . get_class($e) . ': ' . $e->getMessage());
    sjKirim(500, ['status' => 'error', 'message' => 'Tanda terima tidak bisa dicatat. Kirim ulang.']);
}
if ($sudah_dipakai) {
    sjKirim(409, ['status' => 'error', 'message' => 'Tanda terima ini sudah pernah dicatat.']);
}

if ($aksi === 'daftar') {
    sjKirim(200, ['status' => 'ok', 'identitas' => $identitas, 'jari' => $jari, 'mutu' => $mutu]);
}
sjKirim(200, ['status' => 'ok', 'identitas' => $identitas, 'dicabut' => $berubah]);
