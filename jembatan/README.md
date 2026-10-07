# Jembatan sidik jari

Layanan kecil untuk PC kiosk absensi siswa. Halaman kiosk menangkap sidik jari
lewat HID Authentication Device Client (ADC) dengan pembaca U.are.U 4500, lalu
mengirimkannya ke jembatan ini. Jembatan mencocokkannya 1:N dengan SourceAFIS
dan menandatangani hasilnya dengan HMAC, supaya server bisa memastikan
absensi itu benar-benar datang dari kiosk.

Status per 0.4.0: prototipe 4.1 sudah diuji di PC kiosk dengan relawan dewasa,
dan sejak 6 Oktober 2026 kiosk dipasangkan dengan server dan mengirim detak
bertanda tangan (sub-langkah 4.2). Sejak 0.3.0 jari siswa dan guru piket bisa
didaftarkan dan dicabut dari panel admin, dengan izin server (4.3). Mulai
versi ini layanan juga melayani identifikasi dari halaman kiosk, dan detak
bisa memuat keadaan pembaca menurut ADC (4.4).

Absensi lewat sidik jari tetap tertutup sampai saklar di berkas konfigurasi
server dinyalakan. Skrip halaman kiosk yang memakai rute ini ada di
`includes/sj_absen_klien.php`; catatannya ada di `CLAUDE.md` di akar
repositori.

Folder ini tidak ikut deploy: `.cpanel.yml` tidak menyalinnya ke server.

## Isi folder

| Berkas | Guna |
|---|---|
| `JembatanSidikJari.csproj`, `packages.lock.json` | Proyek .NET 10. Semua paket dipin dan dikunci beserta hash isinya. |
| `Program.cs` | Pintu masuk: layanan/konsol, Kestrel di `127.0.0.1:47890`, penjaga Host/Origin/CORS, rute, cek alat lewat WMI, dan perintah `--pasangan`. |
| `Brankas.cs` | Kunci perangkat dan sidiknya, tanda tangan HMAC, pemeriksaan izin dari server, dan enkripsi templat. |
| `Pencocok.cs` | Membaca sampel, ekstraksi SourceAFIS, identifikasi 1:N untuk halaman kiosk dan halaman uji, pendaftaran dan pencabutan berizin, gerbang mutu, kalibrasi, ukur waktu. |
| `wwwroot/uji.html` | Halaman uji di `http://127.0.0.1:47890/`, tertanam di dalam `.exe`. Hanya disajikan kalau jembatan dijalankan di jendela konsol. |
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
menampilkan pemilih berkas PNG sebagai pengganti alat. Halaman juga
menyediakan `window.ujiPengembangan` untuk uji otomatis: `pasangAdc()` di
dalamnya memasang ADC tiruan, supaya mulai, berhenti, dan pergantian format
penangkapan bisa diuji tanpa Authentication Device Client. Di luar Windows,
jembatan menolak berjalan tanpa `--pengembangan`, dan layanan Windows
menolak `--pengembangan`.

Argumen jembatan:

| Argumen | Guna |
|---|---|
| `--port <n>` | Port di 127.0.0.1. Bawaannya 47890. |
| `--data <folder>` | Folder data. Bawaannya `C:\ProgramData\JembatanSidikJari`. |
| `--pasangan` | Menampilkan ID perangkat dan kunci HMAC untuk disalin ke server, lalu keluar tanpa menyalakan server. Hanya di jendela konsol; ditolak layanan. |
| `--pengembangan` | Uji di luar PC kiosk. Wajib di luar Windows, dan ditolak layanan Windows. |
| `--asal-kiosk <asal>` | Hanya bersama `--pengembangan`. Mengganti asal halaman kiosk dengan `http://127.0.0.1:<port>` atau `http://localhost:<port>`, untuk menguji rantainya dengan server lokal. |
| `--rute-layanan` | Hanya bersama `--pengembangan`. Hanya membuka rute yang ada dalam mode layanan, dan seperti layanan hanya menerima sampel raw. |

Dua argumen terakhir tidak bisa sampai ke PC kiosk: keduanya menuntut
`--pengembangan`, dan layanan menolak `--pengembangan`.

## Paket untuk PC kiosk

Salin ke USB: `jembatan-sidik-jari.exe`, `pasang-layanan.ps1`,
`hapus-layanan.ps1`, `kebijakan-chrome.reg`, `cabut-kebijakan-chrome.reg`, dan
README ini. Di PC kiosk, cocokkan hash `.exe` dengan daftar yang diberikan saat
paket dibuat:

```powershell
Get-FileHash .\jembatan-sidik-jari.exe -Algorithm SHA256
```

Versi `.exe` yang sedang jalan berbentuk `0.4.0+<commit>`. Versi itu tampil di
keluaran `pasang-layanan.ps1`, di `http://127.0.0.1:47890/status`, dan di panel
admin setelah detak pertama. Bangun paket dari folder kerja yang bersih, yaitu
setelah semua perubahan di-commit, supaya commit itu memang isi paketnya.

## Memasang di PC kiosk dan memasangkannya dengan server

Ini pekerjaan sub-langkah 4.2. Sesudahnya server mengenali kiosk ini, dan
detaknya tampil di panel admin, menu Laporan > Kiosk Sidik Jari. Yang harus
sudah ada:

- di PC kiosk: driver pembaca, ADC, dan kebijakan Chrome dari uji 4.1 (fase A,
  dan fase C langkah 5, di bawah);
- di server: bagian server 4.2 sudah ter-deploy, kedua tabelnya sudah dibuat,
  dan berkas konfigurasi `sidik-jari-classync.php` sudah berisi rahasia
  tantangan.

Kerjakan dengan akun admin, di luar jam kiosk.

1. **Pasang layanan.** Salin paket ke `C:\JembatanUji\`, cocokkan hash
   `.exe`-nya, lalu dari PowerShell "Run as administrator" di folder itu:

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\pasang-layanan.ps1
   ```

   Skrip yang sama dipakai untuk memperbarui `.exe`: layanan lama dihentikan
   dan diganti, sedangkan kunci dan templat di folder data dibiarkan. Di
   keluarannya, baris "Versi" harus berawalan `0.4.0` dan "Mode" harus
   `layanan`.
2. **Tampilkan pasangannya.** Masih di PowerShell admin:

   ```powershell
   & "$env:ProgramFiles\JembatanSidikJari\jembatan-sidik-jari.exe" --pasangan
   ```

   Keluarannya ID perangkat, kunci HMAC, sidik kunci, dan blok empat baris
   untuk `$sj_perangkat`. Perintah ini hanya membaca `kunci.bin`, jadi layanan
   boleh tetap berjalan. Kalau jawabannya "tidak boleh dibaca akun ini",
   PowerShell-nya belum dibuka sebagai administrator.
