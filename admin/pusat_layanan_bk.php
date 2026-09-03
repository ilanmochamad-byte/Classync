<?php 
// Aktifkan pelaporan error untuk memudahkan diagnosa
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'partials/header.php'; 

// --- FILTER BULAN & TAHUN ---
$bulan_pilih = isset($_GET['bulan']) ? (int)$_GET['bulan'] : date('n');
$tahun_pilih = isset($_GET['tahun']) ? (int)$_GET['tahun'] : date('Y');
$bulan_indo = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

try {
    // --- 1. QUERY JURNAL BK ---
    $sql_jurnal = "SELECT a.waktu_absensi, g.nama_guru, jbk.komponen_layanan, jbk.topik_tema, jbk.sasaran_layanan, jbk.waktu
                   FROM absensi a
                   JOIN guru g ON a.guru_id = g.id
                   JOIN jurnal_bk jbk ON a.id = jbk.absensi_guru_id
                   WHERE a.tipe_absensi = 'bimbingan' AND MONTH(a.waktu_absensi) = ? AND YEAR(a.waktu_absensi) = ?
                   ORDER BY a.waktu_absensi DESC";
    $stmt_jurnal = $conn->prepare($sql_jurnal);
    $stmt_jurnal->bind_param("ii", $bulan_pilih, $tahun_pilih);
    $stmt_jurnal->execute();
    $data_jurnal = $stmt_jurnal->get_result();

    // --- 2. QUERY KONSELING INDIVIDU ---
    $sql_individu = "SELECT a.waktu_absensi, g.nama_guru, ki.nama_konseli, ki.kelas_konseli, ki.deskripsi_masalah, ki.hasil_konseling, ki.rtl_konselor
                     FROM konseling_individu ki
                     JOIN absensi a ON ki.jurnal_bk_id = a.id
                     JOIN guru g ON a.guru_id = g.id
                     WHERE MONTH(a.waktu_absensi) = ? AND YEAR(a.waktu_absensi) = ?
                     ORDER BY a.waktu_absensi DESC";
    $stmt_ind = $conn->prepare($sql_individu);
    $stmt_ind->bind_param("ii", $bulan_pilih, $tahun_pilih);
    $stmt_ind->execute();
    $data_individu = $stmt_ind->get_result();

    // --- 3. QUERY KONSELING KELOMPOK ---
    $sql_kelompok = "SELECT a.waktu_absensi, g.nama_guru, kk.nama_kegiatan, kk.anggota_kelompok, kk.hasil_kegiatan, kk.rtl_konselor
                     FROM konseling_kelompok kk
                     JOIN absensi a ON kk.jurnal_bk_id = a.id
                     JOIN guru g ON a.guru_id = g.id
                     WHERE MONTH(a.waktu_absensi) = ? AND YEAR(a.waktu_absensi) = ?
                     ORDER BY a.waktu_absensi DESC";
    $stmt_kel = $conn->prepare($sql_kelompok);
    $stmt_kel->bind_param("ii", $bulan_pilih, $tahun_pilih);
    $stmt_kel->execute();
    $data_kelompok = $stmt_kel->get_result();

    // --- 4. QUERY DIREKTORI SISWA ---
    $sql_siswa = "SELECT s.nisn, s.nama_siswa, s.kelas, s.jenis_kelamin, s.kontak_ortu,
                         p.siswa_id AS profil_id, p.*
                  FROM siswa s
                  LEFT JOIN profil_bk_siswa p ON s.id = p.siswa_id
                  ORDER BY s.kelas ASC, s.nama_siswa ASC";
    $data_siswa = $conn->query($sql_siswa);

} catch (Exception $e) {
    echo "<div class='alert alert-danger mt-4'><h4><i class='bi bi-exclamation-triangle'></i> Terjadi Kesalahan Database</h4><p><code>" . $e->getMessage() . "</code></p></div>";
    include 'partials/footer.php';
    exit;
}
?>

