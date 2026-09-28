<?php
// status_harian.php — status kehadiran siswa per hari.
//
// status_masuk hanya bercerita tentang kedatangan (Tepat Waktu/Terlambat)
// atau catatan absen manual (Sakit/Izin/Alpa). Kehadiran seorang siswa baru
// final setelah jam pulang: yang absen masuk tetapi tidak absen pulang tidak
// dihitung Hadir. Hasil akhirnya disimpan di absensi_siswa.status_harian.
//
// status_masuk sengaja tidak diubah: monitoring_siswa.tsx di ClassyncApp
// membandingkannya persis dengan 'Tepat Waktu' dan 'Terlambat'.
//
// Akan dipakai cron sore (cron_status_harian.php) dan tombol "Hitung ulang"
// di admin/status_harian.php. Mode senyap: berkas ini tidak mengirim WA.
//
// Butuh includes/db.php lebih dulu: zona waktu Asia/Jakarta dan
// getNamaHariIndonesia() yang dipakai infoHariSekolah().

require_once __DIR__ . '/kalender_sekolah.php';

if (!function_exists('daftarSiswaPkl')) {
    // Siswa yang sedang PKL tidak ikut aturan pulang sekolah; absensinya
    // lewat absensi_pkl.php dengan aturannya sendiri.
    //
    // penempatan_pkl belum punya tanggal mulai dan selesai, jadi "sedang
    // PKL" berarti "punya baris penempatan". Saat PKL berakhir, TU harus
    // menghapus penempatannya lewat admin/penempatan_pkl.php; kalau tidak,
    // siswa itu terus dikecualikan. Kalau kelak penempatan diberi tanggal,
    // cukup fungsi ini yang diubah.
    //
    // Mengembalikan [siswa_id => true, ...].
    function daftarSiswaPkl($conn) {
        $daftar = [];
        $hasil = $conn->query("SELECT siswa_id FROM penempatan_pkl");
        while ($baris = $hasil->fetch_assoc()) {
            $daftar[(int)$baris['siswa_id']] = true;
        }
        return $daftar;
    }
}

if (!function_exists('golonganStatusHarian')) {
    // Status harian untuk satu baris absensi_siswa. Hanya membaca
    // waktu_masuk, waktu_pulang, status_masuk, dan status_harian.
    //
    // Mengembalikan 'Hadir', 'Pulang Lebih Awal', 'Sakit', 'Izin', 'Alpa',
    // atau null kalau barisnya tidak bisa digolongkan.
    function golonganStatusHarian(array $baris) {
        $ada_masuk  = !empty($baris['waktu_masuk']);
        $ada_pulang = !empty($baris['waktu_pulang']);

        if ($ada_masuk) {
            // Izin pulang yang dicatat guru piket. Fungsi ini sendiri tidak
            // pernah memberi Izin pada baris yang punya absen masuk, jadi
            // Izin di baris seperti itu pasti catatan guru piket dan tidak
            // boleh ditimpa.
            if (($baris['status_harian'] ?? null) === 'Izin') {
                return 'Izin';
            }
            return $ada_pulang ? 'Hadir' : 'Pulang Lebih Awal';
        }

        // Tanpa absen masuk, hanya catatan absen manual yang bisa
        // digolongkan. 'Alpha' ejaan lama yang masih ada di enum.
        $dari_absen_manual = ['Sakit' => 'Sakit', 'Izin' => 'Izin', 'Alpa' => 'Alpa', 'Alpha' => 'Alpa'];
        return $dari_absen_manual[$baris['status_masuk'] ?? ''] ?? null;
    }
}

