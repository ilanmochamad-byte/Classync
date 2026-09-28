<?php
// kalender_sekolah.php — jenis hari dan jam sekolah untuk satu tanggal.
//
// Satu-satunya tempat yang menjawab "tanggal ini masuk sekolah atau tidak, dan
// pulang jam berapa". Kiosk memakainya sekarang. Cron Alpa otomatis, cron sore
// (Pulang Lebih Awal), dan jendela absen pulang akan memakainya nanti. Jangan
// menghitung ulang aturan ini di tempat lain.
//
// Butuh includes/db.php lebih dulu: zona waktu Asia/Jakarta dan
// getNamaHariIndonesia().
//
// Jam pulang ditentukan berurutan:
//   1. Pulang Cepat di tabel kalender_sekolah untuk tanggal itu;
//   2. hari Jumat: pengaturan jam_pulang_jumat;
//   3. selain itu: pengaturan jam_pulang (Senin–Kamis dan Sabtu).
// Minggu dan tanggal Libur tidak masuk sekolah, dan jam_pulang-nya null.
//
// Arti kunci jam_pulang sengaja tidak diubah. Pembaca lamanya, termasuk
// get_monitoring_absensi.php di repo API, tetap melihat jam pulang
// Senin–Kamis dan Sabtu.

if (!function_exists('infoHariSekolah')) {
    // $tanggal         'Y-m-d'; null berarti hari ini.
    // $pakai_kalender  false untuk jadwal normal tanpa isi kalender_sekolah,
    //                  misalnya untuk memeriksa isian Pulang Cepat yang baru.
    //
    // Mengembalikan:
    //   'tanggal'       'Y-m-d'
    //   'hari'          'Senin' … 'Minggu'
    //   'masuk_sekolah' bool — false untuk Minggu dan tanggal Libur
    //   'jenis'         'Biasa' | 'Pulang Cepat' | 'Libur' | 'Minggu'
    //   'keterangan'    keterangan dari kalender, atau null
    //   'jam_masuk'     'H:i:s'
    //   'jam_pulang'    'H:i:s', atau null kalau tidak masuk sekolah
    // atau null kalau $tanggal bukan tanggal yang sah.
    function infoHariSekolah($conn, $tanggal = null, $pakai_kalender = true) {
        if ($tanggal === null) {
            $tanggal = date('Y-m-d');
        }
        // Dicocokkan bolak-balik: createFromFormat() menerima luapan, jadi
        // tanpa pencocokan ini 30 Februari diam-diam menjadi 2 Maret.
        $dt = is_string($tanggal) ? DateTime::createFromFormat('!Y-m-d', $tanggal) : false;
        if ($dt === false || $dt->format('Y-m-d') !== $tanggal) {
            return null;
        }
        $hari = getNamaHariIndonesia($dt->format('l'));

        $pengaturan = [];
        $hasil = $conn->query("SELECT nama_pengaturan, nilai_pengaturan FROM pengaturan WHERE nama_pengaturan IN ('jam_masuk', 'jam_pulang', 'jam_pulang_jumat')");
        while ($baris = $hasil->fetch_assoc()) {
            if ($baris['nilai_pengaturan'] !== null && $baris['nilai_pengaturan'] !== '') {
                $pengaturan[$baris['nama_pengaturan']] = date('H:i:s', strtotime($baris['nilai_pengaturan']));
            }
        }
        // Cadangan sama dengan absen-siswa.php, hanya kalau barisnya hilang.
        $jam_masuk  = $pengaturan['jam_masuk']  ?? '07:30:00';
        $jam_pulang = $pengaturan['jam_pulang'] ?? '13:50:00';
        if ($hari === 'Jumat') {
            if (isset($pengaturan['jam_pulang_jumat'])) {
                $jam_pulang = $pengaturan['jam_pulang_jumat'];
            } else {
                error_log('[kalender_sekolah] jam_pulang_jumat belum diatur; hari Jumat memakai jam_pulang');
            }
        }

        $info = [
            'tanggal'       => $tanggal,
            'hari'          => $hari,
            'masuk_sekolah' => true,
            'jenis'         => 'Biasa',
            'keterangan'    => null,
            'jam_masuk'     => $jam_masuk,
            'jam_pulang'    => $jam_pulang,
        ];

        // Minggu selalu libur. Isian kalender pada hari Minggu tidak dibaca.
        if ($hari === 'Minggu') {
            $info['masuk_sekolah'] = false;
            $info['jenis']         = 'Minggu';
            $info['jam_pulang']    = null;
            return $info;
        }
        if (!$pakai_kalender) {
            return $info;
        }

        $stmt = $conn->prepare("SELECT jenis, jam_pulang, keterangan FROM kalender_sekolah WHERE tanggal = ?");
        $stmt->bind_param('s', $tanggal);
        $stmt->execute();
        $kalender = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($kalender) {
            $info['keterangan'] = $kalender['keterangan'];
            if ($kalender['jenis'] === 'Libur') {
                $info['masuk_sekolah'] = false;
                $info['jenis']         = 'Libur';
                $info['jam_pulang']    = null;
            } elseif ($kalender['jenis'] === 'Pulang Cepat' && $kalender['jam_pulang'] !== null) {
                $info['jenis']      = 'Pulang Cepat';
                $info['jam_pulang'] = date('H:i:s', strtotime($kalender['jam_pulang']));
            }
        }
        return $info;
    }
}
