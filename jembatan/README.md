# Jembatan sidik jari (prototipe 4.1)

Layanan kecil untuk PC kiosk absensi siswa. Halaman kiosk menangkap sidik jari
lewat HID Authentication Device Client (ADC) dengan pembaca U.are.U 4500, lalu
mengirimkannya ke jembatan ini. Jembatan mencocokkannya 1:N dengan SourceAFIS
dan menandatangani hasilnya dengan HMAC, supaya server bisa memastikan
absensi itu benar-benar datang dari kiosk.

Status: **prototipe**. Hanya untuk diuji di PC kiosk dengan relawan dewasa.
Belum tersambung ke server sekolah; sambungannya dikerjakan di sub-langkah 4.2.

Folder ini tidak ikut deploy: `.cpanel.yml` tidak menyalinnya ke server.

## Isi folder

| Berkas | Guna |
|---|---|
| `JembatanSidikJari.csproj`, `packages.lock.json` | Proyek .NET 10. Semua paket dipin dan dikunci beserta hash isinya. |
| `Program.cs` | Pintu masuk: layanan/konsol, Kestrel di `127.0.0.1:47890`, penjaga Host/Origin/CORS, rute, cek alat lewat WMI. |
| `Brankas.cs` | Kunci perangkat, tanda tangan HMAC, dan enkripsi templat. |
| `Pencocok.cs` | Membaca sampel, ekstraksi SourceAFIS, identifikasi 1:N, pendaftaran, kalibrasi, ukur waktu. |
| `wwwroot/uji.html` | Halaman uji di `http://127.0.0.1:47890/`, tertanam di dalam `.exe`. |
| `pasang-layanan.ps1`, `hapus-layanan.ps1` | Memasang dan mencabut layanan Windows. |
| `kebijakan-chrome.reg`, `cabut-kebijakan-chrome.reg` | Mengizinkan dan mencabut akses halaman kiosk ke 127.0.0.1 di Chrome/Edge. |

## Membangun (di Mac)

Butuh .NET 10 SDK. Kalau belum ada, pasang ke folder pengguna tanpa sudo:

```bash
curl -sSL https://dot.net/v1/dotnet-install.sh -o dotnet-install.sh
bash dotnet-install.sh --channel 10.0 --install-dir "$HOME/.dotnet" --no-path
```

Menghasilkan satu `.exe` untuk Windows 64-bit. Runtime .NET ikut di dalamnya,
jadi PC kiosk tidak perlu memasang apa pun:

```bash
cd jembatan
DOTNET_CLI_TELEMETRY_OPTOUT=1 ~/.dotnet/dotnet publish -c Release -r win-x64 -p:RestoreLockedMode=true -o "$HOME/jembatan-terbit"
```

- `RestoreLockedMode` membuat publish gagal kalau paket tidak sama dengan
  `packages.lock.json`. Kalau paket memang diganti, perbarui lock file dengan
  `dotnet restore --force-evaluate`, lalu periksa perubahannya.
- Jangan menaikkan `SixLabors.ImageSharp` ke 3.x: lisensinya berganti dan
  kemungkinan besar memutus SourceAFIS 3.14.0.
- Mengganti versi SourceAFIS berarti semua jari didaftarkan ulang, karena
  templat terikat versi pustakanya.

Menjalankan di Mac untuk pengembangan, tanpa alat:

```bash
DOTNET_CLI_TELEMETRY_OPTOUT=1 ~/.dotnet/dotnet run -- --pengembangan --data /tmp/jembatan-data
```

Lalu buka `http://127.0.0.1:47890/`. Dalam mode pengembangan, halaman uji
menampilkan pemilih berkas PNG sebagai pengganti alat. Di luar Windows,
jembatan menolak berjalan tanpa `--pengembangan`, dan layanan Windows
menolak `--pengembangan`.

## Paket untuk PC kiosk

