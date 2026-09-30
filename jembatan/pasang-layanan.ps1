# pasang-layanan.ps1 - memasang jembatan sidik jari sebagai layanan Windows.
#
# Jalankan dari PowerShell "Run as administrator", di folder yang berisi
# jembatan-sidik-jari.exe:
#   powershell -ExecutionPolicy Bypass -File .\pasang-layanan.ps1
#
# Yang dilakukan:
# 1. menghentikan dan mencabut layanan lama, kalau ada;
# 2. menyalin .exe ke C:\Program Files\JembatanSidikJari. Akun kiosk bisa
#    menjalankannya, tetapi tidak bisa mengubah atau menggantinya;
# 3. membuat layanan dengan akun virtual NT SERVICE\JembatanSidikJari: mulai
#    otomatis saat PC menyala, sebelum ada yang login, dan dimulai ulang
#    sendiri kalau mati;
# 4. mendaftarkan sumber Event Log. Ini butuh hak admin, jadi dikerjakan di
#    sini, bukan oleh layanannya;
# 5. mengunci folder data C:\ProgramData\JembatanSidikJari: hanya SYSTEM,
#    Administrators, dan akun layanan. Akun kiosk tidak bisa membaca kunci
#    maupun templat;
# 6. menyalakan layanan dan memeriksa /status.
#
# Folder data yang sudah ada (kunci dan templat dari uji di jendela konsol)
# tidak dihapus; hanya ACL-nya yang dikunci. Skrip ini aman dijalankan ulang
# untuk memperbarui .exe.
#
# Berkas ini sengaja hanya berisi karakter ASCII: Windows PowerShell 5.1
# membaca skrip tanpa BOM sebagai ANSI.

#Requires -Version 5.1
# CmdletBinding: argumen yang tidak dikenal ditolak, bukan diabaikan.
[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'

function Langkah([string]$Teks) {
    Write-Host ''
    Write-Host $Teks -ForegroundColor Cyan
}

function Gagal([string]$Teks) {
    Write-Host ''
    Write-Host "GAGAL: $Teks" -ForegroundColor Red
    exit 1
}

# Program bawaan Windows (sc.exe, icacls.exe) tidak melempar galat; kode
# keluarnya harus diperiksa sendiri.
function Jalankan([string]$Program, [string[]]$Argumen) {
    & $Program @Argumen
    if ($LASTEXITCODE -ne 0) {
        Gagal "$Program $($Argumen -join ' ') keluar dengan kode $LASTEXITCODE."
    }
}

if ($env:OS -ne 'Windows_NT') {
    Gagal 'Skrip ini hanya untuk Windows.'
}

$NamaLayanan  = 'JembatanSidikJari'
$NamaTampil   = 'Jembatan sidik jari Classync'
$Keterangan   = 'Mencocokkan sidik jari di kiosk absensi dan menandatangani hasilnya. Hanya mendengar di 127.0.0.1:47890.'
$Akun         = "NT SERVICE\$NamaLayanan"
$NamaExe      = 'jembatan-sidik-jari.exe'
$FolderPasang = Join-Path $env:ProgramFiles $NamaLayanan
$FolderData   = Join-Path $env:ProgramData $NamaLayanan
$Port         = 47890

$saya = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $saya.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Gagal 'Jalankan dari PowerShell "Run as administrator".'
}
$sumberExe = Join-Path $PSScriptRoot $NamaExe
if (-not (Test-Path -LiteralPath $sumberExe -PathType Leaf)) {
    Gagal "$NamaExe tidak ada di $PSScriptRoot."
}

Langkah '[1/6] Layanan lama'
$lama = Get-Service -Name $NamaLayanan -ErrorAction SilentlyContinue
if ($lama) {
    if ($lama.Status -ne 'Stopped') {
        Stop-Service -Name $NamaLayanan -Force
        $lama.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(30))
    }
    Jalankan 'sc.exe' @('delete', $NamaLayanan)
    for ($i = 0; $i -lt 20 -and (Get-Service -Name $NamaLayanan -ErrorAction SilentlyContinue); $i++) {
        Start-Sleep -Milliseconds 500
    }
    if (Get-Service -Name $NamaLayanan -ErrorAction SilentlyContinue) {
        Gagal 'Layanan lama belum hilang. Tutup jendela Services (services.msc), lalu jalankan ulang skrip ini.'
    }
    Write-Host 'Layanan lama dicabut.'
} else {
    Write-Host 'Tidak ada layanan lama.'
}

$pemakaiPort = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
if ($pemakaiPort) {
    $proses = Get-Process -Id $pemakaiPort.OwningProcess -ErrorAction SilentlyContinue
    Gagal "Port $Port dipakai proses lain: $($proses.ProcessName) (PID $($pemakaiPort.OwningProcess)). Kalau itu jembatan di jendela konsol, tutup jendelanya dulu."
}

