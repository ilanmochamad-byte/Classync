<?php 
include 'partials/header.php';

// Ambil daftar guru untuk dropdown
$guru_list = $conn->query("SELECT id, nama_guru FROM guru ORDER BY nama_guru ASC");
$guru_list_auto = $conn->query("SELECT id, nama_guru FROM guru ORDER BY nama_guru ASC"); // Untuk tab otomatis

// Fungsi Helper Hari Indonesia
function getHariIndo($date) {
    $days = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
    ];
    return $days[date('l', strtotime($date))];
}

$pesan = "";
$tipe_pesan = ""; // 'success' or 'danger'

// --- PROSES 1: SIMPAN ABSENSI MANUAL (SATUAN) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_absensi'])) {
    $guru_id = $_POST['guru_id'];
    $jadwal_id = $_POST['jadwal_id'];
    $tipe_absensi = $_POST['tipe_absensi'];
    $waktu_absensi = $_POST['waktu_absensi'];
    $status = $_POST['status'];
    $keterangan = $_POST['keterangan'];
    $foto_bukti_path = null;

    // Cek duplikasi
    $tgl_absen = date('Y-m-d', strtotime($waktu_absensi));
    $sql_cek = "SELECT id FROM absensi WHERE guru_id = ? AND jadwal_id = ? AND tipe_absensi = ? AND DATE(waktu_absensi) = ?";
    $stmt_cek = $conn->prepare($sql_cek);
    $stmt_cek->bind_param("iiss", $guru_id, $jadwal_id, $tipe_absensi, $tgl_absen);
    $stmt_cek->execute();
    
    if ($stmt_cek->get_result()->num_rows > 0) {
        $pesan = "Error: Guru ini sudah memiliki catatan absensi untuk jadwal dan tanggal tersebut.";
        $tipe_pesan = "danger";
    } else {
        // Upload Foto
        if (isset($_FILES['foto_bukti']) && $_FILES['foto_bukti']['error'] == 0) {
            $target_dir = "../uploads/";
            $file_name = "manual-" . time() . '-' . basename($_FILES["foto_bukti"]["name"]);
            $target_file = $target_dir . $file_name;
            if (move_uploaded_file($_FILES["foto_bukti"]["tmp_name"], $target_file)) {
                $foto_bukti_path = "uploads/" . $file_name;
            }
        }

        $stmt_insert = $conn->prepare("INSERT INTO absensi (guru_id, jadwal_id, tipe_absensi, waktu_absensi, status, keterangan, foto_bukti) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt_insert->bind_param("iisssss", $guru_id, $jadwal_id, $tipe_absensi, $waktu_absensi, $status, $keterangan, $foto_bukti_path);
        
        if ($stmt_insert->execute()) {
            $pesan = "Absensi manual berhasil disimpan.";
            $tipe_pesan = "success";
        } else {
            $pesan = "Gagal menyimpan: " . $stmt_insert->error;
            $tipe_pesan = "danger";
        }
    }
}

