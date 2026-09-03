<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Jakarta');

require_once('../includes/db.php');
require_once('../lib/tcpdf/tcpdf.php');

$kelas = $_POST['kelas'] ?? '';
$bulan = $_POST['bulan'] ?? '';
$tahun = $_POST['tahun'] ?? '';

if (empty($kelas) || empty($bulan) || empty($tahun)) {
    die('Parameter ekspor tidak lengkap!');
}

$nama_bulan = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $bulan, $tahun);

// 1. Ambil daftar siswa unik di kelas tersebut
$sql_siswa = "SELECT id, nama_siswa FROM siswa WHERE kelas = ? ORDER BY nama_siswa ASC";
$stmt_siswa = $conn->prepare($sql_siswa);
$stmt_siswa->bind_param("s", $kelas);
$stmt_siswa->execute();
$res_siswa = $stmt_siswa->get_result();

$siswa_list = [];
while ($row = $res_siswa->fetch_assoc()) {
    $siswa_list[$row['id']] = [
        'nama' => $row['nama_siswa'],
        'absen' => [],
        // --- DITAMBAHKAN TOTAL 'Tidak Absen' ---
        'total' => ['Tepat Waktu' => 0, 'Terlambat' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0, 'Tidak Absen' => 0]
    ];
}
$stmt_siswa->close();

// 2. Ambil data absensi dan kelompokkan
$sql_absen = "SELECT a.siswa_id, DAY(a.tanggal) as hari, a.status_masuk FROM absensi_siswa a JOIN siswa s ON a.siswa_id = s.id WHERE s.kelas = ? AND MONTH(a.tanggal) = ? AND YEAR(a.tanggal) = ?";
$stmt_absen = $conn->prepare($sql_absen);
$stmt_absen->bind_param("sii", $kelas, $bulan, $tahun);
$stmt_absen->execute();
$res_absen = $stmt_absen->get_result();

while ($row = $res_absen->fetch_assoc()) {
    $s_id = $row['siswa_id'];
    $hari = (int)$row['hari'];
    $status = ($row['status_masuk'] == 'Alpha') ? 'Alpa' : $row['status_masuk'];
    
    if(isset($siswa_list[$s_id])) {
        $siswa_list[$s_id]['absen'][$hari] = $status;
        if (isset($siswa_list[$s_id]['total'][$status])) {
            $siswa_list[$s_id]['total'][$status]++;
        }
    }
}
$stmt_absen->close();

// ==========================================
// KUSTOMISASI PDF TCPDF
// ==========================================
class PDF extends TCPDF {
    public $header_title = '';
    public $header_subtitle = '';
    
