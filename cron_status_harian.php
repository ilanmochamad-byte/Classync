<?php
// cron_status_harian.php — cron sore: menetapkan status harian siswa hari ini.
//
// Dijadwalkan di cPanel → Cron Jobs, setiap hari pukul 17.00:
//   0 17 * * * /usr/bin/php /DATA/k1807225/public_html/smkt.alhasan.co.id/classync/cron_status_harian.php >/dev/null 2>&1
//
// Setiap jalan tercatat di tabel log_status_harian. Hari Minggu, tanggal
// Libur, dan jalan sebelum jam pulang pun tercatat, sebagai "dilewati". Jadi
// tabel itulah bukti bahwa cron masih hidup dan jamnya benar, bukan keluaran
// skrip ini. Keluarannya hanya untuk jalan manual dari Terminal cPanel:
//   /usr/bin/php -q /DATA/k1807225/public_html/smkt.alhasan.co.id/classync/cron_status_harian.php
//
// Mode senyap: tidak mengirim WA apa pun. Aturan penggolongannya ada di
// includes/status_harian.php.

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Hanya boleh dijalankan dari cron. Berkas ini ada di webroot, jadi tanpa
// penjaga ini siapa pun bisa memicunya lewat URL.
//
// Yang diperiksa adalah pemanggilan lewat HTTP, BUKAN jenis SAPI.
// /usr/bin/php di server ini adalah php-cgi, dan pemeriksaan
// php_sapi_name() === 'cli' pernah mematikan pengingat pagi seluruh guru
// tanpa satu baris log pun (kirim_notifikasi_harian.php di repo API).
if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit("Skrip ini hanya untuk cron job.\n");
}

// Keluaran cron dibuang ke /dev/null, jadi galat harus tercatat di berkas:
// error_log yang sama dengan yang diisi halaman classync lewat web.
ini_set('error_log', __DIR__ . '/error_log');

// Kalau skrip berhenti di tengah jalan, misalnya includes/db.php memanggil
// die() karena tidak bisa terhubung, hari ini tidak punya baris
// log_status_harian. Baris error_log inilah penjelasannya.
$cron_selesai = false;
register_shutdown_function(function () use (&$cron_selesai) {
    if (!$cron_selesai) {
        error_log('[cron_status_harian] berhenti sebelum selesai; status harian hari ini belum dihitung.');
    }
});

require __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/status_harian.php';

$tanggal = date('Y-m-d');
$hasil   = tetapkanStatusHarian($conn, $tanggal, 'cron');

$ringkasan = date('Y-m-d H:i:s') . ' status harian ' . $tanggal . ': ' . $hasil['hasil'] . ' — ' . $hasil['pesan'];
if ($hasil['jumlah'] !== null) {
    $j = $hasil['jumlah'];
    $ringkasan .= ' Hadir ' . $j['hadir'] . ', Pulang Lebih Awal ' . $j['pulang_awal'] . ', Izin ' . $j['izin']
                . ', Sakit ' . $j['sakit'] . ', Alpa ' . $j['alpa'] . ', PKL ' . $j['pkl'] . ' (tidak disentuh).';
}
echo $ringkasan . "\n";

$cron_selesai = true;
exit(in_array($hasil['hasil'], ['selesai', 'dilewati'], true) ? 0 : 1);
