<?php
// absen_siswa.php — mencatat absen masuk dan absen pulang siswa di kiosk.
//
// Aturannya ada di sini: kapan absen masuk diterima, empat penolakan absen
// pulang, dan isi WA untuk orang tua. Pemakainya api/sj_absen.php (absen lewat
// sidik jari).
//
// api/proses_absen_siswa.php (QR/NISN, dipakai kiosk dan aplikasi) masih
// memuat salinan aturan yang sama. Isi berkas ini dipindahkan dari sana tanpa
// mengubah pesan, urutan pemeriksaan, atau bentuk datanya. Sampai endpoint itu
// memanggil berkas ini, perubahan aturan harus dikerjakan di kedua tempat.
//
// Butuh includes/db.php dan includes/kalender_sekolah.php lebih dulu.

if (!function_exists('simpanFotoAbsenSiswa')) {
    // Menyimpan foto kiosk ke uploads/absensi/<tanggal>/ dan mengembalikan
    // jalurnya dari akar situs, atau null kalau tidak tersimpan. Nama berkasnya
    // dibuat di sini. Dari pemanggil hanya NISN yang ikut, dan hanya huruf,
    // angka, garis bawah, dan tanda hubungnya.
    //
    // Isi fotonya tidak diperiksa, sama dengan api/proses_absen_siswa.php.
    // Pemanggil yang menerima foto dari luar memeriksanya sendiri lebih dulu.
    function simpanFotoAbsenSiswa($base64, $awalan, $nisn, $tanggal) {
        if (!is_string($base64)) {
            return null;
        }
        if (strpos($base64, 'base64,') !== false) {
            $bagian = explode('base64,', $base64);
            $base64 = $bagian[1];
        }
        $base64 = str_replace(' ', '+', $base64);
        $data = base64_decode($base64);
        if ($data === false) {
            return null;
        }

        $folder = __DIR__ . '/../uploads/absensi/' . $tanggal;
        if (!is_dir($folder)) {
            @mkdir($folder, 0755, true);
        }

        $nisn_aman = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$nisn);
        $nama = $awalan . '_' . $nisn_aman . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
        if (file_put_contents($folder . '/' . $nama, $data) === false) {
            return null;
        }
        return 'uploads/absensi/' . $tanggal . '/' . $nama;
    }
}

if (!function_exists('pesanWaAbsenSiswa')) {
    // Isi WA untuk orang tua setelah absen tercatat. $status hanya untuk absen
    // masuk: 'Tepat Waktu' atau 'Terlambat'.
    function pesanWaAbsenSiswa(array $siswa, $mode, $waktu, $status = null) {
        $pesan  = "INFO ABSENSI SMK TERPADU AL HASAN\n\n";
        $pesan .= "Yth. Bpk/Ibu Wali dari:\n";
        $pesan .= "Nama: *" . $siswa['nama_siswa'] . "*\n";
        $pesan .= "Kelas: " . $siswa['kelas'] . "\n\n";
        if ($mode === 'masuk') {
            $pesan .= "Diberitahukan bahwa hari ini (" . date('d/m/Y') . ") Ananda telah melakukan absensi *MASUK* pada pukul *" . date('H:i', strtotime($waktu)) . " WIB*.\n";
            $pesan .= "Status Kehadiran: *" . $status . "*\n\n";
        } else {
            $pesan .= "Diberitahukan bahwa hari ini (" . date('d/m/Y') . ") Ananda telah melakukan absensi *PULANG* pada pukul *" . date('H:i', strtotime($waktu)) . " WIB*.\n\n";
        }
        $pesan .= "Terima kasih.";
        return $pesan;
    }
}

