<?php
// Robust handler for student attendance (masuk / pulang)
// Requires: includes/db.php (provides $conn), includes/wa_sender.php (optional, for notifications)

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Jakarta');

function jsonResponse($status, $message, $data = null) {
    $out = ['status' => $status, 'message' => $message];
    if ($data !== null) $out['data'] = $data;
    $body = json_encode($out);
    // Kirim Content-Length agar client tahu respons sudah selesai
    header('Content-Length: ' . strlen($body));
    echo $body;
    // Flush respons ke client sebelum shutdown function (WA) berjalan
    if (ob_get_level()) { ob_end_flush(); }
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit;
}

function logErr($msg) {
    error_log('[proses_absen_siswa] ' . $msg);
}

// Load dependencies
if (!file_exists(__DIR__ . '/../includes/db.php')) {
    logErr('includes/db.php not found');
    jsonResponse('error', 'Server configuration error (db).');
}
require __DIR__ . '/../includes/db.php';
// Jam pulang hari ini (Jumat, Pulang Cepat, Libur) dari satu sumber.
require_once __DIR__ . '/../includes/kalender_sekolah.php';
// Aturan absen masuk dan pulang, dipakai bersama absen lewat sidik jari
// (api/sj_absen.php).
require_once __DIR__ . '/../includes/absen_siswa.php';

// optional WA sender
$hasWa = false;
if (file_exists(__DIR__ . '/../includes/wa_sender.php')) {
    require_once __DIR__ . '/../includes/wa_sender.php';
    $hasWa = function_exists('kirimNotifikasiWA') && function_exists('formatNomorWA');
}

// Antrian notifikasi WA — dikirim via shutdown function SETELAH respons dikirim ke client
// agar client tidak menunggu HTTP call ke gateway WA (timeout 30 detik).
$wa_queue = [];
register_shutdown_function(function () use (&$wa_queue) {
    foreach ($wa_queue as $notif) {
        try {
            kirimNotifikasiWA($notif['nomor'], $notif['pesan']);
        } catch (Exception $e) {
            error_log('[proses_absen_siswa] WA shutdown error: ' . $e->getMessage());
        }
    }
});

// Read input
$raw = file_get_contents('php://input');
if (!$raw) {
    jsonResponse('error', 'No input received.');
}
$input = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logErr('JSON decode error: ' . json_last_error_msg() . ' raw=' . substr($raw,0,500));
    jsonResponse('error', 'Invalid JSON input.');
}

$nisn = isset($input['nisn']) ? trim($input['nisn']) : '';
$foto_base64 = isset($input['foto_base64']) ? $input['foto_base64'] : null;
$mode = isset($input['mode']) ? strtolower(trim($input['mode'])) : '';

if ($nisn === '' || !in_array($mode, ['masuk','pulang'])) {
    jsonResponse('error', 'Parameter tidak lengkap atau mode tidak valid.');
}

// Find student by nisn
$stmt = $conn->prepare("SELECT id, nama_siswa, kelas, kontak_ortu FROM siswa WHERE nisn = ? LIMIT 1");
if (!$stmt) {
    logErr('Prepare siswa failed: ' . $conn->error);
    jsonResponse('error', 'Server error (prepare).');
}
$stmt->bind_param('s', $nisn);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    $stmt->close();
    jsonResponse('error', 'NISN tidak ditemukan.');
}
$siswa = $res->fetch_assoc();
$stmt->close();

// Aturan absennya ada di includes/absen_siswa.php: kapan absen masuk diterima,
// empat penolakan absen pulang, penyimpanan foto, dan isi WA. Berkas ini
// tinggal membaca kiriman, mencari siswanya, dan menyusun jawaban. Nama berkas
// foto tetap memakai NISN kiriman.
$siswa['nisn'] = $nisn;

try {
    $hasil = catatAbsenSiswa($conn, $siswa, $mode, $foto_base64);
} catch (Exception $e) {
    logErr('Exception: ' . $e->getMessage() . ' raw=' . substr($raw,0,500));
    jsonResponse('error', 'Terjadi kesalahan server: ' . $e->getMessage());
}

if (!$hasil['tercatat']) {
    jsonResponse('error', $hasil['pesan']);
}

// Antri notifikasi WA — dikirim setelah respons (lihat register_shutdown_function di atas)
if ($hasWa && !empty($siswa['kontak_ortu'])) {
    $wa_queue[] = ['nomor' => formatNomorWA($siswa['kontak_ortu']), 'pesan' => $hasil['wa']];
}

jsonResponse('success', $hasil['pesan'], $hasil['data']);
?>