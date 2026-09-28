<?php
// login_piket.php — guru piket masuk untuk mengisi absen manual siswa.
//
// Hanya guru yang terjadwal piket HARI INI (jadwal_piket Aktif) yang bisa
// masuk. NIK dicari di kolom guru.nip (di aplikasi berlabel "NIK"); password
// diperiksa dengan password_verify(), sama seperti login.php di repo API.
// Halaman ini tidak menyentuh auth_token.
//
// Halaman ini terbuka untuk umum, jadi tidak boleh menjadi alat untuk menebak
// password aplikasi guru:
// - semua kegagalan memakai satu pesan yang sama;
// - setiap percobaan dicatat secara atomik SEBELUM password diperiksa,
//   sehingga permintaan yang dikirim bersamaan pun tidak bisa melewati batas;
// - selama NIK terkunci, password tidak diperiksa sama sekali.

require 'includes/db.php';
require_once 'includes/sesi_piket.php';

// Lima percobaan per NIK. Percobaan keenam mengunci NIK itu 15 menit, dan
// hitungannya kembali nol saat terkunci maupun saat berhasil masuk.
const PIKET_MAKS_GAGAL = 5;
const PIKET_LAMA_KUNCI = 15 * 60;

// Hash bcrypt cost 10 (sama dengan PASSWORD_DEFAULT di PHP 8.3 server) dari
// string acak yang tidak disimpan di mana pun. Dipakai untuk NIK yang tidak
// terdaftar, supaya password_verify() tetap berjalan dan lama respons tidak
// membocorkan NIK mana yang ada.
const PIKET_HASH_TIRUAN = '$2y$10$q2752g6lpx8UeC5M8cH6Z.DExOa5huZlBnpkBtlaZGkzvtiTGda1W';

$pesan    = '';
$pesan_ok = isset($_GET['keluar']) ? 'Anda sudah keluar.' : '';
$nip      = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if (!cekCsrfPiket($_POST['csrf_token'] ?? '')) {
        $pesan = 'Formulir kedaluwarsa. Silakan coba lagi.';
    } elseif ($aksi === 'keluar') {
        akhiriSesiPiket();
        header('Location: login_piket.php?keluar=1');
        exit;
    } elseif ($aksi === 'masuk') {
        $nip      = substr(trim((string)($_POST['nip'] ?? '')), 0, 50);
        $password = (string)($_POST['password'] ?? '');
        $sekarang = date('Y-m-d H:i:s');

        if ($nip === '' || $password === '') {
            $pesan = 'NIK dan password wajib diisi.';
        } else {
            // Buang catatan lebih dari sehari yang tidak sedang terkunci,
            // supaya tabel tidak tumbuh oleh NIK asal-asalan.
            $kemarin = date('Y-m-d H:i:s', time() - 86400);
            $stmt = $conn->prepare("DELETE FROM percobaan_login_piket WHERE terakhir < ? AND (terkunci_sampai IS NULL OR terkunci_sampai < ?)");
            $stmt->bind_param('ss', $kemarin, $sekarang);
            $stmt->execute();
            $stmt->close();

            // 1. Catat satu percobaan secara atomik, lalu baca hitungannya.
            $stmt = $conn->prepare("INSERT INTO percobaan_login_piket (nip, gagal, terkunci_sampai, terakhir) VALUES (?, 1, NULL, ?) ON DUPLICATE KEY UPDATE gagal = gagal + 1, terakhir = VALUES(terakhir)");
            $stmt->bind_param('ss', $nip, $sekarang);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("SELECT gagal, terkunci_sampai FROM percobaan_login_piket WHERE nip = ?");
            $stmt->bind_param('s', $nip);
            $stmt->execute();
            $percobaan = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $terkunci_sampai = $percobaan['terkunci_sampai'];
            if ($terkunci_sampai !== null && $terkunci_sampai <= $sekarang) {
                $terkunci_sampai = null;
            }
            if ($terkunci_sampai === null && (int)$percobaan['gagal'] > PIKET_MAKS_GAGAL) {
                // 2a. Melewati batas: kunci sekarang, tanpa memeriksa password.
                $terkunci_sampai = date('Y-m-d H:i:s', time() + PIKET_LAMA_KUNCI);
                $stmt = $conn->prepare("UPDATE percobaan_login_piket SET gagal = 0, terkunci_sampai = ? WHERE nip = ?");
                $stmt->bind_param('ss', $terkunci_sampai, $nip);
                $stmt->execute();
                $stmt->close();
            }

            if ($terkunci_sampai !== null) {
                $pesan = 'Terlalu banyak percobaan. Coba lagi setelah pukul '
                       . date('H.i', strtotime($terkunci_sampai)) . '.';
            } else {
                // 2b. Masih dalam batas: periksa password dan jadwal piket.
                $stmt = $conn->prepare("SELECT id, nama_guru, password FROM guru WHERE nip = ? LIMIT 1");
                $stmt->bind_param('s', $nip);
                $stmt->execute();
                $guru = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $cocok = password_verify($password, $guru['password'] ?? PIKET_HASH_TIRUAN)
                         && $guru !== null;

                if ($cocok && guruPiketHariIni($conn, (int)$guru['id'])) {
                    $stmt = $conn->prepare("DELETE FROM percobaan_login_piket WHERE nip = ?");
                    $stmt->bind_param('s', $nip);
                    $stmt->execute();
                    $stmt->close();

                    mulaiSesiPiket($guru['id'], $guru['nama_guru'], 'password');
                    header('Location: absen_manual.php');
                    exit;
                }

                error_log('[login_piket] gagal masuk, percobaan ke-' . (int)$percobaan['gagal']);
                $pesan = 'NIK atau password salah, atau Anda tidak terjadwal piket hari ini.';
            }
        }
    }
}

