<?php
// Aktifkan error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Debug Absensi Manual API</h2>";
echo "<hr>";

// Test 1: Database Connection
echo "<h3>1. Test Database Connection</h3>";
try {
    require 'includes/db.php';
    if (isset($conn) && $conn) {
        echo "✅ Database connected successfully<br>";
        echo "Connection type: " . get_class($conn) . "<br>";
    } else {
        echo "❌ Database connection failed<br>";
    }
} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "<br>";
}
echo "<hr>";

// Test 2: WA Sender File
echo "<h3>2. Test WA Sender File</h3>";
try {
    require 'includes/wa_sender.php';
    echo "✅ wa_sender.php loaded successfully<br>";
    
    // Test function exists
    if (function_exists('formatNomorWA')) {
        echo "✅ Function formatNomorWA exists<br>";
        echo "Test: formatNomorWA('081234567890') = " . formatNomorWA('081234567890') . "<br>";
    } else {
        echo "❌ Function formatNomorWA not found<br>";
    }
    
    if (function_exists('kirimNotifikasiWA')) {
        echo "✅ Function kirimNotifikasiWA exists<br>";
    } else {
        echo "❌ Function kirimNotifikasiWA not found<br>";
    }
} catch (Exception $e) {
    echo "❌ WA Sender error: " . $e->getMessage() . "<br>";
}
echo "<hr>";

// Test 3: Simulate API Request
echo "<h3>3. Test API Simulation</h3>";

// Ambil siswa pertama yang belum absen
$tanggal_hari_ini = date('Y-m-d');
$query = "SELECT s.id, s.nama_siswa, s.kelas 
          FROM siswa s 
          LEFT JOIN absensi_siswa a ON s.id = a.siswa_id AND a.tanggal = '$tanggal_hari_ini'
          WHERE a.id IS NULL 
          LIMIT 1";

$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    $siswa = $result->fetch_assoc();
    echo "✅ Found test student: " . $siswa['nama_siswa'] . "<br>";
    echo "Student ID: " . $siswa['id'] . "<br>";
    echo "Kelas: " . $siswa['kelas'] . "<br>";
} else {
    echo "⚠️ No students available for testing (all already present)<br>";
}
echo "<hr>";

// Test 4: Test API File Direct
echo "<h3>4. Test API File Accessibility</h3>";
$api_file = __DIR__ . '/api/proses_absen_manual.php';
echo "API File Path: " . $api_file . "<br>";
if (file_exists($api_file)) {
    echo "✅ API file exists<br>";
    echo "File size: " . filesize($api_file) . " bytes<br>";
    echo "Readable: " . (is_readable($api_file) ? "YES" : "NO") . "<br>";
} else {
    echo "❌ API file NOT found<br>";
}
echo "<hr>";

// Test 5: Try to execute API logic manually
echo "<h3>5. Test API Logic Manually</h3>";
try {
    // Simulate input
    $test_siswa_id = isset($siswa) ? $siswa['id'] : 1;
    $test_status = 'Izin';
    
    echo "Testing with:<br>";
    echo "- Siswa ID: " . $test_siswa_id . "<br>";
    echo "- Status: " . $test_status . "<br>";
    
    // Check if student exists
    $stmt_siswa = $conn->prepare("SELECT nama_siswa, kelas, kontak_ortu FROM siswa WHERE id = ?");
    if (!$stmt_siswa) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt_siswa->bind_param("i", $test_siswa_id);
    $stmt_siswa->execute();
    $result_siswa = $stmt_siswa->get_result();
    
    if ($result_siswa->num_rows > 0) {
        $siswa_data = $result_siswa->fetch_assoc();
        echo "✅ Student found: " . $siswa_data['nama_siswa'] . "<br>";
        echo "Kontak Ortu: " . ($siswa_data['kontak_ortu'] ?: 'Tidak ada') . "<br>";
    } else {
        echo "❌ Student not found<br>";
    }
    
    $stmt_siswa->close();
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}
echo "<hr>";

echo "<h3>✅ Debug Complete</h3>";
echo "<p>Jika semua test di atas berhasil, masalah mungkin ada di API file itu sendiri.</p>";
?>