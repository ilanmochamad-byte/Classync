<?php
// admin/proses_import_siswa.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../includes/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["file_excel"])) {
    $file = $_FILES["file_excel"];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        header("Location: siswa.php?import_status=gagal&pesan=" . urlencode("Gagal mengunggah file. Kode error: " . $file['error']));
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

                $nisn = trim($data[0]);
                $nama_siswa = trim($data[1]);
                $jenis_kelamin = trim($data[2]);
                $kelas = trim($data[3]);
                $kontak_ortu = trim($data[4]);
                $password = trim($data[5]);
                
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                // Gunakan INSERT IGNORE agar jika NISN duplikat, baris tersebut dilewati
                $stmt = $conn->prepare("INSERT IGNORE INTO siswa (nisn, nama_siswa, jenis_kelamin, kelas, kontak_ortu, password) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssss", $nisn, $nama_siswa, $jenis_kelamin, $kelas, $kontak_ortu, $hashed_password);
                
                $stmt->execute();
                if ($stmt->affected_rows > 0) { $sukses++; } else { $gagal++; }
            }
            fclose($handle);
        }
    } 
    // FUNGSI MEMBACA XLSX (NATIVE ZIPARCHIVE)
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

                    $nisn = trim($colData[0]);
                    $nama_siswa = trim($colData[1]);
                    $jenis_kelamin = trim($colData[2]);
                    $kelas = trim($colData[3]);
                    $kontak_ortu = trim($colData[4] ?? '');
                    $password = trim($colData[5]);
                    
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    if (!empty($nisn) && !empty($nama_siswa)) {
                        $stmt = $conn->prepare("INSERT IGNORE INTO siswa (nisn, nama_siswa, jenis_kelamin, kelas, kontak_ortu, password) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("ssssss", $nisn, $nama_siswa, $jenis_kelamin, $kelas, $kontak_ortu, $hashed_password);
                        $stmt->execute();
                        if ($stmt->affected_rows > 0) { $sukses++; } else { $gagal++; }
                    }
                }
            }
            $zip->close();
        } else {
            header("Location: siswa.php?import_status=gagal&pesan=" . urlencode("File Excel tidak dapat dibaca atau rusak."));
            exit;
        }
    } else {
        header("Location: siswa.php?import_status=gagal&pesan=" . urlencode("Hanya mendukung format file .csv dan .xlsx"));
        exit;
    }

    $pesan = "Import Selesai: $sukses Data Siswa Baru Berhasil, $gagal Gagal (NISN Duplikat / Kolom Kosong).";
    $status = ($sukses > 0) ? 'sukses' : 'gagal';
    
    header("Location: siswa.php?import_status=$status&pesan=" . urlencode($pesan));
    exit;
} else {
    header("Location: siswa.php");
    exit;
}
?>