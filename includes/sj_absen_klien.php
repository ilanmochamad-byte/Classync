<?php
// sj_absen_klien.php — absen siswa lewat sidik jari, untuk halaman kiosk.
//
// Di-include absen-siswa.php sesudah skrip detak, tepat sebelum </body>.
// Selama halaman kiosk terbuka, skrip ini menangkap sidik jari lewat
// SjTangkap (sj_tangkap_klien.php). Untuk tiap tempelan ia menjalankan satu
// rantai: jembatan di PC kiosk mencocokkan jarinya dan menandatangani hasilnya
// atas tantangan dari server (api/sj_tantangan.php, tujuan absen), lalu
// api/sj_absen.php memeriksa tanda tangan itu dan mencatat absennya. Masuk
// atau pulang diputuskan server. Absen QR/NISN di halaman yang sama tidak
// disentuh.
//
// Berkas ini tidak mengeluarkan apa pun selama $sj_absen_kiosk di berkas
// konfigurasi server bukan true, atau selama belum ada perangkat yang aktif
// dan berkunci. Jadi mematikan saklar itu mematikan skrip ini tanpa deploy,
// dan sebelum saklarnya menyala keluaran absen-siswa.php tidak berubah.
//
// Halaman kiosk terbuka untuk umum. Seperti skrip detak, skrip ini baru
// menyentuh 127.0.0.1 kalau izin loopback-network untuk situs ini berstatus
// "granted", atau peramban ini ditandai sebagai kiosk (?detak=hidup). Di
// peramban lain ia berhenti di situ: tanpa pewaktu, tanpa elemen, tanpa
// permintaan, dan pustaka WebSDK tidak dimuat.
//
// Yang perlu diketahui sebelum mengubahnya:
// - Tantangan disiapkan lebih dulu, supaya tempelan tidak menunggu server dua
//   kali. Server menerimanya 120 detik; di sini ia diganti setelah 60 detik,
//   dan langsung setelah dipakai.
// - Foto webcam diambil saat jari ditempel, dan hanya dikirim kalau jarinya
//   dikenali. Kiriman yang ditolak 413 dikirim ulang tanpa foto: server
//   menjawab 413 sebelum tantangannya dipakai.
// - Setelah absen tercatat, halaman tidak langsung dimuat ulang seperti pada
//   absen QR/NISN. Muat ulang ditunda sampai 15 detik tanpa tempelan, supaya
//   antrean tidak menunggu WebSDK menyala lagi untuk tiap siswa. Selama itu
//   muat ulang 2 detik milik alur QR/NISN ikut ditunda lewat Navigation API:
//   kalau tidak, ia memotong tempelan yang sedang diproses.
// - Layar dijaga tetap menyala dengan Screen Wake Lock. Pada uji 5 Oktober
//   2026 pembaca hilang dari ADC selagi PC ditinggal. Setelan daya Windows di
//   README jembatan tetap wajib; kunci layar ini hanya lapis kedua.
// - Skrip detak menanyakan keadaan pembaca menurut ADC lewat
//   window.SjAbsenKiosk.adc().
// - Galat di sini tidak boleh merusak halaman kiosk. Semua pesan diagnostik
//   masuk ke konsol peramban, berawalan [absen sidik jari].

