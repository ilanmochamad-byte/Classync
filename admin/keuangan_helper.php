<?php
// keuangan_helper.php - Pusat Kalkulasi Finansial Classync

if (!isset($JAM_ISTIRAHAT)) {
    $JAM_ISTIRAHAT = [
        ['mulai' => '10:10:00', 'selesai' => '10:25:00', 'durasi' => 15],
        ['mulai' => '11:45:00', 'selesai' => '12:05:00', 'durasi' => 20]
    ];
}

if (!function_exists('getPengaturanHonor')) {
    /**
     * Membaca tarif honor dari tabel pengaturan.
     * Hasil di-cache dalam variabel statis agar tidak query berulang dalam satu request.
     */
    function getPengaturanHonor($conn) {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $q_set = $conn->query("SELECT nama_pengaturan, nilai_pengaturan FROM pengaturan WHERE nama_pengaturan LIKE 'honor_%'");
        $tarif = [];
        if ($q_set) {
            while($row = $q_set->fetch_assoc()) {
                $tarif[$row['nama_pengaturan']] = (int)$row['nilai_pengaturan'];
            }
        }
        $cached = [
            'honor_per_jp' => $tarif['honor_per_jp'] ?? 10000,
            'honor_ekskul' => $tarif['honor_ekskul'] ?? 25000,
            'honor_piket'  => $tarif['honor_piket'] ?? 25000,
            'honor_bk'     => $tarif['honor_bk'] ?? 25000
        ];
        return $cached;
    }
}

if (!function_exists('hitungJP')) {
    function hitungJP($jam_mulai, $jam_selesai) { 
        global $JAM_ISTIRAHAT;
        if (empty($jam_mulai) || empty($jam_selesai)) return 0;

        $mulai = new DateTime($jam_mulai);
        $selesai = new DateTime($jam_selesai);
        $diff = $selesai->diff($mulai);
        $total_menit_kotor = ($diff->h * 60) + $diff->i;
        
        $menit_pengurang = 0;
        foreach ($JAM_ISTIRAHAT as $istirahat) {
            $mulai_istirahat = new DateTime($istirahat['mulai']);
            $selesai_istirahat = new DateTime($istirahat['selesai']);
            if ($mulai < $mulai_istirahat && $selesai > $selesai_istirahat) {
                $menit_pengurang += $istirahat['durasi'];
            }
        }

        $menit_efektif = $total_menit_kotor - $menit_pengurang;
        if ($menit_efektif <= 0) return 0;
        return round($menit_efektif / 40); 
    }
}

if (!function_exists('cekAbsenBerturutTurut')) {
    function cekAbsenBerturutTurut($conn, $guru_id, $bulan, $tahun) { 
        return false; 
    }
}