Salin ke USB: `jembatan-sidik-jari.exe`, `pasang-layanan.ps1`,
`hapus-layanan.ps1`, `kebijakan-chrome.reg`, `cabut-kebijakan-chrome.reg`, dan
README ini. Di PC kiosk, cocokkan hash `.exe` dengan daftar yang diberikan saat
paket dibuat:

```powershell
Get-FileHash .\jembatan-sidik-jari.exe -Algorithm SHA256
```

Panel Jembatan di halaman uji menampilkan versi `.exe` yang sedang jalan,
berbentuk `0.1.3+<commit>`. Bangun paket dari folder kerja yang bersih, yaitu
setelah semua perubahan di-commit, supaya commit itu memang isi paketnya.

## Uji di PC kiosk

Kerjakan di luar jam kiosk (hari Minggu, atau setelah siswa pulang), karena
ada restart. Sisihkan sekitar 3 jam. Pakai akun admin. Siapkan 3–4 relawan
dewasa yang sudah menandatangani persetujuan uji coba; tidak ada siswa yang
ikut.

Pembaca sekolah sudah diuji dengan dua driver, dan hasilnya berbeda. Yang
menentukan ukuran dan DPI gambar adalah driver, bukan pembacanya:

| | Driver DigitalPersona | Driver WBF |
|---|---|---|
| Dipakai di | komputer lain (uji 1 dan 3 Oktober 2026) | PC kiosk (uji 3 Oktober 2026) |
| Sampel Raw | 500 × 550, 700 DPI, 12 byte ekor bernilai nol | 320 × 360, 508 DPI, tanpa ekor |
| Pembaca menurut ADC | Optical, UID tetap | Unknown, UID berganti tiap alat dicolok |
| Penangkapan | terus-menerus | berhenti setelah tiap tempelan; halaman memulainya lagi |
| Kalibrasi 3 Oktober | 700 terbaik (jarak 42,2) | 500 terbaik (jarak 109,7); 700 hanya 8,2 |

PC kiosk memakai driver WBF yang dipasang Windows sendiri, dan pembacanya
langsung tampil di ADC. Uji di komputer lain cukup untuk mencoba alur halaman,
tetapi kriteria lanjut ke 4.2 dinilai di PC kiosk. Mengganti driver mengubah
ukuran dan DPI gambar, jadi semua jari harus didaftarkan ulang.

### Fase A: driver, ADC, dan jembatan di jendela konsol

1. Catat versi Windows (`winver`, harus 22H2 build 19045), versi Chrome
   (`chrome://version`), dan apakah kiosk memakai Chrome atau Edge. Buat
   titik pemulihan sistem bernama "sebelum-sidik-jari".
2. Colok alat. Di Device Manager, catat nama, kategori, Hardware ID (harus
   diawali `USB\VID_05BA&PID_000A`), serta penyedia dan versi driver. Pastikan
   tidak ada perangkat lunak DigitalPersona, Altus, atau bawaan "Solution" di
   Apps & features; ADC tidak bisa berdampingan dengan produk DigitalPersona
   lain.
3. Pasang HID Authentication Device Client 5.2.0 (64-bit) dari
   `digitalpersona.hidglobal.com/lite-client/`, lalu restart. Di
   `services.msc`, catat nama layanan HID dan pastikan statusnya Running.
4. Salin paket ke `C:\JembatanUji\`, lalu dari PowerShell di folder itu:

   ```powershell
   .\jembatan-sidik-jari.exe
   ```

   Jendela ini menampilkan log jembatan; menutupnya menghentikan jembatan.
   Buka `http://127.0.0.1:47890/` di Chrome, lalu klik halamannya: WebSDK
   hanya mengirim sampel ke jendela yang sedang aktif. Kalau panel
   Authentication Device Client menampilkan pembaca, lanjut ke fase B dengan
   driver yang ada. Di PC kiosk itu driver WBF bawaan Windows.
5. Kalau pembaca tidak muncul dengan driver WBF: di Device Manager, Uninstall
   device dengan centang "Delete the driver software for this device", cabut
   alat, pasang driver DigitalPersona 4.1.1.221 dari
   `hidglobal.com/drivers/49061`, colok alat, lalu ulangi langkah 4.
