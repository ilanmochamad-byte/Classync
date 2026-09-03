<?php
// admin/pengaturan_honor.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'partials/header.php';

// --- LOGIKA SIMPAN PENGATURAN ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_pengaturan'])) {
    $honor_per_jp = (int)str_replace(['.', ','], '', $_POST['honor_per_jp']);
    $honor_ekskul = (int)str_replace(['.', ','], '', $_POST['honor_ekskul']);
    $honor_piket  = (int)str_replace(['.', ','], '', $_POST['honor_piket']);
    $honor_bk     = (int)str_replace(['.', ','], '', $_POST['honor_bk']);

    $settings = [
        'honor_per_jp' => $honor_per_jp,
        'honor_ekskul' => $honor_ekskul,
        'honor_piket'  => $honor_piket,
        'honor_bk'     => $honor_bk
    ];

    $success = true;
    foreach ($settings as $nama => $nilai) {
        // Logika cerdas: Jika belum ada, buat baru. Jika sudah ada, perbarui nilainya.
        $stmt = $conn->prepare("INSERT INTO pengaturan (nama_pengaturan, nilai_pengaturan) VALUES (?, ?) ON DUPLICATE KEY UPDATE nilai_pengaturan = VALUES(nilai_pengaturan)");
        $str_nilai = (string)$nilai;
        $stmt->bind_param("ss", $nama, $str_nilai);
        if (!$stmt->execute()) {
            $success = false;
        }
        $stmt->close();
    }

    if ($success) {
        $pesan = "Nilai honorarium berhasil diperbarui! Perubahan ini akan langsung berlaku pada perhitungan honor bulan ini.";
        $pesan_tipe = "success";
    } else {
        $pesan = "Terjadi kesalahan saat menyimpan pengaturan: " . $conn->error;
        $pesan_tipe = "danger";
    }
}

// --- AMBIL DATA PENGATURAN SAAT INI (Atau Gunakan Default) ---
$tarif_default = [
    'honor_per_jp' => 10000,
    'honor_ekskul' => 25000,
    'honor_piket'  => 25000,
    'honor_bk'     => 25000
];

$q_set = $conn->query("SELECT nama_pengaturan, nilai_pengaturan FROM pengaturan WHERE nama_pengaturan LIKE 'honor_%'");
if ($q_set && $q_set->num_rows > 0) {
    while ($row = $q_set->fetch_assoc()) {
        if (array_key_exists($row['nama_pengaturan'], $tarif_default)) {
            $tarif_default[$row['nama_pengaturan']] = (int)$row['nilai_pengaturan'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan Honorarium - Classync</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f5f7fa; }
        .card-custom { border-radius: 16px; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.04); }
        .card-header-custom { background-color: #fff; border-bottom: 1px solid #f1f2f6; padding: 1.25rem 1.5rem; border-radius: 16px 16px 0 0 !important; }
        .input-group-text { background-color: #f8f9fa; font-weight: 600; color: #4A90A4; border-color: #dee2e6; }
        .form-control:focus { border-color: #4A90A4; box-shadow: 0 0 0 0.25rem rgba(74, 144, 164, 0.25); }
    </style>
</head>
<body>

<div class="bg-white shadow-sm py-3 mb-4">
    <div class="container d-flex justify-content-between align-items-center">
        <h4 class="fw-bold text-dark mb-0"><i class="bi bi-wallet2 text-success me-2"></i> Pengaturan Honorarium</h4>
        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary px-3 py-2 rounded-pill">Remote Control Keuangan</span>
    </div>
</div>

<div class="container mb-5">

    <?php if(isset($pesan)): ?>
        <div class="alert alert-<?php echo $pesan_tipe; ?> alert-dismissible fade show border-0 shadow-sm rounded-4" role="alert">
            <i class="bi <?php echo $pesan_tipe == 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> me-2"></i>
            <?php echo $pesan; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4 justify-content-center">
        <div class="col-lg-8">
            
            <div class="alert alert-warning border-0 shadow-sm rounded-4 mb-4">
                <h6 class="fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i> Perhatian untuk Bendahara!</h6>
                <p class="mb-0 small">Perubahan nilai pada halaman ini akan <strong>langsung berpengaruh</strong> terhadap kalkulasi slip gaji seluruh guru yang diakses melalui aplikasi maupun laporan web. Pastikan nominal yang dimasukkan sudah sesuai dengan SK/Kebijakan Yayasan terbaru.</p>
            </div>

            <div class="card card-custom h-100">
                <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-cash-coin text-success me-2"></i>Tarif Basis Honorarium</h6>
                </div>
                <form method="POST" action="">
                    <div class="card-body p-4">
                        
                        <div class="mb-4">
                            <label for="honor_per_jp" class="form-label fw-bold text-dark">Honor Mengajar per JP (Jam Pelajaran)</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text">Rp</span>
                                <input type="number" class="form-control fw-semibold" id="honor_per_jp" name="honor_per_jp" value="<?php echo $tarif_default['honor_per_jp']; ?>" required min="0">
                                <span class="input-group-text">/ JP</span>
                            </div>
                            <small class="text-muted mt-1 d-block">Dikalikan dengan jumlah Jam Pelajaran yang diinput pada jurnal harian guru.</small>
                        </div>

                        <hr class="my-4 text-muted opacity-25">

                        <div class="mb-4">
                            <label for="honor_ekskul" class="form-label fw-bold text-dark">Honor Ekstrakurikuler</label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input type="number" class="form-control fw-semibold" id="honor_ekskul" name="honor_ekskul" value="<?php echo $tarif_default['honor_ekskul']; ?>" required min="0">
                                <span class="input-group-text">/ Hadir</span>
                            </div>
                            <small class="text-muted mt-1 d-block">Tarif per satu kali kehadiran absensi ekskul.</small>
                        </div>

                        <div class="mb-4">
                            <label for="honor_piket" class="form-label fw-bold text-dark">Honor Guru Piket</label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input type="number" class="form-control fw-semibold" id="honor_piket" name="honor_piket" value="<?php echo $tarif_default['honor_piket']; ?>" required min="0">
                                <span class="input-group-text">/ Sesi</span>
                            </div>
                            <small class="text-muted mt-1 d-block">Tarif per sesi piket (contoh: Piket Pagi, Piket Siang).</small>
                        </div>

                        <div class="mb-4">
                            <label for="honor_bk" class="form-label fw-bold text-dark">Honor Layanan BK (Bimbingan Konseling)</label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input type="number" class="form-control fw-semibold" id="honor_bk" name="honor_bk" value="<?php echo $tarif_default['honor_bk']; ?>" required min="0">
                                <span class="input-group-text">/ Layanan</span>
                            </div>
                            <small class="text-muted mt-1 d-block">Tarif per satu kali entri jurnal layanan BK (Individu / Klasikal).</small>
                        </div>

                    </div>
                    <div class="card-footer bg-light border-0 p-4 pt-3 text-end rounded-bottom-4">
                        <button type="submit" name="simpan_pengaturan" class="btn btn-success btn-lg fw-bold rounded-pill px-5 shadow-sm">
                            <i class="bi bi-save2 me-2"></i> Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'partials/footer.php'; ?>