// Sudah masuk sebagai guru piket: langsung ke absen manual. Sesi admin
// sengaja tidak ikut dialihkan, supaya guru piket tetap bisa masuk di
// peramban yang masih menyimpan sesi admin.
if (sesiPiketAktif($conn) !== null) {
    header('Location: absen_manual.php');
    exit;
}

$csrf     = tokenCsrfPiket();
$hari_ini = getNamaHariIndonesia(date('l'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Masuk Guru Piket - SMK Terpadu Al Hasan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .kartu-masuk {
            width: 100%;
            max-width: 420px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 2rem;
        }

        .kartu-masuk .form-control {
            border-radius: 12px;
            padding: 0.7rem 1rem;
        }

        .btn-masuk {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 50px;
            padding: 0.75rem;
            color: white;
            font-weight: 600;
        }

        .btn-masuk:hover {
            color: white;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="kartu-masuk">
        <div class="text-center mb-4">
            <img src="classync.png" alt="Logo" height="56" class="mb-2">
            <h1 class="h5 fw-bold mb-1">Masuk Guru Piket</h1>
            <p class="text-muted small mb-0">
                Absensi manual siswa, khusus guru piket hari <?php echo htmlspecialchars($hari_ini, ENT_QUOTES, 'UTF-8'); ?>.
            </p>
        </div>

        <?php if ($pesan !== ''): ?>
            <div class="alert alert-danger small" role="alert"><?php echo htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php elseif ($pesan_ok !== ''): ?>
            <div class="alert alert-success small" role="status"><?php echo htmlspecialchars($pesan_ok, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="post" action="login_piket.php" autocomplete="off">
            <input type="hidden" name="aksi" value="masuk">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="mb-3">
                <label for="nip" class="form-label fw-semibold small">NIK</label>
                <input type="text" id="nip" name="nip" class="form-control" inputmode="numeric" maxlength="50"
                       required autofocus autocomplete="off"
                       value="<?php echo htmlspecialchars($nip, ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="mb-4">
                <label for="password" class="form-label fw-semibold small">Password</label>
                <input type="password" id="password" name="password" class="form-control" required autocomplete="off">
                <div class="form-text">Sama dengan password aplikasi Classync.</div>
            </div>

            <button type="submit" class="btn btn-masuk w-100">
                <i class="bi bi-box-arrow-in-right me-2"></i>Masuk
            </button>
        </form>

        <div class="text-center mt-3">
            <a href="absen-siswa.php" class="small text-decoration-none">
                <i class="bi bi-arrow-left me-1"></i>Kembali ke kiosk
            </a>
        </div>
    </div>
</body>
</html>