if (!function_exists('catatAbsenSiswa')) {
    // $siswa  baris tabel siswa: 'id', 'nisn', 'nama_siswa', 'kelas'.
    // $mode   'masuk' atau 'pulang' seperti dipilih di kiosk, atau 'otomatis'.
    //         Pada 'otomatis' server yang memilih, setelah baris hari ini
    //         terkunci: pulang kalau siswa sudah absen masuk, atau kalau jam
    //         pulang hari sekolah itu sudah lewat; selain itu masuk.
    //         Penolakannya sama dengan mode yang terpilih. Jadi siswa yang
    //         belum absen masuk dan baru menempel setelah jam pulang ditolak,
    //         tidak dicatat masuk.
    // $foto   foto kiosk sebagai base64 atau data URI, atau null.
    //
    // Mengembalikan:
    //   'tercatat'  bool
    //   'mode'      'masuk' atau 'pulang', mode yang dijalankan
    //   'kode'      'masuk', 'masuk_diperbarui', atau 'pulang' kalau tercatat;
    //               'sudah_masuk', 'belum_masuk', 'sudah_pulang',
    //               'izin_pulang', atau 'belum_jam_pulang' kalau ditolak
    //   'pesan'     kalimat untuk layar kiosk
    //   'data'      isi 'data' jawaban JSON, atau null kalau ditolak
    //   'wa'        isi WA untuk orang tua, atau null kalau ditolak. Nomor
    //               tujuan dan pengirimannya urusan pemanggil.
    //
    // Galat basis data dilempar ke pemanggil setelah transaksinya dibatalkan
    // dan foto yang telanjur tersimpan dihapus.
    function catatAbsenSiswa($conn, array $siswa, $mode, $foto = null) {
        if (!in_array($mode, ['masuk', 'pulang', 'otomatis'], true)) {
            throw new InvalidArgumentException('Mode absen tidak dikenali.');
        }
        $siswa_id = (int)$siswa['id'];
        $tanggal  = date('Y-m-d');
        $waktu    = date('H:i:s');
        // Jam masuk dan jam pulang hari ini (Jumat, Pulang Cepat, Libur) dari
        // satu sumber.
        $hari = infoHariSekolah($conn, $tanggal);
        $ditolak = function ($mode, $kode, $pesan) {
            return ['tercatat' => false, 'mode' => $mode, 'kode' => $kode, 'pesan' => $pesan, 'data' => null, 'wa' => null];
        };
        $foto_path = null;

        $conn->begin_transaction();
        try {
            // Baris absensi siswa ini untuk hari ini dikunci sampai selesai.
            $stmt = $conn->prepare("SELECT id, waktu_masuk, waktu_pulang, status_masuk, status_harian FROM absensi_siswa WHERE siswa_id = ? AND tanggal = ? FOR UPDATE");
            if (!$stmt) {
                throw new Exception('Prepare lock failed: ' . $conn->error);
            }
            $stmt->bind_param('is', $siswa_id, $tanggal);
            $stmt->execute();
            $baris = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($mode === 'otomatis') {
                // Sama dengan pilihan kiosk sendiri: mulai jam pulang hari
                // sekolah, kiosk berada di mode PULANG dan menolak siswa yang
                // belum absen masuk. Tanpa syarat kedua, siswa yang tidak masuk
                // seharian bisa tercatat Hadir dengan dua tempelan setelah jam
                // pulang. Hari tanpa sekolah tidak punya jam pulang.
                $sudah_masuk = $baris && !empty($baris['waktu_masuk']);
                $lewat_jam_pulang = $hari['masuk_sekolah'] && $waktu >= $hari['jam_pulang'];
                $mode = ($sudah_masuk || $lewat_jam_pulang) ? 'pulang' : 'masuk';
            }

            if ($mode === 'masuk') {
                if ($baris && !empty($baris['waktu_masuk'])) {
                    $conn->rollback();
                    // Setelah jam pulang, siswa yang lupa memilih PULANG diberi tahu caranya.
                    $pesan = 'Siswa sudah absen masuk hari ini pukul ' . date('H.i', strtotime($baris['waktu_masuk'])) . '.';
                    if ($hari['masuk_sekolah'] && $waktu >= $hari['jam_pulang']) {
                        $pesan .= ' Untuk absen pulang, pilih mode PULANG.';
                    }
                    return $ditolak('masuk', 'sudah_masuk', $pesan);
                }

                if ($foto) {
                    $foto_path = simpanFotoAbsenSiswa($foto, 'masuk', $siswa['nisn'], $tanggal);
                }
                $status = (strtotime($waktu) <= strtotime($hari['jam_masuk'])) ? 'Tepat Waktu' : 'Terlambat';
                if ($baris) {
                    // Baris tanpa jam masuk, misalnya Sakit/Izin/Alpa dari absen manual.
                    $stmt = $conn->prepare("UPDATE absensi_siswa SET waktu_masuk = ?, foto_masuk = ?, status_masuk = ? WHERE id = ?");
                    if (!$stmt) {
                        throw new Exception('Prepare update masuk failed: ' . $conn->error);
                    }
                    $stmt->bind_param('sssi', $waktu, $foto_path, $status, $baris['id']);
                    if (!$stmt->execute()) {
                        throw new Exception('Execute update masuk failed: ' . $stmt->error);
                    }
                } else {
                    $stmt = $conn->prepare("INSERT INTO absensi_siswa (siswa_id, tanggal, waktu_masuk, foto_masuk, status_masuk) VALUES (?, ?, ?, ?, ?)");
                    if (!$stmt) {
                        throw new Exception('Prepare insert masuk failed: ' . $conn->error);
                    }
                    $stmt->bind_param('issss', $siswa_id, $tanggal, $waktu, $foto_path, $status);
                    if (!$stmt->execute()) {
                        throw new Exception('Execute insert masuk failed: ' . $stmt->error);
                    }
                }
                $stmt->close();
                $conn->commit();

                return [
                    'tercatat' => true,
                    'mode'     => 'masuk',
                    'kode'     => $baris ? 'masuk_diperbarui' : 'masuk',
                    'pesan'    => $baris ? 'Absensi masuk berhasil diperbarui.' : 'Absensi masuk berhasil disimpan.',
                    'data'     => [
                        'nama_siswa'   => $siswa['nama_siswa'],
                        'kelas'        => $siswa['kelas'],
                        'waktu_masuk'  => $waktu,
                        'status_masuk' => $status,
                        'foto_masuk'   => $foto_path,
                    ],
                    'wa'       => pesanWaAbsenSiswa($siswa, 'masuk', $waktu, $status),
                ];
            }

            // Absen pulang wajib. Empat penolakan, semuanya sebelum foto disimpan:
            // - belum absen masuk;
            // - sudah absen pulang;
            // - sudah diberi izin pulang oleh guru piket (absen_manual.php);
            // - hari sekolah, sebelum jam pulang hari itu. Tanpa aturan ini siswa
            //   bisa absen masuk lalu langsung absen pulang di pagi hari.
            // Hari tanpa sekolah tidak dibatasi jam.
            if (!$baris || empty($baris['waktu_masuk'])) {
                $conn->rollback();
                return $ditolak('pulang', 'belum_masuk', 'Belum ada absen masuk hari ini, jadi absen pulang belum bisa dicatat.');
            }
            if (!empty($baris['waktu_pulang'])) {
                $conn->rollback();
                return $ditolak('pulang', 'sudah_pulang', 'Absensi pulang sudah tercatat sebelumnya.');
            }
            if ($baris['status_harian'] === 'Izin') {
                $conn->rollback();
                return $ditolak('pulang', 'izin_pulang', 'Izin pulang siswa ini sudah dicatat guru piket. Tidak perlu absen pulang.');
            }
            if ($hari['masuk_sekolah'] && $waktu < $hari['jam_pulang']) {
                $conn->rollback();
                return $ditolak('pulang', 'belum_jam_pulang', 'Absen pulang baru dibuka pukul ' . date('H.i', strtotime($hari['jam_pulang'])) . '. Jika harus pulang lebih awal, minta izin ke guru piket.');
            }

            if ($foto) {
                $foto_path = simpanFotoAbsenSiswa($foto, 'pulang', $siswa['nisn'], $tanggal);
            }
            // Absen pulang yang baru datang setelah cron sore berjalan (misalnya
            // siswa ekskul) langsung membetulkan golongannya, tanpa menunggu
            // Hitung ulang. Golongan lain, termasuk izin pulang, tidak disentuh.
            $stmt = $conn->prepare("UPDATE absensi_siswa SET waktu_pulang = ?, foto_pulang = ?, status_harian = IF(status_harian = 'Pulang Lebih Awal', 'Hadir', status_harian) WHERE id = ?");
            if (!$stmt) {
                throw new Exception('Prepare update pulang failed: ' . $conn->error);
            }
            $stmt->bind_param('ssi', $waktu, $foto_path, $baris['id']);
            if (!$stmt->execute()) {
                throw new Exception('Execute update pulang failed: ' . $stmt->error);
            }
            $stmt->close();
            $conn->commit();

            return [
                'tercatat' => true,
                'mode'     => 'pulang',
                'kode'     => 'pulang',
                'pesan'    => 'Absensi pulang berhasil dicatat.',
                'data'     => [
                    'nama_siswa'   => $siswa['nama_siswa'],
                    'kelas'        => $siswa['kelas'],
                    'waktu_pulang' => $waktu,
                    'foto_pulang'  => $foto_path,
                ],
                'wa'       => pesanWaAbsenSiswa($siswa, 'pulang', $waktu),
            ];
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $e2) {
                // Koneksinya sudah putus; transaksinya batal dengan sendirinya.
            }
            if ($foto_path !== null) {
                @unlink(__DIR__ . '/../' . $foto_path);
            }
            throw $e;
        }
    }
}
