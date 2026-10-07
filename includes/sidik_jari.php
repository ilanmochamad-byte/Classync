<?php
// sidik_jari.php — memeriksa pesan bertanda tangan dari jembatan sidik jari.
//
// Jembatan (folder jembatan/, berjalan di PC kiosk) menandatangani hasilnya
// dengan HMAC-SHA256 memakai kunci per perangkat. Berkas ini sisi servernya:
// menyusun ulang pesan yang sama, mencocokkan tanda tangannya, dan memastikan
// setiap tantangan hanya dipakai sekali. Pemakainya: detak kiosk
// (api/sj_tantangan.php dan api/sj_detak.php), pendaftaran jari
// (admin/sj_izin.php, admin/sj_catat.php, dan admin/sidik_jari.php), dan
// absen lewat sidik jari (api/sj_absen.php).
//
// Pesan kanonik, sama persis dengan jembatan/Brankas.cs. Yang ditandatangani
// jembatan dan diperiksa server:
//   SJ1|absen|<perangkat>|<tantangan>|<identitas>|<skor>
//   SJ1|tolak|<perangkat>|<tantangan>|<skor>
//   SJ1|detak|<perangkat>|<tantangan>|alat:<0 atau 1>
//   SJ1|detak|<perangkat>|<tantangan>|alat:<0 atau 1>|adc:<0 atau 1>
//   SJ1|terdaftar|<perangkat>|<tantangan>|<identitas>|<jari>|<mutu>
//   SJ1|dicabut|<perangkat>|<tantangan>|<identitas>|<jumlah>
// tolak adalah tanda terima untuk tempelan yang tidak dikenali. Detak
// berbentuk kedua ditandatangani jembatan 0.4.0 ke atas kalau halaman kiosk
// melaporkan keadaan pembaca menurut ADC kepadanya; bentuk pertama tetap
// diterima.
// Yang ditandatangani server dan diperiksa jembatan, dengan kunci perangkat
// yang sama:
//   SJ1|izin-daftar|<perangkat>|<tantangan jembatan>|<identitas>|<jari>
//   SJ1|izin-cabut|<perangkat>|<tantangan jembatan>|<identitas>
// Server selalu menyusun pesannya sendiri dari kolom yang sudah diperiksa
// polanya. Pesan kiriman pemanggil tidak pernah dipakai, dan pemisah | tidak
// bisa disusupkan.
//
// Kedua arah memakai kunci yang sama, jadi jenis pesannya yang memisahkan:
// server hanya menandatangani izin-*, dan jembatan tidak pernah
// menandatanganinya. Karena itu izin yang sah hanya bisa berasal dari server.
// Tantangan di dalam izin diterbitkan jembatan dan hanya berlaku sekali di
// sana, jadi izin tidak bisa dipakai ulang dan tidak bergantung pada jam PC
// kiosk.
//
// Kunci ada di luar webroot, di SJ_BERKAS_KONFIGURASI:
//   $sj_rahasia_tantangan = ['<64 hex>', ...];
//   $sj_perangkat = ['kiosk-xxxxxx' => ['aktif' => true, 'kunci' => ['<64 hex>', ...]]];
// Keduanya berupa daftar supaya bisa dirotasi tanpa deploy: tambahkan yang
// baru, alihkan, lalu cabut yang lama. Rahasia pertama dipakai menerbitkan
// tantangan; semua yang terdaftar diterima saat memeriksa.
//
// Berkas yang sama memuat saklar absen lewat sidik jari:
//   $sj_absen_kiosk = true;
// Selama baris itu tidak ada, atau nilainya bukan true, tidak ada tantangan
// absen yang diterbitkan dan api/sj_absen.php menolak semua kiriman.
// Perubahannya berlaku seketika, tanpa deploy.
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

if (!defined('SJ_UMUR_IZIN')) {
    // Detik. Tantangan untuk tanda terima pendaftaran dan pencabutan berumur
    // lebih panjang: satu jari butuh empat tempelan, ditambah ulangannya.
    define('SJ_UMUR_IZIN', 900);
}