    public function Header() {
        $logo_path = dirname(__DIR__) . '/logo.png';
        if (file_exists($logo_path)) {
            $this->Image($logo_path, 25, 6, 18, 0, 'PNG');
        }
        $this->SetFont('helvetica', 'B', 14);
        $this->SetY(10);
        $this->Cell(0, 6, $this->header_title, 0, 1, 'C');
        $this->SetFont('helvetica', 'B', 11);
        $this->Cell(0, 5, $this->header_subtitle, 0, 1, 'C');
        $this->SetFont('helvetica', '', 10);
        $this->Cell(0, 5, 'SMK Terpadu Al Hasan Ciamis', 0, 1, 'C');
        $this->Line(15, 30, $this->getPageWidth() - 15, 30);
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Dicetak pada: ' . date('d/m/Y H:i') . ' | Halaman ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

$pdf = new PDF('L', 'mm', 'A4', true, 'UTF-8'); 
$pdf->header_title = 'REKAPITULASI KEHADIRAN SISWA SMK TERPADU AL HASAN CIAMIS';
$pdf->header_subtitle = 'KELAS ' . strtoupper($kelas) . ' - PERIODE ' . strtoupper($nama_bulan[$bulan]) . ' ' . $tahun;

$pdf->SetMargins(15, 35, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// ---------------- PERHITUNGAN LEBAR TABEL (AUTO CENTER) ----------------
$w_no = 8;
$w_nama = 45;
$w_tanggal = 5; 
$w_rekap = 7; // Diperkecil sedikit agar muat 6 kolom rekap
$total_width = $w_no + $w_nama + ($days_in_month * $w_tanggal) + (6 * $w_rekap); // 6 Kolom Rekap (H,T,I,S,A,TA)

$page_width = $pdf->getPageWidth();
$start_x = ($page_width - $total_width) / 2;

$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(74, 144, 226); // Biru Primary
$pdf->SetTextColor(255, 255, 255);

// ---------------- HEADER TABEL ----------------
$pdf->SetX($start_x);
$pdf->Cell($w_no, 10, 'No', 1, 0, 'C', true);
$pdf->Cell($w_nama, 10, 'Nama Siswa', 1, 0, 'C', true);

$pdf->SetFont('helvetica', 'B', 7);
for ($i = 1; $i <= $days_in_month; $i++) {
    $pdf->Cell($w_tanggal, 10, $i, 1, 0, 'C', true);
}

// Kolom Rekap Tambahan (TA = Tidak Absen)
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell($w_rekap, 10, 'H', 1, 0, 'C', true);
$pdf->Cell($w_rekap, 10, 'T', 1, 0, 'C', true);
$pdf->Cell($w_rekap, 10, 'I', 1, 0, 'C', true);
$pdf->Cell($w_rekap, 10, 'S', 1, 0, 'C', true);
$pdf->Cell($w_rekap, 10, 'A', 1, 0, 'C', true); 
$pdf->Cell($w_rekap, 10, 'TA', 1, 1, 'C', true); 

// ---------------- ISI TABEL ----------------
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 7);

$today_date = date('Y-m-d');
$no = 1;

if (!empty($siswa_list)) {
    foreach ($siswa_list as $id => $data) {
        $pdf->SetX($start_x);
        $pdf->SetFillColor(255, 255, 255); 
        $pdf->Cell($w_no, 6, $no++, 1, 0, 'C');
        
        $nama = strlen($data['nama']) > 25 ? substr($data['nama'], 0, 22) . '...' : $data['nama'];
        $pdf->Cell($w_nama, 6, ' ' . $nama, 1, 0, 'L');
        
        // Loop setiap tanggal dalam bulan ini
        for ($i = 1; $i <= $days_in_month; $i++) {
            $status = $data['absen'][$i] ?? '';
            $bg_color = [255, 255, 255]; 
            $kode = '';
            
            $currentDate = "$tahun-" . str_pad($bulan, 2, '0', STR_PAD_LEFT) . "-" . str_pad($i, 2, '0', STR_PAD_LEFT);
            $dayOfWeek = date('N', strtotime($currentDate));
            $is_past_or_today = $currentDate <= $today_date;
            
            // Tentukan Kode Huruf dan Warna Background
            if ($status == 'Tepat Waktu') {
                $bg_color = [40, 167, 69]; $kode = '•'; // Hijau
            } elseif ($status == 'Terlambat') {
                $bg_color = [255, 193, 7]; $kode = 'T'; // Kuning
            } elseif ($status == 'Sakit') {
                $bg_color = [23, 162, 184]; $kode = 'S'; // Biru Muda
            } elseif ($status == 'Izin') {
                $bg_color = [13, 202, 240]; $kode = 'I'; // Biru Pucat
            } elseif ($status == 'Alpa' || $status == 'Alpha') {
                $bg_color = [220, 53, 69]; $kode = 'A'; // Merah
            } elseif ($status == '' && $dayOfWeek != 7 && $is_past_or_today) {
                // --- LOGIKA TIDAK ABSEN (Bukan Hari Minggu & Hari Sudah/Sedang Berjalan) ---
                $bg_color = [108, 117, 125]; $kode = '-'; // Abu-abu gelap
                $data['total']['Tidak Absen']++;
            } elseif ($dayOfWeek == 7) {
                // Jika Hari Minggu Kosong
                $bg_color = [233, 236, 239]; // Abu-abu terang (Disabled)
            }
            
            // Set Text Color
            if ($kode != '' || $dayOfWeek == 7) {
                $pdf->SetFillColor($bg_color[0], $bg_color[1], $bg_color[2]);
                if ($kode == '-') {
                    $pdf->SetTextColor(255, 255, 255);
                } else if ($kode != '') {
                    $pdf->SetTextColor(255, 255, 255);
                } else {
                    $pdf->SetTextColor(0, 0, 0);
                }
            } else {
                $pdf->SetFillColor(255, 255, 255);
                $pdf->SetTextColor(0, 0, 0);
            }
            
            $pdf->Cell($w_tanggal, 6, $kode, 1, 0, 'C', true);
        }
        
        // Cetak Rekap per Siswa (Termasuk 'Tidak Absen')
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell($w_rekap, 6, $data['total']['Tepat Waktu'], 1, 0, 'C');
        $pdf->Cell($w_rekap, 6, $data['total']['Terlambat'], 1, 0, 'C');
        $pdf->Cell($w_rekap, 6, $data['total']['Izin'], 1, 0, 'C');
        $pdf->Cell($w_rekap, 6, $data['total']['Sakit'], 1, 0, 'C');
        $pdf->Cell($w_rekap, 6, $data['total']['Alpa'], 1, 0, 'C');
        $pdf->Cell($w_rekap, 6, $data['total']['Tidak Absen'], 1, 1, 'C'); // Kolom TA
    }
} else {
    $pdf->SetX($start_x);
    $pdf->Cell($total_width, 10, 'Tidak ada siswa ditemukan pada kelas ini.', 1, 1, 'C');
}

// ==========================================================
// BAGIAN FOOTER: LEGEND & TANDA TANGAN 
// ==========================================================
if ($pdf->GetY() > ($pdf->getPageHeight() - 40)) {
    $pdf->AddPage();
}

$y_start_footer = $pdf->GetY() + 5; 

$pdf->SetY($y_start_footer);
$pdf->SetX($start_x);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell(60, 5, 'Keterangan Warna & Kode:', 0, 1, 'L');

function printLegend($pdf, $r, $g, $b, $kode, $teks) {
    $pdf->SetFillColor($r, $g, $b);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(5, 5, $kode, 1, 0, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(25, 5, ' = ' . $teks, 0, 0, 'L');
}

// Legend Baris 1
$pdf->SetX($start_x);
$pdf->SetFont('helvetica', '', 8);
printLegend($pdf, 40, 167, 69, '•', 'Tepat Waktu (H)');
printLegend($pdf, 255, 193, 7, 'T', 'Terlambat');
printLegend($pdf, 23, 162, 184, 'S', 'Sakit');

// Legend Baris 2
$pdf->Ln(6); 
$pdf->SetX($start_x);
printLegend($pdf, 13, 202, 240, 'I', 'Izin');
printLegend($pdf, 220, 53, 69, 'A', 'Alpa');
printLegend($pdf, 108, 117, 125, '-', 'Tidak Absen (Bolos)');

// --- TANDA TANGAN WALI KELAS ---
$pdf->SetY($y_start_footer);
$ttd_x = $start_x + $total_width - 60; 

$pdf->SetX($ttd_x);
$pdf->Cell(60, 5, 'Ciamis, ' . date('d') . ' ' . $nama_bulan[date('n')] . ' ' . date('Y'), 0, 1, 'C');

$pdf->SetX($ttd_x);
$pdf->Cell(60, 5, 'Wali Kelas,', 0, 1, 'C');

$pdf->Ln(15);
$pdf->SetX($ttd_x);
$pdf->Cell(60, 5, '(_________________________)', 0, 1, 'C');

$filename = 'Rekap_Kehadiran_Kelas_' . $kelas . '_' . $nama_bulan[$bulan] . '_' . $tahun . '.pdf';
$pdf->Output($filename, 'I');
?>