Langkah "[2/6] Menyalin $NamaExe ke $FolderPasang"
New-Item -ItemType Directory -Path $FolderPasang -Force | Out-Null
Unblock-File -LiteralPath $sumberExe
$tujuanExe = Join-Path $FolderPasang $NamaExe
Copy-Item -LiteralPath $sumberExe -Destination $tujuanExe -Force
Write-Host 'Disalin.'

Langkah "[3/6] Membuat layanan $NamaLayanan dengan akun $Akun"
# New-Service memasang jalur .exe apa adanya, jadi spasi di "Program Files"
# tidak perlu dikutip berlapis seperti di sc.exe create.
New-Service -Name $NamaLayanan -BinaryPathName ('"' + $tujuanExe + '"') -DisplayName $NamaTampil `
            -Description $Keterangan -StartupType Automatic | Out-Null
# New-Service memakai LocalSystem. Akun virtual dipasang lewat sc.exe, tanpa
# password; layanan belum pernah menyala dengan LocalSystem.
Jalankan 'sc.exe' @('config', $NamaLayanan, 'obj=', $Akun)
# Kalau mati: mulai ulang setelah 1 menit, 1 menit, lalu 5 menit. Hitungannya
# kembali nol setelah sehari.
Jalankan 'sc.exe' @('failure', $NamaLayanan, 'reset=', '86400', 'actions=', 'restart/60000/restart/60000/restart/300000')
Jalankan 'icacls.exe' @($FolderPasang, '/grant', "${Akun}:(OI)(CI)RX")
Write-Host 'Layanan dibuat.'

Langkah '[4/6] Sumber Event Log'
if ([System.Diagnostics.EventLog]::SourceExists($NamaLayanan)) {
    Write-Host 'Sumber Event Log sudah ada.'
} else {
    [System.Diagnostics.EventLog]::CreateEventSource($NamaLayanan, 'Application')
    Write-Host 'Sumber Event Log didaftarkan.'
}

Langkah "[5/6] Mengunci folder data $FolderData"
New-Item -ItemType Directory -Path $FolderData -Force | Out-Null
# SID, bukan nama grup, supaya tidak bergantung bahasa Windows:
# S-1-5-18 SYSTEM, S-1-5-32-544 Administrators.
Jalankan 'icacls.exe' @($FolderData, '/inheritance:r', '/grant:r', '*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F', "${Akun}:(OI)(CI)M")
# Berkas yang sudah ada, misalnya dari uji di jendela konsol, mengikuti ACL
# folder yang baru.
Get-ChildItem -LiteralPath $FolderData -Force | ForEach-Object {
    Jalankan 'icacls.exe' @($_.FullName, '/reset', '/T', '/Q')
}
Write-Host 'Hanya SYSTEM, Administrators, dan akun layanan yang bisa membuka folder ini.'

Langkah '[6/6] Menyalakan layanan'
try {
    Start-Service -Name $NamaLayanan
} catch {
    Gagal ('Layanan tidak mau menyala: ' + $_.Exception.Message + ' Lihat Event Viewer > Windows Logs > Application dan System. ' +
           'Kalau kodenya 1069 (logon failure), akun virtual belum punya hak "Log on as a service".')
}
$status = $null
for ($i = 0; $i -lt 30 -and -not $status; $i++) {
    try {
        $status = Invoke-RestMethod -Uri "http://127.0.0.1:$Port/status" -TimeoutSec 3 -UseBasicParsing
    } catch {
        Start-Sleep -Seconds 1
    }
}
if (-not $status) {
    Gagal "Layanan menyala, tetapi http://127.0.0.1:$Port/status tidak menjawab dalam 30 detik."
}
Write-Host ('Versi     : ' + $status.versi)
Write-Host ('Mode      : ' + $status.mode)
Write-Host ('Perangkat : ' + $status.perangkat)
Write-Host ('Alat      : ' + $status.alat.keterangan)
Write-Host ('Galeri    : ' + $status.galeri.identitas + ' identitas, ' + $status.galeri.templat + ' templat, DPI ' + $status.galeri.dpi)
if ($status.mode -ne 'layanan') {
    Write-Host "PERINGATAN: mode '$($status.mode)', bukan 'layanan'. Ada jembatan lain yang menjawab di port $Port?" -ForegroundColor Yellow
}
Write-Host ''
Write-Host "Selesai. Uji berikutnya (fase C): restart PC, masuk dengan akun standar, lalu buka http://127.0.0.1:$Port/" -ForegroundColor Green
