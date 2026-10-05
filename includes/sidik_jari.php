<?php
// sidik_jari.php — memeriksa pesan bertanda tangan dari jembatan sidik jari.
//
// Jembatan (folder jembatan/, berjalan di PC kiosk) menandatangani hasilnya
// dengan HMAC-SHA256 memakai kunci per perangkat. Berkas ini sisi servernya:
// menyusun ulang pesan yang sama, mencocokkan tanda tangannya, dan memastikan
// setiap tantangan hanya dipakai sekali. Pemakainya sekarang baru detak kiosk
// (api/sj_tantangan.php dan api/sj_detak.php).
//
// Pesan kanonik, sama persis dengan jembatan/Brankas.cs:
//   SJ1|absen|<perangkat>|<tantangan>|<identitas>|<skor>
//   SJ1|detak|<perangkat>|<tantangan>|alat:<0 atau 1>
// Server selalu menyusun pesannya sendiri dari kolom yang sudah diperiksa
// polanya. Pesan kiriman pemanggil tidak pernah dipakai, dan pemisah | tidak
// bisa disusupkan.
//
// Kunci ada di luar webroot, di SJ_BERKAS_KONFIGURASI:
//   $sj_rahasia_tantangan = ['<64 hex>', ...];
//   $sj_perangkat = ['kiosk-xxxxxx' => ['aktif' => true, 'kunci' => ['<64 hex>', ...]]];
// Keduanya berupa daftar supaya bisa dirotasi tanpa deploy: tambahkan yang
// baru, alihkan, lalu cabut yang lama. Rahasia pertama dipakai menerbitkan
// tantangan; semua yang terdaftar diterima saat memeriksa.
//
// Tantangan tidak disimpan saat diterbitkan. Isinya 32 byte (64 hex): waktu
// terbit, 12 byte acak, dan tanda dari rahasia server atas tujuan dan
// perangkatnya. Endpoint penerbitnya terbuka untuk umum, jadi tidak boleh
// bisa dipakai memenuhi tabel. Baris tantangan_kiosk baru ditulis setelah
// tanda tangan perangkat terbukti sah, dan kunci utamanya yang menolak
// pemakaian kedua.
//
// Fungsi yang menerima $conn butuh includes/db.php lebih dulu: zona waktu
// Asia/Jakarta menentukan isi kolom waktunya.

if (!defined('SJ_BERKAS_KONFIGURASI')) {
    define('SJ_BERKAS_KONFIGURASI', '/DATA/k1807225/config/sidik-jari-classync.php');
}

if (!defined('SJ_VERSI_PESAN')) {
    define('SJ_VERSI_PESAN', 'SJ1');
}

if (!defined('SJ_UMUR_TANTANGAN')) {
    // Detik. Cukup untuk satu putaran halaman kiosk, jembatan, lalu server.
    define('SJ_UMUR_TANTANGAN', 120);
}

if (!defined('SJ_SIMPAN_DETAK_HARI')) {
    // Baris detak_kiosk yang lebih tua dari ini dihapus saat detak berikutnya
    // datang, supaya tabelnya tidak tumbuh tanpa batas.
    define('SJ_SIMPAN_DETAK_HARI', 60);
}

// ---------- Bentuk kolom ----------
//
// \A dan \z, bukan ^ dan $: $ juga cocok sebelum baris baru di ujung teks.
// Polanya sama dengan Brankas.cs, kecuali identitas: awalan uji: hanya untuk
// prototipe jembatan, dan server tidak menerimanya.

if (!function_exists('sjBentukPerangkat')) {
    function sjBentukPerangkat($nilai) {
        return is_string($nilai) && preg_match('/\A[a-z0-9-]{1,32}\z/', $nilai) === 1;
    }
}

if (!function_exists('sjBentukTantangan')) {
    function sjBentukTantangan($nilai) {
        return is_string($nilai) && preg_match('/\A[0-9a-f]{64}\z/', $nilai) === 1;
    }
}

if (!function_exists('sjBentukTandaTangan')) {
    function sjBentukTandaTangan($nilai) {
        return is_string($nilai) && preg_match('/\A[0-9a-f]{64}\z/', $nilai) === 1;
    }
}

if (!function_exists('sjBentukIdentitas')) {
    function sjBentukIdentitas($nilai) {
        return is_string($nilai) && preg_match('/\A(?:siswa|guru):[1-9][0-9]{0,9}\z/', $nilai) === 1;
    }
}

