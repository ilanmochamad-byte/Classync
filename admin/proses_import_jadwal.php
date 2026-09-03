<?php
// admin/proses_import_jadwal.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../includes/db.php';

// --- TAMBAHKAN FUNGSI PEMBANTU KONVERSI DI SINI ---
function konversiWaktuExcel($nilai) {
    // Jika nilai berupa angka desimal bawaan Excel (misal 0.3125)
    if (is_numeric($nilai) && $nilai < 1) {
        $total_detik = round($nilai * 86400);
        $jam = floor($total_detik / 3600);
        $menit = floor(($total_detik % 3600) / 60);
        return sprintf('%02d:%02d:00', $jam, $menit);
    }
    // Jika nilai sudah berupa string teks jam biasa (misal "07:30")
    return $nilai;
}
// ------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["file_excel"])) {
    $file = $_FILES["file_excel"];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        header("Location: jadwal_mengajar.php?import_status=gagal&pesan=" . urlencode("Gagal mengunggah file. Kode error: " . $file['error']));
        exit;
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $tmp_name = $file["tmp_name"];
    
    $sukses = 0;
    $gagal = 0;

    // FUNGSI MEMBACA FILE CSV
    if (strtolower($ext) == 'csv') {
        if (($handle = fopen($tmp_name, "r")) !== FALSE) {
            $header = fgetcsv($handle, 1000, ",");
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if(empty($data[0]) || empty($data[1])) continue; 

                $guru_id = (int)$data[0];
                $hari = trim($data[1]);
                $jam_mulai = konversiWaktuExcel(trim($data[2])); // Gunakan fungsi konversi
                $jam_selesai = konversiWaktuExcel(trim($data[3])); // Gunakan fungsi konversi
                $mata_pelajaran = trim($data[4]);
                $kelas = trim($data[5]);

                $stmt = $conn->prepare("INSERT INTO jadwal_mengajar (guru_id, hari, jam_mulai, jam_selesai, mata_pelajaran, kelas) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssss", $guru_id, $hari, $jam_mulai, $jam_selesai, $mata_pelajaran, $kelas);
                
                if ($stmt->execute()) { $sukses++; } else { $gagal++; }
            }
            fclose($handle);
        }
    } 
    // FUNGSI MEMBACA XLSX
    else if (strtolower($ext) == 'xlsx') {
        $zip = new ZipArchive;
        if ($zip->open($tmp_name) === TRUE) {
            $sharedStrings = [];
            if (($sharedStringsData = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $xml = simplexml_load_string($sharedStringsData);
                foreach ($xml->si as $val) {
                    if (isset($val->t)) { $sharedStrings[] = (string)$val->t; }
                    elseif (isset($val->r)) {
                        $str = '';
                        foreach ($val->r as $r) { $str .= (string)$r->t; }
                        $sharedStrings[] = $str;
                    }
                }
            }

            if (($sheetData = $zip->getFromName('xl/worksheets/sheet1.xml')) !== false) {
                $xml = simplexml_load_string($sheetData);
                $isHeader = true;

                foreach ($xml->sheetData->row as $row) {
                    if ($isHeader) { $isHeader = false; continue; }
                    
                    $colData = [];
                    foreach ($row->c as $c) {
                        $val = (string)$c->v;
                        if (isset($c['t']) && (string)$c['t'] == 's') {
                            $val = $sharedStrings[(int)$val] ?? '';
                        }
                        $colData[] = $val;
                    }

                    if (empty($colData) || count($colData) < 6) continue;

                    $guru_id = (int)$colData[0];
                    $hari = trim($colData[1]);
                    $jam_mulai = konversiWaktuExcel(trim($colData[2])); // Gunakan fungsi konversi
                    $jam_selesai = konversiWaktuExcel(trim($colData[3])); // Gunakan fungsi konversi
                    $mata_pelajaran = trim($colData[4]);
                    $kelas = trim($colData[5]);

                    if ($guru_id > 0 && !empty($hari) && !empty($kelas)) {
                        $stmt = $conn->prepare("INSERT INTO jadwal_mengajar (guru_id, hari, jam_mulai, jam_selesai, mata_pelajaran, kelas) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("isssss", $guru_id, $hari, $jam_mulai, $jam_selesai, $mata_pelajaran, $kelas);
                        if ($stmt->execute()) { $sukses++; } else { $gagal++; }
                    }
                }
            }
            $zip->close();
        } else {
            header("Location: jadwal_mengajar.php?import_status=gagal&pesan=" . urlencode("File Excel tidak dapat dibaca atau rusak."));
            exit;
        }
    } else {
        header("Location: jadwal_mengajar.php?import_status=gagal&pesan=" . urlencode("Hanya mendukung format file .csv dan .xlsx"));
        exit;
    }

    $pesan = "Import Selesai: $sukses Berhasil, $gagal Gagal/Dilewati.";
    $status = ($sukses > 0) ? 'sukses' : 'gagal';
    
    header("Location: jadwal_mengajar.php?import_status=$status&pesan=" . urlencode($pesan));
    exit;
} else {
    header("Location: jadwal_mengajar.php");
    exit;
}
?>