# Classync — aplikasi web (admin & area guru)

Bagian web dari sistem absensi SMK Terpadu Al Hasan. Panel admin (TU & kepala
sekolah), area guru, ekspor PDF/Excel, dan pengirim notifikasi WhatsApp.
Dilayani di `https://smkt.alhasan.co.id/classync/`.

## SISTEM INI SEDANG DIPAKAI GURU SETIAP HARI

Absensi yang tercatat di sini terhubung ke perhitungan honor. Kesalahan bukan
sekadar bug — ia jadi gaji yang salah. Tiga aturan yang mengikat:

1. **Kompatibel mundur.** Aplikasi mobile versi lama masih beredar berminggu-minggu
   setelah versi baru rilis. Jangan pernah membuat perubahan yang memutus mereka.
2. **Satu perubahan, satu waktu.** Ubah satu berkas, buka halamannya, pastikan
   jalan, baru lanjut. Jangan mengubah puluhan berkas sekaligus.
3. **Jangan pernah menyunting berkas dengan mengetik ulang isinya** dari keluaran
   tool yang mungkin terpotong. Pakai `sed -i` atau baca-ubah-tulis.

## Cara perubahan sampai ke produksi

```
laptop  →  git push  →  GitHub  →  cPanel "Update from Remote"  →  "Deploy HEAD Commit"
```

`.cpanel.yml` menyalin folder kode ke
`/DATA/k1807225/public_html/smkt.alhasan.co.id/classync`.

**Penyalinan tidak pernah menghapus.** Menghapus berkas dari repositori TIDAK
menghapusnya dari server — itu harus dilakukan manual lewat File Manager cPanel.
Aturan yang sama inilah yang melindungi 1,8 GB foto absensi.

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

## Tugas tertunda yang perlu ditinjau manusia

**Memindahkan kredensial database keluar dari kode.** Saat ini
`includes/db.php` dan `api/db.php` memuat password dalam teks biasa.
Rencananya:

1. Buat `/DATA/k1807225/config/db-classync.php` di server (di luar webroot)
   berisi keempat variabel `$db_host`, `$db_user`, `$db_pass`, `$db_name`.
2. Ubah kedua `db.php` agar memanggil berkas itu, bukan mendefinisikan sendiri.
3. Uji panel admin dan area guru sebelum melanjutkan.
4. Simpan salinan berkas lama dengan akhiran tanggal, di luar webroot.

Pola ini sudah dipakai di kode ini: `generate_modul_ajar.php` (di repo API)
memanggil `config-api.php` dari luar document root.

Rotasi password sendiri dilakukan dengan **membuat pengguna database baru**
di cPanel, mengalihkan konfigurasi, memastikan jalan, baru menghapus yang lama.
Jangan mengganti password pengguna yang sedang dipakai — sistem akan mati.

## Temuan audit yang masih terbuka

Diurutkan menurut keparahan. Detail lengkap ada di laporan audit terpisah.

- **Kritis** — `get_monitoring_absensi.php` baris 18-20, 43-45, 68: SQL injection
  lewat parameter `kelas`, `search`, `tanggal` yang disisipkan langsung ke query.
- **Kritis** — unggahan foto tanpa daftar putih ekstensi di
  `proses_absen_mengajar.php`, `proses_absen_sederhana.php`, `proses_absen_bk.php`.
  Pola yang benar sudah ada di `absensi_pkl.php` (memakai `getimagesize()`).
  Perlu juga `.htaccess` berisi `php_flag engine off` di tiap folder `uploads/`.
- **Kritis** — dump `.sql`, `proxy-log.txt`, `*.zip`, dan `error_log` masih ada
  di dalam webroot server dan bisa diunduh siapa pun.
- **Tinggi** — `api/auth_middleware.php` baris 17 memanggil `require` terhadap
  dirinya sendiri secara rekursif; isinya sebenarnya salinan `dashboard.php`.
  Tujuh endpoint yang bergantung padanya kemungkinan fatal error.
- **Sedang** — login tanpa `session_regenerate_id(true)`, tanpa pembatasan
  percobaan, dan tanpa token CSRF di form admin.
- **Sedang** — 56 berkas membuka koneksi database sendiri padahal `db.php`
  sudah menyediakan `$conn`.
- **Rendah** — berkas kembar: `index2.php`, `index3.php`, `index-not.php`,
  `laporan_honor_salah.php` (sudah dihapus dari repo, **masih ada di server**),
  serta `loginguru.php`, `api/backup/`, dan berkas `guru-area` tanpa ekstensi
  yang isinya salinan `admin/profil_guru.php` — berkas terakhir ini berisiko
  tersaji sebagai teks biasa dan membocorkan kode sumber.

## Jangan lakukan

- Jangan menulis ulang banyak endpoint sekaligus.
- Jangan menjalankan perintah sinkronisasi yang menghapus (`rsync --delete`,
  `git clean -fd`) di folder mana pun yang berisi `uploads/`.
- Jangan menambahkan `vendor/`, `uploads/`, atau `api-wa/` ke Git.
- Jangan meng-commit berkas `.sql`, log, atau apa pun yang memuat kredensial —
  riwayat Git permanen.