3. **Salin ke server.** Di cPanel > File Manager, buka
   `/DATA/k1807225/config/sidik-jari-classync.php`, lalu tempel blok empat
   baris itu di dalam `$sj_perangkat = [ ... ];`. Simpan.
4. **Cocokkan sidiknya.** Di panel admin, buka Laporan > Kiosk Sidik Jari.
   Perangkat itu harus tampil berstatus "aktif", dan kolom "Sidik kunci"-nya
   harus sama dengan baris "Sidik kunci" dari langkah 2. Kalau halaman
   menampilkan peringatan "bukan 64 karakter hex", kuncinya terpotong saat
   disalin: ulangi langkah 3.
5. **Uji rantainya.** Dengan pembaca tercolok, buka halaman yang sama di
   Chrome PC kiosk, lalu klik "Uji rantai". Semua barisnya harus hijau, sampai
   "Server menerima detak". Baris tanda tangan merah berarti jembatan tidak
   melihat pembacanya. Muat ulang halaman: perangkat itu kini "hidup".
   Sesudahnya, keluar dari panel admin di PC kiosk.
6. **Bersihkan.** Jalankan `cls` di PowerShell, lalu tutup jendelanya. Kalau
   keluaran langkah 2 sempat disimpan ke berkas, hapus berkas itu.
7. **Akun standar dan start otomatis.** Kerjakan fase C langkah 2 sampai 4 di
   bawah. Pada uji 5 Oktober 2026 langkah itu belum terbukti.
8. **Setelan daya.** Di Settings > System > Power & sleep, setel "Screen" dan
   "Sleep" untuk keadaan tercolok ke "Never". Pada uji 5 Oktober pembaca
   hilang dari ADC beberapa menit setelah PC ditinggal, lalu muncul lagi saat
   PC dipakai, sedangkan Windows tetap melaporkan alatnya terpasang. Layar
   yang mati adalah dugaan terkuatnya; uji terkendalinya belum dilakukan.
   Setelan ini wajib sebelum 4.4.

Kunci HMAC itu rahasia, setara password kiosk: jangan difoto, dikirim lewat
pesan, atau disimpan di Git. Sidik kunci, yang delapan karakter, bukan
rahasia.

Sebagai layanan, jembatan hanya membuka `/status`, `/detak`, `/identifikasi`
untuk halaman kiosk, dan rute pendaftaran berizin. Alamat
`http://127.0.0.1:47890/` menampilkan halaman ringkas, bukan halaman uji.

Sejak kiosk dipasangkan, `hapus-layanan.ps1 -HapusData` ikut menghapus kunci
yang dikenal server. Sesudah itu kiosk harus dipasangkan ulang, dan semua jari
didaftarkan ulang: templat tidak punya salinan di server. Tanpa `-HapusData`,
kunci dan templat tetap ada.

Mencabut atau mengganti pasangan:

- **Mencabut kiosk:** hapus entrinya dari `$sj_perangkat`, atau ubah `'aktif'`
  menjadi `false`. Berlaku seketika, tanpa deploy.
- **Kunci jembatan berganti**, karena folder data terhapus atau PC kiosk
  diganti: ulangi langkah 2 sampai 5. ID perangkatnya ikut berganti, jadi
  entri lama dihapus.

## Memperbarui jembatan di PC kiosk yang sudah dipasangkan

Jalankan `pasang-layanan.ps1` dari paket yang baru, seperti langkah 1 di atas.
Kunci, ID perangkat, dan templat di folder data tidak disentuh, jadi kiosk
tidak perlu dipasangkan ulang dan sidik kuncinya tetap. Sesudahnya:

- baris "Versi" di keluaran skrip harus berawalan versi yang baru. Kolom
  "Versi jembatan" di panel admin ikut berganti setelah detak berikutnya;
- "Uji rantai" di panel admin harus tetap hijau sampai baris terakhir;
- sejak 0.4.0, rute identifikasi harus terbuka untuk halaman kiosk. Dari
  PowerShell di PC kiosk:

  ```powershell
  curl.exe -sS -i -X POST -H "Origin: https://smkt.alhasan.co.id" -H "Content-Type: application/json" -d "{}" http://127.0.0.1:47890/identifikasi
  ```

  Baris pertama jawabannya harus `HTTP/1.1 400 Bad Request`, dan isinya
  memuat "Tantangan tidak sah.": permintaannya sampai ke pencocok, lalu
  ditolak karena memang tanpa tantangan. Jawaban `403 Forbidden` berarti yang
  berjalan masih jembatan sebelum 0.4.0, atau alamat di `Origin` salah ketik.
  Galat sambungan dari `curl` berarti layanannya tidak berjalan.

Jangan menjalankan `hapus-layanan.ps1 -HapusData` untuk memperbarui.

### Sebelum pendaftaran pertama: pastikan tidak ada data uji

Data dari halaman uji (identitas `uji:`) disimpan di folder data yang sama
dengan layanan. Kalau masih ada, relawan yang jarinya terdaftar di sana
ditolak sebagai jari ganda saat didaftarkan sungguhan. Periksa sekali, dengan
akun admin, di luar jam kiosk:

1. Buka `http://127.0.0.1:47890/status` di Chrome PC kiosk. Kalau `templat` di
   bagian `galeri` bernilai 0, tidak ada yang perlu dihapus.
2. Kalau tidak, hentikan layanan dan jalankan jembatan di jendela konsol, dari
   PowerShell "Run as administrator":

   ```powershell
   sc.exe stop JembatanSidikJari
   & "$env:ProgramFiles\JembatanSidikJari\jembatan-sidik-jari.exe"
   ```

   Kalau baris kedua gagal karena port masih dipakai, layanannya belum selesai
   berhenti: tunggu beberapa detik, lalu ulangi baris itu.
3. Buka `http://127.0.0.1:47890/`, tab Laporan, lalu klik "Hapus semua data
   uji" dua kali. Sejak 0.3.0 tombol itu hanya menghapus identitas `uji:`.
   Templat siswa dan guru tidak disentuh; kalau ada, catatan kejadian
   menyebut jumlahnya.
4. Tutup jendela konsol, lalu jalankan `sc.exe start JembatanSidikJari`. Buka
   lagi alamat di langkah 1: `templat` harus 0.

## Uji prototipe di PC kiosk (4.1)

Prosedur ini sudah dijalankan pada 3 dan 5 Oktober 2026; hasilnya ada di
"Kriteria lanjut ke 4.2". Simpan untuk mengulang pengukuran.