if (!function_exists('sjBentukTujuan')) {
    function sjBentukTujuan($nilai) {
        return $nilai === 'absen' || $nilai === 'detak';
    }
}

// ---------- Konfigurasi ----------

if (!function_exists('sjKonfigurasi')) {
    // Mengembalikan
    //   ['rahasia' => [biner, ...],
    //    'perangkat' => [id => ['aktif' => bool, 'kunci' => [biner, ...]], ...]]
    // atau null kalau berkasnya tidak terbaca atau tidak memuat satu pun
    // rahasia tantangan yang sah.
    //
    // Entri yang bentuknya salah dilewati. Penjelasannya masuk ke $masalah,
    // untuk ditampilkan di panel admin; isi kunci tidak pernah ikut. Tidak
    // ada yang ditulis ke error_log: fungsi ini dipanggil endpoint yang
    // terbuka untuk umum, dan konfigurasi yang salah tidak boleh menjadi cara
    // orang luar memenuhi log.
    function sjKonfigurasi($berkas = SJ_BERKAS_KONFIGURASI, &$masalah = null) {
        $masalah = [];
        // is_readable() dulu: require yang gagal itu fatal error.
        if (!is_readable($berkas)) {
            $masalah[] = 'Berkas konfigurasi tidak ada atau tidak terbaca.';
            return null;
        }
        $sj_rahasia_tantangan = null;
        $sj_perangkat = null;
        // Keluaran berkas itu dibuang: BOM atau baris kosong yang ikut
        // tersimpan dari penyunting akan merusak jawaban JSON.
        ob_start();
        try {
            require $berkas;
        } catch (Throwable $e) {
            $masalah[] = 'Berkas konfigurasi tidak bisa dimuat (' . get_class($e) . ').';
            return null;
        } finally {
            ob_end_clean();
        }

        $hex = function ($nilai) {
            return is_string($nilai) && strlen($nilai) === 64 && ctype_xdigit($nilai) ? hex2bin($nilai) : null;
        };

        $rahasia = [];
        $ke = 0;
        foreach (is_array($sj_rahasia_tantangan) ? $sj_rahasia_tantangan : [] as $nilai) {
            $ke++;
            $biner = $hex($nilai);
            if ($biner === null) {
                $masalah[] = 'Rahasia tantangan ke-' . $ke . ' bukan 64 karakter hex; dilewati.';
                continue;
            }
            $rahasia[] = $biner;
        }
        if (!$rahasia) {
            $masalah[] = 'Tidak ada rahasia tantangan yang sah.';
            return null;
        }

        $perangkat = [];
        $ke = 0;
        foreach (is_array($sj_perangkat) ? $sj_perangkat : [] as $id => $entri) {
            $ke++;
            if (!sjBentukPerangkat($id) || !is_array($entri)) {
                $masalah[] = 'Entri perangkat ke-' . $ke . ' bentuknya salah; dilewati.';
                continue;
            }
            $kunci = [];
            $ke_kunci = 0;
            foreach (is_array($entri['kunci'] ?? null) ? $entri['kunci'] : [] as $nilai) {
                $ke_kunci++;
                $biner = $hex($nilai);
                if ($biner === null) {
                    $masalah[] = 'Kunci ke-' . $ke_kunci . ' untuk ' . $id . ' bukan 64 karakter hex; dilewati.';
                    continue;
                }
                $kunci[] = $biner;
            }
            if (!$kunci) {
                $masalah[] = $id . ' tidak punya kunci yang sah.';
            }
            $perangkat[$id] = ['aktif' => ($entri['aktif'] ?? false) === true, 'kunci' => $kunci];
        }
        return ['rahasia' => $rahasia, 'perangkat' => $perangkat];
    }
}

if (!function_exists('sjPerangkat')) {
    // Entri perangkat yang boleh dipakai: terdaftar, aktif, dan punya kunci.
    // Selain itu null.
    function sjPerangkat($konfigurasi, $id) {
        if (!sjBentukPerangkat($id) || !isset($konfigurasi['perangkat'][$id])) {
            return null;
        }
        $entri = $konfigurasi['perangkat'][$id];
        return $entri['aktif'] && $entri['kunci'] ? $entri : null;
    }
}

if (!function_exists('sjSidikKunci')) {
    // Delapan karakter untuk mencocokkan kunci di server dengan kunci di
    // jembatan tanpa menampilkan kuncinya.
    function sjSidikKunci($kunci_biner) {
        return substr(hash('sha256', $kunci_biner), 0, 8);
    }
}

// ---------- Tantangan ----------

