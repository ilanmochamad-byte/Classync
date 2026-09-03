<?php
require 'includes/db.php';
include 'includes/header.php';

// Ambil daftar guru
$guru_list = $conn->query("SELECT id, nama_guru FROM guru ORDER BY nama_guru ASC");

// Dapatkan hari dan jam sekarang
$hari_ini = getNamaHariIndonesia(date('l')); // 'Senin', 'Selasa', ...
$jam_sekarang = date('H:i:s');
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">Form Absensi Mengajar</h4>
            </div>
            <div class="card-body">
                <?php if(isset($_GET['status'])): ?>
                    <div class="alert alert-<?php echo $_GET['status'] == 'sukses' ? 'success' : 'danger'; ?>">
                        <?php echo htmlspecialchars($_GET['pesan']); ?>
                    </div>
                <?php endif; ?>

                <p>Hari: <strong><?php echo $hari_ini; ?></strong>, Jam: <strong><?php echo $jam_sekarang; ?></strong></p>
                
                <form action="proses_absen.php" method="POST">
                    <input type="hidden" name="tipe_absensi" value="mengajar">
                    
                    <div class="mb-3">
                        <label for="guru_id" class="form-label">Pilih Nama Anda</label>
                        <select class="form-select" id="guru_id" name="guru_id" required>
                            <option value="">-- Pilih Guru --</option>
                            <?php while($guru = $guru_list->fetch_assoc()): ?>
                                <option value="<?php echo $guru['id']; ?>"><?php echo htmlspecialchars($guru['nama_guru']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div id="info-jadwal" class="mb-3 p-3 bg-light rounded border" style="display:none;">
                        <p>Memuat jadwal...</p>
                    </div>

                    <div class="d-grid">
                        <button type="submit" name="submit" id="tombol-absen" class="btn btn-primary btn-lg" disabled>Konfirmasi Kehadiran</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('guru_id').addEventListener('change', function() {
    const guruId = this.value;
    const infoJadwalDiv = document.getElementById('info-jadwal');
    const tombolAbsen = document.getElementById('tombol-absen');

    if (guruId) {
        infoJadwalDiv.style.display = 'block';
        infoJadwalDiv.innerHTML = '<p>Mencari jadwal Anda...</p>';
        tombolAbsen.disabled = true;

        // Gunakan Fetch API untuk mendapatkan jadwal secara dinamis
        fetch(`proses_absen.php?action=cek_jadwal&tipe=mengajar&guru_id=${guruId}`)
            .then(response => response.json())
            .then(data => {
                if (data.status === 'sukses') {
                    infoJadwalDiv.innerHTML = `
                        <h5>Jadwal Ditemukan:</h5>
                        <p><strong>Mata Pelajaran:</strong> ${data.jadwal.mata_pelajaran}</p>
                        <p><strong>Kelas:</strong> ${data.jadwal.kelas}</p>
                        <p><strong>Jam:</strong> ${data.jadwal.jam_mulai} - ${data.jadwal.jam_selesai}</p>
                    `;
                    tombolAbsen.disabled = false;
                } else {
                    infoJadwalDiv.innerHTML = `<p class="text-danger">${data.pesan}</p>`;
                    tombolAbsen.disabled = true;
                }
            })
            .catch(error => {
                infoJadwalDiv.innerHTML = '<p class="text-danger">Terjadi kesalahan saat mengambil data jadwal.</p>';
                console.error('Error:', error);
            });
    } else {
        infoJadwalDiv.style.display = 'none';
        tombolAbsen.disabled = true;
    }
});
</script>

<?php include 'includes/footer.php'; ?>