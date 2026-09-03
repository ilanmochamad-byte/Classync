<?php
// Middleware untuk memeriksa otentikasi
require 'auth_middleware.php';

// Memvalidasi token dan mendapatkan data guru
$guru = authenticate();
$guru_id = (int)$guru['id'];

// Gunakan helper terpusat agar kalkulasi JP dan tarif konsisten dengan admin
require_once __DIR__ . '/../admin/keuangan_helper.php';

// 1. Validasi input periode (bulan & tahun)
$filter_bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : 0;
$filter_tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : 0;

if ($filter_bulan < 1 || $filter_bulan > 12 || $filter_tahun < 2000) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Parameter bulan dan tahun wajib diisi dan harus berupa angka.']);
    exit;
}

// 2. Ambil tarif dari DB lalu hitung honor guru ini
$tarif   = getPengaturanHonor($conn);
$rincian = hitungHonorBulan($conn, $guru_id, $filter_bulan, $filter_tahun, $tarif);

// 3. Mengemas data untuk dikirim sebagai JSON
$response = [
    'status' => 'success',
    'periode' => date('F Y', mktime(0, 0, 0, $filter_bulan, 1, $filter_tahun)),
    'rincian' => [
        'pendapatan' => [
            'tunjangan_tetap'        => $rincian['total_tunjangan'],
            'uang_transport'         => $rincian['uang_transport'],
            'honor_mengajar'         => ['total' => $rincian['honor_mengajar'], 'jp' => $rincian['total_jp']],
            'honor_piket'            => ['total' => $rincian['honor_piket'],    'jumlah' => $rincian['jumlah_piket']],
            'honor_ekskul'           => ['total' => $rincian['honor_ekskul'],   'jumlah' => $rincian['jumlah_ekskul']],
            'honor_bk'               => ['total' => $rincian['honor_bk'],       'jumlah' => $rincian['jumlah_bk']],
            'subtotal'               => $rincian['subtotal_pendapatan']
        ],
        'potongan' => [
            'arisan'   => $rincian['potongan_arisan'],
            'tabungan' => $rincian['potongan_tabungan'],
            'total'    => $rincian['total_potongan']
        ],
        'total_diterima' => $rincian['total_diterima']
    ]
];

http_response_code(200);
echo json_encode($response);

$conn->close();
?>