Kerjakan di luar jam kiosk (hari Minggu, atau setelah siswa pulang), karena
ada restart. Sisihkan sekitar 3 jam. Pakai akun admin. Siapkan 3–4 relawan
dewasa yang sudah menandatangani persetujuan uji coba; tidak ada siswa yang
ikut.

Halaman uji hanya ada kalau jembatan dijalankan di jendela konsol. Kalau
layanannya sudah terpasang, hentikan dulu dari PowerShell admin, lalu nyalakan
lagi setelah jendela konsol ditutup:

```powershell
sc.exe stop JembatanSidikJari
sc.exe start JembatanSidikJari
```

Tulis `sc.exe`, bukan `sc`. Di PowerShell, `sc` adalah nama lain
`Set-Content`: `sc stop JembatanSidikJari` tidak menghentikan apa pun dan
malah membuat berkas bernama `stop` di folder kerja. Jembatan di jendela
konsol memakai folder data yang sama dengan layanan, jadi kunci dan galerinya
sama.

Pembaca sekolah sudah diuji dengan dua driver, dan hasilnya berbeda. Yang
menentukan ukuran dan DPI gambar adalah driver, bukan pembacanya:

| | Driver DigitalPersona | Driver WBF |
|---|---|---|
| Dipakai di | komputer lain (uji 1 dan 3 Oktober 2026) | PC kiosk (uji 3 dan 5 Oktober 2026) |
| Sampel Raw | 500 × 550, 700 DPI, 12 byte ekor bernilai nol | 320 × 360, 508 DPI, tanpa ekor |
| Pembaca menurut ADC | Optical, UID tetap | Unknown, UID berganti tiap alat dicolok |
| Kalibrasi 3 Oktober | 700 terbaik (jarak 42,2) | 508 jaraknya 129, setara dengan yang terbaik (512: 136,8); 700 jaraknya −8,9 |
| Kalibrasi 5 Oktober, median − p99 | belum diuji | 508 terbaik (126,0); 700: 33,7; 800: −3,8 |

PC kiosk memakai driver WBF yang dipasang Windows sendiri, dan pembacanya
langsung tampil di ADC. Uji di komputer lain cukup untuk mencoba alur halaman,
tetapi kriteria lanjut ke 4.2 dinilai di PC kiosk. Mengganti driver mengubah
ukuran dan DPI gambar, jadi semua jari harus didaftarkan ulang.

Di PC kiosk penangkapan sesekali berhenti sendiri, lalu halaman memulainya
lagi. Sebabnya jendela Chrome yang sempat tidak aktif: WebSDK hanya melayani
jendela yang sedang aktif. Uji 5 Oktober memastikannya. Kedua belas mulai
ulang hari itu terjadi 0,12–0,25 detik setelah jendela kembali aktif, setiap
tangkapan layar diikuti satu mulai ulang, dan 259 tempelan identifikasi lewat
tanpa satu pun. Dengan driver DigitalPersona hal ini belum diamati.

Pembaca juga bisa hilang dari ADC selagi PC ditinggal. Pada uji 5 Oktober itu
terjadi dua kali, 4–5 menit setelah kegiatan terakhir, dan pembacanya muncul
lagi saat PC dipakai. Selama itu detak tetap melaporkan alat terpasang, karena
jembatan memeriksa alat lewat Windows, bukan lewat ADC. Layar yang mati karena
hemat daya adalah dugaan terkuatnya, dan uji terkendalinya belum dilakukan.
Untuk 4.4: layar kiosk tidak boleh mati, dan detak harus ikut memuat keadaan
pembaca menurut ADC. Sejak 0.4.0 jembatan menerima keadaan itu dari halaman
dan ikut menandatanganinya (lihat "Detak ke server").

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
   hanya mengirim sampel ke jendela yang sedang aktif. Baris Penangkapan di
   panel Authentication Device Client mengingatkan kalau jendelanya sedang
   tidak aktif. Kalau panel itu menampilkan pembaca, lanjut ke fase B dengan
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
berhenti, dan gambar pendaftaran yang hanya ada di memori hilang. Setelah
mengambil tangkapan layar atau berpindah jendela, klik halaman dulu sebelum
menempelkan jari.

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
   Tangkap. Format pindah sendiri ke Raw saat tab Daftar dibuka. Kalau sampel
   tetap datang sebagai PNG, halaman memulai ulang penangkapannya sendiri;
   kalau catatan kejadian meminta, tekan F5 (laporan tidak hilang). Setiap
   relawan mendaftarkan 6 jari (telunjuk, tengah, manis; kanan dan kiri), 4
   tempelan per jari. Periksa pilihan Jari sebelum menempel: tabel galeri
   harus memuat 6 jari per relawan. Coba daftarkan ulang jari R1 telunjuk
   kanan dengan kode lain: harus ditolak sebagai jari ganda.

   Kalau muncul peringatan merah "DPI alat …, tetapi DPI galeri …", galeri itu
   dibuat dengan driver atau versi lain. Hapus semua data uji, lalu daftar
   ulang.
3. **Kalibrasi & ukur.** "Hitung kalibrasi". Halaman menilai tiap DPI dari
   kolom "Median − p99", yaitu median skor jari yang sama dikurangi p99 skor
   jari yang berbeda, lalu memilih DPI sendiri. Kalau catatan kejadian
   berbunyi "tidak perlu diganti", biarkan. Kalau berbunyi "sebaiknya
   diganti", klik "Terapkan DPI" dua kali. Kalau berbunyi "tidak menyarankan
   DPI apa pun", jangan terapkan apa pun; periksa mutu tempelan pendaftaran.
   DPI yang nilainya 0 atau kurang tidak bisa diterapkan. Kerjakan sebelum
   jembatan dimulai ulang: gambar pendaftaran hanya ada di memori.

   Kolom "Jarak" (skor sama-jari terendah dikurangi skor beda-jari tertinggi)
   tidak dipakai untuk memilih. Ia ditentukan dua pasangan paling ekstrem
   saja, jadi satu tempelan yang buruk cukup untuk membuatnya negatif: pada
   uji 5 Oktober, dengan 36 jari, jaraknya negatif di semua DPI.
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
   sebelumnya". Pada uji 3 Oktober di PC kiosk dengan galeri yang masih
   700 DPI, tempelan kelima dan seterusnya pada jari yang sama selalu gagal,
   dan sebabnya belum diketahui. Dengan galeri 508 DPI langkah ini belum
   pernah dijalankan, termasuk pada uji 5 Oktober, dan tidak lagi menjadi
   syarat. Jalankan hanya kalau gejala itu muncul lagi.
