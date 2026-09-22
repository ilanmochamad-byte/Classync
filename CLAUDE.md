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
| `~/ClassyncApp` | aplikasi React Native/Expo | rilis Play Store & App Store |

Tidak ada OTA — `expo-updates` tidak terpasang. Perubahan backend yang memutus
kontrak JSON hanya bisa diperbaiki lewat rilis toko, berminggu-minggu.
Sebaliknya, perbaikan backend berlaku seketika.

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
- **Sedang** — jalur unggah repo ini: **menunggu uji** untuk dua fase.
  Pekerjaan empat fase (September 2026) memindahkan semua unggahan ke penolong
  bersama `includes/unggah_gambar.php`: ekstensi dari tipe yang terdeteksi
  `getimagesize()`, nama kiriman tidak masuk ke nama berkas, batas 8 MB, dan
  `hapusFotoLamaAman()` yang dipagari `realpath()` ke dalam `uploads/`.
  Fase 1 dan 4 sudah ditutup (lihat "Sudah ditutup"). Yang **belum diuji**:

  - Fase 2, `absensi_pkl.php` (commit `2569f6f`) — dipakai siswa PKL. Dulu
    ekstensi diambil dari nama kiriman tanpa daftar putih, sehingga polyglot
    `x.php` tersimpan sebagai `.php`. Uji: satu absen PKL sungguhan, lalu
    klik thumbnail di riwayat — modalnya harus terbuka.
  - Fase 3, `admin/siswa.php` dan kedua jalur `admin/absensi_manual.php`
    (commit `9c10504`). `siswa.php` juga memakai `$_POST['foto_lama']` mentah
    untuk `unlink()` — lubang yang sama dengan fase 1, di sini terbatas pada
    admin. Uji: ganti foto siswa, simpan absensi manual dengan foto, dan
    pastikan berkas bukan gambar ditolak tanpa menyimpan baris.

  Unggahan yang ditolak kini menghentikan penyimpanan dengan pesan; dulu
  kegagalan diabaikan dan baris tersimpan tanpa foto. Tutup butir ini setelah
  kedua uji berhasil.
- **Sedang** — SQL injection di jalur otomatis (massal) `admin/absensi_manual.php`:
  `$conn->query("SELECT id FROM absensi WHERE guru_id=$gid AND jadwal_id=$jid
  AND DATE(waktu_absensi)='$tanggal_pilih'")`, dengan `$gid` dan
  `$tanggal_pilih` langsung dari formulir. Terbatas pada admin, jadi bobotnya
  di bawah endpoint tanpa autentikasi, tapi tetap harus jadi prepared
  statement. Ditemukan saat fase 3 dan sengaja tidak disentuh di sana.
- **Sedang** — login tanpa `session_regenerate_id(true)`, tanpa pembatasan
  percobaan, tanpa token CSRF di form admin.
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
  (`ea7900f`). Sisa yang belum diputuskan: `admin/profil_guru.php`,
  `admin/edit_profil_guru.php`, dan `admin/proses_edit_profil.php` memakai
  `$_SESSION['guru_id']` padahal login admin hanya menyetel `admin_id`, jadi
  menu **Profil Guru** di panel admin kemungkinan sudah rusak. Dan
  `includes/header.php`, dipakai halaman di akar seperti `absen_piket.php`,
  masih menautkan ke `login_guru.php` yang kini 404. Berkas mati yang masih
  ada di server **tetap ikutkan dalam perubahan keamanan** — halaman yang tidak
  dipakai tetap bisa dijalankan lewat URL langsung.
- `admin/admin_notifikasi.php` — sudah tidak digunakan.
- `classync/api/` — yang masih hidup: `get_dashboard_stats.php` dan
  `proses_absen_siswa.php` dipanggil **ClassyncApp** (`absen-siswa.tsx`);
  `proses_absen_manual.php`, `get_jadwal_admin.php`,
  `update_absen_harian.php`, `delete_absen_harian.php`,
  `ekspor_detail_absensi.php`, `generate_pdf_absensi.php`, dan `db.php`
  dipanggil panel web. Catatan lama di sini hanya menyebut pemakaian oleh
  panel admin, dan itu keliru — grep di repo ini tidak akan menemukan
  pemanggil dari aplikasi.
- Ekspor Excel di halaman rekap/laporan absensi **rusak sejak sebelum**
  pekerjaan kredensial: `admin/laporan.php:8` memanggil `../vendor/autoload.php`
  sementara PhpSpreadsheet ada di `admin/PhpOffice/`. Bukan regresi.

## Jangan lakukan

- Jangan menulis ulang banyak endpoint sekaligus.
- Jangan menjalankan perintah sinkronisasi yang menghapus (`rsync --delete`,
  `git clean -fd`) di folder mana pun yang berisi `uploads/`.
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