if (!function_exists('hitungHonorBulan')) {
    function hitungHonorBulan($conn, $guru_id, $bulan, $tahun, $tarif) {
        $honor_mengajar = 0; $total_jp = 0;
        $honor_piket = 0; $jumlah_piket = 0;
        $honor_ekskul = 0; $jumlah_ekskul = 0;
        $honor_bk = 0; $jumlah_bk = 0;
        $uang_transport = 0;

        // Rentang tanggal bulan yang dipilih (menghindari fungsi MONTH/YEAR agar index dapat dipakai)
        $date_from = sprintf('%04d-%02d-01', $tahun, $bulan);
        $date_to   = date('Y-m-01', strtotime($date_from . ' +1 month'));

        // A. Tunjangan Tetap — hanya kolom yang diperlukan
        $tunjangan_data = [];
        $stmt_tunj = $conn->prepare("SELECT masa_kerja, jabatan, suami_istri, anak, wali_kelas FROM tunjangan_guru WHERE guru_id = ?");
        if ($stmt_tunj) {
            $stmt_tunj->bind_param("i", $guru_id);
            $stmt_tunj->execute();
            $res = $stmt_tunj->get_result();
            if ($res->num_rows > 0) {
                $tunjangan_data = $res->fetch_assoc();
            }
            $stmt_tunj->close();
        }

        // B. Hitung Honor Mengajar (gunakan rentang tanggal, bukan MONTH/YEAR)
        $sql_mengajar = "SELECT a.status, jm.jam_mulai, jm.jam_selesai
                         FROM absensi a
                         JOIN jadwal_mengajar jm ON a.jadwal_id = jm.id
                         WHERE a.guru_id = ?
                           AND a.tipe_absensi = 'mengajar'
                           AND a.waktu_absensi >= ? AND a.waktu_absensi < ?";
        $stmt_mengajar = $conn->prepare($sql_mengajar);
        if ($stmt_mengajar) {
            $stmt_mengajar->bind_param("iss", $guru_id, $date_from, $date_to);
            $stmt_mengajar->execute();
            $result_mengajar = $stmt_mengajar->get_result();
            while($absen = $result_mengajar->fetch_assoc()) {
                $jp = hitungJP($absen['jam_mulai'], $absen['jam_selesai']);
                $honor_basis = $jp * $tarif['honor_per_jp'];
                if ($absen['status'] === 'Hadir' || $absen['status'] === 'Sakit') {
                    $honor_mengajar += $honor_basis; $total_jp += $jp;
                } elseif ($absen['status'] === 'Izin') {
                    $honor_mengajar += ($honor_basis * 0.75); $total_jp += $jp;
                }
            }
            $stmt_mengajar->close();
        }

        // C. Hitung Honor Piket, Ekskul & BK (gunakan rentang tanggal)
        $sql_lain = "SELECT status, tipe_absensi
                     FROM absensi
                     WHERE guru_id = ?
                       AND tipe_absensi IN ('piket', 'ekskul', 'bimbingan')
                       AND waktu_absensi >= ? AND waktu_absensi < ?";
        $stmt_lain = $conn->prepare($sql_lain);
        if ($stmt_lain) {
            $stmt_lain->bind_param("iss", $guru_id, $date_from, $date_to);
            $stmt_lain->execute();
            $result_lain = $stmt_lain->get_result();
            while($absen_lain = $result_lain->fetch_assoc()) {
                $honor_diterima = 0;
                $tipe = trim($absen_lain['tipe_absensi']);
                $status = trim($absen_lain['status']);
                
                $honor_basis = 0;
                if ($tipe == 'piket') $honor_basis = $tarif['honor_piket'];
                elseif ($tipe == 'ekskul') $honor_basis = $tarif['honor_ekskul'];
                elseif ($tipe == 'bimbingan') $honor_basis = $tarif['honor_bk'];

                if ($status === 'Hadir' || $status === 'Sakit') { $honor_diterima = $honor_basis; }
                elseif ($status === 'Izin') { $honor_diterima = $honor_basis * 0.75; }
                
                if ($honor_diterima > 0) {
                    if ($tipe == 'piket') { $jumlah_piket++; $honor_piket += $honor_diterima; } 
                    elseif ($tipe == 'ekskul') { $jumlah_ekskul++; $honor_ekskul += $honor_diterima; }
                    elseif ($tipe == 'bimbingan') { $jumlah_bk++; $honor_bk += $honor_diterima; } 
                }
            }
            $stmt_lain->close();
        }

        // D. Hitung Akumulasi Uang Transportasi Harian (gunakan rentang tanggal)
        $date_to_tanggal = date('Y-m-t', strtotime($date_from)); // tanggal terakhir bulan (inklusif)
        $stmt_transport = $conn->prepare("SELECT SUM(bonus) AS total_transport FROM absensi_harian WHERE guru_id = ? AND tanggal >= ? AND tanggal <= ?");
        if ($stmt_transport) {
            $stmt_transport->bind_param("iss", $guru_id, $date_from, $date_to_tanggal);
            $stmt_transport->execute();
            $res_transport = $stmt_transport->get_result();
            if ($res_transport->num_rows > 0) {
                $row_transport = $res_transport->fetch_assoc();
                $uang_transport = (int)($row_transport['total_transport'] ?? 0);
            }
            $stmt_transport->close();
        }

        // E. Kalkulasi Total Tunjangan
        $total_tunjangan = ($tunjangan_data['masa_kerja'] ?? 0) + ($tunjangan_data['jabatan'] ?? 0) + ($tunjangan_data['suami_istri'] ?? 0) + ($tunjangan_data['anak'] ?? 0) + ($tunjangan_data['wali_kelas'] ?? 0);
        
        // Ambil Potongan — hanya kolom yang diperlukan
        $potongan_data = [];
        $stmt_pot = $conn->prepare("SELECT arisan, tabungan FROM potongan_guru WHERE guru_id = ? AND bulan = ? AND tahun = ?");
        if ($stmt_pot) {
            $stmt_pot->bind_param("iii", $guru_id, $bulan, $tahun);
            $stmt_pot->execute();
            $res_pot = $stmt_pot->get_result();
            if ($res_pot->num_rows > 0) {
                $potongan_data = $res_pot->fetch_assoc();
            }
            $stmt_pot->close();
        }
        $potongan_arisan = $potongan_data['arisan'] ?? 0;
        $potongan_tabungan = $potongan_data['tabungan'] ?? 0;

        // F. Kalkulasi Final 
        $subtotal_pendapatan = $total_tunjangan + $honor_mengajar + $honor_piket + $honor_ekskul + $honor_bk + $uang_transport;
        $total_potongan = $potongan_arisan + $potongan_tabungan;
        $total_diterima = $subtotal_pendapatan - $total_potongan;

        return [
            'id' => $guru_id,
            'total_tunjangan' => $total_tunjangan,
            'honor_mengajar' => $honor_mengajar,
            'total_jp' => $total_jp,
            'honor_piket' => $honor_piket,
            'jumlah_piket' => $jumlah_piket,
            'honor_ekskul' => $honor_ekskul,
            'jumlah_ekskul' => $jumlah_ekskul,
            'honor_bk' => $honor_bk,
            'jumlah_bk' => $jumlah_bk,
            'uang_transport' => $uang_transport,
            'subtotal_pendapatan' => $subtotal_pendapatan,
            'potongan_arisan' => $potongan_arisan,
            'potongan_tabungan' => $potongan_tabungan,
            'total_potongan' => $total_potongan,
            'total_diterima' => $total_diterima
        ];
    }
}

