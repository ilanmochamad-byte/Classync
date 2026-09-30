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

## Uji di PC kiosk

Kerjakan di luar jam kiosk (hari Minggu, atau setelah siswa pulang), karena
ada restart. Sisihkan sekitar 3 jam. Pakai akun admin. Siapkan 3–4 relawan
dewasa yang sudah menandatangani persetujuan uji coba; tidak ada siswa yang
ikut.

### Fase A: driver, ADC, dan jembatan di jendela konsol

1. Catat versi Windows (`winver`, harus 22H2 build 19045), versi Chrome
   (`chrome://version`), dan apakah kiosk memakai Chrome atau Edge. Buat
   titik pemulihan sistem bernama "sebelum-sidik-jari".
2. Colok alat. Di Device Manager, catat nama, kategori, Hardware ID (harus
   diawali `USB\VID_05BA&PID_000A`), serta penyedia dan versi driver. Pastikan
   tidak ada perangkat lunak DigitalPersona, Altus, atau bawaan "Solution" di
   Apps & features; ADC tidak bisa berdampingan dengan produk DigitalPersona
   lain.
3. Kalau sudah ada driver WBF (dipasang Windows Update): Uninstall device,
   centang "Delete the driver software for this device", lalu cabut alat.
4. Pasang driver non-WBF 4.1.1.221 dari `hidglobal.com/drivers/49061`. Colok
   alat, lalu pastikan versinya di Device Manager.
5. Pasang HID Authentication Device Client 5.2.0 (64-bit) dari
   `digitalpersona.hidglobal.com/lite-client/`, lalu restart. Di
   `services.msc`, catat nama layanan HID dan pastikan statusnya Running.
6. Salin paket ke `C:\JembatanUji\`, lalu dari PowerShell di folder itu:

   ```powershell
   .\jembatan-sidik-jari.exe
   ```

   Jendela ini menampilkan log jembatan; menutupnya menghentikan jembatan.
   Buka `http://127.0.0.1:47890/` di Chrome, lalu klik halamannya: WebSDK
   hanya mengirim sampel ke jendela yang sedang aktif. Kalau panel Authentication
   Device Client menampilkan pembaca, lanjut ke fase B.
7. Kalau pembaca tidak muncul: hapus driver non-WBF seperti langkah 3, pasang
   driver WBF 5.0.0.5 dari `hidglobal.com/drivers/39477`, restart, jalankan
   Repair pada ADC di Apps & features, lalu ulangi langkah 6.
8. Kalau keduanya gagal: berhenti, kirim tangkapan layar Device Manager dan
   log halaman, lalu pulihkan dari titik pemulihan.
9. Setelah salah satu driver terbukti: di `gpedit.msc`, Computer Configuration >
   Administrative Templates > Windows Components > Windows Update, aktifkan
   "Do not include drivers with Windows Updates" (di sebagian versi ada di
   subfolder "Manage updates offered from Windows Update"). Tanpa ini, Windows
   Update bisa mengganti driver diam-diam.

Folder data `C:\ProgramData\JembatanSidikJari` dibuat saat jembatan pertama
jalan. Buka `kunci.bin` di dalamnya dengan Notepad (sebagai admin): isinya
harus biner acak, bukan JSON yang terbaca. Itu tanda DPAPI bekerja.

### Fase B: tangkap, daftar, cocok

Semuanya di halaman `http://127.0.0.1:47890/`, dengan jembatan di jendela
konsol.

1. **Tangkap.** Lima tempelan dengan format Raw, lalu lima dengan PNG (pilih di
   panel Authentication Device Client). Periksa ukuran, DPI dari alat, dan
   "Panjang piksel = lebar × tinggi".
2. **Daftar.** Setiap relawan mendaftarkan 6 jari (telunjuk, tengah, manis;
   kanan dan kiri), 4 tempelan per jari. Coba daftarkan ulang jari R1 telunjuk
   kanan dengan kode lain: harus ditolak sebagai jari ganda.
3. **Kalibrasi & ukur.** "Hitung kalibrasi", pilih DPI dengan "Jarak" terbesar,
   lalu "Terapkan DPI" (klik dua kali). Kerjakan sebelum jembatan dimulai
   ulang: gambar pendaftaran hanya ada di memori.
4. **Identifikasi.** Sebelum setiap tempelan, pilih "Yang menempel sekarang".
   Tempel setiap jari terdaftar 5 kali. Tempel jempol dan kelingking 5 kali
   per orang dengan pilihan "Jari tidak terdaftar".
5. **Kalibrasi & ukur.** "Ukur waktu 1:N", sebaiknya setelah beberapa
   identifikasi.
6. **Identifikasi.** "Kirim ulang sampel terakhir": harus ditolak (409).
7. **Detak.** "Detak sekarang". Cabut alat, detak lagi: status alat harus
   berubah. Colok kembali. Nyalakan "Otomatis tiap 60 detik" selama 30 menit.

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
   nama.
2. "Hapus semua data uji" (klik dua kali).
3. Dari PowerShell admin di folder paket:

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\hapus-layanan.ps1 -HapusData
   ```

   Ketik `HAPUS` saat diminta.
4. Yang dibiarkan terpasang: driver, ADC, kebijakan driver, dan kebijakan
   Chrome. Semuanya dipakai lagi di 4.2.
5. Kembalikan kiosk seperti biasa, lalu pastikan absen QR/NISN tetap jalan.

### Kriteria lanjut ke 4.2

- Salah orang: 0.
- Jari tidak terdaftar yang diterima: 0.
- Dikenali pada tempelan pertama ≥ 90%, dan dalam tiga tempelan ≥ 99%.
- Ujung ke ujung di PC ≤ 1 detik pada galeri 500 templat.

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

### Data

Di `C:\ProgramData\JembatanSidikJari` (di Mac: folder `--data`):

- `kunci.bin`: ID perangkat, kunci HMAC, dan kunci templat. Di Windows
  dilindungi DPAPI LocalMachine; yang menjaganya dari akun kiosk adalah ACL
  folder. Kalau `kunci.bin` rusak, atau hilang padahal `templat.json` ada,
  jembatan berhenti, bukan membuat kunci baru.
- `templat.json`: templat terenkripsi AES-256-GCM, satu rekaman per tempelan,
  terikat pada `SJ1|templat|identitas|jari|urutan|versi`. Rekaman yang
  dipindah ke identitas lain gagal didekripsi. Kolom `versi` memuat versi
  SourceAFIS dan DPI ekstraksi, misalnya `sourceafis-net-3.14.0-500`.

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
- Probe identifikasi terakhir yang disimpan di memori untuk `/ukur`.
- Format PNG; di produksi hanya Raw.
- Ambang 50 dan selisih 10, diganti angka dari laporan uji.
- Identifikasi dari halaman kiosk, yang baru dibuka di 4.4.
- Pemasangan kunci HMAC di server (pairing), yang dikerjakan di 4.2.
