<?php
// Error reporting hanya dicatat ke log, tidak ditampilkan ke output
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../includes/db.php');

// Path untuk TCPDF
require_once('../lib/tcpdf/tcpdf.php');

// Set cache folder
if (!defined('K_PATH_CACHE')) {
    $cache_dir = dirname(__DIR__) . '/cache/';
    if (!is_dir($cache_dir)) {
        @mkdir($cache_dir, 0755, true);
    }
    define('K_PATH_CACHE', $cache_dir);
}

// Ambil parameter dari POST
$kelas = isset($_POST['kelas']) ? $_POST['kelas'] : '';
$bulan = isset($_POST['bulan']) ? $_POST['bulan'] : '';
$tahun = isset($_POST['tahun']) ? $_POST['tahun'] : '';

if (empty($kelas) || empty($bulan) || empty($tahun)) {
    die('Parameter tidak lengkap! Kelas: ' . $kelas . ', Bulan: ' . $bulan . ', Tahun: ' . $tahun);
}

// Nama bulan Indonesia
$nama_bulan = array(
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
);

// Hitung jumlah hari dalam bulan tersebut
$jumlah_hari = cal_days_in_month(CAL_GREGORIAN, $bulan, $tahun);

// Ambil data siswa berdasarkan kelas
$stmt_siswa = $conn->prepare("SELECT id, nisn, nama_siswa, jenis_kelamin FROM siswa WHERE kelas = ? ORDER BY nama_siswa ASC");
$stmt_siswa->bind_param("s", $kelas);
$stmt_siswa->execute();
$result_siswa = $stmt_siswa->get_result();
$daftar_siswa = $result_siswa->fetch_all(MYSQLI_ASSOC);
$stmt_siswa->close();

if (empty($daftar_siswa)) {
    die('Tidak ada siswa di kelas: ' . htmlspecialchars($kelas));
}

// Fungsi untuk mengkonversi jenis kelamin ke singkatan
function formatJenisKelamin($jk) {
    $jk_lower = strtolower(trim($jk));
    if (strpos($jk_lower, 'perempuan') !== false || $jk_lower === 'p') {
        return 'P';
    } elseif (strpos($jk_lower, 'laki') !== false || $jk_lower === 'l') {
        return 'L';
    }
    return strtoupper(substr($jk, 0, 1));
}

// Ambil data absensi untuk bulan dan tahun tersebut dalam SATU query bulk
// (mengganti loop N×31 query menjadi 1 query)
$tanggal_mulai = sprintf('%04d-%02d-01', $tahun, $bulan);
$tanggal_selesai = date('Y-m-t', strtotime($tanggal_mulai));

