<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing TCPDF...<br>";

// Test path
$tcpdf_path = __DIR__ . '/lib/tcpdf/tcpdf.php';
echo "TCPDF Path: " . $tcpdf_path . "<br>";
echo "File exists: " . (file_exists($tcpdf_path) ? 'YES' : 'NO') . "<br><br>";

if (file_exists($tcpdf_path)) {
    require_once($tcpdf_path);
    
    $pdf = new TCPDF();
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 10, 'Test TCPDF Berhasil! - smkt.alhasan.co.id', 0, 1);
    $pdf->Output('test.pdf', 'I');
} else {
    echo "ERROR: TCPDF tidak ditemukan!";
}
?>