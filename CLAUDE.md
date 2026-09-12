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

### Masih terbuka

- **Tinggi** — `ini_set('display_errors', 1)` menyala di **57 berkas**
  (45 di repo API, 12 di sini). Inilah yang mengubah kesalahan kecil jadi
  mudah dieksploitasi dan membocorkan jalur berkas serta potongan query.
  `.htaccess` dan php.ini **tidak bisa** mematikannya — `ini_set()` saat
  runtime selalu menang, jadi barisnya harus dicabut dari kode.
- **Tinggi** — dump `.sql`, `proxy-log.txt`, `*.zip`, `error_log` kemungkinan
  masih ada di dalam webroot dan bisa diunduh siapa pun. Belum didata:
  jalankan `find` dulu, jangan hapus tanpa melihat daftarnya.
- **Sedang** — unggahan foto tanpa daftar putih ekstensi di
  `proses_absen_mengajar.php`, `proses_absen_sederhana.php`,
  `proses_absen_bk.php` (ketiganya di repo API). Pola yang benar ada di
  `absensi_pkl.php` (`getimagesize()`). Turun dari Kritis karena `.htaccess`
  di folder unggahan sudah melumpuhkan eksekusi — tapi berkas non-gambar
  masih bisa tersimpan.
- **Sedang** — login tanpa `session_regenerate_id(true)`, tanpa pembatasan
  percobaan, tanpa token CSRF di form admin.
- **Sedang** — 56 berkas membuka koneksi database sendiri padahal `db.php`
  sudah menyediakan `$conn`.
- **Sedang** — zona waktu di ClassyncApp: `date.toISOString()` menghasilkan
  UTC, jadi antara 00.00-07.00 WIB tanggal yang dikirim mundur satu hari.
  Ada di `monitoring_siswa.tsx:96`, `refleksi.tsx:151`, `buat_jurnal.tsx:95`,
  `pengajuan_absensi.tsx:254` — tiga yang terakhir **menulis** tanggal ke
  basis data, dan itu bersinggungan dengan honor. Pola yang benar ada di
  berkas yang sama: `absen-siswa.tsx:106` (`getLocalDateString()`).
  Butuh rilis toko.

### Sudah ditutup

- ~~SQL injection di `get_monitoring_absensi.php`~~ — prepared statement,
  commit `e6a567f` repo API. Endpoint itu juga tidak lagi mengirim pesan galat
  mentah ke pemanggil. Sapuan ulang seluruh repo API tidak menemukan endpoint
  kedua dengan pola yang sama.
- ~~Tidak ada `.htaccess` di folder unggahan~~ — dibuat di `uploads/` dan
  `api/uploads/`, terverifikasi 403. **Bukan** `php_flag engine off` seperti
  saran audit lama: server ini LiteSpeed dengan `AddHandler`, jadi yang
  berlaku `RemoveHandler` + `FilesMatch` + `Options -ExecCGI -Indexes`.
  Isinya diarsipkan di repo, tapi `.cpanel.yml` tidak menyalin `uploads/` —
  salinan server diurus manual.
- ~~`api/auth_middleware.php` rekursif~~ — bukan cacat yang perlu diperbaiki,
  melainkan kode mati. Ketujuh pemanggilnya ada di `guru_area/` dan
  `classync/api/`, keduanya sudah digantikan endpoint repo API dengan nama
  berbeda (`login.php`, `get_honor.php`, `get_profil_guru.php`). Layak dihapus.
- ~~Berkas kembar `guru-area`, `loginguru.php`, `guru_area/index2.php`,
  `api/backup/`~~ — dihapus dari repo (commit `f92eac3`) dan dari server,
  bersama `index3.php`, `index-not.php`, `laporan_honor_salah.php`.

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
