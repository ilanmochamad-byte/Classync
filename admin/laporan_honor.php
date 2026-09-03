<?php
// Error hanya dicatat ke log, tidak ditampilkan di halaman
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include 'partials/header.php';

// Panggil Helper Finansial (keuangan_helper.php ada di folder admin/ yang sama)
require_once __DIR__ . '/keuangan_helper.php';

// Ambil Tarif Dinamis dari Database
$tarif = getPengaturanHonor($conn);

// Default filter
$filter_bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$filter_tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');

// Ambil daftar guru lalu pre-load semua rincian honor sekaligus (batch — 5 query total)
$guru_list_result = $conn->query("SELECT id, nama_guru FROM guru ORDER BY nama_guru ASC");
$guru_list = [];
$guru_ids  = [];
while ($g = $guru_list_result->fetch_assoc()) {
    $guru_list[] = $g;
    $guru_ids[]  = (int)$g['id'];
}
$semua_rincian = hitungHonorBulanBatch($conn, $guru_ids, $filter_bulan, $filter_tahun, $tarif);
?>

<h1 class="mb-4">Laporan Honor Guru</h1>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-center">
            <div class="col-md-5">
                <label for="bulan" class="form-label">Pilih Periode</label>
                <select name="bulan" id="bulan" class="form-select">
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php if($filter_bulan == $i) echo 'selected'; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $i, 10)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-5">
                 <label for="tahun" class="form-label">&nbsp;</label>
                 <input type="number" class="form-control" id="tahun" name="tahun" value="<?php echo $filter_tahun; ?>">
            </div>
            <div class="col-md-2">
                 <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">Tampilkan</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped" style="white-space: nowrap;">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2" class="align-middle text-center">No.</th>
                        <th rowspan="2" class="align-middle">Nama Guru</th>
                        <th colspan="7" class="text-center">Pendapatan</th> 
                        <th colspan="2" class="text-center">Potongan</th>
                        <th rowspan="2" class="align-middle text-center">Total Diterima</th>
                    </tr>
                    <tr>
                        <th class="text-center">Tunjangan Tetap</th>
                        <th class="text-center">Honor Mengajar</th>
                        <th class="text-center">Honor Piket</th>
                        <th class="text-center">Honor Ekskul</th>
                        <th class="text-center">Honor BK</th> 
                        <th class="text-success text-center">Uang Transportasi</th> 
                        <th class="text-center">Subtotal Pendapatan</th>
                        <th class="text-center text-danger">Arisan</th>
                        <th class="text-center text-danger">Tabungan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $nomor = 1;
                    $grand_total = 0;

                    foreach ($guru_list as $guru):
                        $rincian = $semua_rincian[$guru['id']] ?? [];
                        $grand_total += $rincian['total_diterima'] ?? 0;
                    ?>
                    <tr>
                        <td class="text-center"><?php echo $nomor++; ?></td>
                        <td><strong><?php echo htmlspecialchars($guru['nama_guru']); ?></strong></td>
                        <td class="text-end">Rp <?php echo number_format($rincian['total_tunjangan'] ?? 0, 0, ',', '.'); ?></td>
                        <td class="text-end">Rp <?php echo number_format($rincian['honor_mengajar'] ?? 0, 0, ',', '.'); ?> <br><small class="text-muted">(<?php echo $rincian['total_jp'] ?? 0; ?> JP)</small></td>
                        <td class="text-end">Rp <?php echo number_format($rincian['honor_piket'] ?? 0, 0, ',', '.'); ?> <br><small class="text-muted">(<?php echo $rincian['jumlah_piket'] ?? 0; ?>x)</small></td>
                        <td class="text-end">Rp <?php echo number_format($rincian['honor_ekskul'] ?? 0, 0, ',', '.'); ?> <br><small class="text-muted">(<?php echo $rincian['jumlah_ekskul'] ?? 0; ?>x)</small></td>
                        <td class="text-end">Rp <?php echo number_format($rincian['honor_bk'] ?? 0, 0, ',', '.'); ?> <br><small class="text-muted">(<?php echo $rincian['jumlah_bk'] ?? 0; ?>x)</small></td> 
                        
                        <td class="text-end text-success fw-bold">+ Rp <?php echo number_format($rincian['uang_transport'] ?? 0, 0, ',', '.'); ?></td> 

                        <td class="text-end bg-light"><strong>Rp <?php echo number_format($rincian['subtotal_pendapatan'] ?? 0, 0, ',', '.'); ?></strong></td>
                        <td class="text-end text-danger">- Rp <?php echo number_format($rincian['potongan_arisan'] ?? 0, 0, ',', '.'); ?></td>
                        <td class="text-end text-danger">- Rp <?php echo number_format($rincian['potongan_tabungan'] ?? 0, 0, ',', '.'); ?></td>
                        <td class="text-end table-success fs-6"><strong>Rp <?php echo number_format($rincian['total_diterima'] ?? 0, 0, ',', '.'); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-dark">
                    <tr>
                        <td colspan="11" class="text-end fw-bold">TOTAL PENGELUARAN YAYASAN BULAN INI:</td>
                        <td class="text-end fw-bold text-success fs-5">Rp <?php echo number_format($grand_total, 0, ',', '.'); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php include 'partials/footer.php'; ?>