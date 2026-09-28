<?php
// sesi_piket.php — siapa yang boleh mengisi absen manual siswa.
//
// Dipakai login_piket.php, absen_manual.php, dan api/proses_absen_manual.php.
// Butuh includes/db.php lebih dulu: zona waktu Asia/Jakarta dan
// getNamaHariIndonesia().
//
// Ada dua jenis pencatat:
// - guru piket hari ini yang masuk lewat login_piket.php (NIK + password
//   sekarang, sidik jari setelah jembatan kiosk ada);
// - admin yang sedang login di panel admin, sebagai pengganti guru piket
//   yang berhalangan.
//
// Sesi piket terpisah dari sesi admin. Semua kuncinya berawalan piket_, dan
// akhiriSesiPiket() hanya menghapus kunci itu, jadi admin yang login di
// peramban yang sama tidak ikut keluar.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('PIKET_BATAS_DIAM')) {
    // PC kiosk dipakai bergantian, jadi sesi guru piket berakhir sendiri
    // setelah 15 menit tanpa aktivitas.
    define('PIKET_BATAS_DIAM', 15 * 60);
}

if (!function_exists('guruPiketHariIni')) {
    // Benar kalau guru punya jadwal piket Aktif untuk hari ini. Sesi Pagi dan
    // Siang tidak dibedakan: labelnya tidak menentukan waktu.
    function guruPiketHariIni($conn, $guru_id) {
        $hari = getNamaHariIndonesia(date('l'));
        $stmt = $conn->prepare("SELECT 1 FROM jadwal_piket WHERE guru_id = ? AND hari = ? AND status_jadwal = 'Aktif' LIMIT 1");
        $stmt->bind_param('is', $guru_id, $hari);
        $stmt->execute();
        $ada = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ada;
    }
}

if (!function_exists('mulaiSesiPiket')) {
    // $cara: 'password' sekarang, 'sidik_jari' setelah jembatan kiosk ada.
    // Nilainya ikut tercatat di log_absen_manual.
    function mulaiSesiPiket($guru_id, $nama_guru, $cara) {
        session_regenerate_id(true);
        $_SESSION['piket_guru_id']  = (int)$guru_id;
        $_SESSION['piket_nama']     = (string)$nama_guru;
        $_SESSION['piket_cara']     = (string)$cara;
        $_SESSION['piket_tanggal']  = date('Y-m-d');
        $_SESSION['piket_terakhir'] = time();
        $_SESSION['piket_csrf']     = bin2hex(random_bytes(32));
    }
}

if (!function_exists('akhiriSesiPiket')) {
    function akhiriSesiPiket() {
        foreach (['piket_guru_id', 'piket_nama', 'piket_cara', 'piket_tanggal',
                  'piket_terakhir', 'piket_csrf'] as $kunci) {
            unset($_SESSION[$kunci]);
        }
        session_regenerate_id(true);
    }
}

if (!function_exists('sesiPiketAktif')) {
    // Mengembalikan ['guru_id' => int, 'nama' => string, 'cara' => string],
    // atau null.
    //
    // Sesi sah hanya pada tanggal yang sama dengan saat masuk, selama belum
    // PIKET_BATAS_DIAM detik tanpa aktivitas, dan selama guru itu MASIH
    // terjadwal piket hari ini. Jadwal diperiksa ulang di setiap permintaan,
    // supaya jadwal yang diarsipkan admin langsung berlaku tanpa menunggu
    // sesinya habis.
    function sesiPiketAktif($conn) {
        if (empty($_SESSION['piket_guru_id'])) {
            return null;
        }
        $guru_id = (int)$_SESSION['piket_guru_id'];
        if (($_SESSION['piket_tanggal'] ?? '') !== date('Y-m-d')
            || time() - (int)($_SESSION['piket_terakhir'] ?? 0) > PIKET_BATAS_DIAM
            || !guruPiketHariIni($conn, $guru_id)) {
            akhiriSesiPiket();
            return null;
        }
        $_SESSION['piket_terakhir'] = time();
        return ['guru_id' => $guru_id,
                'nama'    => (string)$_SESSION['piket_nama'],
                'cara'    => (string)$_SESSION['piket_cara']];
    }
}

if (!function_exists('pencatatAbsenManual')) {
    // Mengembalikan ['jenis' => 'guru'|'admin', 'id' => int, 'nama' => string,
    // 'cara' => string], atau null kalau tidak ada yang berhak.
    //
    // Guru piket didahulukan. Kalau sesi admin tertinggal di peramban yang
    // sama, catatan tetap atas nama guru piket yang sedang bertugas.
    function pencatatAbsenManual($conn) {
        $piket = sesiPiketAktif($conn);
        if ($piket !== null) {
            return ['jenis' => 'guru', 'id' => $piket['guru_id'],
                    'nama' => $piket['nama'], 'cara' => $piket['cara']];
        }
        if (!empty($_SESSION['admin_logged_in']) && !empty($_SESSION['admin_id'])) {
            $admin_id = (int)$_SESSION['admin_id'];
            $stmt = $conn->prepare("SELECT username FROM admin WHERE id = ?");
            $stmt->bind_param('i', $admin_id);
            $stmt->execute();
            $admin = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($admin) {
                return ['jenis' => 'admin', 'id' => $admin_id,
                        'nama' => (string)$admin['username'], 'cara' => 'admin'];
            }
        }
        return null;
    }
}

if (!function_exists('tokenCsrfPiket')) {
    function tokenCsrfPiket() {
        if (empty($_SESSION['piket_csrf'])) {
            $_SESSION['piket_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['piket_csrf'];
    }
}

if (!function_exists('cekCsrfPiket')) {
    function cekCsrfPiket($token) {
        return is_string($token) && !empty($_SESSION['piket_csrf'])
            && hash_equals($_SESSION['piket_csrf'], $token);
    }
}
