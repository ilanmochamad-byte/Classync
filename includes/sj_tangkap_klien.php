<?php
// sj_tangkap_klien.php — penangkapan sidik jari lewat HID Authentication
// Device Client (ADC), untuk halaman yang dibuka di Chrome PC kiosk.
//
// Di-include halaman yang memerlukannya: admin/sidik_jari.php, dan halaman
// kiosk lewat sj_absen_klien.php. Isinya satu objek, window.SjTangkap.
// Logikanya dipindahkan dari halaman uji jembatan (jembatan/wwwroot/uji.html),
// tempat aturan-aturan ini ditemukan lewat uji di PC kiosk:
// - Hanya format Raw yang diminta, dan format tiap sampel diperiksa. Sampel
//   berformat lain memicu mulai ulang; kalau dua kali tidak menolong, halaman
//   diminta dimuat ulang.
// - Perintah berhenti dan mulai berantrean, satu rangkaian pada satu waktu,
//   dan tiap perintah dibatasi 5 detik. ADC yang diam tidak boleh menahan
//   halaman.
// - WebSDK hanya melayani jendela yang sedang aktif. Setelah jendela aktif
//   kembali, ADC mengirim kejadian "pembaca terhubung" ber-UID nol, dan
//   penangkapan dimulai lagi. Kalau kejadian itu tidak datang, halaman
//   memulainya sendiri sesaat kemudian.
// - Pembaca yang baru dicolok selalu dihentikan lalu dimulai lagi.
// - Kejadian terhubung dan terputus untuk pembaca sungguhan datang dua kali;
//   kembarannya dibuang.
//
// Berkas ini tidak menyentuh 127.0.0.1 sampai SjTangkap.mulai() dipanggil:
// pustaka WebSDK baru dimuat saat itu, dan baru saat itu ADC dihubungi. Di
// peramban selain PC kiosk, permintaan ke 127.0.0.1 memunculkan permintaan
// izin jaringan lokal, jadi halaman pemakainya yang memutuskan kapan
// memanggilnya.
//
// Sampel yang diteruskan ke halaman adalah teks Data dari BioSample WebSDK,
// apa adanya; jembatan yang membacanya. Halaman hanya membukanya untuk
// memeriksa ukurannya dan menggambar pratinjau. Gambarnya tidak disimpan dan
// tidak dikirim ke server.
?>
<script>
window.SjTangkap = window.SjTangkap || (function () {
    'use strict';

    // Versi dan hash-nya sama dengan yang dipakai halaman uji jembatan.
    var PUSTAKA = [
        ['https://cdn.jsdelivr.net/npm/@digitalpersona/websdk@1.1.0/dist/websdk.client.ui.min.js',
            'sha384-baRwCaY+UWsnD5MrvOeGa2cLyPxQw83DBbM0dDtUTgbmOArOkLNpjekYNmh0/b8v'],
        ['https://cdn.jsdelivr.net/npm/@digitalpersona/fingerprint@1.0.0/dist/fingerprint.sdk.min.js',
            'sha384-qRaj7EcnkG9xEa3GLDSbuyk3KyMMkHjczUC91bAkIoD2OoTfONS08AfRMX7I+fxl']
    ];
    var BATAS_ADC = 5000;       // batas tiap perintah berhenti atau mulai
    var TUNGGU_FOKUS = 600;     // jeda setelah jendela aktif kembali
    var PENANGAN = ['onCommunicationFailed', 'onDeviceConnected', 'onDeviceDisconnected', 'onAcquisitionStarted',
        'onAcquisitionStopped', 'onErrorOccurred', 'onSamplesAcquired'];

    var api = null;
    var pemakai = {};           // { sampel, keadaan, catat } dari halaman
    var aktif = false;          // benar di antara mulai() dan henti()
    var adc = { terhubung: null, pembaca: [], menangkap: false, mulai_ulang: 0, dpi: null };

    function keadaan() {
        return {
            terhubung: adc.terhubung,
            pembaca: adc.pembaca.length,
            menangkap: adc.menangkap,
            mulai_ulang: adc.mulai_ulang,
            dpi: adc.dpi,
            jendela_aktif: document.hasFocus()
        };
    }

    function kabari() {
        if (typeof pemakai.keadaan === 'function') {
            pemakai.keadaan(keadaan());
        }
    }

    function catat(teks, jenis) {
        if (typeof pemakai.catat === 'function') {
            pemakai.catat(teks, jenis || 'info');
        }
    }

    function pesanGalat(e) {
        return e && e.message ? e.message : String(e);
    }

    // ---------- Pustaka WebSDK ----------

    function muatSkrip(url, sri) {
        return new Promise(function (selesai, gagal) {
            var skrip = document.createElement('script');
            skrip.src = url;
            skrip.integrity = sri;
            skrip.crossOrigin = 'anonymous';
            skrip.onload = function () { selesai(); };
            skrip.onerror = function () {
                gagal(new Error('Pustaka WebSDK tidak termuat: jsDelivr tidak terjangkau, atau isinya berubah.'));
            };
            document.head.appendChild(skrip);
        });
    }

    var janjiPustaka = null;

    // Dimuat berurutan, dan hanya sekali. Yang gagal boleh dicoba lagi.
    function muatPustaka() {
        if (typeof Fingerprint !== 'undefined' && Fingerprint.WebApi) {
            return Promise.resolve();
        }
        if (!janjiPustaka) {
            janjiPustaka = PUSTAKA.reduce(function (janji, p) {
                return janji.then(function () { return muatSkrip(p[0], p[1]); });
            }, Promise.resolve()).catch(function (e) {
                janjiPustaka = null;
                throw e;
            });
        }
        return janjiPustaka;
    }

    function formatRaw() {
        return typeof Fingerprint !== 'undefined' && Fingerprint.SampleFormat ? Fingerprint.SampleFormat.Raw : 1;
    }

    // ---------- Kejadian ADC ----------

    function uidNol(uid) {
        return /^[0-]*$/.test(uid == null ? '' : uid);
    }

    // Kejadian ber-UID nol tidak disaring: itu satu-satunya tanda bahwa
    // penangkapan perlu dimulai lagi.
    var kejadianTerakhir = { kunci: null, t: 0 };

    function kejadianKembar(jenis, uid) {
        var kunci = jenis + '|' + uid;
        var kini = performance.now();
        var kembar = !uidNol(uid) && kejadianTerakhir.kunci === kunci && kini - kejadianTerakhir.t < 50;
        kejadianTerakhir = { kunci: kunci, t: kini };
        return kembar;
    }

    function pasangApi(baru) {
        // Penangan pada objek lama dilepas, supaya hanya satu objek yang
        // mengubah keadaan.
        if (api) {
            PENANGAN.forEach(function (nama) { api[nama] = function () {}; });
        }
        api = baru;
        adc = { terhubung: null, pembaca: [], menangkap: false, mulai_ulang: 0, dpi: adc.dpi };
        pernahMulai = false;
        formatMeleset = 0;
        api.onCommunicationFailed = function () {
            if (adc.terhubung !== false) {
                catat('Tidak bisa terhubung ke Authentication Device Client di PC ini.', 'galat');
            }
            adc.terhubung = false;
            adc.menangkap = false;
            kabari();
        };
        api.onDeviceConnected = function (e) {
            if (kejadianKembar('terhubung', e.deviceUid)) {
                return;
            }
            var nol = uidNol(e.deviceUid);
            if (!nol) {
                catat('Pembaca terhubung.', 'ok');
            }
            muatPembaca({ pemicu: 'terhubung', uid_nol: nol });
        };
        api.onDeviceDisconnected = function (e) {
            if (kejadianKembar('terputus', e.deviceUid)) {
                return;
            }
            var nol = uidNol(e.deviceUid);
            // UID nol bukan pembaca yang dicabut: kejadian itu datang selagi
            // tidak ada pembaca yang terpasang.
            catat(nol ? 'ADC melaporkan tidak ada pembaca.' : 'Pembaca terputus.', nol ? 'peringatan' : 'galat');
            muatPembaca({ pemicu: 'terputus', uid_nol: nol });
        };
        api.onAcquisitionStarted = function () {
            adc.menangkap = true;
            kabari();
        };
        api.onAcquisitionStopped = function () {
            adc.menangkap = false;
            kabari();
        };
        api.onErrorOccurred = function (e) {
            catat('Galat pembaca: kode ' + e.error + '.', 'galat');
        };
        api.onSamplesAcquired = terimaSampel;
        return muatPembaca({ pemicu: 'muat', uid_nol: null });
    }

    function muatPembaca(asal) {
        var apiIni = api;
        return Promise.resolve().then(function () {
            return api.enumerateDevices();
        }).then(function (daftar) {
            if (api !== apiIni) {
                return;
            }
            adc.terhubung = true;
            adc.pembaca = Array.isArray(daftar) ? daftar.slice() : [];
            kabari();
            // Pembaca yang baru dicolok selalu dimulai lagi, dan dihentikan
            // dulu: belum diketahui apakah ADC melanjutkan penangkapan yang
            // lama sendiri, dan dengan format apa.
            var pembacaBaru = asal.pemicu === 'terhubung' && !asal.uid_nol;
            if (adc.pembaca.length > 0 && (!adc.menangkap || pembacaBaru)) {
                return mulaiTangkap({ asal: asal, paksa: pembacaBaru });
            }
        }).catch(function (e) {
            if (api !== apiIni) {
                return;
            }
            // Sambungan yang putus sudah dicatat onCommunicationFailed.
            var sudahDicatat = adc.terhubung === false;
            adc.terhubung = false;
            adc.menangkap = false;
            kabari();
            if (!sudahDicatat) {
                catat('Daftar pembaca tidak bisa dibaca: ' + pesanGalat(e), 'galat');
            }
        });
    }

    // ---------- Antrean berhenti dan mulai ----------

    var sibuk = false;
    var janjiTangkap = Promise.resolve();
    var lagi = false;
    var paksaLagi = false;
    var pernahMulai = false;

    function denganBatas(janji, apa) {
        var pewaktu;
        var batas = new Promise(function (_, tolak) {
            pewaktu = window.setTimeout(function () {
                tolak(new Error(apa + ' tidak dijawab ADC dalam ' + (BATAS_ADC / 1000) + ' detik'));
            }, BATAS_ADC);
        });
        return Promise.race([janji, batas]).finally(function () { window.clearTimeout(pewaktu); });
    }

    // paksa: berhenti dulu apa pun yang halaman kira, karena ADC bisa saja
    // masih menangkap dengan format lama. asal: kejadian yang memicu mulai
    // otomatis.
    function mulaiTangkap(opsi) {
        opsi = opsi || {};
        if (!api || !aktif) {
            return Promise.resolve();
        }
        if (sibuk) {
            // Mulai otomatis cukup menumpang rangkaian yang berjalan. Yang
            // dipaksa mendapat satu putaran lagi sesudahnya.
            if (opsi.paksa) {
                lagi = true;
                paksaLagi = true;
            }
            return janjiTangkap;
        }
        if (opsi.asal && pernahMulai) {
            adc.mulai_ulang++;
        }
        sibuk = true;
        janjiTangkap = jalankanTangkap(!!opsi.paksa);
        return janjiTangkap;
    }

    async function jalankanTangkap(paksa) {
        var apiIni = api;
        try {
            for (var putaran = 0; putaran < 3; putaran++) {
                var berhentiDulu = adc.menangkap || paksa || paksaLagi;
                lagi = false;
                paksaLagi = false;
                if (berhentiDulu) {
                    try {
                        await denganBatas(api.stopAcquisition(), 'Perintah berhenti');
                    } catch (e) { /* tidak sedang menangkap, atau ADC diam: mulai tetap dicoba */ }
                }
                await denganBatas(api.startAcquisition(formatRaw()), 'Perintah mulai');
                if (api !== apiIni) {
                    return;
                }
                adc.menangkap = true;
                pernahMulai = true;
                if (!lagi) {
                    break;
                }
            }
        } catch (e) {
            catat('Penangkapan tidak bisa dimulai: ' + pesanGalat(e), 'galat');
        } finally {
            sibuk = false;
            kabari();
        }
    }

    window.addEventListener('focus', function () {
        if (!aktif) {
            return;
        }
        kabari();
        window.setTimeout(function () {
            if (aktif && api && adc.pembaca.length > 0 && !adc.menangkap && !sibuk) {
                mulaiTangkap({ asal: { pemicu: 'fokus', uid_nol: null } });
            }
        }, TUNGGU_FOKUS);
    });
    window.addEventListener('blur', function () {
        if (aktif) {
            kabari();
        }
    });

    // ---------- Sampel ----------

    function b64urlKeBytes(teks) {
        var b64 = teks.replace(/-/g, '+').replace(/_/g, '/');
        while (b64.length % 4) {
            b64 += '=';
        }
        var biner = atob(b64);
        var bytes = new Uint8Array(biner.length);
        for (var i = 0; i < biner.length; i++) {
            bytes[i] = biner.charCodeAt(i);
        }
        return bytes;
    }

    // Raw dari WebSDK: BioSample {Header, Data, Version}. Data adalah base64url
    // dari JSON {Data: piksel base64url, Format: {iWidth, iHeight, iXdpi, ...}}.
    // Pikselnya boleh diikuti ekor yang bukan gambar. Aturannya sama dengan
    // jembatan: kelebihan diterima kalau kurang dari sisi terpendek.
    function bacaRaw(bio) {
        var dalam = JSON.parse(new TextDecoder().decode(b64urlKeBytes(bio.Data)));
        var format = dalam.Format || {};
        var piksel = b64urlKeBytes(dalam.Data);
        var lebar = format.iWidth;
        var tinggi = format.iHeight;
        var lebih = piksel.length - lebar * tinggi;
        return {
            teks: bio.Data,
            lebar: lebar,
            tinggi: tinggi,
            dpi: typeof format.iXdpi === 'number' ? format.iXdpi : null,
            panjang: piksel.length,
            utuh: lebar > 0 && tinggi > 0 && lebih >= 0 && lebih < Math.min(lebar, tinggi),
            // Menggambar tempelan ini ke kanvas yang diberikan, diperkecil
            // mengikuti ukuran kanvasnya.
            gambar: function (kanvas) {
                var asli = document.createElement('canvas');
                asli.width = lebar;
                asli.height = tinggi;
                var ctx = asli.getContext('2d');
                var data = ctx.createImageData(lebar, tinggi);
                for (var i = 0; i < lebar * tinggi; i++) {
                    data.data[4 * i] = data.data[4 * i + 1] = data.data[4 * i + 2] = piksel[i];
                    data.data[4 * i + 3] = 255;
                }
                ctx.putImageData(data, 0, 0);
                var tujuan = kanvas.getContext('2d');
                tujuan.fillStyle = '#fff';
                tujuan.fillRect(0, 0, kanvas.width, kanvas.height);
                tujuan.drawImage(asli, 0, 0, kanvas.width, kanvas.height);
            }
        };
    }

    // Sampel yang formatnya bukan Raw berarti ADC menangkap dengan format lain
    // daripada yang diminta. Menolaknya saja tidak cukup: pada uji 3 Oktober
    // 2026 tiga tempelan berturut-turut tertolak sampai halaman dimuat ulang.
    var formatMeleset = 0;

    function terimaSampel(e) {
        if (!aktif) {
            return;
        }
        if (e.sampleFormat !== formatRaw()) {
            formatMeleset++;
            if (formatMeleset <= 2) {
                catat('Sampel datang bukan sebagai Raw. Penangkapan dimulai ulang; tempelkan jari lagi.', 'peringatan');
                mulaiTangkap({ paksa: true });
            } else {
                catat('Sampel masih datang bukan sebagai Raw. Muat ulang halaman ini (F5).', 'galat');
            }
            return;
        }
        formatMeleset = 0;
        var sampel;
        try {
            sampel = bacaRaw(JSON.parse(e.samples)[0]);
        } catch (galat) {
            catat('Sampel dari alat tidak bisa dibaca halaman. Tempelkan jari lagi.', 'galat');
            return;
        }
        if (!sampel.utuh) {
            catat('Sampel dari alat tidak utuh: ' + sampel.panjang + ' byte untuk ' + sampel.lebar + ' × ' + sampel.tinggi
                + '. Tempelkan jari lagi.', 'galat');
            return;
        }
        adc.dpi = sampel.dpi;
        if (typeof pemakai.sampel === 'function') {
            pemakai.sampel(sampel);
        }
    }

    // ---------- Untuk halaman ----------

    // penangan: { sampel(s), keadaan(k), catat(teks, jenis) }, semuanya boleh
    // tidak ada. Aman dipanggil lagi, misalnya setelah gagal terhubung.
    function mulai(penangan) {
        pemakai = penangan || {};
        aktif = true;
        if (api && adc.terhubung !== false) {
            return muatPembaca({ pemicu: 'muat', uid_nol: null });
        }
        return muatPustaka().then(function () {
            if (aktif) {
                return pasangApi(new Fingerprint.WebApi());
            }
        }).catch(function (e) {
            adc.terhubung = false;
            kabari();
            catat(pesanGalat(e), 'galat');
        });
    }

    async function henti() {
        aktif = false;
        lagi = false;
        paksaLagi = false;
        if (!api) {
            return;
        }
        try {
            // Rangkaian mulai yang masih berjalan dibiarkan selesai, supaya
            // perintah berhenti ini yang terakhir sampai ke ADC.
            if (sibuk) {
                await janjiTangkap;
            }
            await denganBatas(api.stopAcquisition(), 'Perintah berhenti');
        } catch (e) { /* sudah berhenti, atau ADC diam */ }
        adc.menangkap = false;
    }

    return { mulai: mulai, henti: henti, keadaan: keadaan };
})();
</script>