6. **Kalibrasi & ukur.** "Ukur waktu 1:N", sebaiknya setelah beberapa
   identifikasi. Angkanya hanya berlaku untuk komputer tempat uji berjalan.
7. **Identifikasi.** "Kirim ulang sampel terakhir": harus ditolak (409).
8. **Detak.** "Detak sekarang". Cabut alat, detak lagi: status alat harus
   berubah. Colok kembali, lalu tempelkan satu jari di tab Tangkap: sampelnya
   harus tetap datang, dan catatan kejadian memuat "pembaca baru terhubung".
   Nyalakan "Otomatis tiap 60 detik" selama 30 menit, dengan halaman dan
   jendela konsol tetap terbuka.

### Fase C: layanan dan akun standar

1. Tutup jendela konsol jembatan. Buka PowerShell dengan "Run as
   administrator", pindah ke folder paket, lalu:

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\pasang-layanan.ps1
   ```

2. Kalau PC belum punya akun standar (bukan admin), buat akun lokal
   `uji-kiosk`.
3. Restart, masuk sebagai `uji-kiosk`, buka `http://127.0.0.1:47890/`. Halaman
   ringkas "Jembatan sidik jari berjalan" harus langsung tampil: itu bukti
   start otomatis. Buka tautan `/status` di halaman itu: "mode" harus
   `layanan`, dan ID perangkat serta angka galerinya harus sama dengan sebelum
   restart, bukti kunci dan templat bertahan. Layanan tidak menyajikan halaman
   uji, jadi penangkapan dan identifikasi dari akun standar baru diuji di 4.4,
   lewat halaman kiosk.
4. Masih sebagai `uji-kiosk`, dari PowerShell biasa, ketiga hal ini harus
   **ditolak**: membuka `C:\ProgramData\JembatanSidikJari`, menjalankan
   `sc.exe stop JembatanSidikJari`, dan menghapus `.exe` di
   `C:\Program Files\JembatanSidikJari`. Jalankan `whoami` dulu untuk
   memastikan jendelanya memang milik `uji-kiosk`. Pada uji 5 Oktober langkah
   ini belum terbukti: jendelanya ternyata sesi admin, dan README waktu itu
   menulis `sc stop`.
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
   Claude. Isinya angka, metadata, dan 300 baris terakhir catatan kejadian:
   tanpa gambar, templat, kunci, atau nama. Catatannya disimpan di peramban,
   jadi tetap utuh walau halaman dimuat ulang atau jembatan berpindah dari
   konsol ke layanan.
2. "Hapus semua data uji" (klik dua kali), lalu "Kosongkan laporan" (klik dua
   kali).
