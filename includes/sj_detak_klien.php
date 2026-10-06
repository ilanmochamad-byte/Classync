<?php
// sj_detak_klien.php — skrip detak kiosk sidik jari, untuk halaman kiosk.
//
// Di-include absen-siswa.php tepat sebelum </body>. Sekali semenit skripnya
// menjalankan satu rantai: status jembatan di PC kiosk, tantangan dari server
// (api/sj_tantangan.php), tanda tangan jembatan, lalu api/sj_detak.php. Detak
// yang sampai tampil di admin/kiosk_sidik_jari.php.
//
// Senyap: tidak ada yang tampil di halaman kiosk, dan kegagalan hanya dicatat
// di konsol peramban. Berkas ini tidak mengeluarkan apa pun selama server
// belum punya perangkat yang aktif dan berkunci, jadi mengosongkan
// $sj_perangkat mematikan detak tanpa deploy.
//
// Halaman kiosk terbuka untuk umum. Di peramban selain PC kiosk, permintaan
// ke 127.0.0.1 memunculkan permintaan izin jaringan lokal, jadi skripnya baru
// menyentuh 127.0.0.1 kalau salah satu ini benar:
// - izin loopback-network untuk situs ini berstatus "granted". Di PC kiosk
//   izin itu datang dari kebijakan Chrome (jembatan/kebijakan-chrome.reg);
// - peramban ini ditandai sebagai kiosk. absen-siswa.php?detak=hidup memasang
//   tandanya, ?detak=mati mencabutnya.
// Di peramban lain skripnya berhenti di situ: tanpa pewaktu, tanpa
// penyimpanan, tanpa permintaan.
//
// Halaman kiosk memuat ulang dirinya 2 detik setelah tiap scan berhasil. Jadi
// jadwal detak disimpan di localStorage, bukan hanya di pewaktu halaman.
// Tanpa itu, pada jam sibuk pewaktunya selalu terulang dari nol, dan kiosk
// tampak diam justru saat paling ramai.

