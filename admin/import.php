<?php include 'partials/header.php'; ?>

<h1 class="mb-4">Import Data dari Excel</h1>

<?php if(isset($_GET['status'])): ?>
    <div class="alert alert-<?php echo $_GET['status'] == 'sukses' ? 'success' : 'danger'; ?>">
        <?php echo htmlspecialchars(urldecode($_GET['pesan'])); ?>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">Import Data Guru</div>
            <div class="card-body">
                <p>Format file Excel (.xlsx):</p>
                <ul>
                    <li>Kolom A: NIP</li>
                    <li>Kolom B: Nama Guru</li>
                    <li>Kolom C: Kontak</li>
                </ul>
                <form action="proses_import.php" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="tipe_import" value="guru">
                    <div class="mb-3">
                        <label for="file_guru" class="form-label">Pilih File Excel</label>
                        <input class="form-control" type="file" name="file_excel" id="file_guru" required accept=".xlsx">
                    </div>
                    <button type="submit" class="btn btn-primary">Import Guru</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">Import Jadwal Mengajar</div>
            <div class="card-body">
                 <p>Format file Excel (.xlsx):</p>
                <ul>
                    <li>Kolom A: NIP Guru (harus sudah ada di database)</li>
                    <li>Kolom B: Hari (Senin, Selasa, dst.)</li>
                    <li>Kolom C: Jam Mulai (format HH:MM:SS)</li>
                    <li>Kolom D: Jam Selesai (format HH:MM:SS)</li>
                    <li>Kolom E: Mata Pelajaran</li>
                    <li>Kolom F: Kelas</li>
                </ul>
                <form action="proses_import.php" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="tipe_import" value="jadwal_mengajar">
                    <div class="mb-3">
                        <label for="file_mengajar" class="form-label">Pilih File Excel</label>
                        <input class="form-control" type="file" name="file_excel" id="file_mengajar" required accept=".xlsx">
                    </div>
                    <button type="submit" class="btn btn-success">Import Jadwal Mengajar</button>
                </form>
            </div>
        </div>
    </div>
    </div>

<?php include 'partials/footer.php'; ?>