6. Kalau pembaca tidak muncul dengan driver DigitalPersona: hapus driver itu
   dengan cara yang sama, pasang driver WBF 5.0.0.5 dari
   `hidglobal.com/drivers/39477`, restart, jalankan Repair pada ADC di Apps &
   features, lalu ulangi langkah 4.
7. Kalau keduanya gagal: berhenti, kirim tangkapan layar Device Manager dan
   log halaman, lalu pulihkan dari titik pemulihan.
8. Setelah salah satu driver terbukti: di `gpedit.msc`, Computer Configuration >
   Administrative Templates > Windows Components > Windows Update, aktifkan
   "Do not include drivers with Windows Updates" (di sebagian versi ada di
   subfolder "Manage updates offered from Windows Update"). Tanpa ini, Windows
   Update bisa mengganti driver diam-diam, dan gambar dari driver lain tidak
   cocok dengan galeri yang sudah ada.

Folder data `C:\ProgramData\JembatanSidikJari` dibuat saat jembatan pertama
jalan. Buka `kunci.bin` di dalamnya dengan Notepad (sebagai admin): isinya
harus biner acak, bukan JSON yang terbaca. Itu tanda DPAPI bekerja.

### Fase B: tangkap, daftar, cocok

Semuanya di halaman `http://127.0.0.1:47890/`, dengan jembatan di jendela
konsol. Jangan tutup jendela itu sebelum fase C langkah 1: jembatan ikut
berhenti, dan gambar pendaftaran yang hanya ada di memori hilang.

1. **Tangkap.** Lima tempelan dengan format Raw, lalu lima dengan PNG (pilih di
   panel Authentication Device Client). Periksa ukuran, DPI dari alat, dan
   baris "Panjang piksel". Yang diharapkan bergantung driver:

   - Driver WBF: ukuran 320 × 360, DPI dari alat 508, "Panjang piksel"
     berbunyi `115200 = lebar × tinggi`, dan baris byte ekor kosong.
   - Driver DigitalPersona: ukuran 500 × 550, DPI dari alat 700, "Panjang
     piksel" berbunyi `275012 = lebar × tinggi + 12 byte ekor`, dan baris
     "Byte ekor (heksadesimal)" berisi dua belas `00`.
   - PNG: ukurannya sama dengan Raw. Baris DPI, panjang piksel, dan byte ekor
     kosong.

   Kalau "Panjang piksel" merah dengan tulisan TIDAK, jembatan akan menolak
   sampel Raw itu. Berhenti di sini dan kirim tangkapan layarnya.
2. **Daftar.** Mulai dari galeri kosong: kalau masih ada data uji lama, klik
   "Hapus semua data uji" di tab Laporan (klik dua kali). Pendaftaran pertama
   di galeri kosong menetapkan DPI galeri dari DPI alat, dan hasil pendaftaran
   menyebut "DPI galeri …": angkanya harus sama dengan "DPI dari alat" di tab
   Tangkap. Format pindah sendiri ke Raw saat tab Daftar dibuka. Setiap
   relawan mendaftarkan 6 jari (telunjuk, tengah, manis; kanan dan kiri), 4
   tempelan per jari. Periksa pilihan Jari sebelum menempel: tabel galeri
   harus memuat 6 jari per relawan. Coba daftarkan ulang jari R1 telunjuk
   kanan dengan kode lain: harus ditolak sebagai jari ganda.

   Kalau muncul peringatan merah "DPI alat …, tetapi DPI galeri …", galeri itu
   dibuat dengan driver atau versi lain. Hapus semua data uji, lalu daftar
   ulang.
3. **Kalibrasi & ukur.** "Hitung kalibrasi". Halaman memilih DPI sendiri dan
   menolak DPI yang jaraknya ≤ 0. Kalau catatan kejadian berbunyi "tidak perlu
   diganti", biarkan. Kalau berbunyi "sebaiknya diganti", klik "Terapkan DPI"
   dua kali. Kerjakan sebelum jembatan dimulai ulang: gambar pendaftaran hanya
   ada di memori.