// --- PROSES 2: SIMPAN ABSENSI OTOMATIS (MASSAL/LIBUR) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_otomatis'])) {
    $selected_gurus = $_POST['guru_ids'] ?? [];
    $tanggal_pilih = $_POST['tanggal_otomatis'];
    $status_auto = $_POST['status_otomatis'];
    $keterangan_auto = $_POST['keterangan_otomatis'];
    
    if (empty($selected_gurus) || empty($tanggal_pilih)) {
        $pesan = "Harap pilih minimal satu guru dan tanggal.";
        $tipe_pesan = "danger";
    } else {
        $hari_indo = getHariIndo($tanggal_pilih);
        $foto_auto_path = null;
        $count_success = 0;

        // Upload Foto (Satu foto untuk semua inputan massal ini)
        if (isset($_FILES['foto_bukti_otomatis']) && $_FILES['foto_bukti_otomatis']['error'] == 0) {
            $target_dir = "../uploads/";
            $file_name = "auto-" . time() . '-' . basename($_FILES["foto_bukti_otomatis"]["name"]);
            $target_file = $target_dir . $file_name;
            if (move_uploaded_file($_FILES["foto_bukti_otomatis"]["tmp_name"], $target_file)) {
                $foto_auto_path = "uploads/" . $file_name;
            }
        }

        foreach ($selected_gurus as $gid) {
            // 1. Cari Jadwal Mengajar Guru tersebut di Hari yang dipilih HANYA YANG AKTIF
            $sql_jadwal = "SELECT id, jam_mulai FROM jadwal_mengajar WHERE guru_id = ? AND hari = ? AND status_jadwal = 'Aktif'";
            $stmt_j = $conn->prepare($sql_jadwal);
            $stmt_j->bind_param("is", $gid, $hari_indo);
            $stmt_j->execute();
            $result_j = $stmt_j->get_result();

            while ($row = $result_j->fetch_assoc()) {
                $jid = $row['id'];
                // Set waktu absensi sesuai tanggal dipilih + jam mulai pelajaran
                $waktu_fix = $tanggal_pilih . ' ' . $row['jam_mulai'];

                // Cek Duplikasi dulu
                $cek_dupl = $conn->query("SELECT id FROM absensi WHERE guru_id=$gid AND jadwal_id=$jid AND DATE(waktu_absensi)='$tanggal_pilih'");
                
                if ($cek_dupl->num_rows == 0) {
                    $stmt_ins = $conn->prepare("INSERT INTO absensi (guru_id, jadwal_id, tipe_absensi, waktu_absensi, status, keterangan, foto_bukti) VALUES (?, ?, 'mengajar', ?, ?, ?, ?)");
                    $stmt_ins->bind_param("iissss", $gid, $jid, $waktu_fix, $status_auto, $keterangan_auto, $foto_auto_path);
                    if($stmt_ins->execute()) $count_success++;
                }
            }
            $stmt_j->close();
            // 2. (Opsional) Logika untuk Piket/Ekskul bisa ditambahkan di sini
            // Misalnya cek tabel jadwal_piket jika ada, lalu insert tipe_absensi='piket'
        }

        if ($count_success > 0) {
            $pesan = "Berhasil memproses absensi otomatis untuk $count_success jadwal pelajaran.";
            $tipe_pesan = "success";
        } else {
            $pesan = "Tidak ada jadwal yang ditemukan untuk guru terpilih pada hari $hari_indo ($tanggal_pilih) atau data sudah ada.";
            $tipe_pesan = "warning";
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Input Absensi Manual</h1>
</div>

<?php if(!empty($pesan)): ?>
    <div class="alert alert-<?php echo $tipe_pesan; ?> alert-dismissible fade show" role="alert">
        <?php echo $pesan; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs" id="myTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="manual-tab" data-bs-toggle="tab" data-bs-target="#manual" type="button" role="tab" aria-selected="true">
                    <i class="bi bi-pencil-square"></i> Input Satuan (Lupa Absen)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="auto-tab" data-bs-toggle="tab" data-bs-target="#auto" type="button" role="tab" aria-selected="false">
                    <i class="bi bi-calendar-check"></i> Absensi Otomatis (Libur/Massal)
                </button>
            </li>
        </ul>
    </div>
    
    <div class="card-body">
        <div class="tab-content" id="myTabContent">
            
            <div class="tab-pane fade show active" id="manual" role="tabpanel">
                <form method="POST" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="guru_id" class="form-label">Pilih Guru</label>
                            <select name="guru_id" id="guru_id" class="form-select" required>
                                <option value="">-- Pilih Guru --</option>
                                <?php while($guru = $guru_list->fetch_assoc()): ?>
                                    <option value="<?php echo $guru['id']; ?>"><?php echo htmlspecialchars($guru['nama_guru']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="tipe_absensi" class="form-label">Tipe Jadwal</label>
                            <select name="tipe_absensi" id="tipe_absensi" class="form-select" required>
                                <option value="">-- Pilih Tipe --</option>
                                <option value="mengajar">Mengajar</option>
                                <option value="piket">Piket</option>
                                <option value="ekskul">Ekstrakurikuler</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="jadwal_id" class="form-label">Pilih Jadwal Spesifik</label>
                        <select name="jadwal_id" id="jadwal_id" class="form-select" required disabled>
                            <option value="">-- Pilih Guru dan Tipe Jadwal Terlebih Dahulu --</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="waktu_absensi" class="form-label">Waktu Absensi</label>
                            <input type="datetime-local" name="waktu_absensi" id="waktu_absensi" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="status" class="form-label">Status Kehadiran</label>
                            <select name="status" id="status" class="form-select" required>
                                <option value="Hadir">Hadir</option>
                                <option value="Sakit">Sakit</option>
                                <option value="Izin">Izin</option>
                                <option value="Alpa">Alpa</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="keterangan" class="form-label">Keterangan</label>
                        <textarea name="keterangan" id="keterangan" class="form-control" rows="2" placeholder="Contoh: Lupa absen karena mati lampu"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="foto_bukti" class="form-label">Upload Foto Bukti (Opsional)</label>
                        <input type="file" name="foto_bukti" id="foto_bukti" class="form-control" accept="image/*">
                    </div>
                    <div class="text-end">
                        <button type="submit" name="simpan_absensi" class="btn btn-primary">Simpan Absensi</button>
                    </div>
                </form>
            </div>

            <div class="tab-pane fade" id="auto" role="tabpanel">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle-fill"></i> Fitur ini digunakan untuk mengisi absensi secara otomatis berdasarkan jadwal. Cocok untuk hari libur nasional, rapat guru, atau kegiatan sekolah dimana guru dianggap hadir/libur.
                </div>
                <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Apakah Anda yakin ingin memproses absensi otomatis untuk guru yang dipilih?');">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Pilih Guru</label>
                        <div class="border p-3 rounded" style="max-height: 200px; overflow-y: auto;">
                            <div class="form-check mb-2 border-bottom pb-2">
                                <input class="form-check-input" type="checkbox" id="checkAll">
                                <label class="form-check-label fw-bold" for="checkAll">Pilih Semua Guru</label>
                            </div>
                            <?php while($g_auto = $guru_list_auto->fetch_assoc()): ?>
                                <div class="form-check">
                                    <input class="form-check-input guru-checkbox" type="checkbox" name="guru_ids[]" value="<?php echo $g_auto['id']; ?>" id="guru_<?php echo $g_auto['id']; ?>">
                                    <label class="form-check-label" for="guru_<?php echo $g_auto['id']; ?>">
                                        <?php echo htmlspecialchars($g_auto['nama_guru']); ?>
                                    </label>
                                </div>
                            <?php endwhile; ?>
                        </div>
                        <div class="form-text">Sistem akan mencari jadwal mengajar guru terpilih pada tanggal di bawah ini.</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="tanggal_otomatis" class="form-label fw-bold">Tanggal Absensi</label>
                            <input type="date" name="tanggal_otomatis" id="tanggal_otomatis" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="status_otomatis" class="form-label fw-bold">Status Kehadiran</label>
                            <select name="status_otomatis" id="status_otomatis" class="form-select" required>
                                <option value="Hadir" selected>Hadir (Kegiatan Sekolah)</option>
                                <option value="Libur">Libur (Tanggal Merah)</option>
                                <option value="Dinas Luar">Dinas Luar</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="keterangan_otomatis" class="form-label">Keterangan</label>
                        <textarea name="keterangan_otomatis" id="keterangan_otomatis" class="form-control" rows="2" required placeholder="Contoh: Libur Hari Raya Idul Fitri"></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="foto_bukti_otomatis" class="form-label">Bukti Pendukung (Surat Edaran/Foto)</label>
                        <input type="file" name="foto_bukti_otomatis" id="foto_bukti_otomatis" class="form-control" accept="image/*,application/pdf">
                    </div>

                    <div class="text-end">
                        <button type="submit" name="simpan_otomatis" class="btn btn-success">
                            <i class="bi bi-robot"></i> Proses Absensi Otomatis
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>

<?php
// Script Javascript
ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- LOGIKA TAB MANUAL ---
    const guruSelect = document.getElementById('guru_id');
    const tipeSelect = document.getElementById('tipe_absensi');
    const jadwalSelect = document.getElementById('jadwal_id');

    function fetchJadwal() {
        const guruId = guruSelect.value;
        const tipe = tipeSelect.value;

        if (guruId && tipe) {
            jadwalSelect.disabled = true;
            jadwalSelect.innerHTML = '<option value="">Memuat jadwal...</option>';

            fetch(`../api/get_jadwal_admin.php?guru_id=${guruId}&tipe=${tipe}`)
                .then(response => response.json())
                .then(data => {
                    jadwalSelect.innerHTML = '<option value="">-- Pilih Jadwal Spesifik --</option>';
                    if (data.status === 'success' && data.jadwal.length > 0) {
                        data.jadwal.forEach(item => {
                            const option = document.createElement('option');
                            option.value = item.id;
                            option.textContent = item.detail;
                            jadwalSelect.appendChild(option);
                        });
                        jadwalSelect.disabled = false;
                    } else {
                        jadwalSelect.innerHTML = '<option value="">-- Tidak ada jadwal ditemukan --</option>';
                    }
                })
                .catch(error => {
                    console.error('Error fetching schedule:', error);
                    jadwalSelect.innerHTML = '<option value="">-- Gagal memuat jadwal --</option>';
                });
        }
    }

    if(guruSelect && tipeSelect) {
        guruSelect.addEventListener('change', fetchJadwal);
        tipeSelect.addEventListener('change', fetchJadwal);
    }

    // --- LOGIKA TAB OTOMATIS (Select All) ---
    const checkAll = document.getElementById('checkAll');
    if(checkAll) {
        checkAll.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.guru-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });
    }
});
</script>
<?php
$custom_script = ob_get_clean();
include 'partials/footer.php'; 
?>