<style>
    .nav-tabs .nav-link { color: #6c757d; font-weight: 500; border: none; padding: 12px 20px; }
    .nav-tabs .nav-link.active { color: #FF6B00; border-bottom: 3px solid #FF6B00; background-color: transparent; }
    .nav-tabs .nav-link:hover { color: #FF6B00; }
    .table-custom th { background-color: #f8f9fa; color: #2c3e50; font-weight: 600; }
    .card-bk { border: none; border-radius: 15px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    .btn-buku-detail { background-color: #f8f9fa; border: 1px solid #dee2e6; color: #4A90A4; font-weight: 600; transition: all 0.2s;}
    .btn-buku-detail:hover { background-color: #4A90A4; color: white; border-color: #4A90A4;}
    
    /* Style untuk Modal Buku Pribadi */
    .buku-section-title { font-weight: 700; color: #FF6B00; border-bottom: 2px solid #f1f2f6; padding-bottom: 8px; margin-top: 20px; margin-bottom: 15px; }
    .buku-label { font-size: 0.85rem; color: #6c757d; font-weight: 600; margin-bottom: 2px; }
    .buku-value { font-size: 0.95rem; color: #2c3e50; margin-bottom: 12px; }
</style>

<div class="mb-4">
    <h2 class="fw-bold text-dark"><i class="bi bi-shield-check text-primary"></i> Pusat Data Layanan BK</h2>
    <p class="text-muted">Manajemen terpadu administrasi Bimbingan dan Konseling SMK Terpadu Al Hasan.</p>
</div>

<div class="card card-bk mb-4">
    <div class="card-body p-3">
        <form method="GET" action="pusat_layanan_bk.php" class="row g-3 align-items-center">
            <div class="col-auto"><label class="col-form-label fw-bold">Periode Laporan:</label></div>
            <div class="col-auto">
                <select name="bulan" class="form-select">
                    <?php for($i=1; $i<=12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo ($i == $bulan_pilih) ? 'selected' : ''; ?>><?php echo $bulan_indo[$i]; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="tahun" class="form-select">
                    <?php for($i = date('Y'); $i >= date('Y') - 1; $i--): ?>
                        <option value="<?php echo $i; ?>" <?php echo ($i == $tahun_pilih) ? 'selected' : ''; ?>><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Tampilkan</button>
            </div>
        </form>
    </div>
</div>

<div class="card card-bk mb-5">
    <div class="card-header bg-white pt-3 border-bottom-0">
        <ul class="nav nav-tabs" id="bkTabs" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#jurnal" type="button" role="tab">Jurnal Harian (RPL)</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#individu" type="button" role="tab">Konseling Individu</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#kelompok" type="button" role="tab">Konseling Kelompok</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#buku" type="button" role="tab">Buku Pribadi</button></li>
        </ul>
    </div>
    
    <div class="card-body p-0">
        <div class="tab-content" id="bkTabsContent">
            
            <div class="tab-pane fade show active p-3" id="jurnal" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover table-custom align-middle">
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Konselor</th>
                                <th>Topik Layanan</th>
                                <th>Sasaran</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($data_jurnal->num_rows > 0): while($row = $data_jurnal->fetch_assoc()): ?>
                            <tr>
                                <td class="small"><strong><?php echo date('d M Y', strtotime($row['waktu_absensi'])); ?></strong><br><?php echo date('H:i', strtotime($row['waktu_absensi'])); ?></td>
                                <td class="fw-bold"><?php echo htmlspecialchars($row['nama_guru']); ?></td>
                                <td><span class="badge bg-info text-dark mb-1"><?php echo htmlspecialchars($row['komponen_layanan']); ?></span><br><?php echo htmlspecialchars($row['topik_tema']); ?></td>
                                <td><?php echo htmlspecialchars($row['sasaran_layanan']); ?></td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">Belum ada data jurnal.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="tab-pane fade p-3" id="individu" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover table-custom align-middle">
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Siswa (Konseli)</th>
                                <th>Konselor</th>
                                <th>Hasil & RTL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($data_individu->num_rows > 0): while($row = $data_individu->fetch_assoc()): ?>
                            <tr>
                                <td class="small"><?php echo date('d/m/Y', strtotime($row['waktu_absensi'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($row['nama_konseli']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($row['kelas_konseli']); ?></small></td>
                                <td><?php echo htmlspecialchars($row['nama_guru']); ?></td>
                                <td><div class="bg-light p-2 rounded small"><strong>Hasil:</strong> <?php echo htmlspecialchars($row['hasil_konseling']); ?><br><strong class="text-success">RTL:</strong> <?php echo htmlspecialchars($row['rtl_konselor']); ?></div></td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">Belum ada data.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="tab-pane fade p-3" id="kelompok" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover table-custom align-middle">
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Nama Kegiatan</th>
                                <th>Anggota</th>
                                <th>Hasil & RTL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($data_kelompok->num_rows > 0): while($row = $data_kelompok->fetch_assoc()): ?>
                            <tr>
                                <td class="small"><?php echo date('d/m/Y', strtotime($row['waktu_absensi'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($row['nama_kegiatan']); ?></strong><br><small class="text-muted">Oleh: <?php echo htmlspecialchars($row['nama_guru']); ?></small></td>
                                <td class="small"><?php echo htmlspecialchars($row['anggota_kelompok']); ?></td>
                                <td><div class="bg-light p-2 rounded small"><strong>Hasil:</strong> <?php echo htmlspecialchars($row['hasil_kegiatan']); ?><br><strong class="text-success">RTL:</strong> <?php echo htmlspecialchars($row['rtl_konselor']); ?></div></td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">Belum ada data.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="tab-pane fade p-3" id="buku" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover table-custom align-middle">
                        <thead>
                            <tr>
                                <th>Nama Siswa</th>
                                <th>Kelas</th>
                                <th>Status Profil BK</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($data_siswa && $data_siswa->num_rows > 0): while($row = $data_siswa->fetch_assoc()): ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($row['nama_siswa']); ?></td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($row['kelas']); ?></span></td>
                                <td>
                                    <?php if($row['profil_id']): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="bi bi-check-circle"></i> Sudah Diisi</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning bg-opacity-10 text-warning border border-warning"><i class="bi bi-exclamation-circle"></i> Belum Lengkap</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-buku-detail rounded-pill px-3"
                                            data-status="<?php echo $row['profil_id'] ? '1' : '0'; ?>"
                                            data-nama="<?php echo htmlspecialchars($row['nama_siswa']); ?>"
                                            data-nisn="<?php echo htmlspecialchars($row['nisn']); ?>"
                                            data-kelas="<?php echo htmlspecialchars($row['kelas']); ?>"
                                            data-jk="<?php echo htmlspecialchars($row['jenis_kelamin'] ?? '-'); ?>"
                                            data-kontak="<?php echo htmlspecialchars($row['kontak_ortu'] ?? '-'); ?>"
                                            
                                            data-ayah="<?php echo htmlspecialchars($row['nama_ayah'] ?? '-'); ?>"
                                            data-kerjaayah="<?php echo htmlspecialchars($row['pekerjaan_ayah'] ?? '-'); ?>"
                                            data-ibu="<?php echo htmlspecialchars($row['nama_ibu'] ?? '-'); ?>"
                                            data-kerjaibu="<?php echo htmlspecialchars($row['pekerjaan_ibu'] ?? '-'); ?>"
                                            data-saudara="<?php echo htmlspecialchars($row['jumlah_saudara'] ?? '-'); ?>"
                                            data-anakke="<?php echo htmlspecialchars($row['anak_ke'] ?? '-'); ?>"
                                            data-kondisikel="<?php echo htmlspecialchars($row['kondisi_keluarga'] ?? '-'); ?>"
                                            
                                            data-sd="<?php echo htmlspecialchars($row['riwayat_sd'] ?? '-'); ?>"
                                            data-smp="<?php echo htmlspecialchars($row['riwayat_smp'] ?? '-'); ?>"
                                            data-prestasiak="<?php echo htmlspecialchars($row['prestasi_akademik'] ?? '-'); ?>"
                                            data-prestasinon="<?php echo htmlspecialchars($row['prestasi_non_akademik'] ?? '-'); ?>"
                                            
                                            data-sehat="<?php echo htmlspecialchars($row['kesehatan'] ?? '-'); ?>"
                                            data-kebiasaan="<?php echo htmlspecialchars($row['kebiasaan'] ?? '-'); ?>"
                                            data-kelebihan="<?php echo htmlspecialchars($row['kelebihan'] ?? '-'); ?>"
                                            data-kekurangan="<?php echo htmlspecialchars($row['kekurangan'] ?? '-'); ?>"
                                            data-teman="<?php echo htmlspecialchars($row['hubungan_teman'] ?? '-'); ?>"
                                            data-organisasi="<?php echo htmlspecialchars($row['organisasi'] ?? '-'); ?>"
                                            data-pergaulan="<?php echo htmlspecialchars($row['pergaulan'] ?? '-'); ?>"
                                            
                                            data-mapelfav="<?php echo htmlspecialchars($row['mapel_favorit'] ?? '-'); ?>"
                                            data-mapelsulit="<?php echo htmlspecialchars($row['mapel_sulit'] ?? '-'); ?>"
                                            data-gayabelajar="<?php echo htmlspecialchars($row['gaya_belajar'] ?? '-'); ?>"
                                            data-motivasi="<?php echo htmlspecialchars($row['motivasi'] ?? '-'); ?>"
                                            data-cita="<?php echo htmlspecialchars($row['cita_cita'] ?? '-'); ?>"
                                            data-minatkarir="<?php echo htmlspecialchars($row['minat_karir'] ?? '-'); ?>"
                                            data-lulus="<?php echo htmlspecialchars($row['rencana_lulus'] ?? '-'); ?>"
                                            data-perkembangan="<?php echo htmlspecialchars($row['catatan_perkembangan'] ?? '-'); ?>"
                                            
                                            data-bs-toggle="modal" data-bs-target="#modalBukuPribadi">
                                        <i class="bi bi-eye"></i> Buka Buku
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="modalBukuPribadi" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="bi bi-person-vcard"></i> Buku Pribadi Siswa</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body bg-light px-4">
        
        <div id="alertBukuKosong" class="alert alert-warning mb-4" style="display: none;">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <strong>Perhatian:</strong> Data profil lengkap anak ini belum diisi oleh Guru BK.
        </div>

        <div class="buku-section-title"><i class="bi bi-info-circle me-2"></i>A. Identitas Utama</div>
        <div class="row bg-white p-3 rounded shadow-sm mx-0 mb-4">
            <div class="col-md-6">
                <div class="buku-label">Nama Lengkap</div><div class="buku-value fw-bold text-primary" id="bp_nama"></div>
                <div class="buku-label">NISN</div><div class="buku-value" id="bp_nisn"></div>
                <div class="buku-label">Kelas</div><div class="buku-value" id="bp_kelas"></div>
            </div>
            <div class="col-md-6">
                <div class="buku-label">Jenis Kelamin</div><div class="buku-value" id="bp_jk"></div>
                <div class="buku-label">Kontak / No. HP</div><div class="buku-value" id="bp_kontak"></div>
            </div>
        </div>

        <div id="containerProfilLengkap">
            
            <div class="buku-section-title"><i class="bi bi-people me-2"></i>B. Data Keluarga</div>
            <div class="row bg-white p-3 rounded shadow-sm mx-0 mb-4">
                <div class="col-md-6">
                    <div class="buku-label">Nama Ayah</div><div class="buku-value" id="bp_ayah"></div>
                    <div class="buku-label">Pekerjaan Ayah</div><div class="buku-value" id="bp_kerjaayah"></div>
                    <div class="buku-label">Kondisi Keluarga</div><div class="buku-value" id="bp_kondisikel"></div>
                </div>
                <div class="col-md-6">
                    <div class="buku-label">Nama Ibu</div><div class="buku-value" id="bp_ibu"></div>
                    <div class="buku-label">Pekerjaan Ibu</div><div class="buku-value" id="bp_kerjaibu"></div>
                    <div class="buku-label">Urutan Anak</div><div class="buku-value">Anak ke-<span id="bp_anakke"></span> dari <span id="bp_saudara"></span> bersaudara</div>
                </div>
            </div>

            <div class="buku-section-title"><i class="bi bi-mortarboard me-2"></i>C. Riwayat Pendidikan & Prestasi</div>
            <div class="row bg-white p-3 rounded shadow-sm mx-0 mb-4">
                <div class="col-md-6">
                    <div class="buku-label">Asal SD/MI</div><div class="buku-value" id="bp_sd"></div>
                    <div class="buku-label">Asal SMP/MTs</div><div class="buku-value" id="bp_smp"></div>
                </div>
                <div class="col-md-6">
                    <div class="buku-label">Prestasi Akademik</div><div class="buku-value" id="bp_prestasiak"></div>
                    <div class="buku-label">Prestasi Non-Akademik</div><div class="buku-value" id="bp_prestasinon"></div>
                </div>
            </div>

            <div class="buku-section-title"><i class="bi bi-heart-pulse me-2"></i>D. Kondisi Pribadi & Sosial</div>
            <div class="row bg-white p-3 rounded shadow-sm mx-0 mb-4">
                <div class="col-md-6">
                    <div class="buku-label">Kesehatan / Riwayat Sakit</div><div class="buku-value" id="bp_sehat"></div>
                    <div class="buku-label">Kelebihan Diri</div><div class="buku-value" id="bp_kelebihan"></div>
                    <div class="buku-label">Kekurangan Diri</div><div class="buku-value" id="bp_kekurangan"></div>
                    <div class="buku-label">Kebiasaan</div><div class="buku-value" id="bp_kebiasaan"></div>
                </div>
                <div class="col-md-6">
                    <div class="buku-label">Hubungan Sosial & Teman</div><div class="buku-value" id="bp_teman"></div>
                    <div class="buku-label">Organisasi yang Diikuti</div><div class="buku-value" id="bp_organisasi"></div>
                    <div class="buku-label">Lingkungan Pergaulan</div><div class="buku-value" id="bp_pergaulan"></div>
                </div>
            </div>

            <div class="buku-section-title"><i class="bi bi-compass me-2"></i>E. Data Belajar & Karir</div>
            <div class="row bg-white p-3 rounded shadow-sm mx-0 mb-4">
                <div class="col-md-6">
                    <div class="buku-label">Mata Pelajaran Favorit</div><div class="buku-value" id="bp_mapelfav"></div>
                    <div class="buku-label">Mata Pelajaran Tersulit</div><div class="buku-value" id="bp_mapelsulit"></div>
                    <div class="buku-label">Gaya Belajar</div><div class="buku-value" id="bp_gayabelajar"></div>
                    <div class="buku-label">Motivasi Belajar</div><div class="buku-value" id="bp_motivasi"></div>
                </div>
                <div class="col-md-6">
                    <div class="buku-label">Cita-cita / Impian</div><div class="buku-value" id="bp_cita"></div>
                    <div class="buku-label">Minat Karir</div><div class="buku-value" id="bp_minatkarir"></div>
                    <div class="buku-label">Rencana Setelah Lulus</div><div class="buku-value" id="bp_lulus"></div>
                </div>
            </div>

            <div class="buku-section-title"><i class="bi bi-journal-text me-2"></i>F. Catatan Perkembangan</div>
            <div class="bg-white p-3 rounded shadow-sm mx-0 mb-2 border-start border-warning border-4">
                <p id="bp_perkembangan" class="text-muted mb-0" style="white-space: pre-wrap; font-style: italic;"></p>
            </div>
            
        </div> </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup Buku</button>
      </div>
    </div>
  </div>
</div>

<div class="mb-5 pb-5"></div>

<?php ob_start(); ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnsBuku = document.querySelectorAll('.btn-buku-detail');
    
    btnsBuku.forEach(btn => {
        btn.addEventListener('click', function() {
            // Cek Status 
            const statusProfil = this.getAttribute('data-status');
            const alertKosong = document.getElementById('alertBukuKosong');
            const containerProfil = document.getElementById('containerProfilLengkap');
            
            if(statusProfil === '0') {
                alertKosong.style.display = 'block';
                containerProfil.style.display = 'none';
            } else {
                alertKosong.style.display = 'none';
                containerProfil.style.display = 'block';
            }

            // A. Identitas
            document.getElementById('bp_nama').textContent = this.getAttribute('data-nama');
            document.getElementById('bp_nisn').textContent = this.getAttribute('data-nisn');
            document.getElementById('bp_kelas').textContent = this.getAttribute('data-kelas');
            document.getElementById('bp_jk').textContent = this.getAttribute('data-jk');
            document.getElementById('bp_kontak').textContent = this.getAttribute('data-kontak');

            // B. Keluarga
            document.getElementById('bp_ayah').textContent = this.getAttribute('data-ayah');
            document.getElementById('bp_kerjaayah').textContent = this.getAttribute('data-kerjaayah');
            document.getElementById('bp_ibu').textContent = this.getAttribute('data-ibu');
            document.getElementById('bp_kerjaibu').textContent = this.getAttribute('data-kerjaibu');
            document.getElementById('bp_saudara').textContent = this.getAttribute('data-saudara');
            document.getElementById('bp_anakke').textContent = this.getAttribute('data-anakke');
            document.getElementById('bp_kondisikel').textContent = this.getAttribute('data-kondisikel');

            // C. Pendidikan
            document.getElementById('bp_sd').textContent = this.getAttribute('data-sd');
            document.getElementById('bp_smp').textContent = this.getAttribute('data-smp');
            document.getElementById('bp_prestasiak').textContent = this.getAttribute('data-prestasiak');
            document.getElementById('bp_prestasinon').textContent = this.getAttribute('data-prestasinon');

            // D. Pribadi & Sosial
            document.getElementById('bp_sehat').textContent = this.getAttribute('data-sehat');
            document.getElementById('bp_kebiasaan').textContent = this.getAttribute('data-kebiasaan');
            document.getElementById('bp_kelebihan').textContent = this.getAttribute('data-kelebihan');
            document.getElementById('bp_kekurangan').textContent = this.getAttribute('data-kekurangan');
            document.getElementById('bp_teman').textContent = this.getAttribute('data-teman');
            document.getElementById('bp_organisasi').textContent = this.getAttribute('data-organisasi');
            document.getElementById('bp_pergaulan').textContent = this.getAttribute('data-pergaulan');

            // E. Belajar & Karir
            document.getElementById('bp_mapelfav').textContent = this.getAttribute('data-mapelfav');
            document.getElementById('bp_mapelsulit').textContent = this.getAttribute('data-mapelsulit');
            document.getElementById('bp_gayabelajar').textContent = this.getAttribute('data-gayabelajar');
            document.getElementById('bp_motivasi').textContent = this.getAttribute('data-motivasi');
            document.getElementById('bp_cita').textContent = this.getAttribute('data-cita');
            document.getElementById('bp_minatkarir').textContent = this.getAttribute('data-minatkarir');
            document.getElementById('bp_lulus').textContent = this.getAttribute('data-lulus');

            // F. Catatan Perkembangan
            document.getElementById('bp_perkembangan').textContent = this.getAttribute('data-perkembangan');
        });
    });
});
</script>
<?php 
$custom_script = ob_get_clean();
include 'partials/footer.php'; 
?>