3. Kalau kiosk belum dipasangkan dengan server dan layanannya tidak dipakai
   lagi, cabut dari PowerShell admin di folder paket:

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\hapus-layanan.ps1 -HapusData
   ```

   Ketik `HAPUS` saat diminta. Kalau kiosk sudah dipasangkan, lewati langkah
   ini: `-HapusData` menghapus kunci yang dikenal server.
4. Yang dibiarkan terpasang: driver, ADC, kebijakan driver, dan kebijakan
   Chrome.
5. Kembalikan kiosk seperti biasa, lalu pastikan absen QR/NISN tetap jalan.

### Kriteria lanjut ke 4.2

- Salah orang: 0. Yang dihitung baris "SALAH ORANG"; jari lain dari orang
  yang sama dicatat terpisah dan tidak termasuk.
- Jari tidak terdaftar yang diterima: 0.
- Orang dikenali pada tempelan pertama ≥ 90%, dan dalam tiga tempelan ≥ 99%.
- Ujung ke ujung di PC kiosk ≤ 1 detik pada galeri 500 templat.

Keadaan per 5 Oktober 2026, dari uji paket 0.1.4 di PC kiosk: enam relawan
dewasa, 36 jari terdaftar, driver WBF, galeri 508 DPI.

| Kriteria | Hasil | Keadaan |
|---|---|---|
| Salah orang 0 | 0 dari 229 tempelan jari terdaftar | terpenuhi |
| Jari tidak terdaftar yang diterima 0 | 0 dari 30; skor terbaiknya 9,3–23,1 | terpenuhi |
| Dikenali pada tempelan pertama ≥ 90% | 149 dari 180 percobaan, 82,8% | belum |
| Dikenali dalam tiga tempelan ≥ 99% | 169 dari 180 percobaan, 93,9% | belum |
| ≤ 1 detik pada galeri 500 templat | median 46 ms, p95 67 ms | terpenuhi |

Digabung dengan dua uji 3 Oktober, tidak ada salah orang pada 390 tempelan,
dan tidak ada jari tidak terdaftar yang diterima pada 50 tempelan.

Kriteria pengenalan belum terpenuhi, dan kekurangannya terpusat pada tiga
relawan:

- Dari 30 percobaan per relawan, tempelan pertama dikenali 30 kali untuk R1,
  R4, dan R5; 25 kali untuk R6; dan 17 kali untuk R2 dan R3.
- Mutu pendaftaran meramalkan hasilnya. Untuk jari yang keserasian tempelan
  pendaftarannya paling rendah 150 atau lebih, 80 dari 80 percobaan dikenali
  pada tempelan pertama. Untuk yang di bawah 60, hanya 10 dari 25.
- Menurut posisi jari, telunjuk kanan terbaik (93%) dan jari manis kiri
  terburuk (70%).
- Penyebabnya bukan ambang: dari 60 tempelan yang ditolak, 49 skornya di
  bawah 40.

Diputuskan lanjut ke 4.2, dengan syarat yang dibawa ke langkah berikutnya:

- **4.3, pendaftaran:** gerbang mutu saat mendaftar, dua jari terbaik per
  orang, jari manis dihindari, dan aturan keserasian yang lebih ketat. Aturan
  sekarang meloloskan empat tempelan yang hanya cocok berpasangan dua-dua.
  Dihitung mundur dari data yang sama, gerbang keserasian 80 memberi 92,6%
  pada tempelan pertama dan 100% dalam tiga, dan dua jari terbaik per orang
  memberi 90% dan 100%. Angka itu dari enam orang dewasa, bukan dari siswa.
- **4.4, kiosk:** layar tidak boleh mati, detak memuat keadaan pembaca
  menurut ADC, dan tingkat pengenalan diukur ulang dengan siswa.

Sejak 0.3.0 gerbang keserasian 80 dan aturan yang lebih ketat itu terpasang di
jembatan (lihat "Ambang"). Memilih dua jari per orang dan tidak menawarkan
jari manis diatur server dan halaman pendaftaran.

Yang lain dari uji 5 Oktober: detak otomatis berhasil 65 kali berturut-turut
selama sekitar satu jam, layanan terpasang dan galerinya bertahan, halaman
kiosk bisa memanggil jembatan dan ADC tanpa permintaan izin setelah kebijakan
Chrome terpasang, dan asal lain ditolak 403. Belum terbukti: start otomatis
setelah restart, dan tiga pemeriksaan akun standar di fase C langkah 4.

## Titik mundur

| Bagian | Cara mundur |
|---|---|
| Driver dan ADC | Titik pemulihan "sebelum-sidik-jari"; atau hapus driver lewat Device Manager (centang "Delete the driver software") dan ADC lewat Apps & features. |
| Layanan | `hapus-layanan.ps1`. Tanpa `-HapusData`, kunci dan templat dibiarkan. |
| Pasangan dengan server | Hapus entri kiosk dari `$sj_perangkat` di berkas konfigurasi server, atau ubah `'aktif'` menjadi `false`. |
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

| Rute | Asal yang boleh | Sebagai layanan | Guna |
|---|---|---|---|
| `GET /` | halaman sendiri | ada | Di jendela konsol: halaman uji. Sebagai layanan: halaman ringkas. |
| `GET /status` | halaman sendiri, kiosk | ada | Versi, mode, ID perangkat, sidik kunci, waktu PC, alat menurut WMI, ringkasan galeri. |
| `POST /detak` | halaman sendiri, kiosk | ada | Detak bertanda tangan dengan status alat, dan dengan keadaan pembaca menurut ADC kalau halaman melaporkannya. |
| `POST /identifikasi` | kiosk; di jendela konsol juga halaman sendiri | ada | Identifikasi 1:N. Untuk kiosk: hasilnya ditandatangani, dikenali maupun tidak. Untuk halaman uji: diagnostik prototipe, dan hanya hasil yang diterima yang ditandatangani. |
| `GET /galeri` | halaman sendiri | tidak | Daftar identitas dan jari terdaftar, tanpa templat. |
| `POST /daftar/mulai` | kiosk | ada | Membuka sesi pendaftaran satu jari. Jawabannya tantangan sekali pakai. |
| `POST /daftar/izin` | kiosk | ada | Menerima izin bertanda tangan server untuk sesi itu. |
| `POST /daftar/tempel` | kiosk | ada | Satu tempelan: dinilai gerbang mutu, lalu tempelan uji. |
| `POST /daftar/selesai` | kiosk | ada | Menyimpan templat dan menandatangani tanda terima untuk server. |
| `POST /cabut/mulai` | kiosk | ada | Membuka sesi pencabutan untuk satu orang. |
| `POST /cabut` | kiosk | ada | Dengan izin server: menghapus templat orang itu dan menandatangani tanda terimanya. |
| `POST /daftar` | halaman sendiri | tidak | Pendaftaran uji, hanya identitas `uji:`: empat tempelan satu jari. |
| `POST /kalibrasi` | halaman sendiri | tidak | Skor per DPI, atau menerapkan DPI baru. |
| `POST /ukur` | halaman sendiri | tidak | Waktu pencocokan untuk galeri 50–2000 templat. |
| `POST /hapus-uji` | halaman sendiri | tidak | Menghapus templat identitas `uji:`, gambar di memori, dan catatan sampel. Templat siswa dan guru tidak disentuh. |

Layanan berjalan tanpa pengawasan di PC yang dipakai siswa, jadi hanya
membuka yang dibutuhkan halaman kiosk. Rute yang tidak dibuka dijawab 404
untuk GET tanpa Origin, dan 403 untuk permintaan lain. Rute pendaftaran dan
pencabutan dibuka juga untuk layanan, karena tanpa izin bertanda tangan server
tidak ada templat yang disimpan atau dihapus. `/identifikasi` dibuka karena
halaman kiosk membutuhkannya; batasnya ada di "Identifikasi dari halaman
kiosk".

"Kiosk" berarti `https://smkt.alhasan.co.id`; hanya `--asal-kiosk` dalam mode
pengembangan yang bisa menggantinya. Tidak ada rute yang mengembalikan templat,
gambar, atau kunci, dan tidak ada rute yang menandatangani isi sembarang:
setiap pesan disusun jembatan sendiri dengan bentuk tetap. Dari kiriman
pemanggil, yang masuk ke pesan tanpa izin server hanya tantangan dan, di
`/detak`, `adc` yang bernilai 0 atau 1. Jembatan tidak pernah menghubungi
server sendiri: halaman yang membawa tantangan dari server ke jembatan, lalu
membawa tanda tangannya kembali.

### Pendaftaran dan pencabutan berizin

Siswa dan guru hanya bisa didaftarkan lewat sesi berizin. Halaman yang
menjalankannya ada di panel admin, dan dibuka di Chrome PC kiosk.

1. `/daftar/mulai` dengan identitas (`siswa:<id>` atau `guru:<id>`) dan nama
   jari. Jawabannya `sesi`: tantangan 64 hex yang berlaku 15 menit.
2. Halaman meminta izin ke `admin/sj_izin.php`. Server menandatangani
   `SJ1|izin-daftar|<perangkat>|<sesi>|<identitas>|<jari>` dengan kunci
   perangkat, hanya untuk admin yang sedang login dan orang yang
   persetujuannya tercatat. Jawabannya juga memuat tantangan server untuk
   tanda terima.
3. `/daftar/izin` membawa tanda tangan itu ke jembatan. Izin yang tidak cocok
   membuang sesinya.
4. `/daftar/tempel`, satu tempelan per kiriman. Begitu ada empat, keempatnya
   dinilai (lihat "Ambang"). Selama belum lolos, tempelan terlemah dibuang dan
   jawabannya meminta satu tempelan lagi, sampai paling banyak 10 tempelan.
   Sesudah lolos, tempelan berikutnya adalah tempelan uji: harus dikenali
   sebagai jari yang baru didaftarkan, paling banyak tiga kali coba.
5. `/daftar/selesai` dengan tantangan server. Baru di sini templat disimpan,
   dan jembatan menandatangani
   `SJ1|terdaftar|<perangkat>|<tantangan>|<identitas>|<jari>|<mutu>`.
6. Halaman mengirim tanda terima itu ke `admin/sj_catat.php`, yang mencatat
   jarinya.

Kolom `tahap` di jawaban `/daftar/tempel` memberi tahu halaman langkah
berikutnya: `tempel`, `uji`, `siap`, atau `gagal`.

Yang ditolak jembatan sendiri, apa pun izinnya:

