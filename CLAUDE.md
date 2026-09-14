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
- **Tinggi** — `admin/approval_absensi.php` tidak idempoten. Halaman itu tidak
  memastikan status masih `Pending` dan tidak memeriksa duplikat, sehingga
  admin yang menekan "Disetujui" dua kali — atau peramban yang mengulang POST
  yang sama — memasukkan baris `absensi` baru setiap kali. Honor mengajar,
  piket, dan ekskul dihitung per baris, jadi hasilnya honor ganda. Perbaikannya
  menuntut transaksi, `SELECT ... FOR UPDATE`, perubahan status bersyarat, dan
  batasan unik di basis data. Ini temuan yang paling sulit terlihat: tidak ada
  gejala sampai slip gaji keluar.
- **Tinggi** — tidak ada batas ukuran berkas unggahan. `upload_max_filesize`
  100 MB, `getimagesize()` hanya perlu membaca header, dan endpointnya tanpa
  autentikasi — seratus permintaan JPEG sah berpadding bisa memakan hampir
  10 GB. Perbaikannya: batas eksplisit sekitar 5 MB sebelum `getimagesize()`,
  samakan `post_max_size`, dan pertimbangkan re-encode gambar untuk membuang
  muatan yang menempel di belakang.
- **Sedang** — lima jalur unggah di repo ini menerima berkas tanpa memeriksa
  isinya: `admin/siswa.php:63`, `admin/proses_edit_profil.php:33`,
  `admin/absensi_manual.php:46` dan `:84`, serta
  `guru_area/proses_edit_profil.php:33` (folder mati). Tiga yang pertama
  halaman admin yang dipakai TU sehari-hari. Keparahannya di bawah endpoint
  API yang sudah ditutup, karena semuanya menuntut sesi admin lebih dulu.
  Lima jalur lain (`absensi_pkl.php`, `guru_area/absen.php`,
  `guru_area/proses_absen.php`, `api/profil.php`, `api/absen.php`) sudah
  memakai `getimagesize()` tapi ekstensinya masih dari klien — terlindungi
  sebagian, belum kebal polyglot. Pola yang benar ada di repo API, `6c77656`.
- **Sedang** — login tanpa `session_regenerate_id(true)`, tanpa pembatasan
  percobaan, tanpa token CSRF di form admin.
- **Sedang** — 56 berkas membuka koneksi database sendiri padahal `db.php`
  sudah menyediakan `$conn`.
- **Sedang** — zona waktu di ClassyncApp: `date.toISOString()` menghasilkan
  UTC, jadi antara 00.00-07.00 WIB tanggal yang dikirim mundur satu hari.
  **Sudah diperbaiki di sumber** — commit `26576d8`, penolong di
  `utils/tanggal.ts` — tapi belum sampai ke guru: tidak ada OTA, jadi butuh
  build EAS dan tinjauan toko. Sampai rilis mendarat, versi lama tetap
  mengirim tanggal mundur dan basis data menerima campuran keduanya.
- **Sedang** — `proses_absen_bk.php` mencatat absensi walau unggah foto gagal.
  Blok `if ($_FILES['foto_bukti']['error'] == 0)` tidak punya `else`, jadi
  galat seperti `UPLOAD_ERR_INI_SIZE` dilewati diam-diam dan barisnya tersimpan
  dengan `foto_bukti` kosong. Dua endpoint absen lain melempar Exception dalam
  keadaan yang sama. Perlu putusan lebih dulu: apakah foto memang wajib.
- **Rendah** — blok `catch` di keempat endpoint unggah repo API mengirim
  `$e->getMessage()` mentah ke aplikasi. Pesannya biasanya pesan aplikasi yang
  berguna bagi guru ("Foto bukti wajib diupload"), tapi eksepsi basis data
  bocor lewat jalur yang sama. Memperbaikinya berarti memisahkan eksepsi
  aplikasi dari eksepsi sistem.
- **Rendah** — `app/absen_hp_backup.tsx:115` dan `:167` di ClassyncApp masih
  memakai `toISOString()`. Namanya terdengar seperti berkas cadangan, tapi di
  Expo Router **setiap berkas di `app/` adalah rute hidup** — `/absen_hp_backup`
  bisa dibuka. Generator `scripts/generate_absen_hp.py:238` dan `:279` juga
  dapat menghidupkan kembali pola yang sama.

### Sudah ditutup

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
  berbeda (`login.php`, `get_honor.php`, `get_profil_guru.php`). Layak dihapus.
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

- `guru_area/` — guru sekarang login lewat ClassyncApp. Jangan masukkan ke
  daftar uji, tapi **tetap ikutkan dalam perubahan keamanan**: berkasnya masih
  hidup dan bisa dieksekusi di server.
- `admin/admin_notifikasi.php` — sama, sudah tidak digunakan.
- `classync/api/` — hanya `proses_absen_siswa.php` dan
  `proses_absen_manual.php` yang masih dipanggil panel admin. Sisanya API lama.
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