if (!function_exists('catatLogStatusHarian')) {
    // Satu baris log_status_harian untuk setiap kali penggolongan dijalankan,
    // termasuk ketika tanggalnya dilewati.
    function catatLogStatusHarian($conn, $tanggal, $pemicu, $admin_id, $hasil, $keterangan, array $jumlah) {
        $dijalankan = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("INSERT INTO log_status_harian (tanggal, dijalankan, pemicu, admin_id, hasil, keterangan, hadir, pulang_awal, izin, sakit, alpa, pkl) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssissiiiiii', $tanggal, $dijalankan, $pemicu, $admin_id, $hasil, $keterangan,
                          $jumlah['hadir'], $jumlah['pulang_awal'], $jumlah['izin'],
                          $jumlah['sakit'], $jumlah['alpa'], $jumlah['pkl']);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('tetapkanStatusHarian')) {
    // Menggolongkan semua baris absensi_siswa pada satu tanggal, menyimpan
    // hasilnya di status_harian, dan mencatat jalannya ke log_status_harian.
    //
    // - Minggu dan tanggal Libur dilewati, begitu pula hari ini sebelum jam
    //   pulang. Keduanya tetap dicatat ke log: log itulah yang menunjukkan
    //   cron masih hidup, dan yang memperlihatkan jadwal cron yang salah jam.
    // - Dihitung ulang setiap kali jalan. Nilai lama ditimpa, kecuali izin
    //   pulang dari guru piket, jadi menjalankannya dua kali aman dan
    //   hitungan yang terlalu awal dibetulkan oleh jalan berikutnya.
    // - Baris siswa PKL tidak disentuh.
    // - Tidak pernah menambah baris. Siswa yang tidak punya baris sama sekali
    //   pada tanggal itu dibiarkan; itu bagian Alpa otomatis.
    //
    // $tanggal   'Y-m-d', paling lambat hari ini.
    // $pemicu    'cron' atau 'admin'.
    // $admin_id  wajib kalau $pemicu 'admin'.
    //
    // Mengembalikan ['hasil' => …, 'pesan' => …, 'jumlah' => …]:
    //   'selesai'   dihitung; 'jumlah' berisi hitungan per golongan
    //   'dilewati'  hari tanpa sekolah, atau hari ini sebelum jam pulang
    //   'ditolak'   masukan tidak sah; tidak dicatat ke log
    //   'gagal'     galat basis data; semua perubahan dibatalkan dan
    //               rinciannya ke error_log
    function tetapkanStatusHarian($conn, $tanggal, $pemicu, $admin_id = null) {
        if ($pemicu === 'cron') {
            $admin_id = null;
        } elseif ($pemicu === 'admin' && !empty($admin_id)) {
            $admin_id = (int)$admin_id;
        } else {
            return ['hasil' => 'ditolak', 'pesan' => 'Pemicu tidak sah.', 'jumlah' => null];
        }

        $hari_ini = date('Y-m-d');
        try {
            $info = infoHariSekolah($conn, $tanggal);
            if ($info === null) {
                return ['hasil' => 'ditolak', 'pesan' => 'Tanggal tidak sah.', 'jumlah' => null];
            }
            if ($tanggal > $hari_ini) {
                return ['hasil' => 'ditolak', 'pesan' => 'Tanggal itu belum terjadi.', 'jumlah' => null];
            }

            $jumlah = ['hadir' => 0, 'pulang_awal' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'pkl' => 0];

            $alasan_lewat = null;
            if (!$info['masuk_sekolah']) {
                $alasan_lewat = $info['jenis'] === 'Libur' ? 'Libur: ' . $info['keterangan'] : 'Hari Minggu.';
            } elseif ($tanggal === $hari_ini && date('H:i:s') < $info['jam_pulang']) {
                $alasan_lewat = 'Dijalankan pukul ' . date('H.i') . ', sebelum jam pulang pukul '
                              . date('H.i', strtotime($info['jam_pulang'])) . '.';
            }
            if ($alasan_lewat !== null) {
                catatLogStatusHarian($conn, $tanggal, $pemicu, $admin_id, 'dilewati', $alasan_lewat, $jumlah);
                return ['hasil' => 'dilewati', 'pesan' => $alasan_lewat, 'jumlah' => null];
            }

            $pkl = daftarSiswaPkl($conn);
            $kunci_jumlah = ['Hadir' => 'hadir', 'Pulang Lebih Awal' => 'pulang_awal', 'Izin' => 'izin', 'Sakit' => 'sakit', 'Alpa' => 'alpa'];
            $diubah = 0;
            $tak_tergolong = 0;
            $berubah_saat_dihitung = 0;

            $conn->begin_transaction();

            $stmt = $conn->prepare("SELECT id, siswa_id, waktu_masuk, waktu_pulang, status_masuk, status_harian FROM absensi_siswa WHERE tanggal = ?");
            $stmt->bind_param('s', $tanggal);
            $stmt->execute();
            $semua_baris = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Baris hanya diubah kalau isinya masih sama dengan yang tadi
            // dibaca. FOR UPDATE sengaja tidak dipakai: kolom tanggal tidak
            // berindeks sendiri, jadi InnoDB akan mengunci seluruh tabel dan
            // menahan absen di kiosk. Kalau seorang siswa absen tepat saat
            // penggolongan berjalan, barisnya dilewati dan dibetulkan jalan
            // berikutnya, bukan ditimpa dengan golongan yang sudah basi.
            $stmt_ubah = $conn->prepare("UPDATE absensi_siswa SET status_harian = ? WHERE id = ? AND waktu_masuk <=> ? AND waktu_pulang <=> ? AND status_masuk <=> ? AND status_harian <=> ?");
            foreach ($semua_baris as $baris) {
                if (isset($pkl[(int)$baris['siswa_id']])) {
                    $jumlah['pkl']++;
                    continue;
                }
                $golongan = golonganStatusHarian($baris);
                if ($golongan !== $baris['status_harian']) {
                    $id = (int)$baris['id'];
                    $stmt_ubah->bind_param('sissss', $golongan, $id, $baris['waktu_masuk'], $baris['waktu_pulang'],
                                           $baris['status_masuk'], $baris['status_harian']);
                    try {
                        $stmt_ubah->execute();
                        $berubah = $stmt_ubah->affected_rows !== 1;
                    } catch (mysqli_sql_exception $e) {
                        // MariaDB 10.6 di produksi membaca versi terbaru baris
                        // itu, jadi syarat di atas tidak cocok dan tidak ada
                        // yang diubah. MariaDB 11.6 ke atas (dengan
                        // innodb_snapshot_isolation) menolaknya dengan galat
                        // 1020. Artinya sama, dan hanya pernyataan ini yang
                        // batal; transaksinya tetap berjalan.
                        if ($e->getCode() !== 1020) {
                            throw $e;
                        }
                        $berubah = true;
                    }
                    if ($berubah) {
                        $berubah_saat_dihitung++;
                        continue;
                    }
                    $diubah++;
                }
                if ($golongan === null) {
                    $tak_tergolong++;
                } else {
                    $jumlah[$kunci_jumlah[$golongan]]++;
                }
            }
            $stmt_ubah->close();

            $keterangan = $diubah . ' baris diubah.';
            if ($tak_tergolong > 0) {
                $keterangan .= ' ' . $tak_tergolong . ' baris tidak bisa digolongkan.';
            }
            if ($berubah_saat_dihitung > 0) {
                $keterangan .= ' ' . $berubah_saat_dihitung . ' baris berubah saat dihitung; jalankan ulang.';
            }
            catatLogStatusHarian($conn, $tanggal, $pemicu, $admin_id, 'selesai', $keterangan, $jumlah);
            $conn->commit();

            return ['hasil' => 'selesai', 'pesan' => $keterangan, 'jumlah' => $jumlah];
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $e_batal) {
                // Tidak ada transaksi yang bisa dibatalkan.
            }
            error_log('[status_harian] ' . $tanggal . ' gagal dihitung: ' . $e->getMessage());
            return ['hasil' => 'gagal', 'pesan' => 'Status harian gagal dihitung. Rinciannya ada di error_log.', 'jumlah' => null];
        }
    }
}
