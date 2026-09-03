<?php
// Atur zona waktu ke Asia/Jakarta
date_default_timezone_set('Asia/Jakarta');

// =================================================================
// TAMBAHKAN DEFINISI JAM ISTIRAHAT DI SINI
// =================================================================
$JAM_ISTIRAHAT = [
    // Istirahat pertama: 15 menit
    ['mulai' => '10:10:00', 'selesai' => '10:25:00', 'durasi' => 15],
    
    // Istirahat kedua: 15 menit
    ['mulai' => '11:45:00', 'selesai' => '12:05:00', 'durasi' => 20]
];

// Konfigurasi Database — dimuat dari luar webroot.
// Berkas itu mendefinisikan $db_host, $db_user, $db_pass, $db_name.
$config_db = '/DATA/k1807225/config/db-classync.php';
if (!is_readable($config_db)) {
    die("Koneksi ke database gagal: konfigurasi tidak ditemukan.");
}
require $config_db;

// Buat Koneksi
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Cek Koneksi
if ($conn->connect_error) {
    die("Koneksi ke database gagal: " . $conn->connect_error);
}

// Fungsi untuk menerjemahkan hari dari Inggris ke Indonesia
function getNamaHariIndonesia($hariInggris) {
    $daftarHari = [
        'Sunday'    => 'Minggu',
        'Monday'    => 'Senin',
        'Tuesday'   => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday'  => 'Kamis',
        'Friday'    => 'Jumat',
        'Saturday'  => 'Sabtu'
    ];
    return $daftarHari[$hariInggris];
}

// =================================================================
// TAMBAHKAN FUNGSI BARU DI BAWAH INI
// =================================================================

/**
 * Menghitung jumlah Jam Pelajaran (JP) berdasarkan jam mulai dan selesai.
 * 1 JP = 45 menit.
 *
 * @param string $jam_mulai Format Waktu (HH:MM:SS)
 * @param string $jam_selesai Format Waktu (HH:MM:SS)
 * @return int Jumlah JP
 */
function hitungJP($jam_mulai, $jam_selesai) {
    // Memanggil variabel jam istirahat global
    global $JAM_ISTIRAHAT;

    if (empty($jam_mulai) || empty($jam_selesai)) {
        return 0;
    }

    $mulai = new DateTime($jam_mulai);
    $selesai = new DateTime($jam_selesai);
    $diff = $selesai->diff($mulai);

    // 1. Hitung total menit kotor dari jadwal
    $total_menit_kotor = ($diff->h * 60) + $diff->i;
    
    // 2. Hitung total menit istirahat yang dilewati
    $menit_pengurang = 0;
    foreach ($JAM_ISTIRAHAT as $istirahat) {
        $mulai_istirahat = new DateTime($istirahat['mulai']);
        $selesai_istirahat = new DateTime($istirahat['selesai']);

        // Kondisi: Jadwal dimulai SEBELUM istirahat mulai, 
        // DAN jadwal selesai SETELAH istirahat selesai.
        if ($mulai < $mulai_istirahat && $selesai > $selesai_istirahat) {
            $menit_pengurang += $istirahat['durasi'];
        }
    }

    // 3. Hitung menit mengajar efektif
    $menit_efektif = $total_menit_kotor - $menit_pengurang;

    // 4. Hitung jumlah JP dari menit efektif
    if ($menit_efektif <= 0) {
        return 0;
    }
    
    // Kita gunakan pembulatan (round) agar lebih adil. 
    // Misal: 75 menit (1.66 JP) akan dihitung sebagai 2 JP.
    $jumlah_jp = round($menit_efektif / 40);

    return $jumlah_jp;
}
?>