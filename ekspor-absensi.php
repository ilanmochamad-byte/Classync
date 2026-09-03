<?php
require 'includes/db.php';

// Ambil daftar kelas unik dari database
$kelas_query = $conn->query("SELECT DISTINCT kelas FROM siswa ORDER BY kelas ASC");
$daftar_kelas = [];
while ($row = $kelas_query->fetch_assoc()) {
    $daftar_kelas[] = $row['kelas'];
}

// Generate tahun (5 tahun ke belakang sampai tahun sekarang)
$tahun_sekarang = date('Y');
$daftar_tahun = range($tahun_sekarang - 4, $tahun_sekarang);

// Daftar bulan
$daftar_bulan = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// Bulan dan tahun saat ini untuk default
$bulan_sekarang = date('n');
$tahun_sekarang = date('Y');
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ekspor Absensi - SMK Terpadu Al Hasan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Poppins', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        
        .ekspor-container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .card-header-custom {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 15px 15px 0 0 !important;
            border: none;
        }
        
        .card-custom {
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            border: none;
            overflow: hidden;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .header-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            animation: bounce 2s infinite;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-select, .form-control {
            border-radius: 10px;
            border: 2px solid #e0e0e0;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }
        
        .form-select:focus, .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .btn-generate {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 1rem 3rem;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 50px;
            color: white;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-generate:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.6);
        }
        
        .btn-generate:active {
            transform: translateY(-1px);
        }
        
        .alert-custom {
            border-radius: 10px;
            border: none;
            background: linear-gradient(135deg, #f6f8fb 0%, #e9ecef 100%);
            border-left: 4px solid #667eea;
        }
        
        .info-card {
            background: linear-gradient(135deg, #f6f8fb 0%, #e9ecef 100%);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #667eea;
        }
        
        .info-card h6 {
            color: #667eea;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .info-card ul {
            margin-bottom: 0;
        }
        
        .info-card ul li {
            padding: 0.25rem 0;
        }
        
        .keterangan-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .badge-h { background-color: #90EE90; color: #2d5016; }
        .badge-i { background-color: #FFD700; color: #7a5f00; }
        .badge-s { background-color: #87CEEB; color: #1a4d6b; }
        .badge-a { background-color: #FFB6C1; color: #8b2e3e; }
        
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        
        .loading-content {
            text-align: center;
            color: white;
        }
        
        .spinner {
            border: 5px solid rgba(255, 255, 255, 0.3);
            border-top: 5px solid white;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .back-button {
            position: fixed;
            top: 20px;
            left: 20px;
            background: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            font-weight: 600;
            color: #667eea;
            transition: all 0.3s ease;
            z-index: 100;
        }
        
        .back-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }
        
        .form-group-icon {
            position: relative;
        }
        
        .form-icon {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
            font-size: 1.2rem;
            pointer-events: none;
        }
        
        @media (max-width: 768px) {
            .ekspor-container {
                padding: 0 1rem;
            }
            
            .btn-generate {
                padding: 0.875rem 2rem;
                font-size: 1rem;
                width: 100%;
            }
            
            .back-button {
                position: static;
                margin-bottom: 1rem;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="spinner"></div>
            <h4>Membuat PDF...</h4>
            <p>Mohon tunggu sebentar</p>
        </div>
    </div>

    <!-- Back Button -->
    <a href="absen-siswa.php" class="back-button">
        <i class="bi bi-arrow-left me-2"></i>Kembali
    </a>

    <div class="ekspor-container">
        <!-- Header -->
        <div class="text-center mb-4">
            <div class="header-icon text-white">
                <i class="bi bi-file-earmark-pdf-fill"></i>
            </div>
            <h1 class="text-white fw-bold mb-2">Ekspor Rekapitulasi Absensi</h1>
            <p class="text-white opacity-75">SMK Terpadu Al Hasan Ciamis</p>
        </div>

        <!-- Main Card -->
        <div class="card card-custom">
            <div class="card-header-custom">
                <h4 class="mb-0">
                    <i class="bi bi-sliders me-2"></i>Pengaturan Ekspor
                </h4>
            </div>
            <div class="card-body p-4">
                <form id="form-ekspor" method="POST" action="api/generate_pdf_absensi.php" target="_blank">
                    <div class="row g-4">
                        <!-- Pilih Kelas -->
                        <div class="col-md-4">
                            <label for="kelas" class="form-label">
                                <i class="bi bi-building"></i> Pilih Kelas
                            </label>
                            <div class="form-group-icon">
                                <select name="kelas" id="kelas" class="form-select" required>
                                    <option value="">-- Pilih Kelas --</option>
                                    <?php foreach ($daftar_kelas as $kelas): ?>
                                        <option value="<?php echo htmlspecialchars($kelas); ?>">
                                            <?php echo htmlspecialchars($kelas); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="bi bi-chevron-down form-icon"></i>
                            </div>
                        </div>

                        <!-- Pilih Bulan -->
                        <div class="col-md-4">
                            <label for="bulan" class="form-label">
                                <i class="bi bi-calendar-month"></i> Pilih Bulan
                            </label>
                            <div class="form-group-icon">
                                <select name="bulan" id="bulan" class="form-select" required>
                                    <option value="">-- Pilih Bulan --</option>
                                    <?php foreach ($daftar_bulan as $num => $nama): ?>
                                        <option value="<?php echo $num; ?>" <?php echo ($bulan_sekarang == $num) ? 'selected' : ''; ?>>
                                            <?php echo $nama; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="bi bi-chevron-down form-icon"></i>
                            </div>
                        </div>

                        <!-- Pilih Tahun -->
                        <div class="col-md-4">
                            <label for="tahun" class="form-label">
                                <i class="bi bi-calendar-event"></i> Pilih Tahun
                            </label>
                            <div class="form-group-icon">
                                <select name="tahun" id="tahun" class="form-select" required>
                                    <option value="">-- Pilih Tahun --</option>
                                    <?php foreach ($daftar_tahun as $thn): ?>
                                        <option value="<?php echo $thn; ?>" <?php echo ($thn == $tahun_sekarang) ? 'selected' : ''; ?>>
                                            <?php echo $thn; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="bi bi-chevron-down form-icon"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Info Card -->
                    <div class="info-card mt-4">
                        <h6>
                            <i class="bi bi-info-circle-fill me-2"></i>Keterangan Status Absensi
                        </h6>
                        <div class="row">
                            <div class="col-md-6">
                                <ul class="list-unstyled mb-0">
                                    <li>
                                        <span class="keterangan-badge badge-h">H</span>
                                        <strong>Hadir</strong> - Siswa hadir tepat waktu / terlambat
                                    </li>
                                    <li>
                                        <span class="keterangan-badge badge-i">I</span>
                                        <strong>Izin</strong> - Siswa izin dengan keterangan
                                    </li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <ul class="list-unstyled mb-0">
                                    <li>
                                        <span class="keterangan-badge badge-s">S</span>
                                        <strong>Sakit</strong> - Siswa sakit dengan surat keterangan
                                    </li>
                                    <li>
                                        <span class="keterangan-badge badge-a">A</span>
                                        <strong>Alpa</strong> - Siswa tidak hadir tanpa keterangan
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Info -->
                    <div class="alert alert-custom mt-3">
                        <div class="d-flex align-items-start">
                            <i class="bi bi-lightbulb-fill text-primary fs-4 me-3"></i>
                            <div>
                                <strong>Tips:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>Pastikan data absensi sudah lengkap sebelum melakukan ekspor</li>
                                    <li>PDF akan otomatis terbuka di tab baru setelah diproses</li>
                                    <li>File dapat langsung dicetak atau disimpan untuk arsip</li>
                                    <li>Format landscape A4 untuk hasil terbaik</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-generate">
                            <i class="bi bi-file-pdf me-2"></i>Generate PDF Sekarang
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Footer Info -->
        <div class="text-center mt-4 text-white">
            <p class="mb-0">
                <i class="bi bi-shield-check me-2"></i>
                Data dilindungi dan aman
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('form-ekspor').addEventListener('submit', function(e) {
            const kelas = document.getElementById('kelas').value;
            const bulan = document.getElementById('bulan').value;
            const tahun = document.getElementById('tahun').value;
            
            if (!kelas || !bulan || !tahun) {
                e.preventDefault();
                alert('⚠️ Mohon lengkapi semua filter!\n\nPilih Kelas, Bulan, dan Tahun terlebih dahulu.');
                return false;
            }
            
            // Show loading overlay
            document.getElementById('loadingOverlay').style.display = 'flex';
            
            // Hide loading after 3 seconds (PDF should open in new tab)
            setTimeout(function() {
                document.getElementById('loadingOverlay').style.display = 'none';
            }, 3000);
        });
        
        // Add animation on select change
        const selects = document.querySelectorAll('.form-select');
        selects.forEach(select => {
            select.addEventListener('change', function() {
                this.style.transform = 'scale(1.02)';
                setTimeout(() => {
                    this.style.transform = 'scale(1)';
                }, 200);
            });
        });
    </script>
</body>
</html>