if (!function_exists('sjDetakKlienAktif')) {
    // Benar kalau server punya setidaknya satu perangkat yang boleh berdetak.
    // Galat apa pun dianggap "tidak": halaman kiosk tidak boleh terganggu.
    function sjDetakKlienAktif() {
        $pustaka = __DIR__ . '/sidik_jari.php';
        // is_readable() dulu: require yang gagal itu fatal error.
        if (!is_readable($pustaka)) {
            return false;
        }
        try {
            require_once $pustaka;
            $konfigurasi = sjKonfigurasi();
            if ($konfigurasi === null) {
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

if (!sjDetakKlienAktif()) {
    return;
}
?>
<script>
(function () {
    'use strict';

    var JEMBATAN = 'http://127.0.0.1:47890';
    var SELANG = 60000;        // satu detak per menit
    var COBA_LAGI = 15000;     // setelah detak yang terpotong muat ulang halaman
    var TUNGGU_AWAL = 1500;    // biarkan halaman kiosk selesai dimuat dulu
    var BATAS = 8000;          // batas waktu tiap permintaan
    var KUNCI_TANDA = 'sj_detak_kiosk';
    var KUNCI_COBA = 'sj_detak_coba';
    var KUNCI_SELESAI = 'sj_detak_selesai';

    function baca(kunci) {
        try {
            return window.localStorage.getItem(kunci);
        } catch (e) {
            return null;
        }
    }

    // false kalau penyimpanan peramban tidak bisa dipakai.
    function tulis(kunci, nilai) {
        try {
            if (nilai === null) {
                window.localStorage.removeItem(kunci);
            } else {
                window.localStorage.setItem(kunci, nilai);
            }
            return true;
        } catch (e) {
            return false;
        }
    }

    // ?detak=hidup menandai peramban ini sebagai kiosk, ?detak=mati mencabut
    // tandanya. Hanya di sini halaman menampilkan sesuatu, supaya yang
    // memasangnya tahu hasilnya. Parameternya lalu dibuang dari alamat, karena
    // halaman ini memuat ulang dirinya setelah tiap scan.
    (function () {
        var alamat = new URL(window.location.href);
        var pilihan = alamat.searchParams.get('detak');
        if (pilihan !== 'hidup' && pilihan !== 'mati') {
            return;
        }
        var tersimpan = tulis(KUNCI_TANDA, pilihan === 'hidup' ? '1' : null);
        var kabar = document.createElement('div');
        kabar.textContent = !tersimpan ? 'Detak kiosk: tanda tidak bisa disimpan di peramban ini.'
            : pilihan === 'hidup' ? 'Detak kiosk: peramban ini ditandai sebagai kiosk.'
            : 'Detak kiosk: tanda kiosk di peramban ini dicabut.';
        kabar.style.cssText = 'position:fixed;left:12px;bottom:12px;z-index:9999;padding:8px 12px;'
            + 'border-radius:6px;background:#1f2937;color:#fff;font:14px sans-serif';
        document.body.appendChild(kabar);
        window.setTimeout(function () { kabar.remove(); }, 6000);
        alamat.searchParams.delete('detak');
        window.history.replaceState(null, '', alamat.pathname + alamat.search + alamat.hash);
    })();

    // 'penanda', 'izin', atau null. Menanyakan izin tidak memunculkan
    // permintaan izin; yang memunculkannya permintaan ke 127.0.0.1 itu sendiri.
    async function gerbang() {
        if (baca(KUNCI_TANDA) === '1') {
            return 'penanda';
        }
        try {
            var izin = await navigator.permissions.query({ name: 'loopback-network' });
            return izin.state === 'granted' ? 'izin' : null;
        } catch (e) {
            return null;    // peramban ini tidak mengenal izin itu
        }
    }

    // Jawaban beserta kodenya, atau null kalau permintaannya tidak sampai atau
    // melewati batas waktu. isi null kalau jawabannya bukan JSON.
    async function panggil(url, kiriman, tahanTutup) {
        var pemutus = new AbortController();
        var pewaktu = window.setTimeout(function () { pemutus.abort(); }, BATAS);
        var opsi = { signal: pemutus.signal };
        if (kiriman !== undefined) {
            opsi.method = 'POST';
            opsi.headers = { 'Content-Type': 'application/json' };
            opsi.body = JSON.stringify(kiriman);
        }
        if (tahanTutup) {
            // Kiriman tetap sampai walau halaman keburu dimuat ulang.
            opsi.keepalive = true;
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
            return ' (tidak sampai)';
        }
        return hasil.isi && typeof hasil.isi.message === 'string' ? ': ' + hasil.isi.message : ' (jawaban ' + hasil.kode + ')';
    }

    var coba = 0;       // kapan detak terakhir dimulai
    var selesai = 0;    // kapan detak terakhir selesai, berhasil atau tidak

    function tandai(kunci) {
        var kini = Date.now();
        tulis(kunci, String(kini));
        return kini;
    }

    // null kalau detaknya diterima server; selain itu sebab gagalnya.
    async function rantai() {
        var status = await panggil(JEMBATAN + '/status');
        if (!status || status.kode !== 200 || !status.isi || typeof status.isi.perangkat !== 'string') {
            return 'jembatan tidak menjawab';
        }
        var tantangan = await panggil('api/sj_tantangan.php', { tujuan: 'detak', perangkat: status.isi.perangkat });
        if (!tantangan || tantangan.kode !== 200 || !tantangan.isi) {
            return 'server tidak memberi tantangan' + alasan(tantangan);
        }
        var tanda = await panggil(JEMBATAN + '/detak', { tantangan: tantangan.isi.tantangan });
        if (!tanda || tanda.kode !== 200 || !tanda.isi) {
            return 'jembatan tidak menandatangani detak' + alasan(tanda);
        }
        // Dihitung selesai sejak dikirim: kalau halaman dimuat ulang sebelum
        // jawabannya tiba, detak ini tidak diulang 15 detik kemudian.
        selesai = tandai(KUNCI_SELESAI);
        var hasil = await panggil('api/sj_detak.php', {
            perangkat: tanda.isi.perangkat,
            tantangan: tantangan.isi.tantangan,
            alat: tanda.isi.alat,
            tanda_tangan: tanda.isi.tanda_tangan,
            versi: status.isi.versi
        }, true);
        if (!hasil || hasil.kode !== 200) {
            return 'server menolak detak' + alasan(hasil);
        }
        return null;
    }

    async function detak() {
        coba = tandai(KUNCI_COBA);
        var sebab = await rantai();
        selesai = tandai(KUNCI_SELESAI);
        if (sebab !== null) {
            console.warn('[detak kiosk] ' + sebab);
        }
    }

    // Detak berikutnya: semenit setelah yang terakhir selesai. Detak yang
    // dimulai tetapi tidak selesai, karena halamannya dimuat ulang, diulang
    // lebih cepat.
    function jadwalkan() {
        var kini = Date.now();
        var tunggu = Math.max(selesai + SELANG - kini, coba + COBA_LAGI - kini, TUNGGU_AWAL);
        window.setTimeout(function () {
            detak().catch(function () {
                selesai = Date.now();
            }).then(jadwalkan);
        }, tunggu);
    }

    gerbang().then(function (jalur) {
        if (jalur === null) {
            return;
        }
        console.info('[detak kiosk] aktif lewat ' + jalur);
        var kini = Date.now();
        coba = Number(baca(KUNCI_COBA)) || 0;
        selesai = Number(baca(KUNCI_SELESAI)) || 0;
        // Cap waktu di depan jam PC, karena jamnya disetel mundur, tidak
        // boleh menunda detak.
        if (coba > kini) {
            coba = 0;
        }
        if (selesai > kini) {
            selesai = 0;
        }
        // Tanpa penyimpanan, jadwal tidak bertahan melewati muat ulang.
        // Supaya tiap muat ulang tidak langsung berdetak, tunggu satu selang.
        if (!tulis(KUNCI_COBA, String(coba))) {
            selesai = kini;
        }
        jadwalkan();
    });
})();
</script>