4. **Identifikasi.** Klik "Mulai urutan", lalu ikuti tulisan "Sekarang: …".
   Urutannya berputar: setiap relawan melewati semua jarinya lima putaran,
   satu tempelan per jari per putaran. Kalau halaman meminta "ulangi jari yang
   sama", tempelkan jari itu lagi; setelah tiga kali gagal halaman pindah
   sendiri. Sesudah lima putaran, relawan menempelkan jempol atau kelingking
   5 kali sebagai jari tidak terdaftar. Kalau seorang relawan berhalangan,
   pakai "Lewati relawan ini". Sampel hanya dihitung selama tab Identifikasi
   terbuka.
5. **Identifikasi, satu jari 8 kali.** Setelah urutan selesai, pilih satu jari
   terdaftar di "Yang menempel sekarang", lalu tempelkan jari itu 8 kali
   berturut-turut. Perhatikan gambar di bawah hasil dan angka "mirip tempelan
   sebelumnya". Pada uji 3 Oktober di PC kiosk, tempelan kelima dan seterusnya
   pada jari yang sama selalu gagal, dan sebabnya belum diketahui. Rekaman
   langkah inilah yang akan menunjukkannya.
6. **Kalibrasi & ukur.** "Ukur waktu 1:N", sebaiknya setelah beberapa
   identifikasi. Angkanya hanya berlaku untuk komputer tempat uji berjalan.
7. **Identifikasi.** "Kirim ulang sampel terakhir": harus ditolak (409).
8. **Detak.** "Detak sekarang". Cabut alat, detak lagi: status alat harus
   berubah. Colok kembali. Nyalakan "Otomatis tiap 60 detik" selama 30 menit,
   dengan halaman dan jendela konsol tetap terbuka.

### Fase C: layanan dan akun standar

1. Tutup jendela konsol jembatan. Buka PowerShell dengan "Run as
   administrator", pindah ke folder paket, lalu:

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\pasang-layanan.ps1
   ```

2. Kalau PC belum punya akun standar (bukan admin), buat akun lokal
   `uji-kiosk`.
3. Restart, masuk sebagai `uji-kiosk`, buka `http://127.0.0.1:47890/`. Harus
   langsung menjawab (bukti start otomatis), identifikasi relawan R1 harus
   berhasil (bukti templat bertahan), dan penangkapan jari harus jalan.
4. Masih sebagai `uji-kiosk`, ketiga hal ini harus **ditolak**: membuka
   `C:\ProgramData\JembatanSidikJari`, menjalankan `sc stop JembatanSidikJari`,
   dan menghapus `.exe` di `C:\Program Files\JembatanSidikJari`.
5. **Uji dari halaman kiosk sungguhan**, tanpa deploy apa pun. Buka
   `https://smkt.alhasan.co.id/classync/absen-siswa.php` (jangan mengabsen
   siapa pun), tekan F12, buka Console, lalu jalankan satu per satu. Kalau
   Chrome meminta, ketik `allow pasting` dulu.

   ```js
   fetch('http://127.0.0.1:47890/status').then(r => r.json()).then(console.log, console.error)
   ```

   ```js
   fetch('https://127.0.0.1:52181/get_connection').then(r => r.text()).then(console.log, console.error)
   ```

   Catat apakah muncul permintaan izin. Tutup dengan Esc; jangan pilih Allow
   maupun Block, supaya tidak ada keputusan yang tersimpan. Lalu pasang
   `kebijakan-chrome.reg` (klik dua kali, butuh admin), buka `chrome://policy`,
   klik "Reload policies", dan pastikan `LoopbackNetworkAllowedForUrls`
   berstatus OK. Ulangi kedua baris: kali ini harus jalan tanpa permintaan
   izin.

   Terakhir, pastikan jembatan sendiri menolak asal lain. Uji ini lewat
   PowerShell, bukan Console situs lain: dari situs lain Chrome sudah menahan
   permintaannya lebih dulu, sehingga jembatan tidak pernah teruji. Pakai
   `curl.exe`, bukan `curl`, karena di Windows PowerShell `curl` adalah nama
   lain `Invoke-WebRequest`:

   ```powershell
   curl.exe -s -i -H "Origin: https://example.com" http://127.0.0.1:47890/status
   ```

   Baris pertama jawabannya harus `HTTP/1.1 403 Forbidden`.

