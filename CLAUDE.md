# Classync — aplikasi web (admin & area guru)

Bagian web dari sistem absensi SMK Terpadu Al Hasan. Panel admin (TU & kepala
sekolah), area guru, ekspor PDF/Excel, dan pengirim notifikasi WhatsApp.
Dilayani di `https://smkt.alhasan.co.id/classync/`.

## SISTEM INI SEDANG DIPAKAI GURU SETIAP HARI

Absensi yang tercatat di sini terhubung ke perhitungan honor. Kesalahan bukan
sekadar bug — ia jadi gaji yang salah. Empat aturan yang mengikat:

1. **Kompatibel mundur.** Aplikasi mobile versi lama masih beredar berminggu-minggu
   setelah versi baru rilis. Jangan pernah membuat perubahan yang memutus mereka.
2. **Satu perubahan, satu waktu.** Ubah satu berkas, buka halamannya, pastikan
   jalan, baru lanjut. Jangan mengubah puluhan berkas sekaligus.
3. **Jangan pernah menyunting berkas dengan mengetik ulang isinya** dari keluaran
   tool yang mungkin terpotong. Pakai `sed -i` atau baca-ubah-tulis.
4. **Tiga repositori, satu sistem.** Sebelum mengubah endpoint atau berkas
   bersama, cari pemanggilnya di `~/ClassyncApp`. URL ditulis harfiah di tiap
   layar (`const API_URL = '...'`), jadi grep di repo backend tidak akan
   menemukannya dan gampang salah menyimpulkan sebuah endpoint tidak terpakai.
   Aplikasi juga terikat pada nilai string tertentu — `monitoring_siswa.tsx`
   membandingkan `status_masuk` persis dengan `'Tepat Waktu'` dan `'Terlambat'`.

## Aturan honor per jenis absensi

`hitungHonorBulan()` di `admin/keuangan_helper.php` menjumlahkan honor **per
baris `absensi`**. Satu baris berlebih berarti satu kali bayar berlebih, dan
tidak ada gejala apa pun sampai slip gaji keluar. Karena itu setiap jalur yang
menulis ke `absensi` butuh penjaga duplikat — dan kuncinya **berbeda per
jenis**:

| Jenis | Kunci duplikat | Alasan |
|---|---|---|
| mengajar | `guru_id` + `jadwal_id` + tanggal | jadwalnya punya hari, jam mulai, dan jam selesai yang pasti; guru tidak mungkin mengajar dua kelas sekaligus |
| ekskul | `guru_id` + `jadwal_id` + tanggal | satu guru boleh membina dua ekskul di hari yang sama dan dibayar dua kali |
| piket | `guru_id` + tanggal, **tanpa `jadwal_id`** | satu hari satu bayar; label sesi Pagi/Siang tidak menentukan waktu |
| bimbingan (BK) | `guru_id` + tanggal + `topik_tema` + `sasaran_layanan` | tidak punya jadwal sama sekali; guru BK melayani 2 sampai 5 kali sehari |

Dua aturan tambahan yang tidak terbaca dari kode:

- **Honor hanya untuk guru yang terjadwal.** Pencarian jadwal harus menuntut
  `status_jadwal = 'Aktif'` untuk ketiga jenis yang punya jadwal, di panel web
  maupun aplikasi. Konsekuensinya disengaja: pengajuan untuk jadwal yang kini
  non-Aktif ditolak, termasuk untuk tanggal ketika jadwal itu masih berjalan.
- **Jadwal ekskul bisa bertumpang tindih.** `BETWEEN jam_mulai AND jam_selesai`
  bisa cocok ke lebih dari satu baris, lalu `LIMIT 1` tanpa `ORDER BY` memilih
  salah satunya secara kebetulan — dua pengajuan berbeda jatuh ke `jadwal_id`
  yang sama dan yang kedua ditolak keliru sebagai duplikat. Pakai
  `ORDER BY (jam_mulai = ?) DESC, id ASC`. Tidak berlaku untuk mengajar: guru
  tidak bisa berada di dua kelas sekaligus.

Semua aturan ini datang dari koreksi manusia, bukan dari kode. Saya menebak
tiga di antaranya dan ketiganya salah. Jangan menyimpulkannya ulang dari isi
tabel — riwayat `absensi` memuat baris dari aturan lama dan dari pengujian.

## Cara perubahan sampai ke produksi

```
laptop  →  git push  →  GitHub  →  cPanel "Update from Remote"  →  "Deploy HEAD Commit"
```

`.cpanel.yml` menyalin folder kode ke
`/DATA/k1807225/public_html/smkt.alhasan.co.id/classync`.

**Penyalinan tidak pernah menghapus.** Menghapus berkas dari repositori TIDAK
menghapusnya dari server — itu harus dilakukan manual lewat File Manager cPanel.
Aturan yang sama inilah yang melindungi 1,8 GB foto absensi.

**Menguji dari server.** WAF menolak User-Agent `curl`, sehingga `curl -sI`
menghasilkan 403 untuk apa pun — termasuk berkas yang sah dan berkas yang
tidak ada. Sertakan `-A` dengan User-Agent peramban, atau hasilnya
menyesatkan.

**Setelan PHP.** `php -i` di terminal cPanel menampilkan setelan **CLI**, bukan
setelan web — CLI `upload_max_filesize` 2M, web dulu 100M. Membaca `php -i`
lalu menyimpulkan batas web sudah pernah terjadi di sini, dan arah
kekeliruannya berbahaya: ia membuat masalah tampak jauh lebih kecil daripada
yang sebenarnya.

Kedua situs **tidak memakai PHP yang sama**, dan setelannya diatur di tempat
yang berbeda:

| Situs | PHP yang dipakai | Setelan yang berlaku ada di |
|---|---|---|
| `api.smkt.alhasan.co.id` | `ea-php80` | MultiPHP INI Editor → `php.ini` di akar situs |
| `smkt.alhasan.co.id/classync/` | **`alt-php83`** (PHP Selector CloudLinux, SAPI `litespeed`) | blok `php_value` di **`classync/.htaccess`** |

MultiPHP INI Editor untuk domain `smkt.alhasan.co.id` mengatur `ea-php81` dan
**tidak menjangkau classync sama sekali**. `.user.ini` juga tidak diperlukan:
pernah dicoba, lalu dihapus, dan nilainya tidak berubah. Blok `php_value` di
`classync/.htaccess` dibuat cPanel dan bisa ditulis ulang kalau setelan PHP
folder itu disimpan lagi dari antarmukanya — kalau batasnya suatu saat kembali
100M, periksa di sana lebih dulu. `.htaccess` itu hanya ada di server;
`.cpanel.yml` tidak menyalinnya.

**Satu-satunya cara yang terbukti menunjukkan nilai yang sungguhan berlaku**:
berkas PHP sementara bernama acak yang mencetak `ini_get()` dan
`php_ini_loaded_file()`, dibuka lewat `curl -A "Mozilla/5.0"`, lalu langsung
dihapus. Uji di tiap folder yang penting (`classync/`, `classync/admin/`,
`classync/api/`), bukan hanya di akarnya.

`/usr/bin/php` di server ini adalah **`php-cgi`** (SAPI `cgi-fcgi`), bukan
PHP CLI — ia bahkan tidak mengenal opsi `-r`. Cron `kirim_notifikasi_harian.php`
memanggil biner itu. Jadi penjaga "hanya dari cron" **tidak boleh** memeriksa
`php_sapi_name() === 'cli'`; periksa `isset($_SERVER['REQUEST_METHOD'])`, yang
selalu ada lewat HTTP dan tidak pernah ada dari cron. Pemeriksaan SAPI pernah
dipasang di sini dan mematikan pengingat pagi seluruh guru, tanpa satu baris
log pun.

## Tiga repositori yang bekerja bersama

| Folder | Isi | Deploy |
|---|---|---|
| `~/Documents/GitHub/classync` | panel admin & web | cPanel Git → `smkt.alhasan.co.id/classync` |
| `~/Documents/GitHub/api.smkt.alhasan.co.id` | 73 endpoint untuk aplikasi | cPanel Git → `api.smkt.alhasan.co.id` |
| `~/ClassyncApp` | aplikasi React Native/Expo | rilis Play Store & App Store; OTA sejak 3.0.0 |

**OTA baru ada sejak ClassyncApp 3.0.0**, yang dirilis 27 September 2026 dan
memasang `expo-updates`. Perbaikan JavaScript untuk pemakai 3.0 bisa dikirim
lewat `eas update` tanpa rilis toko; cara dan batasnya ada di `CLAUDE.md`
ClassyncApp. Aturan kompatibel mundur tidak berubah:

- versi 2.9.2 ke bawah tidak punya OTA. Untuk mereka, perubahan backend yang
  memutus kontrak JSON tetap hanya bisa diperbaiki lewat rilis toko,
  berminggu-minggu;
- OTA hanya sampai ke build berversi sama (`runtimeVersion` berpolicy
  `appVersion`), dan perubahan modul native tetap butuh build toko.

Sebaliknya, perbaikan backend berlaku seketika.

Kalimat "Tidak ada OTA" bertahan di sini sampai 6 Oktober 2026, sembilan hari
setelah 3.0.0 rilis: dokumen ini tidak diperbarui saat rilisnya.

Semua unggahan foto dari **kedua** situs bermuara di `classync/uploads/` —
empat endpoint di repo API menulis ke sana dengan jalur absolut.

## Yang TIDAK ada di repositori ini

| Folder | Isi | Kenapa |
|---|---|---|
| `uploads/` | 1,8 GB, 4.386 foto absensi | Data produksi |
| `vendor/` | Campuran composer + zip manual | Lihat peringatan di bawah |
| `lib/tcpdf/` | TCPDF, dipasang manual | Bukan composer |
| `admin/vendor/` | google/auth + firebase/php-jwt | Pohon terpisah |
| `admin/PhpOffice/` | PhpSpreadsheet, ekstrak manual | Bukan composer |
| `api-wa/` | Gateway WhatsApp Baileys | Berisi sesi hidup `auth_info_baileys/` |

### PERINGATAN: jangan jalankan `composer install` di sini

`vendor/` bukan hasil composer murni. Ada 14 folder pustaka, tapi
`vendor/composer/installed.json` hanya mencatat 9. Lima sisanya —
**phpoffice/phpspreadsheet, ezyang/htmlpurifier, markbaker, myclabs, maennchen** —
dimasukkan manual dengan mengekstrak zip.

Membuat `composer.json` lalu menjalankan `composer install` akan menghasilkan
`vendor/` **tanpa PhpSpreadsheet**, dan semua ekspor Excel mati. Migrasi ke
composer yang benar adalah tugas tersendiri yang butuh pengujian — bukan
pekerjaan sambil lalu.

## Kredensial database

Sudah dipindahkan keluar dari kode (September 2026). Enam berkas yang dulu
memuat password kini memanggil satu berkas di luar webroot:

    /DATA/k1807225/config/db-classync.php

