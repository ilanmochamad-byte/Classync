<?php
// admin/mutasi_siswa.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'partials/header.php';

$pesan = '';
$tipe_pesan = '';

// --- LOGIKA PROSES MUTASI ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['proses_mutasi'])) {
    $siswa_ids = $_POST['siswa_ids'] ?? [];
    $jenis_mutasi = $_POST['jenis_mutasi'] ?? '';
    $kelas_tujuan = trim($_POST['kelas_tujuan'] ?? '');

    if (empty($siswa_ids)) {
        $pesan = "Gagal: Anda belum memilih siswa satupun!";
        $tipe_pesan = "danger";
    } else {
        $ids_string = implode(',', array_map('intval', $siswa_ids));
        
        if ($jenis_mutasi === 'naik_kelas') {
            if (empty($kelas_tujuan)) {
                $pesan = "Gagal: Kelas tujuan harus diisi jika memilih Naik/Pindah Kelas.";
                $tipe_pesan = "warning";
            } else {
                $sql = "UPDATE siswa SET kelas = ? WHERE id IN ($ids_string)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("s", $kelas_tujuan);
                if ($stmt->execute()) {
                    $pesan = count($siswa_ids) . " Siswa berhasil dimutasi ke kelas " . htmlspecialchars($kelas_tujuan) . ".";
                    $tipe_pesan = "success";
                } else {
                    $pesan = "Terjadi kesalahan sistem: " . $stmt->error;
                    $tipe_pesan = "danger";
                }
            }
        } elseif ($jenis_mutasi === 'lulus') {
            $sql = "UPDATE siswa SET kelas = 'Lulus / Alumni' WHERE id IN ($ids_string)";
            if ($conn->query($sql)) {
                $pesan = count($siswa_ids) . " Siswa berhasil diluluskan menjadi Alumni.";
                $tipe_pesan = "success";
            } else {
                $pesan = "Terjadi kesalahan sistem: " . $conn->error;
                $tipe_pesan = "danger";
            }
        }
    }
}

// --- AMBIL DAFTAR KELAS (Kecuali Lulus) ---
$kelas_query = $conn->query("SELECT DISTINCT kelas FROM siswa WHERE kelas != 'Lulus / Alumni' ORDER BY kelas ASC");
$daftar_kelas = [];
while ($row = $kelas_query->fetch_assoc()) {
    $daftar_kelas[] = $row['kelas'];
}

$kelas_pilih = isset($_GET['kelas_asal']) ? $_GET['kelas_asal'] : ($daftar_kelas[0] ?? '');