if (!function_exists('sjAbsenKlienAktif')) {
    // Benar kalau absen lewat sidik jari dibuka di server dan ada setidaknya
    // satu perangkat yang boleh dipakai. Galat apa pun dianggap "tidak":
    // halaman kiosk tidak boleh terganggu.
    function sjAbsenKlienAktif() {
        $pustaka = __DIR__ . '/sidik_jari.php';
        // is_readable() dulu: require yang gagal itu fatal error.
        if (!is_readable($pustaka)) {
            return false;
        }
        try {
            require_once $pustaka;
            $konfigurasi = sjKonfigurasi();
            if ($konfigurasi === null || ($konfigurasi['absen_kiosk'] ?? false) !== true) {
                return false;
            }
            foreach (array_keys($konfigurasi['perangkat']) as $id) {
                if (sjPerangkat($konfigurasi, (string)$id) !== null) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            // Sengaja tanpa error_log: halaman ini terbuka untuk umum, dan
            // galat di sini tidak boleh menjadi cara orang luar memenuhi log.
        }
        return false;
    }
}

if (!sjAbsenKlienAktif()) {
    return;
}
$sj_modul_tangkap = __DIR__ . '/sj_tangkap_klien.php';
if (!is_readable($sj_modul_tangkap)) {
    return;
}
include $sj_modul_tangkap;
?>
<style>
.sj-absen-induk { position: relative; }
.sj-absen-lencana {
    position: absolute; left: 8px; bottom: 8px; z-index: 5; pointer-events: none;
    padding: 3px 10px; border-radius: 12px; font: 600 12px/1.4 sans-serif;
    background: rgba(31, 41, 55, .85); color: #fff;
}
.sj-absen-lencana.siap { background: rgba(22, 101, 52, .9); }
.sj-absen-lencana.perhatian { background: rgba(180, 83, 9, .92); }
.sj-absen-hasil {
    position: absolute; inset: 0; z-index: 6; pointer-events: none;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 12px; text-align: center; font-family: sans-serif; color: #fff;
    background: rgba(31, 41, 55, .9);
}
.sj-absen-hasil[hidden], .sj-absen-lencana[hidden] { display: none; }
.sj-absen-hasil.berhasil { background: rgba(22, 101, 52, .93); }
.sj-absen-hasil.gagal { background: rgba(185, 28, 28, .93); }
.sj-absen-hasil.ulang { background: rgba(180, 83, 9, .93); }
.sj-absen-hasil .judul { font-size: 13px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; opacity: .85; }
.sj-absen-hasil .nama { font-size: 24px; font-weight: 800; line-height: 1.15; margin-top: 4px; }
.sj-absen-hasil .kelompok { font-size: 15px; font-weight: 600; margin-top: 2px; }
.sj-absen-hasil .pesan { font-size: 15px; font-weight: 600; margin-top: 8px; }
.sj-absen-lepas { position: fixed; left: 12px; bottom: 12px; width: 420px; max-width: 90vw; height: 190px; z-index: 9998; pointer-events: none; }
</style>
<script>
(function () {
    'use strict';

    var JEMBATAN = 'http://127.0.0.1:47890';
    var KUNCI_TANDA = 'sj_detak_kiosk';     // penanda kiosk, dipasang skrip detak lewat ?detak=hidup
    var BATAS_JEMBATAN = 8000;      // batas waktu permintaan ke jembatan
    var BATAS_SERVER = 15000;       // batas waktu permintaan ke server
    var SELANG = 15000;             // pemeriksaan berkala: jembatan, tantangan, penangkapan, layar
    var UMUR_STATUS = 60000;        // /status jembatan dibaca ulang semenit sekali
    var UMUR_TANTANGAN = 60000;     // server menerima tantangan sampai 120 detik
    var COBA_ADC = 30000;           // ADC yang belum menjawab dihubungi lagi setelah selama ini
    var LEWATI_ADC_MAKS = 19;       // ADC yang mati: paling banyak sekian pemeriksaan dilewati (5 menit)
    var TUNDA_MUAT_ULANG = 15000;   // muat ulang setelah selama ini tanpa tempelan
    var TUNGGU_NISN = 5000;         // selagi alur QR/NISN dipakai, muat ulang ditunda per selama ini
    var BATAS_TUNGGU_NISN = 60000;  // paling lama menunggu alur itu
    var LAMA_HASIL = 4000;          // lama hasil satu tempelan ditampilkan
    var BEDA_DPI = 0.05;            // sama dengan halaman pendaftaran

    var jembatan = null;        // { perangkat, galeri, dibaca } dari /status, atau null kalau belum bisa dipakai
    var tantangan = null;       // { nilai, diambil }, atau null
    var janjiTantangan = null;  // permintaan tantangan yang sedang berjalan
    var terbuka = true;         // server menerima absen dari perangkat ini; saklarnya menyala saat halaman dibuat
    var tangkapJalan = false;   // SjTangkap sudah diminta menangkap
    var tangkapDiminta = 0;     // kapan terakhir diminta
    var lewatiAdc = 0;          // pemeriksaan berkala yang dilewati sebelum ADC yang mati dihubungi lagi
    var sisaLewatiAdc = 0;      // sisa yang masih harus dilewati
    var sibuk = false;          // satu tempelan sedang diproses
    var tempelanTerakhir = 0;   // kapan tempelan terakhir selesai diproses
    var perluMuatUlang = false;
    var sudahDikabari = {};     // peringatan konsol yang cukup sekali

    function pesanGalat(e) {
        return e && e.message ? e.message : String(e);
    }

    function kabar(teks, jenis) {
        var tulis = jenis === 'info' ? console.info : console.warn;
        tulis.call(console, '[absen sidik jari] ' + teks);
    }

    // Peringatan yang sama tidak diulang tiap pemeriksaan berkala.
    function kabarSekali(kunci, teks) {
        if (sudahDikabari[kunci] !== teks) {
            sudahDikabari[kunci] = teks;
            kabar(teks);
        }
    }

    function lupakanKabar(kunci) {
        delete sudahDikabari[kunci];
    }

    // 'penanda', 'izin', atau null. Sama dengan gerbang skrip detak:
    // menanyakan izin tidak memunculkan permintaan izin.
    async function gerbang() {
        try {
            if (window.localStorage.getItem(KUNCI_TANDA) === '1') {
                return 'penanda';
            }
        } catch (e) { /* tanpa penyimpanan: tinggal izin */ }
        try {
            var izin = await navigator.permissions.query({ name: 'loopback-network' });
            return izin.state === 'granted' ? 'izin' : null;
        } catch (e) {
            return null;    // peramban ini tidak mengenal izin itu
        }
    }

    // Jawaban beserta kodenya, atau null kalau permintaannya tidak sampai atau
    // melewati batas waktu. isi null kalau jawabannya bukan JSON.
    async function panggil(url, kiriman, batas) {
        var pemutus = new AbortController();
        var pewaktu = window.setTimeout(function () { pemutus.abort(); }, batas);
        var opsi = { signal: pemutus.signal };
        if (kiriman !== undefined) {
            opsi.method = 'POST';
            opsi.headers = { 'Content-Type': 'application/json' };
            opsi.body = JSON.stringify(kiriman);
        }
        try {
            var jawaban = await fetch(url, opsi);
            var isi = null;
            try {
                isi = await jawaban.json();
            } catch (e) { /* bukan JSON */ }
            return { kode: jawaban.status, isi: isi };
        } catch (e) {
            return null;
        } finally {
            window.clearTimeout(pewaktu);
        }
    }

    function alasan(hasil) {
        if (hasil === null) {
            return 'tidak ada jawaban';
        }
        return hasil.isi && typeof hasil.isi.message === 'string' ? hasil.isi.message : 'jawaban ' + hasil.kode;
    }

    // ---------- Tampilan ----------

    var lencana = null;
    var hasil = null;
    var pewaktuHasil = null;

    function elemen(nama, kelas, induk) {
        var e = document.createElement(nama);
        e.className = kelas;
        induk.appendChild(e);
        return e;
    }

    // Lencana dan hasil ditumpangkan di atas gambar webcam, tanpa mengubah
    // tata letak halaman. Kalau wadah webcam tidak ada, keduanya ditaruh di
    // pojok kiri bawah layar.
    function siapkanTampilan() {
        var induk = document.querySelector('.webcam-wrap');
        if (induk) {
            induk.classList.add('sj-absen-induk');
        } else {
            induk = elemen('div', 'sj-absen-lepas', document.body);
        }
        lencana = elemen('div', 'sj-absen-lencana', induk);
        lencana.hidden = true;
        hasil = elemen('div', 'sj-absen-hasil', induk);
        hasil.hidden = true;
        hasil.setAttribute('role', 'status');
        hasil.setAttribute('aria-live', 'polite');
        ['judul', 'nama', 'kelompok', 'pesan'].forEach(function (bagian) {
            elemen('div', bagian, hasil);
        });
    }

    // jenis: 'proses', 'berhasil', 'gagal', 'ulang', atau 'netral'. Selain
    // 'proses', hasilnya hilang sendiri setelah LAMA_HASIL, kecuali sudah
    // digantikan tempelan berikutnya.
    function tampilkan(jenis, judul, nama, kelompok, pesan) {
        if (!hasil) {
            return;
        }
        var isi = { judul: judul, nama: nama, kelompok: kelompok, pesan: pesan };
        Object.keys(isi).forEach(function (bagian) {
            var e = hasil.querySelector('.' + bagian);
            e.textContent = isi[bagian] || '';
            e.hidden = !isi[bagian];
        });
        hasil.className = 'sj-absen-hasil ' + jenis;
        hasil.hidden = false;
        if (pewaktuHasil !== null) {
            window.clearTimeout(pewaktuHasil);
            pewaktuHasil = null;
        }
        if (jenis !== 'proses') {
            pewaktuHasil = window.setTimeout(function () {
                pewaktuHasil = null;
                hasil.hidden = true;
            }, LAMA_HASIL);
        }
    }

    function tulisLencana(teks, kelas) {
        lencana.textContent = teks;
        lencana.className = 'sj-absen-lencana' + (kelas ? ' ' + kelas : '');
        lencana.hidden = false;
    }

    function segarkanLencana() {
        if (!lencana) {
            return;
        }
        if (!tangkapJalan) {
            // Tidak menangkap. Kalau sebabnya jembatan di PC ini (mati, atau
            // versinya terlalu lama), itu perlu terlihat di layar kiosk.
            // Kalau server yang menutup (saklar dimatikan, perangkat
            // dicabut), lencananya hilang.
            if (terbuka && jembatan === null) {
                tulisLencana('Sidik jari tidak tersedia', 'perhatian');
            } else {
                lencana.hidden = true;
            }
            return;
        }
        var k = window.SjTangkap.keadaan();
        var teks = 'Sidik jari siap';
        var kelas = 'siap';
        if (k.terhubung === false) {
            teks = 'Sidik jari tidak tersedia';
            kelas = 'perhatian';
        } else if (k.terhubung === null) {
            teks = 'Menyiapkan sidik jari…';
            kelas = '';
        } else if (k.pembaca === 0) {
            teks = 'Pembaca sidik jari tidak terhubung';
            kelas = 'perhatian';
        } else if (!k.jendela_aktif) {
            teks = 'Klik halaman ini untuk mengaktifkan sidik jari';
            kelas = 'perhatian';
        } else if (!k.menangkap) {
            teks = 'Menyiapkan sidik jari…';
            kelas = '';
        }
        tulisLencana(teks, kelas);
    }

    // ---------- Jembatan dan tantangan ----------

    function versiCukup(versi) {
        var m = /^(\d+)\.(\d+)\./.exec(String(versi));
        return !!m && (Number(m[1]) > 0 || Number(m[2]) >= 4);
    }

    // Mengisi jembatan kalau jembatan di PC ini bisa dipakai untuk absen;
    // selain itu mengosongkannya, dan sebabnya masuk ke konsol.
    async function muatJembatan() {
        var status = await panggil(JEMBATAN + '/status', undefined, BATAS_JEMBATAN);
        var s = status && status.kode === 200 ? status.isi : null;
        if (!s || typeof s.perangkat !== 'string' || !s.galeri) {
            jembatan = null;
            kabarSekali('jembatan', 'jembatan di PC ini tidak menjawab (' + alasan(status) + ')');
            return;
        }
        if (!versiCukup(s.versi)) {
            jembatan = null;
            kabarSekali('jembatan', 'jembatan di PC ini versi ' + String(s.versi).split('+')[0] + '; absen sidik jari butuh 0.4.0 ke atas');
            return;
        }
        lupakanKabar('jembatan');
        jembatan = { perangkat: s.perangkat, galeri: s.galeri, dibaca: Date.now() };
    }

    // Tantangan baru untuk perangkat ini. Permintaan yang sedang berjalan
    // dipakai bersama, supaya tempelan dan pemeriksaan berkala tidak meminta
    // dua kali.
    function ambilTantangan() {
        if (janjiTantangan !== null) {
            return janjiTantangan;
        }
        var perangkat = jembatan.perangkat;
        janjiTantangan = panggil('api/sj_tantangan.php', { tujuan: 'absen', perangkat: perangkat }, BATAS_SERVER).then(function (jawaban) {
            if (jawaban && jawaban.kode === 200 && jawaban.isi && typeof jawaban.isi.tantangan === 'string') {
                terbuka = true;
                lupakanKabar('tantangan');
                tantangan = { nilai: jawaban.isi.tantangan, diambil: Date.now() };
                return;
            }
            // 403: saklarnya dimatikan, atau perangkat ini dicabut. Keduanya
            // berlaku seketika di server, jadi penangkapan dihentikan sampai
            // server memberi tantangan lagi. Galat lain bisa sementara.
            if (jawaban && jawaban.kode === 403) {
                terbuka = false;
                tantangan = null;
            }
            kabarSekali('tantangan', 'server tidak memberi tantangan (' + alasan(jawaban) + ')');
        }).finally(function () {
            janjiTantangan = null;
        });
        return janjiTantangan;
    }

    // Tantangan yang masih segar, atau null kalau server tidak memberinya.
    // Yang dikembalikan dicabut dari simpanan: satu tantangan untuk satu
    // tempelan.
    async function pakaiTantangan() {
        if (tantangan === null || Date.now() - tantangan.diambil > UMUR_TANTANGAN) {
            tantangan = null;
            await ambilTantangan();
        }
        if (tantangan === null) {
            return null;
        }
        var nilai = tantangan.nilai;
        tantangan = null;
        return nilai;
    }

    // ---------- Penangkapan ----------

    var catatanTerakhir = null;

    // Catatan SjTangkap. Yang sama persis dengan catatan sebelumnya tidak
    // diulang: ADC yang mati akan dicoba lagi tiap pemeriksaan berkala.
    function catatTangkap(teks, jenis) {
        if (teks === catatanTerakhir) {
            return;
        }
        catatanTerakhir = teks;
        kabar(teks, jenis === 'galat' || jenis === 'peringatan' ? 'peringatan' : 'info');
    }

    // Meminta SjTangkap menangkap, kalau belum, atau kalau penangkapannya
    // perlu ditolong: ADC tidak menjawab, atau pembacanya ada tetapi tidak
    // menangkap padahal jendelanya aktif. Selagi semuanya berjalan, ADC tidak
    // diganggu.
    function pastikanTangkap() {
        var kini = Date.now();
        if (tangkapJalan) {
            var k = window.SjTangkap.keadaan();
            if (k.terhubung === false) {
                // Tiap percobaan membuat objek WebSDK baru (atau memuat
                // pustakanya lagi), dan yang lama tidak bisa dibuang dari
                // sini. Jadi ADC yang mati dihubungi lagi makin jarang: pada
                // pemeriksaan berikutnya, lalu 30 detik, 1, 2, dan 4 menit
                // kemudian, seterusnya 5 menit sekali.
                if (sisaLewatiAdc > 0) {
                    sisaLewatiAdc--;
                    return;
                }
                lewatiAdc = Math.min(lewatiAdc * 2 + 1, LEWATI_ADC_MAKS);
                sisaLewatiAdc = lewatiAdc;
            } else {
                if (k.terhubung === true) {
                    lewatiAdc = 0;
                    sisaLewatiAdc = 0;
                }
                var perlu = (k.terhubung === null && kini - tangkapDiminta > COBA_ADC)
                    || (k.terhubung === true && k.pembaca > 0 && !k.menangkap && k.jendela_aktif);
                if (!perlu) {
                    return;
                }
            }
        }
        tangkapJalan = true;
        tangkapDiminta = kini;
        Promise.resolve(window.SjTangkap.mulai({ sampel: terimaSampel, keadaan: segarkanLencana, catat: catatTangkap })).catch(function (e) {
            kabar('penangkapan tidak bisa dimulai: ' + pesanGalat(e));
        }).then(segarkanLencana);
    }

    function hentikanTangkap() {
        if (!tangkapJalan) {
            return;
        }
        tangkapJalan = false;
        Promise.resolve(window.SjTangkap.henti()).catch(function () { /* sudah berhenti */ }).then(segarkanLencana);
    }

    // Foto dari webcam halaman kiosk sebagai data URI JPEG, atau null.
    // Webcam.snap() memunculkan alert() kalau kameranya belum siap, jadi
    // kesiapannya diperiksa dulu.
    function ambilFoto() {
        return new Promise(function (selesai) {
            var beres = false;
            function akhiri(nilai) {
                if (!beres) {
                    beres = true;
                    selesai(nilai);
                }
            }
            try {
                var kamera = window.Webcam;
                if (!kamera || !kamera.loaded || !kamera.live || typeof kamera.snap !== 'function') {
                    akhiri(null);
                    return;
                }
                kamera.snap(function (uri) {
                    akhiri(typeof uri === 'string' && uri.indexOf('data:image/jpeg;base64,') === 0 ? uri : null);
                });
            } catch (e) {
                akhiri(null);
            }
            window.setTimeout(function () { akhiri(null); }, 500);
        });
    }

    // ---------- Satu tempelan ----------

    var JUDUL = { masuk: 'Absen masuk', pulang: 'Absen pulang', ditolak: 'Tidak dicatat', tidak_dikenali: 'Tidak dikenali', bukan_siswa: 'Guru' };
    var PAKAI_QR = ' Pakai QR atau NISN dulu.';

    function tampilkanHasilServer(isi) {
        var orang = isi.orang || {};
        var data = isi.data || {};
        var nama = data.nama_siswa || orang.nama || '';
        var kelompok = data.kelas || orang.kelompok || '';
        var jenis = isi.hasil === 'masuk' || isi.hasil === 'pulang' ? 'berhasil'
            : isi.hasil === 'tidak_dikenali' ? 'ulang'
            : isi.hasil === 'bukan_siswa' ? 'netral'
            : 'gagal';
        tampilkan(jenis, JUDUL[isi.hasil] || 'Sidik jari', nama, kelompok, typeof isi.message === 'string' ? isi.message : '');
    }

    async function proses(sampel) {
        if (jembatan === null) {
            await muatJembatan();
        }
        if (jembatan === null) {
            tampilkan('gagal', 'Sidik jari', '', '', 'Sidik jari sedang tidak bisa dipakai.' + PAKAI_QR);
            return;
        }
        // Templat dan tempelan harus dari pembaca dan driver yang sama. Kalau
        // DPI-nya berbeda, semua tempelan akan ditolak tanpa sebab yang
        // terlihat, jadi lebih baik berhenti di sini dengan pesan yang jelas.
        var g = jembatan.galeri;
        if (g.templat > 0 && sampel.dpi && g.dpi && Math.abs(sampel.dpi - g.dpi) / g.dpi > BEDA_DPI) {
            kabar('DPI pembaca (' + sampel.dpi + ') berbeda dari DPI templat yang tersimpan (' + g.dpi + '): driver pembaca berubah?');
            tampilkan('gagal', 'Sidik jari', '', '', 'Pembaca sidik jari perlu diperiksa admin.' + PAKAI_QR);
            return;
        }

        tampilkan('proses', 'Sidik jari', '', '', 'Memeriksa sidik jari…');
        var foto = await ambilFoto();
        var nilai = await pakaiTantangan();
        if (nilai === null) {
            tampilkan('gagal', 'Sidik jari', '', '', terbuka ? 'Server tidak menjawab. Tempelkan jari lagi, atau pakai QR atau NISN.'
                : 'Absen sidik jari sedang ditutup.' + PAKAI_QR);
            return;
        }

        var cocok = await panggil(JEMBATAN + '/identifikasi', { tantangan: nilai, format: 'raw', sampel: sampel.teks }, BATAS_JEMBATAN);
        var j = cocok && cocok.kode === 200 ? cocok.isi : null;
        if (!j || typeof j.diterima !== 'boolean' || typeof j.perangkat !== 'string' || typeof j.tanda_tangan !== 'string' || !Number.isInteger(j.skor)) {
            // Tanpa tanda tangan jembatan tidak ada yang bisa dikirim ke server.
            if (cocok !== null && cocok.kode === 409 && cocok.isi && typeof cocok.isi.message === 'string') {
                // Sampel kembar, atau belum ada jari yang terdaftar di PC ini.
                tampilkan('ulang', 'Sidik jari', '', '', cocok.isi.message);
            } else if (cocok !== null && cocok.kode === 400) {
                kabar('jembatan menolak tempelan (' + alasan(cocok) + ')');
                tampilkan('ulang', 'Sidik jari', '', '', 'Tempelan tidak terbaca. Tempelkan jari lagi.');
            } else {
                kabar('jembatan tidak mengerjakan identifikasi (' + alasan(cocok) + ')');
                jembatan = null;
                tampilkan('gagal', 'Sidik jari', '', '', 'Sidik jari sedang tidak bisa dipakai.' + PAKAI_QR);
            }
            return;
        }

        // Jawaban jembatan diteruskan apa adanya: server menyusun ulang pesan
        // bertanda tangan dari nilai-nilai ini.
        var kiriman = { perangkat: j.perangkat, tantangan: nilai, diterima: j.diterima, skor: j.skor, tanda_tangan: j.tanda_tangan };
        if (j.diterima) {
            kiriman.identitas = j.identitas;
            if (foto !== null) {
                kiriman.foto_base64 = foto;
            }
        }
        var jawaban = await panggil('api/sj_absen.php', kiriman, BATAS_SERVER);
        if (jawaban !== null && jawaban.kode === 413 && kiriman.foto_base64 !== undefined) {
            kabar('foto terlalu besar untuk server; absen dikirim ulang tanpa foto');
            delete kiriman.foto_base64;
            jawaban = await panggil('api/sj_absen.php', kiriman, BATAS_SERVER);
        }
        if (jawaban === null) {
            // Kirimannya bisa saja sudah sampai. Tempelan berikutnya yang
            // memastikan: server mencatatnya, atau menjawab bahwa siswa itu
            // sudah absen.
            tampilkan('gagal', 'Sidik jari', '', '', 'Server tidak menjawab, jadi absen ini belum pasti tercatat. Tempelkan jari lagi.');
            return;
        }
        if (jawaban.kode !== 200 || !jawaban.isi || typeof jawaban.isi.hasil !== 'string') {
            kabar('server tidak mencatat tempelan (' + alasan(jawaban) + ')');
            tampilkan('gagal', 'Tidak dicatat', '', '', 'Absen tidak tercatat. ' + (jawaban.isi && typeof jawaban.isi.message === 'string'
                ? jawaban.isi.message : 'Tempelkan jari lagi, atau pakai QR atau NISN.'));
            return;
        }
        if (jawaban.isi.hasil === 'masuk' || jawaban.isi.hasil === 'pulang') {
            perluMuatUlang = true;
        }
        tampilkanHasilServer(jawaban.isi);
    }

    function terimaSampel(sampel) {
        if (sibuk) {
            kabar('tempelan diabaikan: tempelan sebelumnya masih diproses', 'info');
            return;
        }
        sibuk = true;
        proses(sampel).catch(function (e) {
            kabar('tempelan tidak selesai diproses: ' + pesanGalat(e));
            tampilkan('gagal', 'Sidik jari', '', '', 'Sidik jari sedang tidak bisa dipakai.' + PAKAI_QR);
        }).then(function () {
            sibuk = false;
            tempelanTerakhir = Date.now();
            // Muat ulang mundur lagi, dan batas tunggunya untuk alur QR/NISN
            // dihitung dari awal.
            mulaiTungguNisn = 0;
            // Tantangan untuk tempelan berikutnya disiapkan sekarang.
            if (jembatan !== null && terbuka && tantangan === null) {
                ambilTantangan();
            }
            jadwalkanMuatUlang();
        });
    }

    // ---------- Muat ulang yang ditunda ----------

    var pewaktuMuat = null;
    var mulaiTungguNisn = 0;

    // Alur QR/NISN sedang dipakai: ada NISN yang belum selesai dikirim
    // (isiannya baru dikosongkan setelah server menjawab), atau hasilnya
    // sedang tampil.
    function qrSedangDipakai() {
        var nisn = document.getElementById('nisn-input');
        if (nisn && nisn.value !== '') {
            return true;
        }
        var kabarQr = document.getElementById('notification-bar');
        return !!kabarQr && window.getComputedStyle(kabarQr).display !== 'none';
    }

    // Halaman dimuat ulang supaya daftar hadir dan statistiknya ikut berubah,
    // tetapi baru setelah antrean reda. Alur QR/NISN yang sedang dipakai juga
    // tidak dipotong, kecuali sudah terlalu lama begitu.
    //
    // Hanya satu pewaktu pada satu waktu. Tempelan yang datang sesudah
    // pewaktunya dipasang tidak menggesernya: saat pewaktu itu habis, sisa
    // waktunya dihitung lagi dari tempelan terakhir.
    function jadwalkanMuatUlang() {
        if (!perluMuatUlang || pewaktuMuat !== null) {
            return;
        }
        var tunggu = Math.max(tempelanTerakhir + TUNDA_MUAT_ULANG - Date.now(), sibuk ? 1000 : 0);
        pewaktuMuat = window.setTimeout(function () {
            pewaktuMuat = null;
            var kini = Date.now();
            if (sibuk || kini - tempelanTerakhir < TUNDA_MUAT_ULANG) {
                jadwalkanMuatUlang();
                return;
            }
            if (qrSedangDipakai()) {
                if (mulaiTungguNisn === 0) {
                    mulaiTungguNisn = kini;
                }
                if (kini - mulaiTungguNisn < BATAS_TUNGGU_NISN) {
                    pewaktuMuat = window.setTimeout(function () {
                        pewaktuMuat = null;
                        jadwalkanMuatUlang();
                    }, TUNGGU_NISN);
                    return;
                }
            }
            window.location.reload();
        }, tunggu);
    }

    // Alur QR/NISN memuat ulang halaman 2 detik setelah tiap absen berhasil.
    // Kalau itu terjadi selagi sebuah tempelan diproses, tempelannya hilang,
    // atau tercatat tanpa hasilnya sempat tampil, dan siswa berikutnya harus
    // menunggu WebSDK menyala lagi. Jadi selagi ada tempelan, dan sampai
    // TUNDA_MUAT_ULANG sesudahnya, muat ulang dari skrip halaman dibatalkan
    // dan diganti muat ulang yang ditunda di atas. Muat ulang dari tombol
    // peramban tidak lewat peristiwa ini.
    function jagaMuatUlang() {
        var navigasi = window.navigation;
        if (!navigasi || typeof navigasi.addEventListener !== 'function') {
            return;     // tanpa Navigation API, muat ulang berjalan seperti biasa
        }
        navigasi.addEventListener('navigate', function (e) {
            try {
                if (e.navigationType !== 'reload' || e.userInitiated) {
                    return;
                }
                if (!sibuk && Date.now() - tempelanTerakhir >= TUNDA_MUAT_ULANG) {
                    return;
                }
                e.preventDefault();
                perluMuatUlang = true;
                jadwalkanMuatUlang();
            } catch (galat) { /* muat ulangnya dibiarkan berjalan */ }
        });
    }

    // ---------- Layar ----------

    var kunciLayar = null;

    // Kunci layar lepas sendiri saat halaman tidak terlihat, jadi diminta
    // lagi di tiap pemeriksaan berkala dan saat halaman terlihat kembali.
    async function jagaLayar() {
        if (kunciLayar !== null || !navigator.wakeLock || document.visibilityState !== 'visible') {
            return;
        }
        try {
            var kunci = await navigator.wakeLock.request('screen');
            kunciLayar = kunci;
            lupakanKabar('layar');
            kunci.addEventListener('release', function () {
                if (kunciLayar === kunci) {
                    kunciLayar = null;
                }
            });
        } catch (e) {
            kabarSekali('layar', 'layar tidak bisa dijaga tetap menyala: ' + pesanGalat(e));
        }
    }

    // ---------- Untuk skrip detak ----------

    // Untuk kolom "Pembaca (ADC)" di halaman pantau, yang mengartikan "siap"
    // sebagai "halaman kiosk sedang bisa menangkap jari":
    // - 1 kalau penangkapan sedang berjalan;
    // - 0 kalau ADC tidak menjawab, pembacanya tidak terlihat, atau jendela
    //   ini tidak aktif (WebSDK hanya melayani jendela yang aktif);
    // - null kalau belum diketahui, kalau penangkapan baru dimulai, atau
    //   kalau skrip ini tidak sedang menangkap. Detak lalu dikirim tanpa adc.
    window.SjAbsenKiosk = {
        adc: function () {
            if (!tangkapJalan) {
                return null;
            }
            var k = window.SjTangkap.keadaan();
            if (k.terhubung === null) {
                return null;
            }
            if (k.terhubung !== true || !(k.pembaca > 0) || !k.jendela_aktif) {
                return 0;
            }
            return k.menangkap ? 1 : null;
        }
    };

    // ---------- Pemeriksaan berkala ----------

    async function periksa() {
        var kini = Date.now();
        if (jembatan === null || kini - jembatan.dibaca > UMUR_STATUS) {
            await muatJembatan();
        }
        if (jembatan !== null && (tantangan === null || kini - tantangan.diambil > UMUR_TANTANGAN)) {
            await ambilTantangan();
        }
        if (jembatan !== null && terbuka) {
            pastikanTangkap();
        } else {
            hentikanTangkap();
        }
        jagaLayar();
        segarkanLencana();
    }

    function jadwalkan() {
        window.setTimeout(function () {
            periksa().catch(function (e) {
                kabar('pemeriksaan berkala gagal: ' + pesanGalat(e));
            }).then(jadwalkan);
        }, SELANG);
    }

    gerbang().then(function (jalur) {
        if (jalur === null) {
            return;
        }
        if (!window.SjTangkap) {
            kabar('modul penangkapan tidak termuat');
            return;
        }
        kabar('aktif lewat ' + jalur, 'info');
        siapkanTampilan();
        jagaMuatUlang();
        document.addEventListener('visibilitychange', function () {
            jagaLayar();
        });
        periksa().catch(function (e) {
            kabar('pemeriksaan pertama gagal: ' + pesanGalat(e));
        }).then(jadwalkan);
    }).catch(function (e) {
        kabar('tidak bisa dimulai: ' + pesanGalat(e));
    });
})();
</script>