Berkas itu mendefinisikan `$db_host`, `$db_user`, `$db_pass`, `$db_name`.
Yang memanggilnya: `includes/db.php`, `api/db.php`,
`api/update_absen_harian.php`, `api/delete_absen_harian.php`,
`admin/admin_notifikasi.php`, dan `includes/db.php` di repo API.

Polanya selalu `is_readable()` dulu, karena `require` yang gagal itu fatal
error — bukan Exception yang bisa ditangkap:

```php
$config_db = '/DATA/k1807225/config/db-classync.php';
if (!is_readable($config_db)) { /* pesan galat sesuai konteks berkas */ }
require $config_db;
```

Bentuk pesan galatnya **berbeda per berkas dan itu disengaja**: halaman HTML
memakai `die()`, endpoint JSON memakai bentuk yang sudah dipakai berkas itu
sendiri. Jangan diseragamkan — aplikasi versi lama membaca bentuk tertentu.

**Jangan menaruh kredensial di dalam kode, dalam keadaan apa pun.** Riwayat
Git kedua repositori publik dan permanen.

### Rotasi password

Pengguna `k1807225_user_absensi` sudah diganti `k1807225_absensi_2026`
(September 2026). Prosedurnya kalau perlu diulang: buat pengguna DB **baru**
di cPanel, ubah hanya berkas konfigurasi di server, uji, amati beberapa hari,
baru hapus pengguna lama dari bagian **Current Users** — bukan hanya mencabut
haknya di kolom Privileged Users. Jangan mengganti password pengguna yang
sedang dipakai; sistem mati seketika.

Karena seluruh kode membaca satu berkas di luar webroot, rotasi tidak lagi
memerlukan deploy kode sama sekali.

## Notifikasi: dua jalur dan tiga bentuk token

Ada **dua** jalur pengiriman yang terpisah, dan keduanya mudah dikira satu:

| Jalur | Berkas pengirim | Lewat |
|---|---|---|
| proksi | `admin/approval_absensi.php`, `proses_approval_absensi.php` | `send_fcm_api.php` |
| langsung | `kirim_notifikasi_harian.php`, `admin_notifikasi.php` (dua repo) | `fcm.googleapis.com` |

Perbaikan pada `send_fcm_api.php` **tidak** menyentuh jalur kedua. Justru jalur
kedua yang pengirim terbesar: seluruh guru, setiap hari.

ClassyncApp memanggil `getDevicePushTokenAsync()`, yang mengembalikan token
**asli platform** — bukan satu bentuk seragam:

| Bentuk | Asal | Tujuan yang benar |
|---|---|---|
| mengandung `:`, ~142 karakter | Android | FCM v1 |
| heksadesimal murni, 64-160 karakter | iOS (APNs) | `api.push.apple.com` |
| `ExponentPushToken[...]`, 41 karakter | sisa sebelum 13 Jul 2026 | tidak ada — perlu daftar ulang |

Panjangnya saja bukan bukti. Token APNs dari iOS versi baru bisa 160 karakter,
dan itu pernah saya simpulkan sebagai token FCM — keliru. Pembedanya titik dua:
token FCM selalu memuatnya, token APNs tidak pernah.

Sensus 20 September 2026, dari 21 guru sungguhan: **8 FCM, 10 APNs, 2 Expo,
1 kosong.** Hanya delapan yang bisa dihubungi.

`error_log` ada **per situs**. Baris dari pemanggil ada di
`smkt.alhasan.co.id/classync/error_log`, sedangkan baris dari `send_fcm_api.php`
dan penolong APNs ada di `api.smkt.alhasan.co.id/error_log`. Mencari di situs
yang salah menghasilkan log kosong, dan log kosong gampang disalahartikan
sebagai "tidak terjadi apa-apa".

## Absensi siswa: kalender sekolah, status harian, dan aturan pulang

Sejak September 2026, absensi siswa dipindah bertahap ke sidik jari
(U.are.U 4500). Yang sudah berjalan adalah fondasinya, dikerjakan satu
berkas per langkah: kalender sekolah di PR #6–#10, lalu status harian dan
aturan pulang di PR #12–#16.

**Satu sumber jam sekolah.** `infoHariSekolah()` di
`includes/kalender_sekolah.php` menjawab "tanggal ini masuk sekolah atau tidak,
dan pulang jam berapa". Urutannya:

1. Pulang Cepat di tabel `kalender_sekolah`;
2. `jam_pulang_jumat` untuk hari Jumat;
3. `jam_pulang` untuk hari lain.

Minggu dan tanggal Libur tidak masuk sekolah. Kalender diisi admin/TU di
`admin/kalender_sekolah.php` (menu Jadwal). Kiosk, cron sore, izin pulang, dan
aturan pulang semuanya memakai fungsi ini, jadi jangan menghitung ulang
aturannya di tempat lain. Arti kunci `jam_pulang` sengaja tidak diubah
(Senin–Kamis dan Sabtu), karena `get_monitoring_absensi.php` di repo API masih
membacanya apa adanya.

**Status harian.** `status_masuk` hanya mencatat kedatangan, dan sengaja tidak
diubah karena `monitoring_siswa.tsx` membandingkannya persis. Kehadiran final
disimpan di kolom `absensi_siswa.status_harian`:

| Keadaan baris | `status_harian` |
|---|---|
| absen masuk dan absen pulang | Hadir |
| absen masuk tanpa absen pulang | Pulang Lebih Awal |
| izin pulang dari guru piket | Izin |
| Sakit/Izin/Alpa (juga ejaan lama Alpha) dari absen manual | sama |
| siswa yang punya baris `penempatan_pkl` | tidak disentuh |

Penggolongannya ada di `includes/status_harian.php`, dan dijalankan oleh
`cron_status_harian.php` lewat cron cPanel setiap hari pukul 17.00:

    0 17 * * * /usr/bin/php /DATA/k1807225/public_html/smkt.alhasan.co.id/classync/cron_status_harian.php >/dev/null 2>&1

- **Bukti cron hidup ada di tabel `log_status_harian`.**
  - Setiap jalan tercatat di sana, termasuk Minggu, Libur, dan jalan sebelum
    jam pulang (hasilnya `dilewati`). Dari tabel itu juga terlihat apakah jam
    cron-nya benar.
  - Jalan manual dari Terminal juga tercatat berpemicu `cron`, jadi bedakan
    lewat jamnya.
  - Galat cron masuk ke `classync/error_log`.
- **Dihitung ulang setiap kali jalan,** jadi aman diulang. Hanya satu nilai
  yang dipertahankan: Izin pada baris yang punya absen masuk. Penggolongan
  sendiri tidak pernah menghasilkan nilai itu, jadi Izin di sana pasti izin
  pulang.
- **Tidak menambah baris.** Siswa yang sama sekali tidak absen dibiarkan
  sampai Alpa otomatis dibuat.
- **Tanpa `FOR UPDATE`.** Kolom `tanggal` tidak berindeks sendiri, jadi InnoDB
  akan mengunci seluruh tabel dan menahan absen di kiosk.
  - Gantinya, `UPDATE` hanya berlaku kalau isi baris masih sama dengan yang
    dibaca.
  - MariaDB 11.6 ke atas (`innodb_snapshot_isolation`) menjawab keadaan yang
    sama dengan galat 1020. Kodenya menangani galat itu per baris; produksi
    memakai 10.6.
  - MariaDB lokal yang lebih baru menyalakan pengaturan itu secara bawaan.
    Matikan saat menguji kalau ingin meniru produksi.
- **Siswa PKL dan alumni.**
  - Siswa PKL berarti siswa yang punya baris di `penempatan_pkl`. Tabel itu
    belum bertanggal, jadi TU harus menghapus penempatannya setelah PKL
    selesai; kalau tidak, siswa itu terus dikecualikan. Aturan ini hanya ada
    di `daftarSiswaPkl()`.
  - Alumni berarti `kelas = 'Lulus / Alumni'`, yang diisi
    `admin/mutasi_siswa.php`.
- **Halaman pantau** ada di `admin/status_harian.php` (Laporan → Status Harian
  Siswa). Isinya jumlah per golongan, daftar Pulang Lebih Awal, tren absen
  pulang 14 hari sekolah, dan tombol Hitung ulang.

**Aturan pulang, berlaku mulai 29 September 2026.** `api/proses_absen_siswa.php`
dipakai kiosk dan menu Absen Siswa di aplikasi. Endpoint ini menolak absen
pulang dalam tiga keadaan:

- sebelum jam pulang hari itu;
- siswa belum absen masuk;
- siswa sudah diberi izin pulang.

Penolakan dikirim sebagai `message` biasa, dan aplikasi versi lama
menampilkannya apa adanya.

- Siswa yang harus pulang lebih awal dicatat guru piket di kartu Izin Pulang
  Lebih Awal (`absen_manual.php`). Akibatnya:
  - `status_harian` menjadi Izin;
  - tercatat di `log_absen_manual` dengan status `Izin Pulang`;
  - orang tua menerima WA.
- Absen pulang setelah cron sore mengubah Pulang Lebih Awal menjadi Hadir.
- Kiosk memilih mode PULANG sendiri mulai jam pulang. Dulu halaman dimuat
  ulang setelah setiap scan dan selalu kembali ke MASUK. Itu kemungkinan
  besar salah satu sebab hanya 16% absen masuk di Agustus 2026 yang diikuti
  absen pulang.

**Mode senyap.** Belum ada WA untuk Pulang Lebih Awal, dan selain halaman
pantau belum ada laporan yang membaca `status_harian`. Yang masih menunggu:

- **Alpa otomatis pukul 09.00.**
  - Harus mengecualikan siswa PKL dan alumni.
  - Penolakan "Siswa sudah melakukan absensi hari ini" di
    `api/proses_absen_manual.php` harus diubah bersamaan, supaya guru piket
    bisa mengoreksi Alpa otomatis.
- **WA Pulang Lebih Awal,** setelah tren absen pulang di atas 90%, dengan rem
  darurat. Kalau TU lupa mengisi Pulang Cepat, semua absen pulang hari itu
  tertolak. Rem itulah yang mencegah WA massal yang keliru.
- **Sebelas berkas laporan pindah ke `status_harian`,** satu per satu,
  termasuk dua di repo API. Tanggal sebelum aturan ini berlaku tetap memakai
  logika lama.
- **Sidik jarinya sendiri.** Fondasinya sudah ada, lihat "Absensi sidik jari:
  jembatan, tantangan, dan detak kiosk" di bawah. Pendaftaran jari dan absen
  lewat sidik jari belum dibuka.

Terverifikasi di produksi 28 September 2026:

- cron yang dijalankan manual menggolongkan 26 baris;
- halaman pantau cocok dengan log kiosk: 6 Hadir, 20 Pulang Lebih Awal, dan
  10 PKL dari 36 baris;
- Hitung ulang oleh admin tercatat;
- URL cron menjawab 403.

