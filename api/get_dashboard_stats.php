<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Set Zona Waktu
date_default_timezone_set('Asia/Jakarta');

require_once '../includes/db.php';

try {
    $tanggal_hari_ini = date('Y-m-d');

    // 1) Ambil pengaturan jam masuk & pulang
    $pengaturan_query = $conn->query("
        SELECT nama_pengaturan, nilai_pengaturan
        FROM pengaturan
        WHERE nama_pengaturan IN ('jam_masuk', 'jam_pulang')
    ");
    $pengaturan = [];
    while ($r = $pengaturan_query->fetch_assoc()) {
        $pengaturan[$r['nama_pengaturan']] = $r['nilai_pengaturan'];
    }

    $jam_masuk = (isset($pengaturan['jam_masuk']) && $pengaturan['jam_masuk'] !== '')
        ? date('H:i:s', strtotime($pengaturan['jam_masuk']))
        : '07:30:00';

    // 2) Statistik header (gunakan prepared statement)
    $likeDkv = '%DKV%';

    $stmt_total_siswa = $conn->prepare("
        SELECT COUNT(id) AS total
        FROM siswa
        WHERE kelas LIKE ?
    ");
    $stmt_total_siswa->bind_param("s", $likeDkv);
    $stmt_total_siswa->execute();
    $total_siswa = (int)$stmt_total_siswa->get_result()->fetch_assoc()['total'];
    $stmt_total_siswa->close();

    $total_guru = (int)$conn->query("SELECT COUNT(id) AS total FROM guru")->fetch_assoc()['total'];

    $stmt_total_kelas = $conn->prepare("
        SELECT COUNT(DISTINCT kelas) AS total
        FROM siswa
        WHERE kelas LIKE ?
    ");
    $stmt_total_kelas->bind_param("s", $likeDkv);
    $stmt_total_kelas->execute();
    $total_kelas = (int)$stmt_total_kelas->get_result()->fetch_assoc()['total'];
    $stmt_total_kelas->close();

    // 3) Statistik kehadiran hari ini
    $ot_stmt = $conn->prepare("
        SELECT COUNT(id) AS total
        FROM absensi_siswa
        WHERE tanggal = ?
          AND waktu_masuk IS NOT NULL
          AND TIME(waktu_masuk) <= ?
    ");
    $ot_stmt->bind_param("ss", $tanggal_hari_ini, $jam_masuk);
    $ot_stmt->execute();
    $on_time = (int)$ot_stmt->get_result()->fetch_assoc()['total'];
    $ot_stmt->close();

    $lat_stmt = $conn->prepare("
        SELECT COUNT(id) AS total
        FROM absensi_siswa
        WHERE tanggal = ?
          AND waktu_masuk IS NOT NULL
          AND TIME(waktu_masuk) > ?
    ");
    $lat_stmt->bind_param("ss", $tanggal_hari_ini, $jam_masuk);
    $lat_stmt->execute();
    $terlambat = (int)$lat_stmt->get_result()->fetch_assoc()['total'];
    $lat_stmt->close();

    $hadir_stmt = $conn->prepare("
        SELECT COUNT(DISTINCT siswa_id) AS total
        FROM absensi_siswa
        WHERE tanggal = ?
          AND waktu_masuk IS NOT NULL
    ");
    $hadir_stmt->bind_param("s", $tanggal_hari_ini);
    $hadir_stmt->execute();
    $hadir_count = (int)$hadir_stmt->get_result()->fetch_assoc()['total'];
    $hadir_stmt->close();

    $tidak_hadir = max(0, $total_siswa - $hadir_count);

    // 4) Recent activity
    $recent_query = $conn->prepare("
        SELECT
            s.nama_siswa,
            s.kelas,
            a.waktu_masuk,
            a.foto_masuk,
            CASE
                WHEN TIME(a.waktu_masuk) <= ? THEN 'Tepat Waktu'
                ELSE 'Terlambat'
            END AS status_masuk
        FROM absensi_siswa a
        JOIN siswa s ON a.siswa_id = s.id
        WHERE a.tanggal = ?
          AND a.waktu_masuk IS NOT NULL
          AND s.kelas LIKE ?
        ORDER BY a.waktu_masuk DESC
        LIMIT 5
    ");
    $recent_query->bind_param("sss", $jam_masuk, $tanggal_hari_ini, $likeDkv);
    $recent_query->execute();
    $recent_data = $recent_query->get_result()->fetch_all(MYSQLI_ASSOC);
    $recent_query->close();

    // 5) Progress kehadiran per kelas (OPTIMIZED: satu query, no N+1)
    $progress_stmt = $conn->prepare("
        SELECT
            s.kelas,
            COUNT(s.id) AS total_siswa,
            COUNT(DISTINCT CASE
                WHEN a.waktu_masuk IS NOT NULL THEN s.id
                ELSE NULL
            END) AS total_hadir
        FROM siswa s
        LEFT JOIN absensi_siswa a
            ON a.siswa_id = s.id
           AND a.tanggal = ?
        WHERE s.kelas LIKE ?
        GROUP BY s.kelas
        ORDER BY s.kelas ASC
    ");
    $progress_stmt->bind_param("ss", $tanggal_hari_ini, $likeDkv);
    $progress_stmt->execute();
    $progress_result = $progress_stmt->get_result();

    $progress_kelas = [];
    while ($row = $progress_result->fetch_assoc()) {
        $total_siswa_kelas = (int)$row['total_siswa'];
        $total_hadir_kelas = (int)$row['total_hadir'];
        $total_tidak_hadir_kelas = $total_siswa_kelas - $total_hadir_kelas;
        $persentase = $total_siswa_kelas > 0
            ? round(($total_hadir_kelas / $total_siswa_kelas) * 100)
            : 0;

        $progress_kelas[] = [
            'kelas' => $row['kelas'],
            'persentase' => $persentase,
            'total_siswa' => $total_siswa_kelas,
            'total_hadir' => $total_hadir_kelas,
            'total_tidak_hadir' => $total_tidak_hadir_kelas
        ];
    }
    $progress_stmt->close();

    echo json_encode([
        'status' => 'success',
        'stats' => [
            'totalSiswa' => $total_siswa,
            'totalGuru' => $total_guru,
            'totalKelas' => $total_kelas,
            'onTime' => $on_time,
            'terlambat' => $terlambat,
            'tidakHadir' => $tidak_hadir
        ],
        'recent' => $recent_data,
        'progress' => $progress_kelas
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>