### Fase D: laporan dan bersih-bersih

1. Di halaman uji, tab Laporan: "Susun laporan", "Salin", lalu kirim ke
   Claude. Isinya hanya angka dan metadata: tanpa gambar, templat, kunci, atau
   nama. Catatannya disimpan di peramban, jadi tetap utuh walau halaman dimuat
   ulang atau jembatan berpindah dari konsol ke layanan.
2. "Hapus semua data uji" (klik dua kali), lalu "Kosongkan laporan" (klik dua
   kali).
3. Dari PowerShell admin di folder paket:

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\hapus-layanan.ps1 -HapusData
   ```

   Ketik `HAPUS` saat diminta.
4. Yang dibiarkan terpasang: driver, ADC, kebijakan driver, dan kebijakan
   Chrome. Semuanya dipakai lagi di 4.2.
5. Kembalikan kiosk seperti biasa, lalu pastikan absen QR/NISN tetap jalan.

### Kriteria lanjut ke 4.2

- Salah orang: 0. Yang dihitung baris "SALAH ORANG"; jari lain dari orang
  yang sama dicatat terpisah dan tidak termasuk.
- Jari tidak terdaftar yang diterima: 0.
- Orang dikenali pada tempelan pertama ≥ 90%, dan dalam tiga tempelan ≥ 99%.
- Ujung ke ujung di PC kiosk ≤ 1 detik pada galeri 500 templat.

Keadaan per 3 Oktober 2026, dari uji di PC kiosk dengan driver WBF:

- Waktu terpenuhi: galeri 500 templat butuh median 80 ms (p95 90 ms) dan
  ekstraksi 21 ms, di Windows 10 build 19045 dengan 4 prosesor.
- Salah orang 0 dan jari tidak terdaftar yang diterima 0, pada 96 tempelan.
- Tingkat pengenalan belum bisa dinilai. Galeri uji itu masih memakai 700 DPI,
  dan tempelan kelima dan seterusnya pada jari yang sama selalu gagal.

## Titik mundur

| Bagian | Cara mundur |
|---|---|
| Driver dan ADC | Titik pemulihan "sebelum-sidik-jari"; atau hapus driver lewat Device Manager (centang "Delete the driver software") dan ADC lewat Apps & features. |
| Layanan | `hapus-layanan.ps1`. Tanpa `-HapusData`, kunci dan templat dibiarkan. |
| Kebijakan Chrome/Edge | `cabut-kebijakan-chrome.reg`. |
| Kebijakan driver | Kembalikan "Do not include drivers with Windows Updates" ke Not Configured. |
| Akun `uji-kiosk` | Hapus di Settings > Accounts. |
| Repositori | Revert commit-nya. Folder ini tidak ter-deploy, jadi server tidak tersentuh. |

## Cara kerjanya

### Rute

Semua rute hanya di `127.0.0.1:47890`. Permintaan dengan Host selain
`127.0.0.1`/`localhost` ditolak, Origin yang tidak terdaftar untuk rute itu
ditolak sebelum diproses, permintaan tanpa Origin hanya boleh GET, POST wajib
`application/json`, dan kiriman dibatasi 2 MB.

| Rute | Asal yang boleh | Guna |
|---|---|---|
| `GET /` | halaman sendiri | Halaman uji. |
| `GET /status` | halaman sendiri, kiosk | Versi, mode, ID perangkat, waktu PC, alat menurut WMI, ringkasan galeri. |
| `POST /detak` | halaman sendiri, kiosk | Detak bertanda tangan dengan status alat. |
| `GET /galeri` | halaman sendiri | Daftar identitas dan jari terdaftar, tanpa templat. |
| `POST /daftar` | halaman sendiri | Pendaftaran uji: empat tempelan satu jari. |
| `POST /identifikasi` | halaman sendiri | Identifikasi 1:N, hasil yang diterima ditandatangani. |
| `POST /kalibrasi` | halaman sendiri | Skor per DPI, atau menerapkan DPI baru. |
| `POST /ukur` | halaman sendiri | Waktu pencocokan untuk galeri 50–2000 templat. |
| `POST /hapus-uji` | halaman sendiri | Menghapus templat, gambar di memori, dan catatan sampel. |

"Kiosk" berarti `https://smkt.alhasan.co.id`. Tidak ada rute yang
mengembalikan templat atau gambar, dan tidak ada rute yang menandatangani isi
kiriman pemanggil. Jembatan tidak pernah menghubungi server sendiri.