// --- AMBIL DATA SISWA BERDASARKAN KELAS ASAL ---
$list_siswa = null;
if (!empty($kelas_pilih)) {
    $stmt_siswa = $conn->prepare("SELECT id, nisn, nama_siswa, jenis_kelamin FROM siswa WHERE kelas = ? ORDER BY nama_siswa ASC");
    $stmt_siswa->bind_param("s", $kelas_pilih);
    $stmt_siswa->execute();
    $list_siswa = $stmt_siswa->get_result();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mutasi & Kelulusan - Classync</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f5f7fa; }
        .card-custom { border-radius: 16px; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.04); }
        .card-header-custom { background-color: #fff; border-bottom: 1px solid #f1f2f6; padding: 1.25rem 1.5rem; border-radius: 16px 16px 0 0 !important; }
        
        .student-row { transition: background-color 0.2s; cursor: pointer; }
        .student-row:hover { background-color: #f8f9fa; }
        
        /* Checkbox Custom Styling */
        .form-check-input { width: 1.2rem; height: 1.2rem; cursor: pointer; }
        .form-check-input:checked { background-color: #4A90A4; border-color: #4A90A4; }
        
        .radio-custom-card {
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .radio-custom-card:hover { border-color: #4A90A4; background-color: #f0f8fa; }
        .form-check-input:checked + .radio-custom-card {
            border-color: #4A90A4;
            background-color: #E8F4F8;
        }
    </style>
</head>
<body>

<div class="bg-white shadow-sm py-3 mb-4">
    <div class="container d-flex justify-content-between align-items-center">
        <h4 class="fw-bold text-dark mb-0"><i class="bi bi-arrow-left-right text-primary me-2"></i> Mutasi & Kelulusan Siswa</h4>
        <a href="siswa.php" class="btn btn-outline-secondary btn-sm rounded-pill"><i class="bi bi-people"></i> Data Siswa</a>
    </div>
</div>

<div class="container mb-5">

    <?php if(!empty($pesan)): ?>
        <div class="alert alert-<?php echo $tipe_pesan; ?> alert-dismissible fade show shadow-sm border-0 rounded-4" role="alert">
            <i class="bi <?php echo $tipe_pesan == 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> me-2"></i>
            <?php echo $pesan; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card card-custom mb-4">
        <div class="card-body p-4">
            <form method="GET" action="" class="d-flex align-items-end gap-3">
                <div class="flex-grow-1">
                    <label class="form-label fw-bold text-muted small">Pilih Kelas Asal (Yang Ingin Dimutasi)</label>
                    <select name="kelas_asal" class="form-select border-primary fw-semibold" onchange="this.form.submit()">
                        <option value="">-- Pilih Kelas --</option>
                        <?php foreach ($daftar_kelas as $kls): ?>
                            <option value="<?php echo htmlspecialchars($kls); ?>" <?php echo ($kelas_pilih == $kls) ? 'selected' : ''; ?>>
                                Kelas <?php echo htmlspecialchars($kls); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <?php if(!empty($kelas_pilih) && $list_siswa): ?>
    <form method="POST" action="" id="formMutasi">
        <div class="row g-4">
            
            <div class="col-lg-8">
                <div class="card card-custom h-100">
                    <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-list-check text-primary me-2"></i>Daftar Siswa Kelas <?php echo htmlspecialchars($kelas_pilih); ?></h6>
                        <span class="badge bg-primary rounded-pill"><?php echo $list_siswa->num_rows; ?> Siswa</span>
                    </div>
                    
                    <div class="card-body p-0">
                        <div class="d-flex align-items-center p-3 border-bottom bg-light">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" id="selectAll" checked>
                                <label class="form-check-label fw-bold ms-2" for="selectAll" style="cursor: pointer;">
                                    Pilih Semua Siswa
                                </label>
                            </div>
                        </div>
                        
                        <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                            <table class="table table-hover align-middle mb-0">
                                <tbody>
                                    <?php if($list_siswa->num_rows > 0): while($siswa = $list_siswa->fetch_assoc()): ?>
                                    <tr class="student-row" onclick="toggleCheckbox('check_<?php echo $siswa['id']; ?>')">
                                        <td class="ps-4" style="width: 50px;">
                                            <div class="form-check mb-0">
                                                <input class="form-check-input student-checkbox" type="checkbox" name="siswa_ids[]" value="<?php echo $siswa['id']; ?>" id="check_<?php echo $siswa['id']; ?>" checked onclick="event.stopPropagation(); checkSelectAllStatus();">
                                            </div>
                                        </td>
                                        <td style="width: 100px;">
                                            <span class="badge bg-secondary opacity-75"><?php echo htmlspecialchars($siswa['nisn']); ?></span>
                                        </td>
                                        <td class="fw-bold text-dark">
                                            <?php echo htmlspecialchars($siswa['nama_siswa']); ?>
                                        </td>
                                        <td class="text-end pe-4 text-muted small">
                                            <i class="bi <?php echo $siswa['jenis_kelamin'] == 'Laki-laki' ? 'bi-gender-male text-primary' : 'bi-gender-female text-danger'; ?>"></i>
                                            <?php echo $siswa['jenis_kelamin']; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-5">
                                            <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                                            Tidak ada siswa di kelas ini.
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card card-custom h-100" style="position: sticky; top: 20px;">
                    <div class="card-header card-header-custom">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-sliders text-warning me-2"></i>Pengaturan Mutasi</h6>
                    </div>
                    <div class="card-body p-4">
                        
                        <p class="text-muted small mb-4">Tentukan tindakan untuk <strong id="countSelected" class="text-dark"><?php echo $list_siswa->num_rows; ?></strong> siswa yang dipilih.</p>

                        <div class="mb-4">
                            <label class="form-check p-0 mb-3 position-relative">
                                <input class="form-check-input position-absolute opacity-0" type="radio" name="jenis_mutasi" value="naik_kelas" id="optNaik" checked onchange="toggleTujuan()">
                                <div class="radio-custom-card">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-arrow-up-circle-fill fs-3 text-primary me-3"></i>
                                        <div>
                                            <h6 class="fw-bold mb-0 text-dark">Naik / Pindah Kelas</h6>
                                            <small class="text-muted">Pindahkan siswa ke kelas tingkat selanjutnya.</small>
                                        </div>
                                    </div>
                                </div>
                            </label>

                            <label class="form-check p-0 position-relative">
                                <input class="form-check-input position-absolute opacity-0" type="radio" name="jenis_mutasi" value="lulus" id="optLulus" onchange="toggleTujuan()">
                                <div class="radio-custom-card">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-mortarboard-fill fs-3 text-success me-3"></i>
                                        <div>
                                            <h6 class="fw-bold mb-0 text-dark">Lulus / Alumni</h6>
                                            <small class="text-muted">Luluskan siswa dari sekolah.</small>
                                        </div>
                                    </div>
                                </div>
                            </label>
                        </div>

                        <div id="kelasTujuanContainer" class="mb-4">
                            <label class="form-label fw-bold text-dark">Ketik Kelas Tujuan <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-lg border-primary" name="kelas_tujuan" id="kelas_tujuan" placeholder="Contoh: 11-DKV">
                            <small class="text-muted mt-1 d-block">Pastikan penulisan nama kelas seragam dengan data yang sudah ada.</small>
                        </div>

                    </div>
                    <div class="card-footer bg-white border-0 p-4 pt-0">
                        <button type="submit" name="proses_mutasi" class="btn btn-primary btn-lg w-100 fw-bold rounded-pill shadow-sm" onclick="return confirmSubmit()">
                            <i class="bi bi-check-circle me-2"></i> Eksekusi Mutasi
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </form>
    <?php elseif(empty($kelas_pilih)): ?>
        <div class="alert alert-info border-0 rounded-4 shadow-sm text-center py-5">
            <i class="bi bi-arrow-up-circle-fill fs-1 text-info opacity-50 d-block mb-3"></i>
            <h5>Silakan pilih Kelas Asal terlebih dahulu pada form di atas.</h5>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // Toggle Select All Checkboxes
    const selectAllCheckbox = document.getElementById('selectAll');
    const studentCheckboxes = document.querySelectorAll('.student-checkbox');
    const countDisplay = document.getElementById('countSelected');

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            let count = 0;
            studentCheckboxes.forEach(cb => {
                cb.checked = selectAllCheckbox.checked;
                if(cb.checked) count++;
            });
            updateCount(count);
        });
    }

    // Toggle Single Checkbox via Row Click
    function toggleCheckbox(id) {
        const cb = document.getElementById(id);
        cb.checked = !cb.checked;
        checkSelectAllStatus();
    }

    // Check Status of Select All based on individual checks
    function checkSelectAllStatus() {
        const total = studentCheckboxes.length;
        let checkedCount = 0;
        studentCheckboxes.forEach(cb => {
            if(cb.checked) checkedCount++;
        });

        if(selectAllCheckbox) {
            selectAllCheckbox.checked = (total === checkedCount && total > 0);
        }
        updateCount(checkedCount);
    }

    function updateCount(count) {
        if(countDisplay) {
            countDisplay.innerText = count;
        }
    }

    // Toggle Input Kelas Tujuan Visibility
    function toggleTujuan() {
        const isNaik = document.getElementById('optNaik').checked;
        const container = document.getElementById('kelasTujuanContainer');
        const inputTujuan = document.getElementById('kelas_tujuan');
        
        if(isNaik) {
            container.style.display = 'block';
            inputTujuan.setAttribute('required', 'required');
        } else {
            container.style.display = 'none';
            inputTujuan.removeAttribute('required');
        }
    }

    // Init state on load
    window.onload = function() {
        if(document.getElementById('optNaik')) toggleTujuan();
    };

    // Confirm Submission
    function confirmSubmit() {
        const count = parseInt(countDisplay.innerText);
        if (count === 0) {
            Swal.fire('Gagal', 'Pilih minimal satu siswa untuk dieksekusi.', 'warning');
            return false;
        }

        const isLulus = document.getElementById('optLulus').checked;
        const target = isLulus ? 'LULUS (ALUMNI)' : document.getElementById('kelas_tujuan').value;

        if (!isLulus && target.trim() === '') {
            Swal.fire('Gagal', 'Kelas tujuan tidak boleh kosong.', 'warning');
            return false;
        }

        return confirm(`Anda yakin ingin memindahkan ${count} siswa terpilih menjadi: ${target}?\n\nData yang diubah tidak dapat dikembalikan secara massal.`);
    }
</script>

<?php include 'partials/footer.php'; ?>