- identitas `uji:`, yang hanya untuk halaman uji;
- sampel selain raw, kecuali dalam mode pengembangan tanpa `--rute-layanan`;
- sampel yang sama persis dengan sampel sebelumnya;
- jari yang mirip jari terdaftar milik orang lain, atau jari lain milik orang
  yang sama. Jari yang didaftarkan ulang menggantikan templat lamanya.

Pencabutan: `/cabut/mulai` dengan identitas, izin server atas
`SJ1|izin-cabut|<perangkat>|<sesi>|<identitas>`, lalu `/cabut` dengan izin
itu dan tantangan server. Semua templat orang itu dihapus, termasuk rekaman
yang tidak bisa dibuka lagi, dan jembatan menandatangani
`SJ1|dicabut|<perangkat>|<tantangan>|<identitas>|<jumlah>`.
Orang yang memang tidak punya templat dijawab dengan jumlah 0, supaya catatan
server tetap bisa dibereskan. Pencabutan juga membatalkan pendaftaran orang
itu yang sedang berjalan, supaya sesi yang diizinkan sebelumnya tidak
menyimpan templatnya lagi.

Sesi pendaftaran dibatasi per golongan: paling banyak empat yang belum
diizinkan dan empat yang sudah diizinkan. Sesi baru membuang yang tertua di
golongannya, jadi sesi yang sudah diizinkan hanya bisa tergusur oleh izin lain
yang sah. Sesi hanya hidup di memori, jadi hilang kalau jembatan dimulai
ulang. Gambar tempelan tidak disimpan: yang dipegang sesi hanya templatnya,
dan itu pun dibuang kalau sesinya gagal atau ditinggalkan.

### Identifikasi dari halaman kiosk

Sejak 0.4.0 `/identifikasi` melayani halaman kiosk, juga sebagai layanan.
Urutannya seperti detak:

1. Halaman meminta tantangan bertujuan `absen` ke `api/sj_tantangan.php`.
2. Halaman mengirim `{tantangan, format: "raw", sampel}` ke `/identifikasi`.
3. Jembatan mencocokkan sampel itu dengan templat siswa dan guru, lalu
   menandatangani hasilnya: pesan `absen` kalau jarinya dikenali, pesan
   `tolak` kalau tidak.
4. Halaman meneruskan jawabannya ke `api/sj_absen.php`. Server menyusun ulang
   pesannya sendiri, lalu mencocokkan HMAC-nya.

Isi jawaban 200:

| Kolom | Isi |
|---|---|
| `diterima` | `true` kalau jarinya dikenali; aturannya ada di "Ambang". |
| `identitas` | `siswa:<id>` atau `guru:<id>`. `null` kalau tidak diterima. |
| `skor` | Skor kandidat terbaik, dibulatkan ke bawah: bilangan bulat 0 sampai 9999. |
| `perangkat` | ID perangkat ini. |
| `waktu_ms` | Lama pengerjaan di jembatan. |
| `pesan`, `tanda_tangan` | Pesan kanonik dan HMAC-nya. |

Tempelan yang tidak dikenali ikut ditandatangani, supaya server boleh
mencatatnya: dari catatan itulah tingkat pengenalan diukur. Jari guru yang
terdaftar ikut dikenali; apa yang dicatat untuk siswa dan untuk guru
diputuskan server.

Bedanya dari identifikasi untuk halaman uji:

- yang dicocokkan hanya templat siswa dan guru. Templat `uji:` yang
  tertinggal di galeri tidak bisa menang, dan tidak dihitung sebagai
  identitas kedua;
- jawabannya tanpa daftar kandidat dan tanpa diagnostik, dan tidak menyebut
  siapa pun kalau tempelannya ditolak. Rute ini bisa dipanggil halaman mana
  pun di situs kiosk yang terbuka di PC itu, jadi jawabannya dibuat
  sesedikit mungkin;
- tempelannya tidak disimpan di memori. Yang diingat hanya sidik SHA-256
  sampelnya, untuk menolak sampel kembar;
- hanya sampel raw, kecuali dalam mode pengembangan tanpa `--rute-layanan`.

Yang dijawab tanpa tanda tangan, jadi tidak ada yang diteruskan ke server:

- 400 untuk tantangan yang bukan 64 hex huruf kecil, dan untuk sampel yang
  tidak terbaca;
- 409 kalau belum ada jari siswa atau guru yang terdaftar;
- 409 untuk sampel yang sama persis dengan sampel sebelumnya.

Skor di tanda terima `tolak` adalah skor kandidat terbaik. Tempelan yang
ditolak dengan skor 50 atau lebih berarti dua orang hampir sama kuat:
selisihnya di bawah 10.

Batas rancangan yang perlu diketahui:

- **Rute ini menjawab sejak 0.4.0 terpasang,** juga selagi server belum
  menerima absen lewat sidik jari. Jembatan hanya memeriksa bentuk tantangan,
  dan tidak tahu apakah tantangan itu terbitan server. Tanda tangan atas
  tantangan lain ditolak server, tetapi isi jawabannya tetap terbaca
  pemanggil: skor, dan identitas kalau diterima.
- **Pemanggilnya tidak hanya halaman kiosk.** Halaman mana pun di situs kiosk
  yang terbuka di Chrome PC itu bisa memanggilnya, dan begitu juga program
  yang berjalan di PC itu. Jembatan tidak membatasi jumlah percobaan, dan
  percobaan itu tidak tercatat di Event Viewer.
- **Tanda tangan membuktikan jembatan ini mencocokkan sebuah sampel, bukan
  bahwa sebuah jari menyentuh pembaca.** Sampel datang dari halaman, jadi
  jembatan tidak bisa membedakan tempelan baru dari rekaman tempelan lama.
- **Jembatan tidak mengingat tantangan.** Yang membuat satu tantangan hanya
  tercatat sekali adalah server. Tingkat pengenalan yang dihitung dari tanda
  terima `tolak` mengandaikan halaman meneruskan semuanya.

Karena itu PC kiosk sendiri harus dijaga: akun standar tanpa hak admin, dan
siswa tidak diberi jalan ke konsol peramban atau ke program lain. Batas ini
ditinjau lagi sebelum peralihan (4.5).

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
  jembatan berhenti, bukan membuat kunci baru. Kunci HMAC hanya keluar lewat
  `--pasangan`, yang hanya membaca dan tidak pernah membuat kunci. Kunci
  templat tidak pernah keluar.
- `templat.json`: templat terenkripsi AES-256-GCM, satu rekaman per tempelan,
  terikat pada `SJ1|templat|identitas|jari|urutan|versi`. Rekaman yang
  dipindah ke identitas lain gagal didekripsi. Kolom `versi` memuat versi
  SourceAFIS dan DPI ekstraksi, misalnya `sourceafis-net-3.14.0-508`.