if (!function_exists('sjTandaTantangan')) {
    // 32 karakter hex: tanda server atas tujuan, perangkat, dan kepala
    // tantangan (waktu terbit + byte acak).
    function sjTandaTantangan($rahasia, $tujuan, $perangkat, $kepala) {
        $isi = SJ_VERSI_PESAN . '|tantangan|' . $tujuan . '|' . $perangkat . '|' . $kepala;
        return substr(hash_hmac('sha256', $isi, $rahasia), 0, 32);
    }
}

if (!function_exists('sjTantanganBaru')) {
    // Tantangan baru untuk satu tujuan dan satu perangkat. Tidak menyentuh
    // basis data. $kini hanya diisi oleh uji.
    function sjTantanganBaru($konfigurasi, $tujuan, $perangkat, $kini = null) {
        if (!sjBentukTujuan($tujuan) || !sjBentukPerangkat($perangkat)) {
            throw new InvalidArgumentException('Tujuan atau perangkat tidak sah.');
        }
        $kepala = bin2hex(pack('N', $kini === null ? time() : (int)$kini) . random_bytes(12));
        return $kepala . sjTandaTantangan($konfigurasi['rahasia'][0], $tujuan, $perangkat, $kepala);
    }
}

if (!function_exists('sjPeriksaTantangan')) {
    // Mengembalikan:
    //   'sah'          terbitan server ini untuk tujuan dan perangkat itu, dan
    //                  belum lewat SJ_UMUR_TANTANGAN;
    //   'bentuk'       bukan 64 karakter hex, atau tujuan/perangkat tidak sah;
    //   'asing'        bukan terbitan server ini, atau untuk tujuan atau
    //                  perangkat lain;
    //   'kedaluwarsa'  terbitan server ini, tetapi sudah terlalu tua.
    //
    // Belum memeriksa apakah tantangannya pernah dipakai; itu tugas
    // sjPakaiTantangan(), setelah tanda tangan perangkat terbukti sah.
    function sjPeriksaTantangan($konfigurasi, $tantangan, $tujuan, $perangkat, $kini = null) {
        if (!sjBentukTantangan($tantangan) || !sjBentukTujuan($tujuan) || !sjBentukPerangkat($perangkat)) {
            return 'bentuk';
        }
        $kepala = substr($tantangan, 0, 32);
        $tanda = substr($tantangan, 32);
        $cocok = false;
        foreach ($konfigurasi['rahasia'] as $rahasia) {
            if (hash_equals(sjTandaTantangan($rahasia, $tujuan, $perangkat, $kepala), $tanda)) {
                $cocok = true;
            }
        }
        if (!$cocok) {
            return 'asing';
        }
        $terbit = unpack('N', hex2bin(substr($kepala, 0, 8)))[1];
        $usia = ($kini === null ? time() : (int)$kini) - $terbit;
        // Terbit dan periksa memakai jam server yang sama. Lima detik ke depan
        // ditoleransi untuk jam yang disetel mundur di antaranya.
        return $usia >= -5 && $usia <= SJ_UMUR_TANTANGAN ? 'sah' : 'kedaluwarsa';
    }
}