Aturan pulang di kiosk belum teruji di produksi saat catatan ini ditulis.

Tabel dan kolomnya dibuat manual di phpMyAdmin, jadi tidak ada di repo:

    CREATE TABLE kalender_sekolah (
      tanggal DATE NOT NULL, jenis ENUM('Libur','Pulang Cepat') NOT NULL,
      jam_pulang TIME NULL, keterangan VARCHAR(150) NOT NULL,
      admin_id INT NOT NULL, diubah_pada DATETIME NOT NULL,
      PRIMARY KEY (tanggal),
      CONSTRAINT jam_sesuai_jenis CHECK ((jenis = 'Libur' AND jam_pulang IS NULL)
                                      OR (jenis = 'Pulang Cepat' AND jam_pulang IS NOT NULL)));
    ALTER TABLE absensi_siswa ADD COLUMN status_harian
      ENUM('Hadir','Pulang Lebih Awal','Sakit','Izin','Alpa') NULL DEFAULT NULL;
    CREATE TABLE log_status_harian (
      id INT NOT NULL AUTO_INCREMENT, tanggal DATE NOT NULL, dijalankan DATETIME NOT NULL,
      pemicu ENUM('cron','admin') NOT NULL, admin_id INT NULL,
      hasil ENUM('selesai','dilewati') NOT NULL, keterangan VARCHAR(255) NOT NULL DEFAULT '',
      hadir INT NOT NULL DEFAULT 0, pulang_awal INT NOT NULL DEFAULT 0, izin INT NOT NULL DEFAULT 0,
      sakit INT NOT NULL DEFAULT 0, alpa INT NOT NULL DEFAULT 0, pkl INT NOT NULL DEFAULT 0,
      PRIMARY KEY (id), KEY tanggal (tanggal));
    -- ditambah satu baris pengaturan: jam_pulang_jumat = '10:50:00'

## Absensi sidik jari: jembatan, tantangan, dan detak kiosk