### Sampel

Halaman meneruskan sampel dari WebSDK apa adanya; jembatan yang membacanya.

- **Raw:** `Data` sebuah BioSample, yaitu base64url dari JSON
  `{Data, Format}`. `Data` di dalamnya adalah piksel 8-bit baris demi baris
  (base64url), dan `Format` memuat `iWidth`, `iHeight`, dan `iXdpi`. Struktur
  ini tidak didokumentasikan HID; yang tertulis di sini hasil pengukuran.
  Ukuran dan DPI-nya bergantung driver: 320 × 360 pada 508 DPI lewat WBF, dan
  500 × 550 pada 700 DPI lewat driver DigitalPersona.
- **Ekor:** pikselnya boleh diikuti byte yang bukan gambar. Lewat driver
  DigitalPersona, U.are.U 4500 mengirim 12 byte bernilai nol setelah 500 × 550
  piksel (275.012 byte). Lewat WBF tidak ada ekor. Jembatan menerima kelebihan
  yang kurang dari sisi terpendek gambar, dan hanya memakai lebar × tinggi
  byte pertama, juga untuk penjaga sampel kembar. Data yang kurang, atau yang
  lebihnya sepanjang sisi terpendek atau lebih, ditolak: itu tanda `Format`
  tidak menggambarkan datanya.
- **PNG:** base64url berkas PNG. Hanya untuk prototipe.

### Data

Di `C:\ProgramData\JembatanSidikJari` (di Mac: folder `--data`):

- `kunci.bin`: ID perangkat, kunci HMAC, dan kunci templat. Di Windows
  dilindungi DPAPI LocalMachine; yang menjaganya dari akun kiosk adalah ACL
  folder. Kalau `kunci.bin` rusak, atau hilang padahal `templat.json` ada,
  jembatan berhenti, bukan membuat kunci baru.
- `templat.json`: templat terenkripsi AES-256-GCM, satu rekaman per tempelan,
  terikat pada `SJ1|templat|identitas|jari|urutan|versi`. Rekaman yang
  dipindah ke identitas lain gagal didekripsi. Kolom `versi` memuat versi
  SourceAFIS dan DPI ekstraksi, misalnya `sourceafis-net-3.14.0-508`.

DPI galeri mengikuti alat. Pendaftaran pertama di galeri kosong memakai DPI
yang dilaporkan sampelnya. Sebelum itu, dan untuk sampel tanpa DPI seperti
PNG, jembatan memakai 500. Galeri yang sudah berisi memakai DPI rekamannya,
dan hanya berubah lewat "Terapkan DPI".

Gambar sidik jari tidak pernah ditulis ke disk. Gambar pendaftaran dan probe
identifikasi terakhir hanya disimpan di memori, untuk kalibrasi dan ukur waktu.

### Tanda tangan

Pesan kanonik, dengan setiap kolom diperiksa polanya sehingga `|` tidak bisa
disusupkan, lalu HMAC-SHA256 dalam hex huruf kecil:

```
SJ1|absen|<perangkat>|<tantangan 64 hex>|<identitas>|<skor>
SJ1|detak|<perangkat>|<tantangan 64 hex>|alat:<0 atau 1>
```