if (!defined('SJ_SIMPAN_DETAK_HARI')) {
    // Baris detak_kiosk yang lebih tua dari ini dihapus saat detak berikutnya
    // datang, supaya tabelnya tidak tumbuh tanpa batas.
    define('SJ_SIMPAN_DETAK_HARI', 60);
}

if (!defined('SJ_SIMPAN_LOG_ABSEN_HARI')) {
    // Baris log_absen_sidik_jari yang lebih tua dari ini dihapus saat tempelan
    // berikutnya dicatat.
    define('SJ_SIMPAN_LOG_ABSEN_HARI', 60);
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
        return in_array($nilai, ['absen', 'detak', 'daftar', 'cabut'], true);
    }
}

if (!function_exists('sjDaftarJari')) {
    // Jari yang boleh didaftarkan, berurutan seperti ditawarkan halaman
    // pendaftaran: kedua telunjuk dulu, jari tengah sebagai pengganti. Jari
    // manis sengaja tidak ada: pada uji 5 Oktober 2026 pengenalannya terburuk.
    function sjDaftarJari() {
        return [
            'telunjuk-kanan' => 'telunjuk kanan',
            'telunjuk-kiri'  => 'telunjuk kiri',
            'tengah-kanan'   => 'jari tengah kanan',
            'tengah-kiri'    => 'jari tengah kiri',
        ];
    }
}

if (!function_exists('sjBentukJari')) {
    function sjBentukJari($nilai) {
        return is_string($nilai) && isset(sjDaftarJari()[$nilai]);
    }
}

if (!function_exists('sjBentukBilangan')) {
    // Bilangan bulat tanpa tanda dari 0 sampai $maks, sebagai integer. JSON
    // "87" (teks) dan 87.0 (pecahan) ditolak, supaya pesan yang disusun server
    // tidak bisa berbeda dari yang ditandatangani jembatan.
    function sjBentukBilangan($nilai, $maks) {
        return is_int($nilai) && $nilai >= 0 && $nilai <= $maks;
    }
}

// ---------- Konfigurasi ----------