$absensi_map = [];
if (!empty($daftar_siswa)) {
    $siswa_ids = array_column($daftar_siswa, 'id');
    $id_placeholders = implode(',', array_fill(0, count($siswa_ids), '?'));
    $id_types = str_repeat('i', count($siswa_ids));

    $stmt_bulk = $conn->prepare("
        SELECT siswa_id, DAY(tanggal) AS hari, waktu_masuk, status_masuk
        FROM absensi_siswa
        WHERE siswa_id IN ($id_placeholders)
          AND tanggal >= ? AND tanggal <= ?
    ");
    $bulk_params = array_merge($siswa_ids, [$tanggal_mulai, $tanggal_selesai]);
    $bulk_types = $id_types . 'ss';
    $stmt_bulk->bind_param($bulk_types, ...$bulk_params);
    $stmt_bulk->execute();
    $result_bulk = $stmt_bulk->get_result();
    while ($row_bulk = $result_bulk->fetch_assoc()) {
        $absensi_map[(int)$row_bulk['siswa_id']][(int)$row_bulk['hari']] = $row_bulk;
    }
    $stmt_bulk->close();
}

$data_absensi = array();
foreach ($daftar_siswa as $siswa) {
    $siswa_id = (int)$siswa['id'];
    $kehadiran = array();
    
    for ($hari = 1; $hari <= $jumlah_hari; $hari++) {
        $absen_data = $absensi_map[$siswa_id][$hari] ?? null;
        
        if ($absen_data) {
            $status = strtolower($absen_data['status_masuk']);
            
            if (!empty($absen_data['waktu_masuk'])) {
                $kehadiran[$hari] = 'H';
            } 
            elseif (strpos($status, 'izin') !== false) {
                $kehadiran[$hari] = 'I';
            } 
            elseif (strpos($status, 'alpa') !== false || strpos($status, 'alpha') !== false) {
                $kehadiran[$hari] = 'A';
            }
            elseif (strpos($status, 'sakit') !== false) {
                $kehadiran[$hari] = 'S';
            }
            else {
                $kehadiran[$hari] = '-';
            }
        } else {
            $kehadiran[$hari] = '-';
        }
    }
    
    // Hitung persentase
    $hadir = 0;
    $izin = 0;
    $alpa = 0;
    $sakit = 0;
    
    foreach ($kehadiran as $status) {
        if ($status === 'H') $hadir++;
        elseif ($status === 'I') $izin++;
        elseif ($status === 'A') $alpa++;
        elseif ($status === 'S') $sakit++;
    }
    
    $tidak_hadir = $izin + $alpa + $sakit; // PERBAIKAN: Sakit juga dihitung sebagai tidak hadir
    $total_hari_valid = $hadir + $tidak_hadir;
    
    $persen_hadir = $total_hari_valid > 0 ? round(($hadir / $total_hari_valid) * 100, 1) : 0;
    
    $data_absensi[] = array(
        'siswa' => $siswa,
        'kehadiran' => $kehadiran,
        'persen_hadir' => $persen_hadir,
        'total_hadir' => $hadir,
        'total_izin' => $izin,
        'total_alpa' => $alpa,
        'total_sakit' => $sakit
    );
}

// ============================================
// GENERATE PDF MENGGUNAKAN TCPDF
// ============================================

class PDF extends TCPDF {
    public $header_title = '';
    public $header_subtitle = '';
    
    public function Header() {
        // Logo - Ukuran proporsional
        $logo_path = dirname(__DIR__) . '/logo.png';
        if (file_exists($logo_path)) {
            $this->Image($logo_path, 15, 8, 20, 0, 'PNG', '', '', false, 300, '', false, false, 0);
        }
        
        // Title
        $this->SetFont('helvetica', 'B', 16);
        $this->SetY(10);
        $this->Cell(0, 6, $this->header_title, 0, 1, 'C');
        
        $this->SetFont('helvetica', 'B', 12);
        $this->Cell(0, 5, $this->header_subtitle, 0, 1, 'C');
        
        $this->SetFont('helvetica', '', 10);
        $this->Cell(0, 5, 'SMK Terpadu Al Hasan Ciamis', 0, 1, 'C');
        
        $this->Line(10, 35, $this->getPageWidth() - 10, 35);
        $this->Ln(3);
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Halaman ' . $this->getAliasNumPage() . ' dari ' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

$pdf = new PDF('L', 'mm', 'A4', true, 'UTF-8'); // Landscape
$pdf->header_title = 'REKAPITULASI ABSENSI SISWA KELAS ' . strtoupper($kelas);
$pdf->header_subtitle = 'BULAN ' . strtoupper($nama_bulan[$bulan]) . ' ' . $tahun;

$pdf->SetCreator('SMK Terpadu Al Hasan');
$pdf->SetAuthor('SMK Terpadu Al Hasan');
$pdf->SetTitle('Rekap Absensi ' . $kelas . ' - ' . $nama_bulan[$bulan] . ' ' . $tahun);

$pdf->SetMargins(10, 40, 10);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// ============================================
// GUNAKAN METODE CELL() NATIVE - LEBIH PRESISI
// ============================================

// Definisikan lebar kolom tetap (dalam mm)
$w_no = 7;          // No
$w_nisn = 18;       // NISN
$w_nama = 35;       // Nama Siswa
$w_kelas = 15;      // Kelas
$w_jk = 7;          // L/P

// PERBAIKAN: Tambahkan kolom S (Sakit)
// Hitung lebar untuk kolom tanggal (30 adalah total untuk H, I, A, S, %)
$sisa_width = 277 - ($w_no + $w_nisn + $w_nama + $w_kelas + $w_jk + 30); // 30 = 6+6+6+6+12 untuk H,I,A,S,%
$w_tanggal = $sisa_width / $jumlah_hari;

$w_total = 6;       // Kolom H, I, A, S (masing-masing 6mm)
$w_persen = 12;     // Kolom %

$line_height = 5;   // Tinggi baris

// Set font untuk header
$pdf->SetFont('helvetica', 'B', 7);
$pdf->SetFillColor(74, 144, 226); // Biru
$pdf->SetTextColor(255, 255, 255); // Putih

// Header Row
$pdf->Cell($w_no, $line_height, 'No', 1, 0, 'C', true);
$pdf->Cell($w_nisn, $line_height, 'NISN', 1, 0, 'C', true);
$pdf->Cell($w_nama, $line_height, 'Nama Siswa', 1, 0, 'C', true);
$pdf->Cell($w_kelas, $line_height, 'Kelas', 1, 0, 'C', true);
$pdf->Cell($w_jk, $line_height, 'L/P', 1, 0, 'C', true);

// Header tanggal 1-31
for ($i = 1; $i <= $jumlah_hari; $i++) {
    $pdf->Cell($w_tanggal, $line_height, $i, 1, 0, 'C', true);
}

// Header total - PERBAIKAN: Tambahkan kolom S
$pdf->Cell($w_total, $line_height, 'H', 1, 0, 'C', true);
$pdf->Cell($w_total, $line_height, 'I', 1, 0, 'C', true);
$pdf->Cell($w_total, $line_height, 'S', 1, 0, 'C', true); // KOLOM SAKIT DITAMBAHKAN
$pdf->Cell($w_total, $line_height, 'A', 1, 0, 'C', true);
$pdf->Cell($w_persen, $line_height, '%', 1, 1, 'C', true);

// Reset color untuk data
$pdf->SetTextColor(0, 0, 0); // Hitam
$pdf->SetFont('helvetica', '', 6);

// Data siswa
$no = 1;
foreach ($data_absensi as $data) {
    $siswa = $data['siswa'];
    $kehadiran = $data['kehadiran'];
    $jk_singkat = formatJenisKelamin($siswa['jenis_kelamin']);
    
    // Truncate nama jika terlalu panjang
    $nama_siswa = $siswa['nama_siswa'];
    if (strlen($nama_siswa) > 30) {
        $nama_siswa = substr($nama_siswa, 0, 27) . '...';
    }
    
    // Row data
    $pdf->Cell($w_no, $line_height, $no++, 1, 0, 'C');
    $pdf->Cell($w_nisn, $line_height, $siswa['nisn'], 1, 0, 'C');
    $pdf->Cell($w_nama, $line_height, $nama_siswa, 1, 0, 'L'); // Left align untuk nama
    $pdf->Cell($w_kelas, $line_height, $kelas, 1, 0, 'C');
    $pdf->Cell($w_jk, $line_height, $jk_singkat, 1, 0, 'C');
    
    // Data kehadiran per tanggal
    for ($i = 1; $i <= $jumlah_hari; $i++) {
        $status = isset($kehadiran[$i]) ? $kehadiran[$i] : '-';
        
        // Set background color berdasarkan status
        if ($status == 'H') {
            $pdf->SetFillColor(144, 238, 144); // Hijau muda
        } elseif ($status == 'I') {
            $pdf->SetFillColor(255, 215, 0); // Kuning
        } elseif ($status == 'A') {
            $pdf->SetFillColor(255, 182, 193); // Merah muda
        } elseif ($status == 'S') {
            $pdf->SetFillColor(135, 206, 235); // Biru muda
        } else {
            $pdf->SetFillColor(255, 255, 255); // Putih
        }
        
        $pdf->Cell($w_tanggal, $line_height, $status, 1, 0, 'C', true);
    }
    
    // Kolom total - PERBAIKAN: Tambahkan total Sakit
    $pdf->SetFont('helvetica', 'B', 6);
    
    // Total Hadir (H) - Hijau
    $pdf->SetFillColor(144, 238, 144);
    $pdf->Cell($w_total, $line_height, $data['total_hadir'], 1, 0, 'C', true);
    
    // Total Izin (I) - Kuning
    $pdf->SetFillColor(255, 215, 0);
    $pdf->Cell($w_total, $line_height, $data['total_izin'], 1, 0, 'C', true);
    
    // Total Sakit (S) - Biru muda
    $pdf->SetFillColor(135, 206, 235);
    $pdf->Cell($w_total, $line_height, $data['total_sakit'], 1, 0, 'C', true);
    
    // Total Alpa (A) - Merah muda
    $pdf->SetFillColor(255, 182, 193);
    $pdf->Cell($w_total, $line_height, $data['total_alpa'], 1, 0, 'C', true);
    
    // Persentase Hadir - Putih
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Cell($w_persen, $line_height, $data['persen_hadir'] . '%', 1, 1, 'C', false);
    
    $pdf->SetFont('helvetica', '', 6);
}

// Keterangan
$pdf->Ln(3);
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(0, 4, 'Keterangan: H = Hadir | I = Izin | S = Sakit | A = Alpa | - = Tidak ada data | L/P = Laki-laki/Perempuan', 0, 1, 'L');

// Tanda tangan
$pdf->Ln(5);
$pdf->SetFont('helvetica', '', 10);

$bulan_sekarang = date('n');
$tanggal_ekspor = date('d') . ' ' . $nama_bulan[$bulan_sekarang] . ' ' . date('Y');
$pdf->Cell(0, 5, 'Ciamis, ' . $tanggal_ekspor, 0, 1, 'R');
$pdf->Ln(2);
$pdf->Cell(0, 5, 'Wali Kelas,', 0, 1, 'R');
$pdf->Ln(15);
$pdf->Cell(0, 5, '_________________________', 0, 1, 'R');

// Output PDF
$filename = 'Rekap_Absensi_' . str_replace(' ', '_', $kelas) . '_' . $nama_bulan[$bulan] . '_' . $tahun . '.pdf';
$pdf->Output($filename, 'I');
?>