DPI galeri mengikuti alat. Pendaftaran pertama di galeri kosong memakai DPI
yang dilaporkan sampelnya. Sebelum itu, dan untuk sampel tanpa DPI seperti
PNG, jembatan memakai 500. Galeri yang sudah berisi memakai DPI rekamannya,
dan hanya berubah lewat "Terapkan DPI".

Templat hanya ada di PC kiosk dan tidak disalin ke server. Kalau folder data
hilang, semua jari didaftarkan ulang.

Gambar sidik jari tidak pernah ditulis ke disk. Gambar pendaftaran uji dan
probe identifikasi terakhir dari halaman uji disimpan di memori, untuk
kalibrasi dan ukur waktu. Gambar pendaftaran siswa dan guru, dan tempelan
dari halaman kiosk, tidak disimpan sama sekali.

### Tanda tangan

Pesan kanonik, dengan setiap kolom diperiksa polanya sehingga `|` tidak bisa
disusupkan, lalu HMAC-SHA256 dalam hex huruf kecil:

```
SJ1|absen|<perangkat>|<tantangan 64 hex>|<identitas>|<skor>
SJ1|tolak|<perangkat>|<tantangan 64 hex>|<skor>
SJ1|detak|<perangkat>|<tantangan 64 hex>|alat:<0 atau 1>
SJ1|detak|<perangkat>|<tantangan 64 hex>|alat:<0 atau 1>|adc:<0 atau 1>
SJ1|terdaftar|<perangkat>|<tantangan 64 hex>|<identitas>|<jari>|<mutu>
SJ1|dicabut|<perangkat>|<tantangan 64 hex>|<identitas>|<jumlah>
```

`tolak` adalah tanda terima untuk tempelan di kiosk yang tidak dikenali. Ia
tidak memuat identitas, dan memakai tantangan dari jenis yang sama dengan
pesan `absen`; server hanya menerima satu tanda terima per tantangan. Detak
berbentuk kedua dipakai kalau halaman melaporkan keadaan pembaca menurut ADC;
tanpa laporan itu bentuknya tetap yang pertama.

Dua pesan lain ditandatangani server dan hanya diperiksa jembatan:

```
SJ1|izin-daftar|<perangkat>|<tantangan jembatan 64 hex>|<identitas>|<jari>
SJ1|izin-cabut|<perangkat>|<tantangan jembatan 64 hex>|<identitas>
```

Kedua arah memakai kunci yang sama, jadi jenis pesannya yang memisahkan.
Jembatan tidak pernah menandatangani pesan `izin-*`, dan server tidak pernah
menandatangani yang lain. Karena itu izin yang cocok hanya bisa berasal dari
server.

Vektor uji, untuk memastikan server menyusun pesan dan HMAC yang sama:

| | Nilai |
|---|---|
| Kunci HMAC (hex) | `000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f` |
| Sidik kunci | `630dcd29` |
| Tantangan | `a0a1a2a3a4a5a6a7a8a9aaabacadaeafb0b1b2b3b4b5b6b7b8b9babbbcbdbebf` |
| Pesan absen | `SJ1\|absen\|kiosk-uji\|<tantangan>\|siswa:123\|87` |
| HMAC absen | `5143256d84f0681ff427ffdb4f43b170d7aef6defc4cf052d85bad90b758223f` |
| Pesan detak | `SJ1\|detak\|kiosk-uji\|<tantangan>\|alat:0` |
| HMAC detak | `560ca340fdcbcf931d460a0936578fd1ed3976a7aea0af5fef1a38b6a8b65bae` |
| Pesan tolak | `SJ1\|tolak\|kiosk-uji\|<tantangan>\|31` |
| HMAC tolak | `3eb813096644921ce4eab24c5e641d2a3a089d4474aac3ddbadc80752edc2440` |
| Pesan detak dengan ADC | `SJ1\|detak\|kiosk-uji\|<tantangan>\|alat:1\|adc:0` |
| HMAC detak dengan ADC | `30fe9ba68854d15b0b5b811a04a88b191ade580141ae636e98f30fe6bd3a087e` |
| Pesan izin daftar | `SJ1\|izin-daftar\|kiosk-uji\|<tantangan>\|siswa:123\|telunjuk-kanan` |
| HMAC izin daftar | `1e7ade55a30e25372bccb1ece40ac31fce69fe296ad054d8110a57d59b1004f4` |
| Pesan izin cabut | `SJ1\|izin-cabut\|kiosk-uji\|<tantangan>\|siswa:123` |
| HMAC izin cabut | `25e1504752279d15736b6337750698e0a823d2880ec444aebb80bc406b76b0ed` |
| Pesan terdaftar | `SJ1\|terdaftar\|kiosk-uji\|<tantangan>\|siswa:123\|telunjuk-kanan\|143` |
| HMAC terdaftar | `0b774bbb4a3428e06524bfc8866744e883f06b83009dace15d155561f129dece` |
| Pesan dicabut | `SJ1\|dicabut\|kiosk-uji\|<tantangan>\|siswa:123\|8` |
| HMAC dicabut | `7aa56608d4a03d2c7602574cbd21902cbfe7d61e1525a4ddb69344511467637b` |

```php
hash_hmac('sha256', $pesan, hex2bin('000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f'));
```

Sidik kunci adalah delapan karakter pertama SHA-256 atas byte kunci HMAC,
bukan atas teks hex-nya. Jembatan menampilkannya di `/status` dan di keluaran
`--pasangan`, dan server menampilkannya di panel admin. Dengan itu kedua
salinan kunci bisa dicocokkan tanpa memperlihatkan kuncinya.

### Detak ke server

Sejak 4.2 server memeriksa tanda tangan detak:

1. Halaman meminta tantangan ke `api/sj_tantangan.php`, untuk ID perangkat
   yang dijawab `/status`.
2. Halaman meneruskan tantangan itu ke `/detak`. Jembatan menandatanganinya
   bersama status alat.
3. Halaman mengirim hasilnya ke `api/sj_detak.php`. Server menyusun ulang
   pesannya sendiri, mencocokkan HMAC-nya dengan kunci perangkat di
   konfigurasinya, lalu mencatat satu baris detak.

Tantangan berlaku 120 detik dan hanya bisa dipakai sekali. Sisi servernya ada
di `includes/sidik_jari.php`. Tombol "Uji rantai" di panel admin menjalankan
ketiga langkah itu sekali.