if (!function_exists('sjPakaiTantangan')) {
    // Mencatat tantangan sebagai terpakai. Mengembalikan false kalau sudah
    // pernah dipakai: kunci utama tabelnya yang memutuskan, jadi dua
    // permintaan serentak pun hanya satu yang lolos.
    //
    // Panggil hanya setelah tanda tangan perangkat sah. Dengan begitu tabel
    // ini tidak bisa diisi tanpa kunci perangkat.
    function sjPakaiTantangan($conn, $tantangan, $tujuan, $perangkat) {
        $kini = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("INSERT INTO tantangan_kiosk (tantangan, tujuan, perangkat, dipakai) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $tantangan, $tujuan, $perangkat, $kini);
        try {
            $tersimpan = $stmt->execute();
            $galat = $stmt->errno;
        } catch (mysqli_sql_exception $e) {
            $tersimpan = false;
            $galat = $e->getCode();
        }
        $stmt->close();
        if (!$tersimpan) {
            if ($galat === 1062) {
                return false;
            }
            throw new RuntimeException('Tantangan tidak bisa dicatat (galat ' . $galat . ').');
        }

        // Tantangan hanya berumur SJ_UMUR_TANTANGAN detik, jadi baris yang
        // lebih tua dari sehari tidak lagi berguna.
        $batas = date('Y-m-d H:i:s', time() - 86400);
        $stmt = $conn->prepare("DELETE FROM tantangan_kiosk WHERE dipakai < ? LIMIT 200");
        $stmt->bind_param('s', $batas);
        $stmt->execute();
        $stmt->close();
        return true;
    }
}

// ---------- Pesan dan tanda tangan ----------

if (!function_exists('sjPesanDetak')) {
    // null kalau ada kolom yang tidak sah. $alat harus integer 0 atau 1.
    function sjPesanDetak($perangkat, $tantangan, $alat) {
        if (!sjBentukPerangkat($perangkat) || !sjBentukTantangan($tantangan) || ($alat !== 0 && $alat !== 1)) {
            return null;
        }
        return implode('|', [SJ_VERSI_PESAN, 'detak', $perangkat, $tantangan, 'alat:' . $alat]);
    }
}

if (!function_exists('sjPesanAbsen')) {
    // null kalau ada kolom yang tidak sah. $skor harus integer 0 sampai 9999.
    function sjPesanAbsen($perangkat, $tantangan, $identitas, $skor) {
        if (!sjBentukPerangkat($perangkat) || !sjBentukTantangan($tantangan) || !sjBentukIdentitas($identitas)
            || !is_int($skor) || $skor < 0 || $skor > 9999) {
            return null;
        }
        return implode('|', [SJ_VERSI_PESAN, 'absen', $perangkat, $tantangan, $identitas, $skor]);
    }
}

if (!function_exists('sjTandaTanganSah')) {
    // $entri dari sjPerangkat(). Benar kalau tanda tangannya cocok dengan
    // salah satu kunci perangkat itu.
    function sjTandaTanganSah($entri, $pesan, $tanda_tangan) {
        if (!is_string($pesan) || $pesan === '' || !sjBentukTandaTangan($tanda_tangan)) {
            return false;
        }
        $sah = false;
        foreach ($entri['kunci'] as $kunci) {
            if (hash_equals(hash_hmac('sha256', $pesan, $kunci), $tanda_tangan)) {
                $sah = true;
            }
        }
        return $sah;
    }
}

// ---------- Detak ----------

if (!function_exists('sjCatatDetak')) {
    // Satu baris per detak yang sah. $versi hanya keterangan dari halaman
    // kiosk dan tidak ikut ditandatangani.
    function sjCatatDetak($conn, $perangkat, $alat, $versi) {
        $kini = date('Y-m-d H:i:s');
        $versi = is_string($versi) && preg_match('/\A[0-9A-Za-z.+-]{1,64}\z/', $versi) === 1 ? $versi : '';
        $stmt = $conn->prepare("INSERT INTO detak_kiosk (waktu, perangkat, alat, versi) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssis', $kini, $perangkat, $alat, $versi);
        $stmt->execute();
        $stmt->close();

        $batas = date('Y-m-d H:i:s', time() - SJ_SIMPAN_DETAK_HARI * 86400);
        $stmt = $conn->prepare("DELETE FROM detak_kiosk WHERE perangkat = ? AND waktu < ? LIMIT 500");
        $stmt->bind_param('ss', $perangkat, $batas);
        $stmt->execute();
        $stmt->close();
    }
}

// ---------- Permintaan dan jawaban JSON ----------

if (!function_exists('sjKirim')) {
    // Mengirim jawaban JSON lalu berhenti.
    function sjKirim($kode, $isi) {
        http_response_code($kode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($isi);
        exit;
    }
}

if (!function_exists('sjIsiPermintaan')) {
    // Isi permintaan POST berbentuk objek JSON, sebagai array. Selain itu
    // dijawab langsung: 405 untuk metode lain, 413 untuk kiriman di atas
    // $maks_byte, 400 untuk yang bukan objek JSON. Daftar kosong [] tidak
    // terbedakan dari objek kosong {} dan diperlakukan sama.
    function sjIsiPermintaan($maks_byte = 4096) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            sjKirim(405, ['status' => 'error', 'message' => 'Hanya menerima POST.']);
        }
        $mentah = file_get_contents('php://input', false, null, 0, $maks_byte + 1);
        if ($mentah === false || strlen($mentah) > $maks_byte) {
            sjKirim(413, ['status' => 'error', 'message' => 'Kiriman terlalu besar.']);
        }
        $isi = json_decode($mentah, true);
        if (!is_array($isi) || ($isi !== [] && array_is_list($isi))) {
            sjKirim(400, ['status' => 'error', 'message' => 'Kiriman harus berupa objek JSON.']);
        }
        return $isi;
    }
}