if (!function_exists('hitungHonorBulanBatch')) {
    /**
     * Menghitung honor untuk SEMUA guru sekaligus dalam 5 query (bukan N×5 query).
     * Digunakan oleh laporan_honor.php untuk menghindari N+1 problem.
     *
     * @param mysqli   $conn
     * @param int[]    $guru_ids   Array ID guru
     * @param int      $bulan
     * @param int      $tahun
     * @param array    $tarif      Hasil getPengaturanHonor()
     * @return array   Map guru_id => rincian honor (format sama dengan hitungHonorBulan)
     */
    function hitungHonorBulanBatch($conn, array $guru_ids, $bulan, $tahun, $tarif) {
        if (empty($guru_ids)) return [];

        $date_from       = sprintf('%04d-%02d-01', $tahun, $bulan);
        $date_to         = date('Y-m-01', strtotime($date_from . ' +1 month'));
        $date_to_tanggal = date('Y-m-t', strtotime($date_from));

        $placeholders = implode(',', array_fill(0, count($guru_ids), '?'));
        $id_types     = str_repeat('i', count($guru_ids));

        // Inisialisasi hasil untuk setiap guru
        $results = [];
        foreach ($guru_ids as $id) {
            $results[$id] = [
                'id' => $id,
                '_tunjangan_raw' => [],
                'honor_mengajar' => 0, 'total_jp' => 0,
                'honor_piket'    => 0, 'jumlah_piket' => 0,
                'honor_ekskul'   => 0, 'jumlah_ekskul' => 0,
                'honor_bk'       => 0, 'jumlah_bk' => 0,
                'uang_transport' => 0,
                'total_tunjangan'    => 0,
                'potongan_arisan'    => 0,
                'potongan_tabungan'  => 0,
                'subtotal_pendapatan' => 0,
                'total_potongan'     => 0,
                'total_diterima'     => 0,
            ];
        }

        // 1. Tunjangan Tetap (1 query untuk semua guru)
        $stmt = $conn->prepare("SELECT guru_id, masa_kerja, jabatan, suami_istri, anak, wali_kelas FROM tunjangan_guru WHERE guru_id IN ($placeholders)");
        $stmt->bind_param($id_types, ...$guru_ids);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $results[$row['guru_id']]['_tunjangan_raw'] = $row;
        }
        $stmt->close();

        // 2. Honor Mengajar (1 query untuk semua guru, rentang tanggal)
        $stmt = $conn->prepare(
            "SELECT a.guru_id, a.status, jm.jam_mulai, jm.jam_selesai
             FROM absensi a
             JOIN jadwal_mengajar jm ON a.jadwal_id = jm.id
             WHERE a.guru_id IN ($placeholders)
               AND a.tipe_absensi = 'mengajar'
               AND a.waktu_absensi >= ? AND a.waktu_absensi < ?"
        );
        $params = array_merge($guru_ids, [$date_from, $date_to]);
        $stmt->bind_param($id_types . 'ss', ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $gid = $row['guru_id'];
            $jp  = hitungJP($row['jam_mulai'], $row['jam_selesai']);
            $hb  = $jp * $tarif['honor_per_jp'];
            if ($row['status'] === 'Hadir' || $row['status'] === 'Sakit') {
                $results[$gid]['honor_mengajar'] += $hb;
                $results[$gid]['total_jp'] += $jp;
            } elseif ($row['status'] === 'Izin') {
                $results[$gid]['honor_mengajar'] += $hb * 0.75;
                $results[$gid]['total_jp'] += $jp;
            }
        }
        $stmt->close();

        // 3. Honor Piket / Ekskul / BK (1 query untuk semua guru)
        $stmt = $conn->prepare(
            "SELECT guru_id, status, tipe_absensi
             FROM absensi
             WHERE guru_id IN ($placeholders)
               AND tipe_absensi IN ('piket', 'ekskul', 'bimbingan')
               AND waktu_absensi >= ? AND waktu_absensi < ?"
        );
        $params = array_merge($guru_ids, [$date_from, $date_to]);
        $stmt->bind_param($id_types . 'ss', ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $gid   = $row['guru_id'];
            $tipe  = trim($row['tipe_absensi']);
            $status = trim($row['status']);
            $hb    = 0;
            if ($tipe == 'piket') $hb = $tarif['honor_piket'];
            elseif ($tipe == 'ekskul') $hb = $tarif['honor_ekskul'];
            elseif ($tipe == 'bimbingan') $hb = $tarif['honor_bk'];
            $honor_diterima = 0;
            if ($status === 'Hadir' || $status === 'Sakit') { $honor_diterima = $hb; }
            elseif ($status === 'Izin') { $honor_diterima = $hb * 0.75; }
            if ($honor_diterima > 0) {
                if ($tipe == 'piket') { $results[$gid]['jumlah_piket']++; $results[$gid]['honor_piket'] += $honor_diterima; }
                elseif ($tipe == 'ekskul') { $results[$gid]['jumlah_ekskul']++; $results[$gid]['honor_ekskul'] += $honor_diterima; }
                elseif ($tipe == 'bimbingan') { $results[$gid]['jumlah_bk']++; $results[$gid]['honor_bk'] += $honor_diterima; }
            }
        }
        $stmt->close();

        // 4. Uang Transportasi (1 query untuk semua guru)
        $stmt = $conn->prepare(
            "SELECT guru_id, SUM(bonus) AS total_transport
             FROM absensi_harian
             WHERE guru_id IN ($placeholders)
               AND tanggal >= ? AND tanggal <= ?
             GROUP BY guru_id"
        );
        $params = array_merge($guru_ids, [$date_from, $date_to_tanggal]);
        $stmt->bind_param($id_types . 'ss', ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $results[$row['guru_id']]['uang_transport'] = (int)($row['total_transport'] ?? 0);
        }
        $stmt->close();

        // 5. Potongan (1 query untuk semua guru)
        $stmt = $conn->prepare(
            "SELECT guru_id, arisan, tabungan
             FROM potongan_guru
             WHERE guru_id IN ($placeholders) AND bulan = ? AND tahun = ?"
        );
        $params = array_merge($guru_ids, [$bulan, $tahun]);
        $stmt->bind_param($id_types . 'ii', ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $results[$row['guru_id']]['potongan_arisan']   = $row['arisan'];
            $results[$row['guru_id']]['potongan_tabungan'] = $row['tabungan'];
        }
        $stmt->close();

        // Kalkulasi final untuk setiap guru
        foreach ($results as $id => &$r) {
            $td = $r['_tunjangan_raw'];
            $r['total_tunjangan'] = ($td['masa_kerja'] ?? 0) + ($td['jabatan'] ?? 0)
                + ($td['suami_istri'] ?? 0) + ($td['anak'] ?? 0) + ($td['wali_kelas'] ?? 0);
            $r['subtotal_pendapatan'] = $r['total_tunjangan'] + $r['honor_mengajar']
                + $r['honor_piket'] + $r['honor_ekskul'] + $r['honor_bk'] + $r['uang_transport'];
            $r['total_potongan'] = $r['potongan_arisan'] + $r['potongan_tabungan'];
            $r['total_diterima'] = $r['subtotal_pendapatan'] - $r['total_potongan'];
            unset($r['_tunjangan_raw']);
        }
        unset($r);

        return $results;
    }
}
?>