Sejak 0.4.0 halaman boleh menyertakan `adc` di kiriman `/detak`: bilangan 1
kalau halaman sedang menangkap jari, 0 kalau tidak bisa (ADC tidak menjawab,
pembacanya tidak terlihat ADC, atau jendela kiosknya tidak aktif). Jembatan
menuliskannya ke pesan sebagai kolom tersendiri, dan menggemakannya di kolom
`adc` jawabannya.

- **Halaman meneruskan `adc` dari jawaban jembatan, bukan nilai kirimannya
  sendiri.** Jembatan sebelum 0.4.0 mengabaikan `adc`, menandatangani pesan
  bentuk lama, dan jawabannya tidak memuat `adc`. Dengan begitu yang sampai
  ke server selalu sama dengan yang ditandatangani.
- **Hanya bilangan 0 atau 1.** Teks `"1"`, `true`, dan bilangan lain ditolak
  400. `null` sama dengan tidak menyertakannya.
- **`alat` tetap pemeriksaan jembatan sendiri, lewat Windows. `adc` laporan
  halaman,** yang oleh jembatan hanya diteruskan ke pesan bertanda tangan.
  Detak dengan `alat` 1 dan `adc` 0 berarti Windows masih melihat pembacanya
  sedangkan halaman tidak bisa menangkap jari, seperti pada uji 5 Oktober.

### Ambang

Identifikasi diterima kalau skor terbaik ≥ 50 dan unggul ≥ 10 atas identitas
kedua. Aturannya satu untuk halaman uji dan halaman kiosk; untuk kiosk,
identitas kedua hanya dicari di antara siswa dan guru. Ambang 1:1 SourceAFIS
(40) dipakai untuk mencari jari ganda dan untuk keterhubungan tempelan
pendaftaran. Angka 50 dan 10 semula nilai awal
prototipe. Uji 5 Oktober dengan 36 jari mempertahankannya: skor tertinggi
terhadap orang lain 45,8, dua kali, dan menurunkan ambang ke 40 hanya akan
menolong 11 dari 60 tempelan yang ditolak.

Kalibrasi 3 Oktober memberi jarak yang lebar di kedua driver. Di PC kiosk pada
508 DPI, skor sama-jari terendah 151,6 dan beda-jari tertinggi 22,6. Dengan
driver DigitalPersona pada 700 DPI, angkanya 74,5 dan 32,3. Ambang jangan
diturunkan ke 40: pada uji identifikasi di PC kiosk dengan galeri 700 DPI,
telunjuk kiri pernah mendapat skor 41,3 terhadap telunjuk kanan orang yang
sama.

Jarak selebar itu hanya ada pada galeri kecil dengan tempelan yang baik. Pada
5 Oktober, dengan 36 jari, skor sama-jari terendah 0: ada tempelan pendaftaran
dari jari yang sama yang tidak cocok satu sama lain. Itu soal mutu pendaftaran,
yang dibenahi di 4.3, bukan soal ambang.

Sejak 0.3.0 pendaftaran memakai gerbang mutu, karena nilai mutu dari ADC
tidak berguna: pada uji 5 Oktober nilainya "Good" untuk semua 259 tempelan.

- **Keserasian terendah ≥ 80.** Keserasian sebuah tempelan adalah skor
  terbaiknya terhadap tiga tempelan lain. Aturan lama hanya menuntut 40.
- **Keempat tempelan harus saling terhubung** lewat pasangan yang cocok 1:1.
  Empat tempelan yang hanya cocok berpasangan dua-dua ditolak, walau tiap
  tempelan punya pasangan yang kuat: jarinya diletakkan dengan dua cara yang
  tidak saling mengenali.
- **Tempelan uji** sesudahnya memakai aturan identifikasi (50 dan 10), dihitung
  hanya terhadap templat yang baru.

Angka 80 dihitung mundur dari uji 5 Oktober: dari 36 jari, 27 lolos, dan jari
yang lolos dikenali 92,6% pada tempelan pertama dan 100% dalam tiga. Datanya
enam orang dewasa, jadi angkanya ditinjau lagi setelah pendaftaran sungguhan.

Jari ganda dicari dengan ambang 40, dan dua jari berbeda sesekali melewati
angka itu: pada uji 5 Oktober skor tertinggi terhadap orang lain 45,8. Jadi
sebagian penolakan "mirip jari yang sudah terdaftar" bisa keliru, dan makin
sering seiring galeri membesar. Jari yang ditolak begitu didaftarkan dengan
jari lain. Tiap penolakan, juga tiap pendaftaran yang gagal di gerbang mutu
atau di tempelan uji, dicatat sebagai peringatan beserta skornya. Sebagai
layanan, catatannya ada di Event Viewer > Windows Logs > Application, sumber
`JembatanSidikJari`. Ambang ini ikut ditinjau setelah pendaftaran sungguhan.

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

Harus dicabut atau diubah sebelum dipakai untuk siswa (4.3 sampai 4.4):

- Pendaftaran tanpa izin server lewat `/daftar`. Sejak 0.3.0 hanya untuk
  identitas `uji:` dan hanya di jendela konsol; siswa dan guru didaftarkan
  lewat sesi berizin.
- Rute `/kalibrasi`, `/ukur`, `/hapus-uji`, dan `/galeri`, serta halaman uji
  di `/` beserta pemilih berkas mode pengembangan. Sejak 0.2.0 semuanya hanya
  ada di jendela konsol; layanan tidak membukanya.
- Identitas `uji:` di `Brankas.cs`. Server menolaknya, sesi berizin juga, dan
  identifikasi dari halaman kiosk tidak mencocokkannya.
- Cek alat yang hanya lewat Windows. Sejak 0.4.0 detak memuat keadaan
  pembaca menurut ADC kalau halaman kiosk melaporkannya, yaitu selagi skrip
  absennya menangkap.
- Probe identifikasi terakhir yang disimpan di memori untuk `/ukur`, dan
  `skor_probe_sebelumnya` di jawaban `/identifikasi`: kemiripan dengan
  tempelan sebelumnya, hanya untuk menyelidiki tempelan yang gagal. Keduanya
  hanya untuk halaman uji.
- Catatan laporan yang disimpan halaman uji di `localStorage` peramban,
  termasuk catatan kejadian dan riwayat mulai ulang penangkapan.
- Format PNG; di produksi hanya Raw.
- Ambang 50 dan selisih 10, ditinjau lagi setelah diukur dengan siswa di 4.4.
- Jawaban diagnostik `/identifikasi` untuk halaman uji: daftar kandidat, waktu
  per tahap, dan tanda tangan absen atas identitas `uji:`. Halaman kiosk
  mendapat jawaban ringkasnya sendiri sejak 0.4.0.
