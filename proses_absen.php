<?php
require 'includes/db.php';

header('Content-Type: application/json'); // Set header untuk response AJAX

// Logika untuk Cek Jadwal (AJAX Request)
if (isset($_GET['action']) && $_GET['action'] == 'cek_jadwal') {
    $guru_id = $_GET['guru_id'];
    $tipe = $_GET['tipe'];
    
    $hari_ini = getNamaHariIndonesia(date('l'));
    $jam_sekarang = date('H:i:s');
    
    $response = ['status' => 'gagal', 'pesan' => 'Jadwal tidak ditemukan.'];

    $sql = "";
    if ($tipe == 'mengajar') {
        $sql = "SELECT id, mata_pelajaran, kelas, jam_mulai, jam_selesai FROM jadwal_mengajar WHERE guru_id = ? AND hari = ? AND ? BETWEEN jam_mulai AND jam_selesai LIMIT 1";
    } elseif ($tipe == 'piket') {
        $sql = "SELECT id, sesi FROM jadwal_piket WHERE guru_id = ? AND hari = ? LIMIT 1";
    } elseif ($tipe == 'ekskul') {
        $sql = "SELECT id, nama_ekskul, jam_mulai, jam_selesai FROM jadwal_ekskul WHERE guru_id = ? AND hari = ? AND ? BETWEEN jam_mulai AND jam_selesai LIMIT 1";
    }

    $stmt = $conn->prepare($sql);
    
    if ($tipe == 'piket') {
        $stmt->bind_param("is", $guru_id, $hari_ini);
    } else {
        $stmt->bind_param("iss", $guru_id, $hari_ini, $jam_sekarang);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $jadwal = $result->fetch_assoc();
        $response['status'] = 'sukses';
        $response['pesan'] = 'Jadwal ditemukan.';
        $response['jadwal'] = $jadwal;
    } else {
        $response['pesan'] = 'Tidak ada jadwal untuk Anda pada jam ini.';
    }

    echo json_encode($response);
    exit;
}

// Logika untuk Menyimpan Absensi (Form Submission)
if (isset($_POST['submit'])) {
    header('Content-Type: text/html'); // Balikkan ke HTML untuk redirect
    $guru_id = $_POST['guru_id'];
    $tipe_absensi = $_POST['tipe_absensi'];
    $redirect_url = "absen_{$tipe_absensi}.php";

    $hari_ini = getNamaHariIndonesia(date('l'));
    $jam_sekarang = date('H:i:s');
    $waktu_absensi = date('Y-m-d H:i:s');

    // Cek jadwal sekali lagi sebelum menyimpan
    $sql_cek = "";
    if ($tipe_absensi == 'mengajar') {
        $sql_cek = "SELECT id FROM jadwal_mengajar WHERE guru_id = ? AND hari = ? AND ? BETWEEN jam_mulai AND jam_selesai LIMIT 1";
    } elseif ($tipe_absensi == 'piket') {
        $sql_cek = "SELECT id FROM jadwal_piket WHERE guru_id = ? AND hari = ? LIMIT 1";
    } elseif ($tipe_absensi == 'ekskul') {
        $sql_cek = "SELECT id FROM jadwal_ekskul WHERE guru_id = ? AND hari = ? AND ? BETWEEN jam_mulai AND jam_selesai LIMIT 1";
    }

    $stmt_cek = $conn->prepare($sql_cek);
    if ($tipe_absensi == 'piket') {
        $stmt_cek->bind_param("is", $guru_id, $hari_ini);
    } else {
        $stmt_cek->bind_param("iss", $guru_id, $hari_ini, $jam_sekarang);
    }
    $stmt_cek->execute();
    $result_cek = $stmt_cek->get_result();
    
    if ($result_cek->num_rows > 0) {
        $jadwal = $result_cek->fetch_assoc();
        $jadwal_id = $jadwal['id'];

        // Cek apakah sudah absen hari ini untuk jadwal ini
        $sql_sudah_absen = "SELECT id FROM absensi WHERE guru_id = ? AND jadwal_id = ? AND tipe_absensi = ? AND DATE(waktu_absensi) = CURDATE()";
        $stmt_sudah_absen = $conn->prepare($sql_sudah_absen);
        $stmt_sudah_absen->bind_param("iis", $guru_id, $jadwal_id, $tipe_absensi);
        $stmt_sudah_absen->execute();
        if ($stmt_sudah_absen->get_result()->num_rows > 0) {
            header("Location: $redirect_url?status=gagal&pesan=" . urlencode("Anda sudah melakukan absensi untuk jadwal ini hari ini."));
            exit;
        }

        // Simpan absensi
        $sql_insert = "INSERT INTO absensi (guru_id, jadwal_id, tipe_absensi, waktu_absensi, status) VALUES (?, ?, ?, ?, 'Hadir')";
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->bind_param("iiss", $guru_id, $jadwal_id, $tipe_absensi, $waktu_absensi);
        
        if ($stmt_insert->execute()) {
            header("Location: $redirect_url?status=sukses&pesan=" . urlencode("Absensi berhasil direkam. Terima kasih."));
        } else {
            header("Location: $redirect_url?status=gagal&pesan=" . urlencode("Gagal merekam absensi."));
        }
    } else {
        header("Location: $redirect_url?status=gagal&pesan=" . urlencode("Jadwal tidak valid atau sudah berakhir."));
    }
    exit;
}
?>