Vektor uji, untuk memastikan server menyusun pesan dan HMAC yang sama:

| | Nilai |
|---|---|
| Kunci HMAC (hex) | `000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f` |
| Tantangan | `a0a1a2a3a4a5a6a7a8a9aaabacadaeafb0b1b2b3b4b5b6b7b8b9babbbcbdbebf` |
| Pesan absen | `SJ1\|absen\|kiosk-uji\|<tantangan>\|siswa:123\|87` |
| HMAC absen | `5143256d84f0681ff427ffdb4f43b170d7aef6defc4cf052d85bad90b758223f` |
| Pesan detak | `SJ1\|detak\|kiosk-uji\|<tantangan>\|alat:0` |
| HMAC detak | `560ca340fdcbcf931d460a0936578fd1ed3976a7aea0af5fef1a38b6a8b65bae` |

```php
hash_hmac('sha256', $pesan, hex2bin('000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f'));
```

### Ambang

Identifikasi diterima kalau skor terbaik ≥ 50 dan unggul ≥ 10 atas identitas
kedua. Pendaftaran memakai ambang 1:1 SourceAFIS (40) untuk keserasian
tempelan dan untuk mencari jari ganda. Angka 50 dan 10 adalah nilai awal
prototipe; angka akhirnya dipilih dari laporan uji di PC kiosk.

Kalibrasi 3 Oktober memberi jarak yang lebar di kedua driver. Di PC kiosk pada
500 DPI, skor sama-jari terendah 126,7 dan beda-jari tertinggi 17. Dengan
driver DigitalPersona pada 700 DPI, angkanya 74,5 dan 32,3. Ambang jangan
diturunkan ke 40: pada uji identifikasi di PC kiosk, telunjuk kiri pernah
mendapat skor 41,3 terhadap telunjuk kanan orang yang sama.

## Mengubah halaman uji

`wwwroot/uji.html` dikunci CSP dengan hash SHA-256 skrip inline-nya. Setelah
skrip itu diubah, hitung ulang hash-nya dari akar repositori, atau peramban
menolak menjalankan skrip:

```bash
python3 - <<'EOF'
import base64, hashlib, re
jalur = 'jembatan/wwwroot/uji.html'
teks = open(jalur, encoding='utf-8').read()
awal = teks.rindex('<script>') + len('<script>')
akhir = teks.index('</script>', awal)
h = base64.b64encode(hashlib.sha256(teks[awal:akhir].encode('utf-8')).digest()).decode()
teks = re.sub(r"'sha256-[A-Za-z0-9+/=]+'", f"'sha256-{h}'", teks, count=1)
open(jalur, 'w', encoding='utf-8', newline='\n').write(teks)
print(h)
EOF
```

Hash itu dihitung atas akhir baris LF. `.gitattributes` menjaga `uji.html`
tetap LF dan kedua berkas `.reg` tetap CRLF, di sistem operasi apa pun.

## Hanya untuk prototipe

Harus dicabut atau diubah sebelum dipakai untuk siswa (4.2 sampai 4.4):

- Pendaftaran tanpa token dari server. Di 4.3 pendaftaran butuh token sekali
  pakai dari admin.
- Rute `/kalibrasi`, `/ukur`, `/hapus-uji`, dan `/galeri`, serta halaman uji
  di `/` beserta pemilih berkas mode pengembangan.
- Identitas `uji:` di `Brankas.cs`.
- Probe identifikasi terakhir yang disimpan di memori untuk `/ukur`, dan
  `skor_probe_sebelumnya` di jawaban `/identifikasi`: kemiripan dengan
  tempelan sebelumnya, hanya untuk menyelidiki tempelan yang gagal.
- Catatan laporan yang disimpan halaman uji di `localStorage` peramban.
- Format PNG; di produksi hanya Raw.
- Ambang 50 dan selisih 10, diganti angka dari laporan uji.
- Identifikasi dari halaman kiosk, yang baru dibuka di 4.4.
- Pemasangan kunci HMAC di server (pairing), yang dikerjakan di 4.2.