Pekerjaan sidik jarinya sendiri dibagi lima sub-langkah, dan nomornya dipakai
di README jembatan dan di kode: 4.1 prototipe jembatan, 4.2 fondasi server,
4.3 pendaftaran jari, 4.4 kiosk berdampingan dengan QR/NISN, dan 4.5
peralihan. Yang sudah ada di repo adalah 4.1 (PR #18–#22) dan 4.2: sisi
server di PR #23, jembatan 0.2.0 di PR #24, dan detak dari halaman kiosk di
PR #25.

**Absen lewat sidik jari belum dibuka.** Alur QR/NISN di `absen-siswa.php`
dan `api/proses_absen_siswa.php` tidak berubah. Yang ada baru detak: bukti
bahwa halaman kiosk, jembatan, dan server saling mengenali.

**Tiga bagian, dan jembatan tidak ikut deploy.**

| Bagian | Berkas | Sampai ke tempatnya lewat |
|---|---|---|
| jembatan | `jembatan/`, layanan Windows di PC kiosk yang hanya mendengar di `127.0.0.1:47890` | `.exe` yang dibangun di Mac lalu disalin lewat USB; `.cpanel.yml` tidak menyalin folder itu |
| server | `includes/sidik_jari.php`, `api/sj_tantangan.php`, `api/sj_detak.php`, `admin/kiosk_sidik_jari.php` | deploy biasa |
| halaman kiosk | `includes/sj_detak_klien.php`, di-include `absen-siswa.php` | deploy biasa |

Jembatan mencocokkan sidik jari dan menandatangani hasilnya dengan HMAC,
memakai kunci per kiosk. Ia tidak pernah menghubungi server sendiri: halaman
yang membawa tantangan dari server ke jembatan, lalu membawa tanda tangannya
kembali.

Cara membangun, memasang, memasangkan, dan menguji jembatan ada di
`jembatan/README.md`, beserta hasil ukur 3 dan 5 Oktober 2026. Jangan
disalin ke sini. Tiga hal dari sana yang mudah terlewat:

- `hapus-layanan.ps1 -HapusData` menghapus kunci yang dikenal server. Kiosk
  harus dipasangkan ulang, dan semua jari didaftarkan ulang.
- Mengganti driver pembaca atau versi SourceAFIS juga membuat semua jari
  harus didaftarkan ulang.
- Selain halamannya sendiri, jembatan hanya menerima asal
  `https://smkt.alhasan.co.id`. Menguji rantainya dengan server lokal butuh
  `--pengembangan --asal-kiosk http://127.0.0.1:<port>`, yang ditolak
  layanan di PC kiosk.

**Kunci ada di luar webroot**, di folder yang sama dengan `db-classync.php`
dan `fcm-classync.php`:

    /DATA/k1807225/config/sidik-jari-classync.php

```php
$sj_rahasia_tantangan = ['<64 karakter hex>'];
$sj_perangkat = ['kiosk-xxxxxx' => ['aktif' => true, 'kunci' => ['<64 karakter hex>']]];
```

- **Keduanya berupa daftar,** supaya bisa dirotasi tanpa deploy: tambahkan
  yang baru, alihkan, lalu cabut yang lama. Rahasia pertama dipakai
  menerbitkan tantangan, dan semua yang terdaftar diterima.
- **Rahasia tantangan** dibuat di Terminal cPanel dengan
  `openssl rand -hex 32`.
- **ID dan kunci perangkat dibuat jembatan sendiri.** Kuncinya hanya keluar
  lewat `jembatan-sidik-jari.exe --pasangan` di PowerShell admin PC kiosk,
  bersama blok yang tinggal ditempel ke `$sj_perangkat`. Langkahnya ada di
  README jembatan, "Memasang di PC kiosk dan memasangkannya dengan server".
- **Perubahan berkas ini berlaku seketika.** Mengosongkan `$sj_perangkat`,
  atau mengubah `'aktif'` menjadi `false`, mencabut kiosk tanpa deploy.
- **Yang boleh dibagikan hanya sidik kunci:** delapan karakter pertama
  SHA-256 atas byte kunci. Jembatan menampilkannya di `/status` dan
  `--pasangan`, server di halaman pantau. Sidik yang sama berarti kunci yang
  sama. Kunci dan rahasia itu sendiri tidak boleh masuk Git, PR, atau
  percakapan.

**Tantangan dan detak.** Sekali semenit halaman kiosk menjalankan satu
rantai:

1. `GET /status` ke jembatan, untuk mendapat ID perangkatnya;
2. `POST api/sj_tantangan.php`, yang menerbitkan tantangan untuk perangkat
   itu;
3. `POST /detak` ke jembatan, yang menandatangani
   `SJ1|detak|<perangkat>|<tantangan>|alat:<0 atau 1>`;
4. `POST api/sj_detak.php`, yang menyusun ulang pesan itu sendiri,
   mencocokkan HMAC-nya, lalu mencatat satu baris di `detak_kiosk`.

Detak yang sampai membuktikan seluruh rantainya hidup: halaman kiosk,
jembatan, dan kunci yang sama di kedua sisi.

- **Kedua endpoint terbuka untuk umum,** karena halaman kiosk tidak punya
  sesi. Penjaganya kunci perangkat, lewat dua hal di bawah. Jangan
  menambahkan tulisan ke basis data atau ke `error_log` sebelum tanda tangan
  perangkat terbukti sah: orang luar bisa memakainya untuk memenuhi tabel
  atau log.
  - Tantangan tidak disimpan saat diterbitkan. Isinya waktu terbit, byte
    acak, dan tanda dari rahasia server.
  - Basis data baru dibuka setelah tanda tangan perangkat terbukti sah.
- **Tantangan sekali pakai.** Berlaku 120 detik, dan terikat pada tujuan dan
  perangkatnya. Kunci utama `tantangan_kiosk` yang menolak pemakaian kedua,
  termasuk dari kiriman serentak.
- **Tujuan yang dibuka baru `detak`.** Pesan `SJ1|absen|…` sudah dikenal
  jembatan dan pustaka, tetapi belum ada endpoint yang menerimanya.
- **Kolom `alat` adalah keadaan pembaca menurut Windows,** bukan menurut ADC
  yang dipakai halaman untuk menangkap sidik jari. Pada uji 5 Oktober pembaca
  hilang dari ADC selagi PC ditinggal, sementara detak tetap melaporkan alat
  terpasang. Jadi `alat = 1` belum membuktikan kiosk bisa menangkap jari.
- **Masalah konfigurasi tampil di halaman pantau, bukan di `error_log`.**
  Yang masuk ke `classync/error_log` hanya galat server yang tidak bisa
  dipicu dari luar: `[sj_detak]` untuk galat basis data setelah tanda tangan
  sah, dan `[sj_tantangan]` kalau tantangan tidak bisa dibuat.
- **Kedua endpoint aman dibuka lewat URL.** Permintaan selain POST dijawab
  405 sebelum apa pun dibaca.

**Detak dari halaman kiosk** ada di `includes/sj_detak_klien.php`.

- **Senyap.** Tidak ada yang tampil di halaman kiosk. Kegagalannya hanya
  dicatat di konsol peramban, berawalan `[detak kiosk]`.
- **Hanya dikeluarkan kalau server punya perangkat aktif yang berkunci.**
  Sebelum itu keluaran `absen-siswa.php` sama byte demi byte dengan sebelum
  skrip ini ada. Jadi mengosongkan `$sj_perangkat` juga mematikan detak.
- **Hanya berjalan di peramban kiosk.** Halaman kiosk terbuka untuk umum, dan
  di luar PC kiosk permintaan ke `127.0.0.1` membuat Chrome memunculkan
  permintaan izin jaringan lokal. Skripnya baru menyentuh `127.0.0.1` kalau
  salah satu ini benar:
  - izin `loopback-network` untuk situs ini berstatus `granted`. Di PC kiosk
    izin itu datang dari kebijakan Chrome, `jembatan/kebijakan-chrome.reg`;
  - peramban itu ditandai sebagai kiosk. `absen-siswa.php?detak=hidup`
    memasang tandanya, dan `?detak=mati` mencabutnya. Tandanya tersimpan di
    `localStorage`, jadi berlaku per profil Chrome dan ikut hilang kalau data
    situs dihapus. Penanda ini hanya untuk peramban tanpa kebijakan itu; PC
    kiosk tidak memerlukannya.
- **Jadwalnya juga disimpan di `localStorage`.** Halaman kiosk memuat ulang
  dirinya 2 detik setelah tiap scan berhasil. Pewaktu biasa akan terulang
  dari nol pada jam sibuk, dan kiosk tampak diam justru saat paling ramai.
- **Galat di berkas ini tidak boleh merusak halaman kiosk.**
  `absen-siswa.php` meng-include-nya setelah `is_readable()`, di dalam
  `try`/`catch`.

**Halaman pantau** ada di `admin/kiosk_sidik_jari.php` (Laporan → Kiosk Sidik
Jari).

- **"Hidup" berarti detak terakhir belum lewat 3 menit.** Detak hanya ada
  selama halaman kiosk terbuka di Chrome PC kiosk. PC yang mati dan halaman
  kiosk yang tertutup sama-sama terbaca "diam".
- **Ringkasan 7 hari per perangkat:** detak pertama dan terakhir, jumlah
  detak, jeda di atas 3 menit, dan detak tanpa alat. Jeda dihitung di antara
  dua detak pada hari yang sama. Jadi halaman kiosk yang ditutup lalu dibuka
  lagi hari itu tampil sebagai jeda, sedangkan malam hari tidak.
- **Tombol Uji rantai** menjalankan satu detak dari peramban yang membuka
  halaman itu. Jembatan hanya mendengar di `127.0.0.1`, jadi uji ini hanya
  berhasil di PC kiosk. Dari komputer lain ia gagal di baris pertama, dan itu
  bukan tanda kiosk rusak.

Kalau kiosk "diam" padahal halamannya terbuka, periksa berurutan:

1. bagian atas halaman pantau, tempat masalah konfigurasi dan tabel yang
   belum dibuat ditampilkan;
2. **Uji rantai** dari PC kiosk. Baris yang merah menunjukkan bagian yang
   bermasalah;
3. konsol halaman kiosk (F12). Baris `[detak kiosk] aktif lewat izin` atau
   `[detak kiosk] aktif lewat penanda` harus ada, dan baris peringatan
   `[detak kiosk]` menyebut bagian yang gagal. Kalau tidak ada satu pun,
   peramban itu belum melewati gerbangnya: buka
   `absen-siswa.php?detak=hidup` sekali.

Terverifikasi di produksi 6 Oktober 2026, di PC kiosk dengan jembatan 0.2.0:

- halaman pantau menampilkan kiosknya "aktif" dan "hidup", alat terpasang,
  tanpa peringatan konfigurasi;
- Uji rantai lulus dengan semua baris hijau, dan detaknya tercatat;
- layanan jembatan menyala sendiri setelah PC di-restart;
- halaman kiosk berdetak sendiri semenit sekali, dan konsolnya menulis
  `[detak kiosk] aktif lewat izin`;
- izin dari kebijakan Chrome terbaca `granted`, di halaman kiosk maupun di
  halaman pantau. Dokumentasi Chrome tidak menyebut hal ini. Memeriksanya
  di konsol:
  `navigator.permissions.query({name: 'loopback-network'}).then(p => console.log(p.state), console.error)`

Belum teruji:

- **Detak sepanjang jam sekolah,** termasuk jam sibuk dengan scan sungguhan.
  Buktinya ada di ringkasan 7 hari mulai 7 Oktober 2026. Jeda selagi halaman
  kiosk terbuka perlu diselidiki.
- **Tiga pemeriksaan akun standar** di fase C README jembatan. Hasilnya
  belum dilaporkan.

Yang masih menunggu sesudah 4.2:

- **4.3, pendaftaran jari.** Uji 5 Oktober memenuhi kriteria aman dan waktu,
  tetapi pengenalan pada tempelan pertama baru 82,8% dari syarat 90%.
  Syarat perbaikannya ada di README jembatan, "Kriteria lanjut ke 4.2":
  gerbang mutu saat mendaftar, dua jari terbaik per orang, dan aturan
  keserasian yang lebih ketat.
- **4.4, kiosk berdampingan dengan QR/NISN.** Layar kiosk tidak boleh mati,
  detak memuat keadaan pembaca menurut ADC, dan tingkat pengenalan diukur
  ulang dengan siswa.
- **4.5, peralihan.**

Kedua tabelnya dibuat manual di phpMyAdmin, jadi tidak ada di repo. Keduanya
membersihkan diri sendiri: `tantangan_kiosk` hanya menyimpan tantangan yang
sudah dipakai, selama sehari, dan `detak_kiosk` satu baris per detak selama
60 hari.

    CREATE TABLE tantangan_kiosk (
      tantangan CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      tujuan VARCHAR(16) NOT NULL, perangkat VARCHAR(32) NOT NULL,
      dipakai DATETIME NOT NULL,
      PRIMARY KEY (tantangan), KEY dipakai (dipakai));
    CREATE TABLE detak_kiosk (
      id INT NOT NULL AUTO_INCREMENT, waktu DATETIME NOT NULL,
      perangkat VARCHAR(32) NOT NULL, alat TINYINT NOT NULL,
      versi VARCHAR(64) NOT NULL DEFAULT '',
      PRIMARY KEY (id), KEY perangkat_waktu (perangkat, waktu));

## Temuan audit

Daftar ini ditinjau audit independen pada 14 September 2026, di luar pekerjaan
yang menghasilkannya. Audit itu menemukan tiga temuan yang tidak pernah
terdaftar sebelumnya — dua di antaranya Kritis — dan mengoreksi tiga klaim
yang sempat tertulis di dokumen ini sebagai fakta. Koreksinya dicatat di butir
masing-masing. Pelajarannya: klaim keamanan yang tidak diuji langsung cenderung
terlalu optimis, terutama tentang perilaku mod_mime dan konteks JavaScript.

### Masih terbuka

- **Kritis** — endpoint absen di repo API tidak menuntut autentikasi sama
  sekali. Siapa pun yang mengirim `guru_id`, `jadwal_id`, dan satu gambar sah
  ke `proses_absen_mengajar.php`, `proses_absen_sederhana.php`, atau
  `proses_absen_bk.php` akan tercatat hadir — dan baris itu ikut dihitung
  sebagai honor. `proses_absen_sederhana.php` bahkan tidak memastikan jadwal
  yang dikirim milik guru tersebut. Ini proyek migrasi token empat fase yang
  dikunci di `CLAUDE.md` repo API; jangan menegakkan autentikasi tanpa
  melewati keempat fasenya, karena aplikasi versi lama akan mati.
- **Sedang** — notifikasi iOS berjalan lewat penanganan sementara. Sejak
  13 Juli 2026 ClassyncApp memakai `getDevicePushTokenAsync()`, yang di iOS
  mengembalikan token APNs — dan token APNs tidak akan pernah diterima FCM v1.
  Selama dua bulan sepuluh dari 21 guru tidak menerima notifikasi apa pun,
  tanpa gejala, karena respons FCM tidak pernah diperiksa; baru terlihat
  setelah commit `4958e4d` mencatatnya ke log. Semula berbobot Kritis.

  **Tertangani** oleh `includes/pengirim_apns.php` di repo API (`196f452`,
  `181d5d4`, lalu `dd7774b` untuk pengingat harian): server memilah menurut
  bentuk token dan mengirim token APNs langsung ke Apple. **Pengantarannya**
  terverifikasi di produksi 21 September 2026 di iPhone: notifikasi berbunyi,
  jalur production berhasil pada percobaan pertama, dan fallback ke sandbox
  belum pernah terpakai.

  **Tautan-dalam sempat salah dicatat terbukti.** Catatan sebelumnya di sini
  menyatakan sudah, dan itu keliru: uji pertama dilakukan saat aplikasi kebetulan sudah
  terbuka di halaman tujuannya sendiri. Dari halaman lain, menekan notifikasi
  tidak berpindah. Penyebabnya, untuk notifikasi jarak jauh `expo-notifications`
  mengisi `content.data` **hanya dari kunci `body`** (`NotificationRecords.swift`,
  `serializedNotificationData()`), sedangkan payload menaruh `screen` di tingkat
  atas. Diperbaiki commit `2a4d1a5`, dan **terverifikasi 22 September 2026**
  di iPhone dengan aplikasi berjalan di latar dan terbuka di halaman lain:
  menekan notifikasi membuka riwayat pengajuan. Tautan-dalam di **Android**
  belum pernah diuji — jalurnya berbeda, lewat `data` di payload FCM.

  Peluncuran dari keadaan mati (aplikasi dihapus dari latar) tetap tidak
  berpindah halaman walau payload-nya benar: `_layout.tsx` hanya memakai
  `addNotificationResponseReceivedListener`, tanpa
  `getLastNotificationResponseAsync()`, dan `initialize()` menimpa navigasi
  dengan `router.replace('/(tabs)/dashboard')` 50 ms setelah mulai. Perlu
  rilis aplikasi.

  Yang membuatnya masih terbuka ada dua. Pertama, ini penanganan sementara:
  penyelesaian permanennya **putusan A/B** di aplikasi — (A) SDK Firebase iOS
  supaya tokennya FCM sejati, atau (B) kembali ke Expo Push yang menangani
  kedua platform. Tidak mendesak; setelah salah satunya rilis,
  `includes/pengirim_apns.php` **tinggal dicabut seluruhnya** — ia memang
  ditulis untuk dibuang. Kedua, dari tiga guru yang tokennya tidak bisa
  dipakai (dua bertoken Expo, satu kosong), baru guru 17 yang terbukti sudah
  mendaftar ulang lewat 2.9.2 — pengingatnya terkirim 22 September. Guru 18
  (Expo) dan guru 22 (kosong) akan ikut begitu mereka membuka 2.9.2; periksa
  bentuk `push_token` mereka di tabel `guru` — cara membedakannya ada di
  bagian "Notifikasi" di atas.
- **Rendah** — cabang galat `->error` setelah `execute()` di panel admin
  kemungkinan tidak pernah tercapai. classync berjalan di `alt-php83`, dan
  sejak PHP 8.1 bawaan `mysqli_report` adalah `ERROR | STRICT`;
  `includes/db.php` dan `admin/partials/header.php` tidak mengubahnya.
  Akibatnya `INSERT` yang gagal melempar `mysqli_sql_exception` yang tidak
  tertangkap, dan admin melihat halaman kosong atau 500 — bukan pesan
  "Gagal menyimpan: …" yang ditulis kodenya. Tidak ada data yang salah
  tersimpan; yang hilang pesan galatnya. **Dugaan dari kode, belum diuji**,
  dan belum disapu ke halaman admin lain. Ditemukan di
  `admin/absensi_manual.php:76`.
- **Sedang** — login tanpa `session_regenerate_id(true)`, tanpa pembatasan
  percobaan, tanpa token CSRF di form admin. Ketiganya sudah ada di
  `login_piket.php` dan `includes/sesi_piket.php` (commit `a81308f`,
  `5a39cea`), termasuk penguncian per NIK yang atomik — pola yang bisa
  ditiru saat login admin dibenahi.
- **Sedang** — 56 berkas membuka koneksi database sendiri padahal `db.php`
  sudah menyediakan `$conn`.
- **Rendah** — blok `catch` di keempat endpoint unggah repo API mengirim
  `$e->getMessage()` mentah ke aplikasi. Pesannya biasanya pesan aplikasi yang
  berguna bagi guru ("Foto bukti wajib diupload"), tapi eksepsi basis data
  bocor lewat jalur yang sama. Memperbaikinya berarti memisahkan eksepsi
  aplikasi dari eksepsi sistem.
- **Rendah** — `save_token.php` di repo API tampaknya kode mati: ia menulis ke
  kolom `expo_push_token`, bukan `push_token`, dan tidak ada satu pun layar di
  ClassyncApp yang memanggilnya. Tanpa autentikasi, jadi siapa pun bisa menimpa
  kolom itu untuk `guru_id` mana pun. Layak dihapus setelah dipastikan.
- **Rendah** — `app/absen_hp_backup.tsx:115` dan `:167` di ClassyncApp masih
  memakai `toISOString()`. Namanya terdengar seperti berkas cadangan, tapi di
  Expo Router **setiap berkas di `app/` adalah rute hidup** — `/absen_hp_backup`
  bisa dibuka. Generator `scripts/generate_absen_hp.py:238` dan `:279` juga
  dapat menghidupkan kembali pola yang sama.

### Sudah ditutup

- ~~Enam berkas lama di akar yang bisa mengubah data tanpa login~~ — commit
  `a394ea4`, `25dd809`, dan `dabfb43`. Keenamnya sudah tidak dipakai, tetapi
  `cp -f *.php` di `.cpanel.yml` menyalinnya ke webroot di setiap deploy.
  Tidak satu pun pernah tercatat di sini sebagai temuan:
  - `laporan_absensi_siswa.php` — laporan siswa versi lama. Bisa mengubah dan
    menghapus baris `absensi_siswa`, termasuk lewat `?hapus=` dengan GET.
    Penggantinya `admin/laporan_absensi_siswa.php`.
  - `hapus_foto_lama.php` — begitu URL-nya dibuka, skrip ini menghapus semua
    foto bukti absen guru yang lebih dari 30 hari dan mengosongkan
    `foto_bukti`.
    - Komentarnya menyebut cron, tetapi berkasnya tanpa penjaga.
    - Skrip ini tidak pernah berjalan: dump 1 September 2026 masih memuat path
      foto September 2025.
    - Berkasnya dihapus, bukan diberi penjaga, karena foto itu bukti honor.
  - `proses_absen.php` beserta `absen_mengajar.php`, `absen_piket.php`, dan
    `absen_ekskul.php` — formulir "Pilih Nama Anda". Formulir ini langsung
    mencatat absensi guru berstatus Hadir, tanpa foto dan tanpa approval,
    jadi ikut dibayar. Di dump tidak ada jejak pemakaiannya.

  Terverifikasi 28 September 2026: kelima alamat selain `hapus_foto_lama.php`
  menjawab 404, dan `admin/laporan_absensi_siswa.php` tetap 302 ke login.
  `hapus_foto_lama.php` sengaja tidak diuji lewat URL (lihat "Jangan
  lakukan").
- ~~`absen_manual.php` dan `api/proses_absen_manual.php` terbuka untuk
  umum~~ — commit `5a39cea`, `a81308f`, `57c3159`, dan `8069d33`, satu
  berkas per langkah. Halaman yang ditautkan dari sidebar kiosk ini bisa
  dipakai siapa pun tanpa login untuk mencatat Sakit/Izin/Alpa siswa mana
  pun, dan orang tuanya langsung menerima WA. Menutup halamannya saja tidak
  cukup, karena endpoint-nya bisa dipanggil langsung — keduanya kini dijaga.

  Yang boleh mencatat ada dua, ditentukan `pencatatAbsenManual()` di
  `includes/sesi_piket.php`: guru yang terjadwal piket **hari ini**
  (`jadwal_piket` Aktif) dan masuk lewat `login_piket.php`, atau admin yang
  sedang login di panel admin sebagai pengganti guru piket yang berhalangan.
  Kalau sesi admin tertinggal di peramban yang sama, guru piket didahulukan.
  Setiap catatan menyimpan pencatatnya di `log_absen_manual` (`guru_id` atau
  `admin_id`, beserta `cara_masuk`) dalam satu transaksi dengan `INSERT`
  absensinya: kalau log gagal ditulis, absensinya batal dan WA tidak
  terkirim. Endpoint juga menuntut token CSRF dari formulirnya, dan menjawab
  kiriman tanpa sesi dengan bentuk `sendResponse()` yang sama ditambah
  `perlu_masuk`.

  `login_piket.php` memakai NIK (kolom `guru.nip`, berlabel "NIK" di
  aplikasi) dan password aplikasi, diperiksa `password_verify()` seperti
  `login.php` di repo API; `auth_token` tidak disentuh. Halamannya terbuka
  untuk umum, jadi ia tidak boleh menjadi alat untuk menebak password
  aplikasi guru:

  - semua kegagalan memakai satu pesan yang sama;
  - percobaan dicatat secara atomik (`INSERT … ON DUPLICATE KEY UPDATE
    gagal = gagal + 1`) **sebelum** password diperiksa. Membaca hitungan
    lalu menulisnya kembali bisa dilewati dengan permintaan serentak —
    semuanya membaca angka yang sama;
  - percobaan keenam mengunci NIK itu 15 menit, dan selama terkunci
    password tidak diperiksa sama sekali;
  - NIK yang tidak terdaftar tetap melewati `password_verify()` terhadap
    hash tiruan cost 10, supaya lama respons tidak membedakannya.

  Sesi piket berkunci `piket_` sehingga Keluar tidak mengeluarkan admin,
  hanya sah pada tanggal yang sama, berakhir setelah 15 menit tanpa
  aktivitas, dan jadwal piketnya diperiksa ulang di setiap permintaan.

  Dua tabelnya dibuat manual di phpMyAdmin, jadi tidak ada di repo:

      CREATE TABLE percobaan_login_piket (
        nip VARCHAR(50) NOT NULL, gagal INT UNSIGNED NOT NULL DEFAULT 0,
        terkunci_sampai DATETIME NULL, terakhir DATETIME NOT NULL,
        PRIMARY KEY (nip));
      CREATE TABLE log_absen_manual (
        id INT NOT NULL AUTO_INCREMENT, waktu DATETIME NOT NULL,
        guru_id INT NULL, admin_id INT NULL, cara_masuk VARCHAR(20) NOT NULL,
        siswa_id INT NOT NULL, tanggal DATE NOT NULL,
        status VARCHAR(20) NOT NULL,
        PRIMARY KEY (id), KEY tanggal (tanggal), KEY guru_id (guru_id),
        CONSTRAINT ada_pencatat CHECK (guru_id IS NOT NULL OR admin_id IS NOT NULL));

  Membuka NIK yang terkunci sebelum waktunya:
  `DELETE FROM percobaan_login_piket WHERE nip = '…';`

  Terverifikasi di produksi 28 September 2026: dengan NIK akun uji,
  kegagalan ditolak dengan pesan umum, percobaan keenam dikunci sampai 15
  menit kemudian (`gagal` kembali 0) dan terbuka lewat `DELETE`, dan
  `error_log` mencatat `[login_piket] gagal masuk, percobaan ke-N`; guru
  piket hari itu masuk dan melihat namanya beserta tombol Keluar; admin
  yang login di panel admin melihat "Admin: …" tanpa tombol Keluar;
  `absen_manual.php` tanpa sesi dijawab 302 ke `login_piket.php`; catatan
  yang disimpan lewat halaman muncul di `log_absen_manual` beserta
  pencatatnya; dan endpoint yang dikirimi `siswa_id` 999999 tanpa sesi
  menjawab "Sesi Anda
  berakhir…" dengan `perlu_masuk`. `siswa_id` yang tidak ada itu sengaja:
  sebelum gerbangnya terpasang pun, uji itu tidak menyimpan baris atau
  mengirim WA.

  Sengaja tidak disentuh: pembanding password teks polos — tidak
  ditambahkan, jadi guru yang password-nya belum ber-hash harus di-reset
  lewat `admin/guru.php`; penolakan "Siswa sudah melakukan absensi hari
  ini", yang harus diubah bersamaan dengan Alpa otomatis supaya guru piket
  bisa mengoreksinya; `$e->getMessage()` di respons galat; dan atribut
  `data-nama`/`data-kelas` tanpa escape di `absen_manual.php`. Di PC kiosk,
  tawaran menyimpan password di peramban harus dimatikan, karena guru
  mengetik password aplikasinya di PC bersama.
- ~~Jalur unggah repo ini, fase 2 dan 3~~ — commit `2569f6f` (fase 2,
  `absensi_pkl.php`) dan `9c10504` (fase 3, `admin/siswa.php` dan kedua
  jalur `admin/absensi_manual.php`). Menutup pekerjaan empat fase yang
  memindahkan semua unggahan ke `includes/unggah_gambar.php`: ekstensi dari
  tipe yang terdeteksi `getimagesize()`, nama kiriman tidak masuk ke nama
  berkas, batas 8 MB, dan `hapusFotoLamaAman()` yang dipagari `realpath()`
  ke dalam `uploads/`. Fase 1 dan 4 sudah ditutup lebih dulu.

  Terverifikasi di produksi 25 September 2026: absen PKL tersimpan dan modal
  thumbnail-nya terbuka; ganti foto siswa tersimpan dan tampil; absensi
  manual dengan foto asli tersimpan dengan pola nama baru; berkas teks
  berekstensi `.jpg` ditolak dengan "Foto harus berupa gambar…" tanpa baris
  tersimpan. Atribut `accept="image/*"` di formulir **bukan** lapis
  pengaman — ia hanya menyaring pemilih berkas peramban, dan uji penolakan
  harus memakai berkas yang lolos saringan itu.

  Satu sisa fase 3 ikut dibereskan di `24ed697`: kolom foto jalur massal
  menawarkan `application/pdf`, padahal sejak `9c10504` server menolak
  apa pun selain gambar, sehingga admin yang memilih PDF kehilangan seluruh
  absensi massalnya. Pencarian `auto-*.pdf` di `uploads/` tidak menemukan
  satu pun, jadi PDF tidak pernah dipakai di jalur itu; `accept` cukup
  disamakan dengan input satuan.
- ~~Input satuan `admin/absensi_manual.php` tidak memeriksa jadwal di sisi
  server~~ — commit `2325e0a`. `guru_id`, `jadwal_id`, `tipe_absensi`, dan
  `waktu_absensi` disimpan langsung dari formulir; saringan
  `status_jadwal = 'Aktif'` hanya ada di dropdown. Jadwal guru lain, jadwal
  non-Aktif, atau jadwal Senin pada tanggal Selasa bisa tercatat dan dibayar.
  Kini jadwal harus Aktif, milik guru itu, dan harinya cocok dengan tanggal —
  sama dengan `approval_absensi.php` dan jalur massal. Tukar hari sengaja
  ditolak, konsisten dengan jalur approval. Jam tidak dicocokkan: admin
  memilih jadwalnya langsung, jadi jam tidak dibutuhkan untuk mencarinya.
  Nama tabel diambil dari peta tetap, bukan dari formulir.

  `waktu_absensi` harus `datetime-local` yang sah, dan dicocokkan
  **bolak-balik**: `DateTime::createFromFormat()` menerima luapan —
  30 Februari jadi 2 Maret, jam 25 jadi 01.00 esok hari — dan tanpa
  pencocokan itu hari jadwal dihitung dari tanggal hasil luapan. Celah ini
  ada di rencana awal dan baru terlihat saat diuji lokal.

  Terverifikasi di produksi 25 September 2026: mengajar, piket, dan ekskul
  dengan jadwal sah tersimpan; jadwal dengan hari yang tidak cocok, jadwal
  guru lain, dan jadwal non-Aktif ditolak.

  Sengaja tidak disentuh: `status` dan `keterangan` belum divalidasi (nilai
  `status` di luar dropdown tidak dibayar `hitungHonorBulan()`), dropdown
  belum disaring menurut tanggal, dan belum ada transaksi.
- ~~`echo $pesan` tanpa escape di `admin/absensi_manual.php`~~ — commit
  `4a0dbdb`. Pertahanan berlapis, bukan penutup lubang yang terbuka: sejak
  validasi tanggal di `3739dd3`, kesepuluh tempat yang mengisi `$pesan`
  hanya memuat literal, bilangan, tanggal `Y-m-d`, nama hari dari peta tetap,
  dan pesan tetap `includes/unggah_gambar.php`. Kini
  `htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8')`, supaya pesan yang kelak
  memuat nama guru atau isi formulir tetap aman. Terverifikasi di produksi
  24 September 2026: pesan sukses dan pesan duplikat tampil tanpa entitas
  yang terlihat. `$tipe_pesan` di atribut `class` sengaja tidak di-escape:
  nilainya hanya salah satu dari tiga literal.
- ~~Kunci duplikat piket di jalur aplikasi `proses_absen_sederhana.php`~~ —
  commit `1e0194f`, repo API. Pengecekan absen ganda memakai `guru_id` +
  `jadwal_id` + `tipe_absensi` + tanggal untuk piket dan ekskul, sehingga
  guru bisa absen piket sesi Pagi lalu Siang di hari yang sama dari
  `absen_sederhana.tsx`; keduanya masuk `Pending`, dan kalau kepala sekolah
  menyetujui keduanya lewat `proses_action_piket.php`, honornya terbayar dua
  kali. Piket kini dicek per guru per hari tanpa `jadwal_id` dan tanpa
  memandang `status` — piket `Ditolak` ikut menghalangi absen ulang lewat
  sesi lain, sengaja disamakan dengan panel web dan jalur approval. Ekskul
  tetap per jadwal. Penolakan tetap HTTP 409 dengan bentuk `{error,
  message}`; `absen_sederhana.tsx` hanya membaca `message`, jadi versi lama
  langsung menampilkan pesan barunya tanpa rilis. Terverifikasi di produksi
  24 September 2026: piket sesi kedua ditolak dengan pesan piket, dan absen
  ekskul tetap tersimpan.

  Dengan ini keempat jalur yang menulis piket ke `absensi` memakai kunci yang
  sama: input satuan panel web, approval panel web, approval repo API, dan
  absen langsung dari aplikasi.

  Sengaja tidak disentuh: `proses_action_piket.php` mengubah status tanpa
  pemeriksaan apa pun. Saat butir ini ditutup, itu tidak lagi berbahaya:
  sensus 24 September 2026 atas seluruh `absensi` menemukan satu pasangan
  piket ganda dan nol pasangan `Pending`. Pasangan itu guru 12, 7 Maret 2026,
  baris 1977 dan 2227, keduanya `Hadir`. Kemungkinan besar honor Maret
  terbayar dua kali. Seperti baris ganda guru 4 pada Juli, keputusannya ada
  di bendahara, bukan keputusan teknis.
- ~~Kunci duplikat piket di input satuan `admin/absensi_manual.php`~~ —
  commit `a88367a`. Input satuan memakai `guru_id` + `jadwal_id` +
  `tipe_absensi` + tanggal untuk semua jenis, sehingga piket kedua di hari
  yang sama lolos lewat jadwal sesi lain. Piket kini dicek per guru per
  tanggal tanpa `jadwal_id`, sama dengan `approval_absensi.php`; mengajar dan
  ekskul tetap per jadwal. Pemeriksaannya tidak memandang `status`, juga
  sama dengan jalur approval: baris piket `Ditolak` atau `Pending` ikut
  menghalangi input manual di tanggal itu. Terverifikasi di produksi
  24 September 2026: piket sesi kedua ditolak dengan pesan piket, dan dua
  jadwal ekskul berbeda di tanggal yang sama tetap tersimpan.

  Sengaja tidak disentuh: server tidak memeriksa bahwa `jadwal_id` kiriman
  milik guru itu, berstatus `Aktif`, dan harinya cocok dengan tanggal —
  kemudian ditutup di `2325e0a`.
- ~~SQL injection di jalur massal `admin/absensi_manual.php`~~ — commit
  `3739dd3`. Pengecekan duplikat menempelkan `guru_ids[]` dan
  `tanggal_otomatis` dari formulir langsung ke teks SQL; kini prepared
  statement dengan kunci yang sama (`guru_id` + `jadwal_id` + tanggal).
  `tanggal_otomatis` juga harus `Y-m-d` yang sah sebelum dipakai: tanggal tak
  sah membuat `getHariIndo()` mengembalikan 'Kamis' (1970), sehingga jadwal
  Kamis bisa tercatat dengan `waktu_absensi` sampah. Terverifikasi di produksi
  24 September 2026: kiriman sah tersimpan, kiriman ulang ditolak sebagai
  duplikat, dan tanggal yang disisipi SQL ditolak tanpa baris tersimpan.

  Sengaja tidak disentuh: kunci duplikat piket di jalur **satuan** berkas yang
  sama — kemudian ditutup di `a88367a`; dan `echo $pesan` tanpa escape —
  kemudian ditutup di `4a0dbdb`.
- ~~Tidak ada batas ukuran berkas unggahan~~ — dua lapis, keduanya kini
  terpasang. Dulu `upload_max_filesize` dan `post_max_size` 100M di kedua situs,
  `getimagesize()` hanya membaca header, dan endpoint API tanpa autentikasi —
  seratus permintaan JPEG sah berpadding bisa memakan hampir 10 GB.

  **Lapis kode**: batas 8 MB di keempat endpoint unggah repo API
  (`includes/pesan_unggah.php`, commit `4ee3eb3`) dan di semua jalur unggah
  repo ini (`includes/unggah_gambar.php`, fase 1-3). **Lapis server**,
  diterapkan 22 September 2026 dan diuji per folder dengan `ini_get()`:

      api.smkt.alhasan.co.id          8M / 16M   MultiPHP INI Editor
      classync/, admin/, api/          8M / 16M   php_value di classync/.htaccess

  Angka 8 MB dari sensus 5.036 foto produksi, 21 September 2026: median
  0,03 MB, p95 2,60 MB, p99 4,13 MB, terbesar 23,54 MB — hanya satu berkas di
  atas 8 MB. `post_max_size` sengaja 16M, bukan disamakan: `absen-siswa.tsx`
  mengirim foto sebagai base64 di dalam JSON, yang tidak disentuh
  `upload_max_filesize` sama sekali dan menggelembung sekitar 33%.

  Lapis server classync sempat **tampak** sudah terpasang padahal belum:
  MultiPHP INI Editor untuk domain `smkt` menyimpan 8M, tapi classync memakai
  `alt-php83`, dan uji `ini_get()` di folder itu tetap menunjukkan 100M. Tanpa
  uji per folder, butir ini akan tercatat tertutup dalam keadaan terbuka.

  Terverifikasi 22 September 2026: absen mengajar dengan foto dari aplikasi
  tersimpan (`absen-mengajar-9-1790080463-6687dcd5.jpg`) dan tampil di laporan
  admin — batas baru tidak menolak foto yang sah.
- ~~Penghapusan berkas sembarang di `proses_edit_profil.php`~~ — commit
  `8c59402`. `admin/proses_edit_profil.php` dan salinan identiknya di
  `guru_area/` memakai `$_POST['foto_lama']` mentah untuk `unlink()`. Guru
  mana pun yang login lewat web bisa mengirim
  `foto_lama=../../../config/db-classync.php` dan mematikan seluruh sistem —
  lubang yang sama dengan `update_profil_guru.php` di repo API (`188c705`),
  terlewat di panel web. Foto lama kini dibaca dari basis data, dan
  penghapusannya lewat `hapusFotoLamaAman()`. Diuji dengan masukan
  bermusuhan: traversal ke konfigurasi dan berkas kode di luar `uploads/`
  ditolak dan tercatat ke `error_log`. Tidak diuji lewat login guru karena
  web guru tidak dipakai lagi; salinan `guru_area/`-nya kemudian dihapus di
  fase 4.
- ~~Web guru, API lama, dan perancah di webroot~~ — commit `ea7900f`. Web guru
  sudah tidak dipakai (tautannya dinonaktifkan, guru mengedit profil lewat
  aplikasi), tapi berkasnya masih bisa dijalankan lewat URL langsung. Dihapus
  dari repo dan **dipindah** dari server ke
  `/DATA/k1807225/arsip-fase4-2026-09-22` — bukan dihapus, supaya bisa
  dikembalikan. Isinya: seluruh `guru_area/`, `login_guru.php`,
  `proses_login_guru.php`, delapan berkas `api/` lama (`absen`, `profil`,
  `riwayat_absensi`, `login_guru`, `logout_guru`, `auth_middleware`,
  `dashboard`, `laporan_honor`), dan perancah `test_koneksi.php` — yang
  mencetak nama basis data dan `connect_error` ke siapa pun —
  `test-manual-api.php`, `admin/test_sesi1.php`, `admin/test_sesi2.php`.

  Dasarnya: tidak ada pemanggil dengan path lengkap di repo ini, dan tidak
  satu pun versi ClassyncApp dalam seluruh riwayat Git-nya pernah
  memanggilnya. `.cpanel.yml` ikut diubah karena masih menyalin
  `guru_area/`. Terverifikasi 22 September 2026: keempat alamat uji menjawab
  404, `api/get_dashboard_stats.php` tetap 200. `guru_area/index.php`
  menjawab 301 karena aturan `.htaccess` yang membuang `index.php` — foldernya
  sendiri sudah tidak ada.
- ~~`kirim_notifikasi_harian.php` tanpa penjaga dan buta terhadap iOS~~ —
  pengirim terbesar sistem ini: seluruh guru, setiap pagi pukul 07.00. Dulu ia
  bisa dipicu siapa pun lewat URL, mengirim semua token ke FCM sehingga guru
  iPhone tidak pernah menerima pengingat, dan tidak memeriksa jawaban Google
  sama sekali. Commit `dd7774b` repo API memilah token lewat
  `includes/pengirim_apns.php`, mencatat kegagalan ke `error_log` per guru,
  memasang penjaga cron, dan menyalakan kembali verifikasi TLS.

  Penjaga cron di `dd7774b` **salah** dan mematikan pengingat seluruh guru pada
  22 September 2026: ia memeriksa `php_sapi_name() === 'cli'`, padahal
  `/usr/bin/php` di sini `php-cgi`. Nol pengingat tersimpan pagi itu, dibanding
  11 sehari sebelumnya, dan log tetap bersih karena keluarannya ke `/dev/null`.
  Kesimpulan "pasti CLI" saya ambil dari tidak adanya `REQUEST_METHOD` — yang
  hanya membuktikan skripnya tidak dipanggil lewat HTTP. Diperbaiki commit
  `548f199` dengan memeriksa `REQUEST_METHOD` langsung.

  Terverifikasi 22 September 2026 lewat pemanggilan manual dengan biner yang
  sama dengan cron: 10 dari 10 guru terkirim, empat di antaranya (guru 3, 4, 5,
  8) lewat APNs — pengingat harian pertama yang sampai ke iPhone sejak Juli.
  Log yang kosong di sini **tidak** membuktikan apa pun; yang membuktikan cron
  berjalan adalah baris baru di tabel `notifikasi` dengan judul
  `Pengingat Jadwal Classync`.
- ~~ClassyncApp tidak pernah mendaftarkan ulang push token~~ —
  `registerForPushNotificationsAsync()` punya dua jalan pintas
  `expo-secure-store` yang keluar sebelum server dihubungi, dan Keychain iOS
  bertahan melewati pemasangan ulang, sehingga token yang salah di basis data
  tidak pernah bisa diperbaiki. Commit `aa255aa` membuang keduanya: token kini
  dikirim ke server setiap peluncuran. Rilis sebagai 2.9.2 dan sudah beredar.
  Bukti di produksi: guru 17 memegang token Expo pada 20 September, dan pada
  22 September pengingatnya terkirim — tokennya sudah berganti, yang hanya
  mungkin lewat pendaftaran ulang.
- ~~Kunci rahasia FCM tertulis di kode~~ — kunci yang dipakai kedua pemanggil
  approval untuk menembak `send_fcm_api.php` pernah tertulis apa adanya di
  tiga berkas di dua repositori publik. Dipindah ke
  `/DATA/k1807225/config/fcm-classync.php` (commit `4958e4d` repo API,
  `d4819b1` repo ini), lalu **dirotasi** 21 September 2026 — memindahkan saja
  tidak cukup, karena kunci lamanya permanen di riwayat Git.

  Berkas konfigurasi memuat dua hal, dan pemisahannya disengaja: `$fcm_secret`
  kunci yang **dikirim**, `$fcm_secrets_sah` daftar kunci yang **diterima**.
  Karena penerima menerima daftar, rotasinya tidak menuntut deploy sama sekali:
  tambah kunci baru ke daftar, alihkan pengirim, lalu cabut yang lama. Kalau
  perlu diulang, urutan itu yang dipakai.

  Terverifikasi: dengan kunci lama, `curl` ke `send_fcm_api.php` dijawab
  `Kunci Rahasia Salah`; setelah kunci lama dikembalikan sementara ke daftar,
  jawabannya berubah jadi galat token dari Google — bukti bahwa pencabutan itu
  yang menentukan. Setelah pencabutan, approval sungguhan tetap membunyikan
  notifikasi. Perubahan pada berkas konfigurasi berlaku seketika.

  Kegagalan di jalur ini **senyap** bagi pengguna — approval tetap berhasil,
  yang hilang hanya pemberitahuan — tapi sejak `4958e4d` penolakannya tercatat
  ke `error_log` kedua pemanggil. Jangan menulis kunci apa pun, lama maupun
  baru, ke berkas di dalam Git, termasuk ke berkas ini.
- ~~`admin/approval_absensi.php` tidak idempoten~~ — ditutup lewat lima commit
  di dua repo: `d143f39`, `99ab1b9`, `db58ada` di panel web, lalu `9cca477` di
  repo API dan `eca427d` untuk `status_jadwal`. Kedua jalur approval kini
  memakai transaksi, `SELECT ... FOR UPDATE`, `UPDATE ... AND status =
  'Pending'` dengan `affected_rows` sebagai penentu, dan penjaga duplikat yang
  kuncinya berbeda per jenis. `commit()` dipasang **sebelum** panggilan FCM
  supaya jaringan yang lambat tidak menahan kunci baris.

  Yang memakan waktu bukan transaksinya, melainkan menemukan kunci yang benar
  untuk tiap jenis — hasilnya ada di "Aturan honor per jenis absensi" di atas.
  Cakupan saya yang pertama terlalu sempit: saya menjaga "satu pengajuan, satu
  approval", padahal yang dibutuhkan "satu slot, satu baris absensi".
  Pengujian menghasilkan empat baris ganda dalam sepuluh menit.

  Batasan unik di basis data **belum** dipasang, masih terhalang satu baris
  ganda Juli (guru 4, jadwal 381, 7 Juli 2026). Itu data honor bulan yang sudah
  terbayar; keputusannya di tangan bendahara, bukan keputusan teknis.

  Terverifikasi di produksi 19 September 2026 lewat delapan uji, panel web dan
  aplikasi. Yang paling meyakinkan uji ekskul: dua jadwal bertumpang tindih
  keduanya berhasil, lalu pengajuan ketiga untuk slot yang sama ditolak dengan
  pesan yang menyebut nama ekskulnya.
- ~~`proses_absen_bk.php` mencatat absensi walau unggah foto gagal~~ — commit
  `98bdfdb`, repo API. Foto kini wajib, menyusul dua endpoint absen lain yang
  sudah begitu sejak awal. Galat unggah dibedakan dari "tidak ada foto":
  `UPLOAD_ERR_INI_SIZE` berbunyi "ukuran berkasnya terlalu besar", bukan
  "wajib diupload" yang membingungkan guru yang fotonya jelas terlampir. Foto
  yang telanjur pindah ke `uploads/` ikut dihapus kalau `INSERT` gagal —
  `rollback()` tidak menyentuh berkas.
- ~~`proses_absen_bk.php` tanpa penjaga duplikat~~ — commit `a6cd9d5`, repo API.
  Kuncinya `(guru_id, 'bimbingan', CURDATE(), topik_tema, sasaran_layanan)`.
  Bukan per tanggal saja: guru BK memang melayani 2 sampai 5 kali sehari, jadi
  kunci itu akan memotong honor yang sah. Bukan per topik saja: topik yang sama
  wajar dibawakan ke dua kelas berbeda. Kombinasi topik dan sasaran terbukti
  tidak pernah berulang satu kali pun dalam seluruh riwayat produksi.

  Ini pemeriksaan biasa, bukan kuncian baris, dan batasnya sengaja ditulis di
  komentar kodenya. Kuncinya melintasi `absensi` dan `jurnal_bk` sehingga tidak
  bisa dijadikan batasan unik, dan `FOR UPDATE` tidak dipakai karena
  `DATE(waktu_absensi)` bukan indeks: InnoDB akan mengunci terlalu banyak baris
  di `absensi`, tabel tersibuk, dan menahan absen guru lain. Celah sepersekian
  detik untuk dua kiriman yang benar-benar bersamaan masih ada.
- ~~Zona waktu di ClassyncApp mengirim tanggal mundur sehari~~ —
  `date.toISOString()` menghasilkan UTC, jadi antara 00.00-07.00 WIB tanggal
  yang dikirim mundur satu hari. Diperbaiki commit `26576d8` dengan penolong di
  `utils/tanggal.ts`, dan **sudah sampai ke guru**: `ec3af18` yang menyetel
  versi 2.9.1 memuat `26576d8` sebagai leluhurnya, dan 2.9.1 sudah beredar.

  Catatan ini sempat tertulis sebagai "belum sampai ke guru" dan itu keliru —
  dokumen ini tidak diperbarui setelah rilisnya. Versi lama tetap beredar
  berminggu-minggu, jadi basis data masih menerima campuran keduanya sampai
  semua guru memperbarui.
- ~~SQL injection di `get_monitoring_absensi.php`~~ — prepared statement,
  commit `e6a567f` repo API. Endpoint itu juga tidak lagi mengirim pesan galat
  mentah ke pemanggil. Sapuan ulang seluruh repo API tidak menemukan endpoint
  kedua dengan pola yang sama.
- ~~Tidak ada `.htaccess` di folder unggahan~~ — dibuat di `uploads/` dan
  `api/uploads/`, lalu diperketat lagi lewat commit `d7cee63` setelah audit.
  **Bukan** `php_flag engine off` seperti saran audit lama: server ini
  LiteSpeed dengan `AddHandler`.

  Pembagian tugas di dalamnya penting dipahami sebelum menyederhanakannya,
  dan penalaran saya yang pertama di sini terbalik:

  - `RemoveHandler`/`RemoveType` — argumen ekstensi mod_mime **tidak peka
    huruf**, dan ini **satu-satunya** lapis yang menjangkau nama
    multi-ekstensi seperti `foto.php.jpg`. `FilesMatch` tidak melihatnya
    karena hanya mencocokkan ekstensi terakhir.
  - `FilesMatch` — **peka huruf secara bawaan**, jadi `(?i)` wajib. Tanpa itu
    `probe.PHP` dan `dump.SQL` lolos.
  - `RemoveOutputFilter` + `Options -Includes -IncludesNOEXEC` — SSI tidak
    tersentuh `RemoveHandler`, dan `-ExecCGI` bukan `-Includes`.

  Terverifikasi di produksi 14 September 2026, dengan User-Agent peramban:

      probe.PHP       403                              ← (?i) bekerja
      probe.php.jpg   200, isi kode sumber mentah      ← RemoveHandler bekerja
      probe.SHTML     403                              ← SSI tertutup
      uji.SQL         403                              ← (?i) di akar bekerja

  Baris kedua itu bukti bahwa `.htaccess` ini menanggung beban, bukan sekadar
  pelengkap: berkas multi-ekstensi tetap tersaji dengan status 200, dan yang
  mencegahnya dieksekusi hanya `RemoveHandler`.

  Isinya diarsipkan di repo, tapi `.cpanel.yml` tidak menyalin `uploads/` —
  salinan server diurus manual. Blok `(?i)` untuk log/dump di `.htaccess` akar
  kedua situs juga hanya ada di server.

  Berkas uji `probe.php` dari verifikasi 14 September sempat tertinggal di
  `uploads/` — terblokir (403), tapi tetap sisa uji. Dipindah ke arsip fase 4
  pada 25 September 2026; kini 404. Setelah menguji dengan berkas probe,
  pindahkan berkasnya hari itu juga.
- ~~`api/auth_middleware.php` rekursif~~ — bukan cacat yang perlu diperbaiki,
  melainkan kode mati. Ketujuh pemanggilnya ada di `guru_area/` dan
  `classync/api/`, keduanya sudah digantikan endpoint repo API dengan nama
  berbeda (`login.php`, `get_honor.php`, `get_profil_guru.php`). Dihapus di
  commit `ea7900f` bersama pemanggilnya.
- ~~Berkas kembar `guru-area`, `loginguru.php`, `guru_area/index2.php`,
  `api/backup/`~~ — dihapus dari repo (commit `f92eac3`) dan dari server,
  bersama `index3.php`, `index-not.php`, `laporan_honor_salah.php`.
- ~~`display_errors` menyala di 54 berkas~~ — diganti `'0'` di 9 berkas repo
  ini (commit `432d912`) dan 45 berkas repo API (commit `6542b62`), dua tahap
  dengan deploy dan uji terpisah. Yang diganti potongan nilainya, bukan
  barisnya: 12 berkas di repo API menaruh `error_reporting(E_ALL)` di baris
  yang sama, sehingga menghapus barisnya akan ikut mematikan pencatatan ke
  log. `error_reporting(E_ALL)` sengaja dibiarkan menyala di semuanya.
- ~~Log, dump, dan arsip bisa diunduh dari webroot~~ — semuanya dihapus
  13 September 2026. Tapi yang bertahan bukan penghapusannya, melainkan blok
  ini, ditambahkan **di bawah** blok buatan cPanel pada `.htaccess` akar
  `smkt.alhasan.co.id/classync/` dan `api.smkt.alhasan.co.id/`:

      <FilesMatch "^(error_log|.*\.log|proxy-log\.txt|debug_log\.txt|.*\.sql|.*\.zip|.*\.bak)$">
          Require all denied
      </FilesMatch>

  `error_log` lahir lagi setiap kali ada galat — blok inilah yang membuatnya
  tidak bisa diunduh. **Jangan dihapus.** Terverifikasi: `uji.log` → 403,
  `classync.png` → 200.
- ~~Izin berkas terlalu longgar~~ — `admin/` dari 0777 jadi 0755;
  `absen_ekskul.php`, `absen_mengajar.php`, `absen_piket.php` dari 0666 jadi
  0644. Hanya di server; izin tidak ikut Git, jadi tidak ada jejaknya di repo.
  Ketiga berkas `absen_*.php` itu kemudian dihapus di `dabfb43`.
- ~~Perancah pengembang di webroot~~ — `test-tcpdf.php` dan
  `debug_absen_manual.php` dihapus dari repo (commit `432d912`) dan dari
  server. Keduanya mencetak keluaran debug dan bisa dibuka siapa pun.
- ~~Unggahan foto tanpa daftar putih di repo API~~ — commit `6c77656`, empat
  berkas: ketiga endpoint absen ditambah `update_profil_guru.php`. Yang
  menutup lubangnya bukan `getimagesize()`, melainkan asal ekstensinya:
  diambil dari `$info[2]`, tipe yang terdeteksi, bukan dari nama kiriman
  klien. Polyglot yang lolos `getimagesize()` tetap tersimpan sebagai `.jpg`.
  Lebih ketat daripada `absensi_pkl.php` yang jadi rujukan audit. WEBP
  diizinkan meski sensus 1.896 foto produksi hanya menemukan 1.203 `.jpeg`,
  687 `.jpg`, 6 `.png`, dan nol HEIC. Terverifikasi di produksi lewat pola
  nama berkas yang baru.
- ~~Penghapusan berkas arbitrer di `update_profil_guru.php`~~ — commit
  `188c705`, repo API. `$_POST['foto_lama']` dipakai mentah di dua tempat, dan
  endpointnya tanpa autentikasi: digabung ke path absolut lalu `unlink()`, dan
  disimpan ke kolom `foto_profil` kalau tidak ada foto baru. Basisnya berakhir
  `/` sehingga `..` menjadi komponen path utuh dan traversal bekerja —
  `foto_lama=../../../config/db-classync.php` menghapus konfigurasi basis data
  dan mematikan seluruh sistem. Diperbaiki dengan membaca foto lama dari basis
  data, ditambah pagar `realpath()` sebelum `unlink()`.
- ~~Regresi GIF~~ — commit `e81f6f9`, repo API. Daftar putih di `6c77656`
  hanya memetakan JPEG/PNG/WEBP, sementara `absen_bk.tsx:239` menerima GIF.
  Guru BK yang memilih GIF dari galeri ditolak server. Sensus foto produksi
  tidak menemukan GIF, jadi saya menyimpulkan formatnya tidak terpakai — yang
  tidak saya periksa adalah format apa yang **diterima** layar aplikasi. Dua
  hal berbeda, dan yang kedua itulah kontraknya.
- ~~Path foto tidak di-escape di tujuh sink~~ — commit `b2985c2`. Audit
  menunjuk `admin/laporan.php:247`; sapuan seluruh repo menemukan enam lagi di
  `admin/laporan_absensi_siswa.php`, `laporan_absensi_siswa.php`, dan
  `absensi_pkl.php`. Klaim sebelumnya bahwa "panel admin memakai
  `htmlspecialchars()` pada semua path foto" keliru: hanya `<img src=` yang
  diperiksa, `href` tidak.

  Dua sink di `absensi_pkl.php` menaruh nilai yang sama ke **dua konteks** —
  atribut `src` dan string JavaScript di dalam `onclick`. Perbaikan pertama
  memakai `json_encode` dengan `JSON_HEX_QUOT` dan **mematikan modal foto PKL
  di produksi**, karena flag itu hanya mengubah kutip di dalam isi string,
  bukan kutip pembatas JSON-nya. Diperbaiki commit `b92a527` dengan
  `htmlspecialchars(json_encode($v), ENT_QUOTES, 'UTF-8')`, diuji lebih dulu
  dengan masukan bermusuhan.
- ~~`guru_id` mentah di nama berkas~~ — commit `caa1fcf`, repo API. Di-cast
  `(int)` di titik masuk pada keempat endpoint unggah. Klaim bahwa berkas
  polyglot "tidak akan pernah bisa dieksekusi bahkan seandainya `.htaccess`
  hilang" **salah**: `guru_id=1.php.` menghasilkan `absen-1.php.-TIME.jpg`, dan
  mod_mime memproses setiap komponen ekstensi, bukan hanya yang terakhir.
  Sekalian `rand(100, 999)` diganti `bin2hex(random_bytes(4))` — peluang
  tabrakan 1/900 bagi guru yang sama pada detik yang sama, dan berkas kedua
  akan menimpa yang pertama.

## Yang sudah tidak dipakai atau sudah rusak

- Web guru — `guru_area/`, `login_guru.php`, dan API lamanya sudah dihapus
  (`ea7900f`). Sisanya di panel admin — `admin/profil_guru.php`,
  `admin/edit_profil_guru.php`, dan `admin/proses_edit_profil.php`, beserta
  menu **Profil Guru** di navbar — dihapus dari repo di `851213a`. Ketiganya
  memakai `$_SESSION['guru_id']` padahal login admin hanya menyetel
  `admin_id`, sehingga bagi admin menu itu selalu menampilkan profil kosong
  (terverifikasi di produksi 22 September 2026). Ketiga berkas itu juga sudah
  dipindah dari server ke arsip fase 4 yang sama. Terverifikasi 24 September
  2026: ketiganya menjawab 404, sedangkan `admin/dashboard.php` sebagai
  pembanding tetap 302 ke login.
  Tautan ke `login_guru.php` di `includes/header.php` sudah menjadi komentar
  HTML, jadi tidak lagi tampil. Berkas mati yang masih ada di server **tetap
  ikutkan dalam perubahan keamanan** — halaman yang tidak dipakai tetap bisa
  dijalankan lewat URL langsung.
- `admin/admin_notifikasi.php` — sudah tidak digunakan.
- `classync/api/` — yang masih hidup: `get_dashboard_stats.php` dan
  `proses_absen_siswa.php` dipanggil **ClassyncApp** (`absen-siswa.tsx`);
  `proses_absen_manual.php`, `get_jadwal_admin.php`,
  `update_absen_harian.php`, `delete_absen_harian.php`,
  `ekspor_detail_absensi.php`, `generate_pdf_absensi.php`, dan `db.php`
  dipanggil panel web; `sj_tantangan.php` dan `sj_detak.php` dipanggil
  halaman kiosk dan `admin/kiosk_sidik_jari.php`, bukan aplikasi. Catatan
  lama di sini hanya menyebut pemakaian oleh panel admin, dan itu keliru —
  grep di repo ini tidak akan menemukan pemanggil dari aplikasi.
- Ekspor Excel di halaman rekap/laporan absensi **rusak sejak sebelum**
  pekerjaan kredensial: `admin/laporan.php:8` memanggil `../vendor/autoload.php`
  sementara PhpSpreadsheet ada di `admin/PhpOffice/`. Bukan regresi.

## Jangan lakukan

- Jangan menulis ulang banyak endpoint sekaligus.
- Jangan menjalankan perintah sinkronisasi yang menghapus (`rsync --delete`,
  `git clean -fd`) di folder mana pun yang berisi `uploads/`.
- Jangan memeriksa berkas PHP yang bisa mengubah atau menghapus data dengan
  membuka URL-nya, termasuk lewat `curl -I`. Permintaan HEAD pun menjalankan
  skripnya. Periksa keberadaannya lewat File Manager atau Terminal cPanel.
- Jangan menambahkan `vendor/`, `uploads/`, atau `api-wa/` ke Git.
- Jangan meng-commit berkas `.sql`, log, atau apa pun yang memuat kredensial —
  riwayat Git permanen.
- Jangan menaruh kredensial di dalam kode — pakai berkas konfigurasi di luar
  webroot.
- Jangan menambahkan kembali `ini_set('display_errors', 1)` ke berkas apa pun.
- Jangan menyeragamkan bentuk pesan galat antar-endpoint; aplikasi versi lama
  membaca bentuk tertentu.
- Jangan memasukkan nilai ke atribut event handler (`onclick` dan sejenisnya)
  hanya dengan `htmlspecialchars()`. Peramban mengurai entitas HTML lebih dulu,
  jadi `&#039;` kembali jadi `'` sebelum JavaScript membacanya. Pakai
  `htmlspecialchars(json_encode($v), ENT_QUOTES, 'UTF-8')` — dua lapis,
  masing-masing untuk konteksnya. Dan uji dengan **mengklik**: kegagalan di
  sini senyap, halaman tetap tampil normal dan tidak ada galat apa pun.
- Jangan menganggap memasang ulang aplikasi membersihkan `expo-secure-store`
  di iOS. Ia memakai Keychain, dan Keychain **bertahan melewati penghapusan
  aplikasi** — berbeda dari Android. Saran "pasang ulang saja" pernah
  diberikan atas dasar itu dan terbukti tidak berguna: token di basis data
  tidak berubah, dan menghapus barisnya pun tidak membuat aplikasi menulis
  ulang. Kalau sebuah nilai harus bisa disetel ulang, aplikasinya sendiri yang
  harus menghapusnya.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
