<?php
// unggah_gambar.php — menyimpan gambar unggahan dengan aman, untuk panel web.
//
// Pola yang sama dengan repo API (commit 6c77656 untuk daftar putih,
// 4ee3eb3 untuk batas ukuran). Repositorinya terpisah, jadi berkas ini
// salinannya sendiri, bukan rujukan lintas repo.
//
// Fungsi-fungsi di sini MENGEMBALIKAN hasil, tidak melempar dan tidak
// mencetak apa pun. Bentuk pesan galat tetap urusan masing-masing halaman
// (die(), redirect, atau pesan di layar) — jangan diseragamkan di sini.

if (!defined('BATAS_UNGGAH_BYTE')) {
    // Dari sensus 5.036 foto produksi, 21 September 2026: p99 4,13 MB, hanya
    // satu berkas di atas 8 MB. Sama dengan batas di repo API.
    define('BATAS_UNGGAH_BYTE', 8 * 1024 * 1024);
}

if (!function_exists('simpanGambarUnggahan')) {
    // $berkas         satu elemen $_FILES
    // $folder_absolut folder tujuan, berakhir '/'
    // $prefiks        awal nama berkas, misalnya 'guru-12'
    //
    // Mengembalikan ['ok' => true, 'nama' => 'guru-12-...jpg'] atau
    // ['ok' => false, 'pesan' => '...'].
    //
    // Yang menutup lubangnya adalah asal EKSTENSI: diambil dari tipe yang
    // terdeteksi getimagesize(), bukan dari nama kiriman klien. Berkas
    // polyglot yang lolos getimagesize() tetap tersimpan sebagai .jpg/.png,
    // dan nama kiriman klien tidak pernah masuk ke nama berkas sama sekali.
    function simpanGambarUnggahan($berkas, $folder_absolut, $prefiks) {
        $kode = isset($berkas['error']) ? $berkas['error'] : UPLOAD_ERR_NO_FILE;
        if ($kode === UPLOAD_ERR_INI_SIZE || $kode === UPLOAD_ERR_FORM_SIZE) {
            return ['ok' => false, 'pesan' => 'Foto gagal diunggah: ukuran berkasnya terlalu besar.'];
        }
        if ($kode !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'pesan' => 'Foto gagal diunggah. Silakan coba lagi.'];
        }

        // Sebelum getimagesize(), karena fungsi itu hanya membaca header dan
        // tidak peduli berapa besar sisa berkasnya.
        if ((int)$berkas['size'] > BATAS_UNGGAH_BYTE) {
            return ['ok' => false, 'pesan' => 'Foto terlalu besar. Maksimal '
                . (BATAS_UNGGAH_BYTE / 1048576) . ' MB.'];
        }

        $info = @getimagesize($berkas['tmp_name']);
        $ekstensi_izin = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png',
                          IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
        if ($info === false || !isset($ekstensi_izin[$info[2]])) {
            return ['ok' => false, 'pesan' => 'Foto harus berupa gambar JPG, PNG, WEBP, atau GIF.'];
        }

        if (!is_dir($folder_absolut)) {
            mkdir($folder_absolut, 0755, true);
        }

        // Prefiks disaring ke karakter aman supaya pemanggil yang lengah pun
        // tidak bisa menyelipkan titik atau garis miring ke nama berkas.
        $prefiks = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$prefiks);
        $nama = $prefiks . '-' . time() . '-' . bin2hex(random_bytes(4))
              . '.' . $ekstensi_izin[$info[2]];

        if (!move_uploaded_file($berkas['tmp_name'], $folder_absolut . $nama)) {
            return ['ok' => false, 'pesan' => 'Foto gagal disimpan di server.'];
        }
        return ['ok' => true, 'nama' => $nama];
    }
}

if (!function_exists('hapusFotoLamaAman')) {
    // Menghapus berkas lama HANYA kalau letaknya benar-benar di dalam
    // uploads/. $relatif adalah nilai kolom basis data, misalnya
    // 'uploads/guru/guru-12-....jpg'.
    //
    // Nilainya tetap dipagari walau berasal dari basis data, bukan dari
    // $_POST: kolom itu dulu pernah diisi langsung dari kiriman klien, jadi
    // isinya tidak bisa dipercaya penuh. Tanpa pagar ini,
    // '../../../config/db-classync.php' menghapus konfigurasi basis data.
    function hapusFotoLamaAman($relatif) {
        if (!is_string($relatif) || $relatif === '') return false;

        $akar  = realpath(__DIR__ . '/..');
        $batas = realpath(__DIR__ . '/../uploads');
        if ($akar === false || $batas === false) return false;

        $target = realpath($akar . '/' . $relatif);
        if ($target === false || !is_file($target)) return false;
        if (strpos($target, $batas . DIRECTORY_SEPARATOR) !== 0) {
            error_log("hapusFotoLamaAman: menolak menghapus di luar uploads/ — " . $relatif);
            return false;
        }
        return @unlink($target);
    }
}
