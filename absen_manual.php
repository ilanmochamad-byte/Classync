<?php
require 'includes/db.php';

// Ambil semua siswa yang BELUM absen hari ini
$tanggal_hari_ini = date('Y-m-d');
$query_belum_hadir = $conn->query("
    SELECT s.id, s.nama_siswa, s.kelas, s.nisn
    FROM siswa s
    LEFT JOIN absensi_siswa a ON s.id = a.siswa_id AND a.tanggal = '$tanggal_hari_ini'
    WHERE a.id IS NULL
    ORDER BY s.kelas, s.nama_siswa
");
$siswa_belum_hadir = $query_belum_hadir->fetch_all(MYSQLI_ASSOC);

// Hitung statistik
$total_siswa = $conn->query("SELECT COUNT(id) as total FROM siswa")->fetch_assoc()['total'];
$total_hadir = $conn->query("SELECT COUNT(id) as total FROM absensi_siswa WHERE tanggal = '$tanggal_hari_ini'")->fetch_assoc()['total'];
$total_belum = $total_siswa - $total_hadir;
$persentase_hadir = $total_siswa > 0 ? round(($total_hadir / $total_siswa) * 100, 1) : 0;

// Nama bulan Indonesia
$bulan_indonesia = [
    'January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret',
    'April' => 'April', 'May' => 'Mei', 'June' => 'Juni',
    'July' => 'Juli', 'August' => 'Agustus', 'September' => 'September',
    'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember'
];
$hari_indonesia = [
    'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
    'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
];
$tanggal_format = date('d') . ' ' . $bulan_indonesia[date('F')] . ' ' . date('Y');
$hari_format = $hari_indonesia[date('l')];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Absensi Manual Guru - SMK Terpadu Al Hasan</title>
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
        
        .navbar-custom {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.1);
            border-radius: 15px;
            margin-bottom: 2rem;
        }
        
        .container-main {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .card-custom {
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            border: none;
            overflow: hidden;
            animation: slideUp 0.5s ease-out;
            background: white;
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
        
        .card-header-custom {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border: none;
        }
        
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-item {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
            transition: transform 0.3s ease;
        }
        
        .stat-item:hover {
            transform: translateY(-5px);
        }
        
        .stat-item h3 {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0;
        }
        
        .stat-item p {
            margin: 0;
            opacity: 0.9;
        }
        
        .form-select-custom {
            border-radius: 12px;
            border: 2px solid #e0e0e0;
            padding: 1rem;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-select-custom:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .status-button-group {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1.5rem 0;
        }
        
        .btn-status {
            padding: 1.5rem;
            border-radius: 15px;
            border: 3px solid transparent;
            transition: all 0.3s ease;
            font-weight: 600;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-status i {
            font-size: 2rem;
        }
        
        .btn-status:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .btn-check:checked + .btn-status {
            border-color: currentColor;
            transform: scale(1.05);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }
        
        .btn-sakit {
            background: linear-gradient(135deg, #f6d365 0%, #fda085 100%);
            color: #8b4513;
        }
        
        .btn-izin {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            color: #0066cc;
        }
        
        .btn-alpha {
            background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);
            color: #cc0000;
        }
        
        .btn-submit-custom {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 1.25rem 3rem;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 50px;
            color: white;
            transition: all 0.3s ease;
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-submit-custom:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.6);
        }
        
        .btn-submit-custom:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .success-illustration {
            text-align: center;
            padding: 3rem;
        }
        
        .success-icon {
            font-size: 5rem;
            color: #28a745;
            animation: scaleIn 0.5s ease-out;
        }
        
        @keyframes scaleIn {
            from {
                transform: scale(0);
            }
            to {
                transform: scale(1);
            }
        }
        
        .back-button {
            background: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            font-weight: 600;
            color: #667eea;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .back-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
            color: #764ba2;
        }
        
        .notification-custom {
            border-radius: 12px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            border: none;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            animation: slideDown 0.3s ease-out;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .search-box {
            position: relative;
            margin-bottom: 1rem;
        }
        
        .search-box input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 3rem;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .search-box input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }
        
        @media (max-width: 768px) {
            .status-button-group {
                grid-template-columns: 1fr;
            }
            
            .stat-grid {
                grid-template-columns: 1fr;
            }
            
            body {
                padding: 1rem 0;
            }
            
            .card-header-custom h4 {
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <div class="container container-main">
        <!-- Header Navbar -->
        <nav class="navbar navbar-custom">
            <div class="container-fluid">
                <a class="navbar-brand d-flex align-items-center" href="#">
                    <img src="classync.png" alt="Logo" height="40" class="me-2">
                    <div>
                        <div class="fw-bold text-dark">Absensi Manual Guru</div>
                        <small class="text-muted">SMK Terpadu Al Hasan</small>
                    </div>
                </a>
                <a href="absen-siswa.php" class="back-button">
                    <i class="bi bi-arrow-left me-2"></i>Kembali
                </a>
            </div>
        </nav>

        <!-- Statistik Dashboard -->
        <div class="stat-grid">
            <div class="stat-item">
                <i class="bi bi-people-fill mb-2" style="font-size: 2rem;"></i>
                <h3><?php echo $total_siswa; ?></h3>
                <p>Total Siswa</p>
            </div>
            <div class="stat-item">
                <i class="bi bi-check-circle-fill mb-2" style="font-size: 2rem;"></i>
                <h3><?php echo $total_hadir; ?></h3>
                <p>Sudah Absen</p>
            </div>
            <div class="stat-item">
                <i class="bi bi-exclamation-circle-fill mb-2" style="font-size: 2rem;"></i>
                <h3><?php echo $total_belum; ?></h3>
                <p>Belum Absen</p>
            </div>
        </div>

        <!-- Main Card -->
        <div class="card card-custom">
            <div class="card-header-custom">
                <h4 class="mb-1">
                    <i class="bi bi-pencil-square me-2"></i>Input Absensi Manual
                </h4>
                <p class="mb-0 opacity-75">
                    <i class="bi bi-calendar-date me-2"></i><?php echo $hari_format . ', ' . $tanggal_format; ?>
                </p>
            </div>
            <div class="card-body p-4">
                
                <div id="notification-bar" class="notification-custom" style="display: none;"></div>

                <?php if (empty($siswa_belum_hadir)): ?>
                    <div class="success-illustration">
                        <i class="bi bi-check-circle-fill success-icon"></i>
                        <h3 class="mt-3 fw-bold">Luar Biasa!</h3>
                        <p class="text-muted">Semua siswa sudah melakukan absensi hari ini.</p>
                        <div class="mt-4">
                            <div class="progress" style="height: 30px; border-radius: 15px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: 100%;" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100">
                                    <strong>100% Absensi Selesai</strong>
                                </div>
                            </div>
                        </div>
                        <a href="absen-siswa.php" class="btn btn-submit-custom mt-4">
                            <i class="bi bi-house-fill me-2"></i>Kembali ke Beranda
                        </a>
                    </div>
                <?php else: ?>
                    <form id="manual-absen-form">
                        <!-- Search Box -->
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" id="search-siswa" class="form-control" placeholder="Cari nama siswa atau kelas..." autocomplete="off">
                        </div>

                        <!-- Select Siswa -->
                        <div class="mb-4">
                            <label for="siswa-select" class="form-label fw-bold">
                                <i class="bi bi-person-fill me-2"></i>Pilih Siswa yang Tidak Hadir
                            </label>
                            <select id="siswa-select" class="form-select form-select-custom" required>
                                <option value="" selected disabled>-- Pilih Nama Siswa (<?php echo count($siswa_belum_hadir); ?> siswa belum absen) --</option>
                                <?php foreach ($siswa_belum_hadir as $siswa): ?>
                                    <option value="<?php echo $siswa['id']; ?>" 
                                            data-nama="<?php echo strtolower($siswa['nama_siswa']); ?>" 
                                            data-kelas="<?php echo strtolower($siswa['kelas']); ?>">
                                        <?php echo htmlspecialchars($siswa['nama_siswa']) . " - " . htmlspecialchars($siswa['kelas']); ?>
                                        <?php if (!empty($siswa['nisn'])): ?>
                                            (NISN: <?php echo htmlspecialchars($siswa['nisn']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Status Buttons -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">
                                <i class="bi bi-clipboard-check me-2"></i>Pilih Status Kehadiran
                            </label>
                            <div class="status-button-group">
                                <div>
                                    <input type="radio" class="btn-check" name="status" id="status-sakit" value="Sakit" autocomplete="off" required>
                                    <label class="btn btn-status btn-sakit w-100" for="status-sakit">
                                        <i class="bi bi-bandaid-fill"></i>
                                        <span>SAKIT</span>
                                    </label>
                                </div>

                                <div>
                                    <input type="radio" class="btn-check" name="status" id="status-izin" value="Izin" autocomplete="off">
                                    <label class="btn btn-status btn-izin w-100" for="status-izin">
                                        <i class="bi bi-envelope-paper-fill"></i>
                                        <span>IZIN</span>
                                    </label>
                                </div>

                                <div>
                                    <input type="radio" class="btn-check" name="status" id="status-alpha" value="Alpha" autocomplete="off">
                                    <label class="btn btn-status btn-alpha w-100" for="status-alpha">
                                        <i class="bi bi-person-x-fill"></i>
                                        <span>ALPHA</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Progress Bar -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between mb-2">
                                <small class="text-muted">Progress Absensi Hari Ini</small>
                                <small class="text-muted fw-bold"><?php echo $persentase_hadir; ?>%</small>
                            </div>
                            <div class="progress" style="height: 20px; border-radius: 10px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $persentase_hadir; ?>%;" aria-valuenow="<?php echo $persentase_hadir; ?>" aria-valuemin="0" aria-valuemax="100">
                                    <?php echo $total_hadir; ?>/<?php echo $total_siswa; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="text-center">
                            <button type="submit" class="btn btn-submit-custom">
                                <i class="bi bi-send-check-fill me-2"></i>Simpan dan Kirim Notifikasi WA
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('manual-absen-form');
    const notificationBar = document.getElementById('notification-bar');
    const siswaSelect = document.getElementById('siswa-select');
    const searchInput = document.getElementById('search-siswa');

    // Search functionality
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const options = siswaSelect.querySelectorAll('option');
            
            let hasVisibleOptions = false;
            options.forEach(option => {
                if (option.value === '') {
                    option.style.display = ''; // Always show placeholder
                    return;
                }
                
                const nama = option.getAttribute('data-nama');
                const kelas = option.getAttribute('data-kelas');
                
                if (nama.includes(searchTerm) || kelas.includes(searchTerm)) {
                    option.style.display = '';
                    hasVisibleOptions = true;
                } else {
                    option.style.display = 'none';
                }
            });
            
            // Auto-select if only one option visible
            if (hasVisibleOptions && searchTerm.length > 2) {
                const visibleOptions = Array.from(options).filter(opt => opt.value !== '' && opt.style.display !== 'none');
                if (visibleOptions.length === 1) {
                    siswaSelect.value = visibleOptions[0].value;
                }
            }
        });
    }

    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const siswaId = siswaSelect.value;
            const statusRadio = document.querySelector('input[name="status"]:checked');
            
            if (!siswaId || !statusRadio) {
                showNotification('⚠️ Harap pilih siswa dan statusnya terlebih dahulu.', false);
                return;
            }

            const status = statusRadio.value;
            const submitButton = form.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memproses...';

            fetch('api/proses_absen_manual.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    siswa_id: siswaId,
                    status: status
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                return response.json();
            })
            .then(result => {
                if (result.status === 'success') {
                    showNotification('✅ ' + result.message, true);
                    
                    // Hapus siswa yang baru saja diabsen dari dropdown
                    const selectedOption = siswaSelect.options[siswaSelect.selectedIndex];
                    siswaSelect.removeChild(selectedOption);
                    siswaSelect.value = "";
                    
                    // Reset status radio
                    statusRadio.checked = false;
                    
                    // Reset search
                    if (searchInput) searchInput.value = '';
                    
                    // Show all options again
                    const allOptions = siswaSelect.querySelectorAll('option');
                    allOptions.forEach(opt => opt.style.display = '');

                    // Update placeholder count
                    const remainingCount = siswaSelect.options.length - 1;
                    siswaSelect.options[0].text = `-- Pilih Nama Siswa (${remainingCount} siswa belum absen) --`;

                    // Jika semua sudah diabsen, tampilkan pesan sukses
                    if (siswaSelect.options.length <= 1) {
                        form.innerHTML = `
                            <div class="success-illustration">
                                <i class="bi bi-check-circle-fill success-icon"></i>
                                <h3 class="mt-3 fw-bold">Selesai!</h3>
                                <p class="text-muted">Semua siswa yang belum hadir telah diproses.</p>
                                <div class="mt-4">
                                    <div class="progress" style="height: 30px; border-radius: 15px;">
                                        <div class="progress-bar bg-success" role="progressbar" style="width: 100%;">
                                            <strong>100% Absensi Selesai</strong>
                                        </div>
                                    </div>
                                </div>
                                <a href="absen-siswa.php" class="btn btn-submit-custom mt-4">
                                    <i class="bi bi-house-fill me-2"></i>Kembali ke Beranda
                                </a>
                            </div>
                        `;
                    }

                } else {
                    showNotification('❌ ' + result.message, false);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('❌ Terjadi kesalahan: ' + error.message, false);
            })
            .finally(() => {
                submitButton.disabled = false;
                submitButton.innerHTML = '<i class="bi bi-send-check-fill me-2"></i>Simpan dan Kirim Notifikasi WA';
            });
        });
    }

    function showNotification(message, isSuccess) {
        notificationBar.textContent = message;
        notificationBar.className = 'notification-custom alert ' + (isSuccess ? 'alert-success' : 'alert-danger');
        notificationBar.style.display = 'block';

        window.scrollTo({ top: 0, behavior: 'smooth' });

        setTimeout(() => {
            notificationBar.style.display = 'none';
        }, 5000);
    }
});
</script>

</body>
</html>