if (!function_exists('sjKonfigurasi')) {
    // Mengembalikan
    //   ['rahasia' => [biner, ...],
    //    'perangkat' => [id => ['aktif' => bool, 'kunci' => [biner, ...]], ...],
    //    'absen_kiosk' => bool]
    // atau null kalau berkasnya tidak terbaca atau tidak memuat satu pun
    // rahasia tantangan yang sah. 'absen_kiosk' hanya benar kalau berkasnya
    // memuat $sj_absen_kiosk = true.
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
        $sj_absen_kiosk = null;
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

        // Hanya true yang menyalakan. Nilai lain, misalnya 1 atau 'ya',
        // dianggap mati dan dilaporkan, supaya saklarnya tidak menyala karena
        // salah ketik.
        if ($sj_absen_kiosk !== null && !is_bool($sj_absen_kiosk)) {
            $masalah[] = '$sj_absen_kiosk harus true atau false; dianggap false.';
        }
        return ['rahasia' => $rahasia, 'perangkat' => $perangkat, 'absen_kiosk' => $sj_absen_kiosk === true];
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
    //                  belum lewat $umur detik;
    //   'bentuk'       bukan 64 karakter hex, atau tujuan/perangkat tidak sah;
    //   'asing'        bukan terbitan server ini, atau untuk tujuan atau
    //                  perangkat lain;
    //   'kedaluwarsa'  terbitan server ini, tetapi sudah terlalu tua.
    //
    // Belum memeriksa apakah tantangannya pernah dipakai; itu tugas
    // sjPakaiTantangan(), setelah tanda tangan perangkat terbukti sah.
    function sjPeriksaTantangan($konfigurasi, $tantangan, $tujuan, $perangkat, $kini = null, $umur = SJ_UMUR_TANTANGAN) {
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
        return $usia >= -5 && $usia <= (int)$umur ? 'sah' : 'kedaluwarsa';
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

        // Tantangan berumur paling lama SJ_UMUR_IZIN detik, jadi baris yang
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
    //
    // $adc adalah keadaan pembaca menurut ADC, seperti dilaporkan halaman
    // kiosk kepada jembatan: integer 0 atau 1, atau null kalau tidak
    // dilaporkan. Dengan null pesannya berbentuk lama, tanpa bagian adc.
    function sjPesanDetak($perangkat, $tantangan, $alat, $adc = null) {
        if (!sjBentukPerangkat($perangkat) || !sjBentukTantangan($tantangan) || ($alat !== 0 && $alat !== 1)
            || ($adc !== null && $adc !== 0 && $adc !== 1)) {
            return null;
        }
        $bagian = [SJ_VERSI_PESAN, 'detak', $perangkat, $tantangan, 'alat:' . $alat];
        if ($adc !== null) {
            $bagian[] = 'adc:' . $adc;
        }
        return implode('|', $bagian);
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

if (!function_exists('sjPesanTolak')) {
    // Tanda terima jembatan untuk tempelan yang tidak dikenali. Tidak memuat
    // identitas, hanya skor kandidat terbaiknya: integer 0 sampai 9999.
    // $tantangan terbitan server untuk tujuan absen, sama dengan pesan absen,
    // jadi satu tantangan hanya menghasilkan salah satu dari keduanya. null
    // kalau ada kolom yang tidak sah.
    function sjPesanTolak($perangkat, $tantangan, $skor) {
        if (!sjBentukPerangkat($perangkat) || !sjBentukTantangan($tantangan) || !sjBentukBilangan($skor, 9999)) {
            return null;
        }
        return implode('|', [SJ_VERSI_PESAN, 'tolak', $perangkat, $tantangan, $skor]);
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

if (!function_exists('sjPesanIzinDaftar')) {
    // Izin server untuk mendaftarkan satu jari milik satu orang. $tantangan
    // diterbitkan jembatan. null kalau ada kolom yang tidak sah.
    function sjPesanIzinDaftar($perangkat, $tantangan, $identitas, $jari) {
        if (!sjBentukPerangkat($perangkat) || !sjBentukTantangan($tantangan) || !sjBentukIdentitas($identitas)
            || !sjBentukJari($jari)) {
            return null;
        }
        return implode('|', [SJ_VERSI_PESAN, 'izin-daftar', $perangkat, $tantangan, $identitas, $jari]);
    }
}

if (!function_exists('sjPesanIzinCabut')) {
    // Izin server untuk menghapus semua templat satu orang dari jembatan.
    // $tantangan diterbitkan jembatan. null kalau ada kolom yang tidak sah.
    function sjPesanIzinCabut($perangkat, $tantangan, $identitas) {
        if (!sjBentukPerangkat($perangkat) || !sjBentukTantangan($tantangan) || !sjBentukIdentitas($identitas)) {
            return null;
        }
        return implode('|', [SJ_VERSI_PESAN, 'izin-cabut', $perangkat, $tantangan, $identitas]);
    }
}

if (!function_exists('sjPesanTerdaftar')) {
    // Tanda terima jembatan: jari itu sudah tersimpan. $tantangan terbitan
    // server untuk tujuan daftar. $mutu adalah keserasian terendah keempat
    // tempelannya, integer 0 sampai 9999. null kalau ada kolom yang tidak sah.
    function sjPesanTerdaftar($perangkat, $tantangan, $identitas, $jari, $mutu) {
        if (!sjBentukPerangkat($perangkat) || !sjBentukTantangan($tantangan) || !sjBentukIdentitas($identitas)
            || !sjBentukJari($jari) || !sjBentukBilangan($mutu, 9999)) {
            return null;
        }
        return implode('|', [SJ_VERSI_PESAN, 'terdaftar', $perangkat, $tantangan, $identitas, $jari, $mutu]);
    }
}

if (!function_exists('sjPesanDicabut')) {
    // Tanda terima jembatan: templat orang itu sudah dihapus. $tantangan
    // terbitan server untuk tujuan cabut. $jumlah adalah banyaknya templat
    // yang dihapus, integer 0 sampai 99; 0 berarti memang sudah tidak ada.
    function sjPesanDicabut($perangkat, $tantangan, $identitas, $jumlah) {
        if (!sjBentukPerangkat($perangkat) || !sjBentukTantangan($tantangan) || !sjBentukIdentitas($identitas)
            || !sjBentukBilangan($jumlah, 99)) {
            return null;
        }
        return implode('|', [SJ_VERSI_PESAN, 'dicabut', $perangkat, $tantangan, $identitas, $jumlah]);
    }
}

if (!function_exists('sjTandaIzin')) {
    // Tanda tangan server atas pesan izin, satu per kunci perangkat itu.
    // Jembatan hanya punya satu kunci; selama rotasi server belum tahu yang
    // mana, jadi semuanya dikirim dan jembatan mencari yang cocok.
    //
    // Hanya untuk pesan izin-*. Pesan lain ditolak, supaya fungsi ini tidak
    // bisa dipakai menandatangani detak, absen, atau tanda terima.
    function sjTandaIzin($entri, $pesan) {
        if (!is_string($pesan) || preg_match('/\A' . SJ_VERSI_PESAN . '\|izin-(?:daftar|cabut)\|/', $pesan) !== 1) {
            throw new InvalidArgumentException('Hanya pesan izin yang boleh ditandatangani server.');
        }
        $tanda = [];
        foreach ($entri['kunci'] as $kunci) {
            $tanda[] = hash_hmac('sha256', $pesan, $kunci);
        }
        return $tanda;
    }
}

// ---------- Detak ----------

if (!function_exists('sjCatatDetak')) {
    // Satu baris per detak yang sah. $versi hanya keterangan dari halaman
    // kiosk dan tidak ikut ditandatangani.
    //
    // $adc (0, 1, atau null kalau tidak dilaporkan) masuk ke kolom adc, yang
    // ditambahkan di sub-langkah 4.4. Kalau kolom itu belum dibuat (galat
    // 1054), detaknya tetap dicatat tanpa adc: kolom yang terlupa tidak boleh
    // membuat kiosk tampak diam. Halaman pantau yang melaporkan kolomnya
    // belum ada. Mengembalikan adc yang benar-benar tersimpan: 0, 1, atau null.
    function sjCatatDetak($conn, $perangkat, $alat, $versi, $adc = null) {
        $kini = date('Y-m-d H:i:s');
        $versi = is_string($versi) && preg_match('/\A[0-9A-Za-z.+-]{1,64}\z/', $versi) === 1 ? $versi : '';
        $tercatat = false;
        if ($adc === 0 || $adc === 1) {
            try {
                $stmt = $conn->prepare("INSERT INTO detak_kiosk (waktu, perangkat, alat, versi, adc) VALUES (?, ?, ?, ?, ?)");
                if ($stmt === false) {
                    if ($conn->errno !== 1054) {
                        throw new RuntimeException('Detak tidak bisa dicatat (galat ' . $conn->errno . ').');
                    }
                } else {
                    $stmt->bind_param('ssisi', $kini, $perangkat, $alat, $versi, $adc);
                    $tercatat = $stmt->execute();
                    $stmt->close();
                }
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() !== 1054) {
                    throw $e;
                }
            }
        }
        if (!$tercatat) {
            $stmt = $conn->prepare("INSERT INTO detak_kiosk (waktu, perangkat, alat, versi) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('ssis', $kini, $perangkat, $alat, $versi);
            $stmt->execute();
            $stmt->close();
        }

        $batas = date('Y-m-d H:i:s', time() - SJ_SIMPAN_DETAK_HARI * 86400);
        $stmt = $conn->prepare("DELETE FROM detak_kiosk WHERE perangkat = ? AND waktu < ? LIMIT 500");
        $stmt->bind_param('ss', $perangkat, $batas);
        $stmt->execute();
        $stmt->close();
        return $tercatat ? $adc : null;
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
    //
    // $kedalaman diteruskan ke json_decode(): 2 berarti objek datar, tanpa
    // larik atau objek di dalamnya. Endpoint yang menerima kiriman besar harus
    // mengisinya. JSON yang bersarang dalam memakan memori seratus kali
    // ukurannya saat diurai, dan itu terjadi sebelum tanda tangan diperiksa:
    // orang luar bisa menghabiskan memori dan memenuhi error_log dengannya.
    function sjIsiPermintaan($maks_byte = 4096, $kedalaman = 512) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            sjKirim(405, ['status' => 'error', 'message' => 'Hanya menerima POST.']);
        }
        $mentah = file_get_contents('php://input', false, null, 0, $maks_byte + 1);
        if ($mentah === false || strlen($mentah) > $maks_byte) {
            sjKirim(413, ['status' => 'error', 'message' => 'Kiriman terlalu besar.']);
        }
        $isi = json_decode($mentah, true, $kedalaman);
        if (!is_array($isi) || ($isi !== [] && array_is_list($isi))) {
            sjKirim(400, ['status' => 'error', 'message' => 'Kiriman harus berupa objek JSON.']);
        }
        return $isi;
    }
}

if (!function_exists('sjWajibAdmin')) {
    // Untuk endpoint JSON di admin/: menuntut sesi admin dan token CSRF yang
    // dipasang admin/sidik_jari.php. Mengembalikan admin_id; selain itu
    // menjawab 401 atau 403 lalu berhenti.
    //
    // Sesi tidak dimulai untuk permintaan tanpa cookie sesi, supaya kiriman
    // dari luar tidak dibalas dengan cookie baru. Sesinya dibaca lalu langsung
    // ditutup, supaya permintaan lain dari halaman yang sama tidak menunggu
    // kuncinya.
    //
    // Ditutup dengan session_write_close(), bukan dibuka dengan
    // 'read_and_close': yang kedua tidak memperbarui cap waktu sesi. Halaman
    // pendaftaran bisa berjam-jam hanya memanggil endpoint ini tanpa memuat
    // halaman lain, dan tanpa pembaruan itu sesi admin habis di tengah
    // pendaftaran walaupun adminnya terus bekerja.
    function sjWajibAdmin($isi) {
        $masuk = false;
        $admin_id = 0;
        $csrf = '';
        if (isset($_COOKIE[session_name()]) && session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
            $masuk = isset($_SESSION['admin_logged_in']);
            $admin_id = (int)($_SESSION['admin_id'] ?? 0);
            $csrf = $_SESSION['sidik_jari_csrf'] ?? '';
            session_write_close();
        }
        if (!$masuk || $admin_id <= 0) {
            sjKirim(401, ['status' => 'error', 'message' => 'Sesi admin berakhir. Masuk lagi ke panel admin.', 'perlu_masuk' => true]);
        }
        $kiriman = $isi['csrf'] ?? null;
        if (!is_string($csrf) || $csrf === '' || !is_string($kiriman) || !hash_equals($csrf, $kiriman)) {
            sjKirim(403, ['status' => 'error', 'message' => 'Token halaman tidak cocok. Muat ulang halaman pendaftaran.']);
        }
        return $admin_id;
    }
}

// ---------- Pendaftaran jari ----------
//
// Templat sidik jari hanya ada di jembatan. Server mencatat siapa yang
// menyetujui (persetujuan_sidik_jari) dan jari mana yang terdaftar di
// perangkat mana (pendaftaran_sidik_jari). Catatan pendaftaran hanya ditulis
// dari tanda terima bertanda tangan jembatan.

if (!function_exists('sjOrang')) {
    // Orang di balik sebuah identitas, atau null kalau tidak ada:
    //   ['identitas', 'jenis' => 'siswa' atau 'guru', 'nama', 'kelompok',
    //    'boleh' => bool, 'alasan' => teks kalau tidak boleh]
    // Yang boleh didaftarkan: siswa yang belum lulus, dan guru yang punya
    // jadwal piket Aktif.
    function sjOrang($conn, $identitas) {
        if (!sjBentukIdentitas($identitas)) {
            return null;
        }
        list($jenis, $id) = explode(':', $identitas, 2);
        $id = (int)$id;
        if ($jenis === 'siswa') {
            $stmt = $conn->prepare("SELECT nama_siswa, kelas FROM siswa WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $baris = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$baris) {
                return null;
            }
            $lulus = $baris['kelas'] === 'Lulus / Alumni';
            return ['identitas' => $identitas, 'jenis' => 'siswa', 'nama' => $baris['nama_siswa'], 'kelompok' => $baris['kelas'],
                    'boleh' => !$lulus, 'alasan' => $lulus ? 'Siswa ini sudah lulus.' : ''];
        }
        $stmt = $conn->prepare("SELECT g.nama_guru, (SELECT COUNT(*) FROM jadwal_piket j WHERE j.guru_id = g.id AND j.status_jadwal = 'Aktif') AS piket FROM guru g WHERE g.id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $baris = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$baris) {
            return null;
        }
        $piket = (int)$baris['piket'] > 0;
        return ['identitas' => $identitas, 'jenis' => 'guru', 'nama' => $baris['nama_guru'], 'kelompok' => 'Guru piket',
                'boleh' => $piket, 'alasan' => $piket ? '' : 'Guru ini tidak punya jadwal piket yang aktif.'];
    }
}

if (!function_exists('sjPersetujuan')) {
    // Baris persetujuan_sidik_jari untuk identitas itu, atau null.
    function sjPersetujuan($conn, $identitas) {
        $stmt = $conn->prepare("SELECT identitas, status, tanggal_surat, keterangan, admin_id, dicatat, tidak_terbaca FROM persetujuan_sidik_jari WHERE identitas = ?");
        $stmt->bind_param('s', $identitas);
        $stmt->execute();
        $baris = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $baris ?: null;
    }
}

if (!function_exists('sjCatatPendaftaran')) {
    // Mencatat satu jari yang baru disimpan jembatan. Pendaftaran lama untuk
    // jari yang sama di perangkat yang sama menjadi 'diganti', karena jembatan
    // sudah menimpa templatnya. Tanda "jari tidak terbaca" orang itu dicabut.
    //
    // Panggil di dalam transaksi, setelah tanda terimanya terbukti sah. Kunci
    // unik satu_aktif menolak baris aktif kedua untuk jari yang sama.
    function sjCatatPendaftaran($conn, $perangkat, $identitas, $jari, $mutu, $admin_id) {
        $kini = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("UPDATE pendaftaran_sidik_jari SET status = 'diganti', aktif = NULL, diubah = ?, diubah_admin = ? WHERE identitas = ? AND jari = ? AND perangkat = ? AND aktif = 1");
        $stmt->bind_param('sisss', $kini, $admin_id, $identitas, $jari, $perangkat);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO pendaftaran_sidik_jari (identitas, jari, perangkat, mutu, terdaftar, admin_id, status, aktif) VALUES (?, ?, ?, ?, ?, ?, 'aktif', 1)");
        $stmt->bind_param('sssisi', $identitas, $jari, $perangkat, $mutu, $kini, $admin_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE persetujuan_sidik_jari SET tidak_terbaca = NULL, tidak_terbaca_admin = NULL WHERE identitas = ?");
        $stmt->bind_param('s', $identitas);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('sjCatatPencabutan')) {
    // Menandai semua jari orang itu di perangkat itu sebagai 'dicabut'.
    // Mengembalikan jumlah baris yang berubah. Panggil di dalam transaksi,
    // setelah tanda terimanya terbukti sah.
    function sjCatatPencabutan($conn, $perangkat, $identitas, $admin_id) {
        $kini = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("UPDATE pendaftaran_sidik_jari SET status = 'dicabut', aktif = NULL, diubah = ?, diubah_admin = ? WHERE identitas = ? AND perangkat = ? AND aktif = 1");
        $stmt->bind_param('siss', $kini, $admin_id, $identitas, $perangkat);
        $stmt->execute();
        $berubah = $stmt->affected_rows;
        $stmt->close();
        return $berubah;
    }
}

if (!function_exists('sjTandaiTidakTerbaca')) {
    // Mencatat bahwa jari orang itu tidak bisa didaftarkan. Hanya menyentuh
    // baris yang persetujuannya 'setuju'; pemanggil yang memastikan baris itu
    // ada. Pendaftaran yang berhasil kemudian mencabut tanda ini.
    function sjTandaiTidakTerbaca($conn, $identitas, $admin_id) {
        $kini = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("UPDATE persetujuan_sidik_jari SET tidak_terbaca = ?, tidak_terbaca_admin = ? WHERE identitas = ? AND status = 'setuju'");
        $stmt->bind_param('sis', $kini, $admin_id, $identitas);
        $stmt->execute();
        $stmt->close();
    }
}

// ---------- Absen lewat sidik jari ----------
//
// Jembatan menandatangani hasil identifikasinya: pesan absen kalau jarinya
// dikenali, pesan tolak kalau tidak. api/sj_absen.php memeriksa tanda tangan
// itu, lalu mencatat setiap tempelan di log_absen_sidik_jari. Absensinya
// sendiri dicatat catatAbsenSiswa() di includes/absen_siswa.php.

if (!function_exists('sjPendaftaranAktif')) {
    // Jumlah jari orang itu yang tercatat aktif di perangkat itu. Nol berarti
    // templatnya ada di jembatan tanpa catatan di server, misalnya karena
    // tanda terima pendaftarannya tidak pernah sampai.
    function sjPendaftaranAktif($conn, $identitas, $perangkat) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM pendaftaran_sidik_jari WHERE identitas = ? AND perangkat = ? AND aktif = 1");
        $stmt->bind_param('ss', $identitas, $perangkat);
        $stmt->execute();
        $jumlah = (int)$stmt->get_result()->fetch_row()[0];
        $stmt->close();
        return $jumlah;
    }
}

if (!function_exists('sjCatatLogAbsen')) {
    // Satu baris per tempelan yang tanda tangannya sah.
    //   $identitas   null untuk tempelan yang tidak dikenali
    //   $hasil       'masuk', 'pulang', 'ditolak', 'tidak_dikenali', atau
    //                'bukan_siswa'
    //   $keterangan  kode alasan untuk 'ditolak', misalnya 'belum_jam_pulang'
    // Panggil hanya setelah tanda tangan perangkat sah, supaya tabel ini tidak
    // bisa diisi tanpa kunci perangkat.
    function sjCatatLogAbsen($conn, $perangkat, $identitas, $skor, $hasil, $keterangan = '') {
        $kini = date('Y-m-d H:i:s');
        $keterangan = substr((string)$keterangan, 0, 150);
        $stmt = $conn->prepare("INSERT INTO log_absen_sidik_jari (waktu, perangkat, identitas, skor, hasil, keterangan) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssiss', $kini, $perangkat, $identitas, $skor, $hasil, $keterangan);
        $stmt->execute();
        $stmt->close();

        $batas = date('Y-m-d H:i:s', time() - SJ_SIMPAN_LOG_ABSEN_HARI * 86400);
        $stmt = $conn->prepare("DELETE FROM log_absen_sidik_jari WHERE waktu < ? LIMIT 500");
        $stmt->bind_param('s', $batas);
        $stmt->execute();
        $stmt->close();
    }
}
