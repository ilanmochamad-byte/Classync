# hapus-layanan.ps1 - mencabut layanan jembatan sidik jari.
#
# Jalankan dari PowerShell "Run as administrator":
#   powershell -ExecutionPolicy Bypass -File .\hapus-layanan.ps1
#   powershell -ExecutionPolicy Bypass -File .\hapus-layanan.ps1 -HapusData
#
# Tanpa -HapusData, folder data C:\ProgramData\JembatanSidikJari (kunci.bin
# dan templat.json) dibiarkan, supaya layanan bisa dipasang ulang tanpa
# mendaftarkan ulang jari. Dengan -HapusData, folder itu dihapus setelah Anda
# mengetik HAPUS. Kunci dan templat yang terhapus tidak bisa dikembalikan.
#
# Driver, Authentication Device Client, dan kebijakan Chrome tidak disentuh.
#
# Berkas ini sengaja hanya berisi karakter ASCII: Windows PowerShell 5.1
# membaca skrip tanpa BOM sebagai ANSI.

#Requires -Version 5.1
# CmdletBinding: parameter salah ketik (misalnya -HapusDta) ditolak, bukan
# diabaikan diam-diam.
[CmdletBinding()]
param([switch]$HapusData)
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

if ($env:OS -ne 'Windows_NT') {
    Gagal 'Skrip ini hanya untuk Windows.'
}

$NamaLayanan  = 'JembatanSidikJari'
$FolderPasang = Join-Path $env:ProgramFiles $NamaLayanan
$FolderData   = Join-Path $env:ProgramData $NamaLayanan

$saya = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $saya.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Gagal 'Jalankan dari PowerShell "Run as administrator".'
}

Langkah '[1/4] Layanan'
$layanan = Get-Service -Name $NamaLayanan -ErrorAction SilentlyContinue
if ($layanan) {
    if ($layanan.Status -ne 'Stopped') {
        Stop-Service -Name $NamaLayanan -Force
        $layanan.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(30))
    }
    & sc.exe delete $NamaLayanan
    if ($LASTEXITCODE -ne 0) {
        Gagal "sc.exe delete keluar dengan kode $LASTEXITCODE."
    }
    for ($i = 0; $i -lt 20 -and (Get-Service -Name $NamaLayanan -ErrorAction SilentlyContinue); $i++) {
        Start-Sleep -Milliseconds 500
    }
    if (Get-Service -Name $NamaLayanan -ErrorAction SilentlyContinue) {
        Write-Host 'Layanan ditandai untuk dicabut; hilang setelah jendela Services (services.msc) ditutup atau PC dimulai ulang.' -ForegroundColor Yellow
    } else {
        Write-Host 'Layanan dicabut.'
    }
} else {
    Write-Host 'Tidak ada layanan.'
}

Langkah "[2/4] Folder program $FolderPasang"
if (Test-Path -LiteralPath $FolderPasang) {
    # .exe kadang masih terkunci sebentar setelah layanan berhenti.
    for ($i = 1; $i -le 10; $i++) {
        try {
            Remove-Item -LiteralPath $FolderPasang -Recurse -Force
            break
        } catch {
            if ($i -eq 10) {
                Gagal ('Folder program tidak bisa dihapus: ' + $_.Exception.Message)
            }
            Start-Sleep -Seconds 1
        }
    }
    Write-Host 'Folder program dihapus.'
} else {
    Write-Host 'Tidak ada.'
}

Langkah '[3/4] Sumber Event Log'
if ([System.Diagnostics.EventLog]::SourceExists($NamaLayanan)) {
    [System.Diagnostics.EventLog]::DeleteEventSource($NamaLayanan)
    Write-Host 'Sumber Event Log dihapus. Catatan lamanya tetap ada di log Application.'
} else {
    Write-Host 'Tidak ada.'
}

Langkah "[4/4] Folder data $FolderData"
if (-not (Test-Path -LiteralPath $FolderData)) {
    Write-Host 'Tidak ada.'
} elseif (-not $HapusData) {
    Write-Host 'Dibiarkan: berisi kunci dan templat. Jalankan dengan -HapusData untuk menghapusnya.'
} else {
    $jawaban = Read-Host 'Ketik HAPUS untuk menghapus kunci dan templat secara permanen'
    if ($jawaban -ceq 'HAPUS') {
        Remove-Item -LiteralPath $FolderData -Recurse -Force
        Write-Host 'Folder data dihapus.'
    } else {
        Write-Host 'Dibatalkan; folder data dibiarkan.'
    }
}

Write-Host ''
Write-Host 'Selesai.' -ForegroundColor Green
