<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tes Isolasi Modal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<div class="container mt-5">
    <h1>Tes Fungsi Tombol Edit dan Modal</h1>
    <p>Ini adalah tes sederhana untuk memastikan JavaScript bisa membaca data dari tombol.</p>

    <button class="btn btn-warning" 
            data-bs-toggle="modal" 
            data-bs-target="#tesModal"
            data-guru_id="99"
            data-nama_guru="Guru Uji Coba">
        Klik Tombol Tes Ini
    </button>
</div>

<div class="modal fade" id="tesModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="tesModalLabel">Modal Tes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
          <p>Data yang diterima dari tombol:</p>
          <div id="hasil-tes">Memuat...</div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tesModal = document.getElementById('tesModal');
    if (tesModal) {
        tesModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const hasilDiv = document.getElementById('hasil-tes');

            // Mencoba membaca data-guru_id dari tombol
            const guruId = button.getAttribute('data-guru_id');
            const namaGuru = button.getAttribute('data-nama_guru');

            // Menampilkan hasilnya di dalam modal
            let hasilText = "Nama Guru: " + namaGuru + "<br>";
            hasilText += "ID Guru: " + guruId;
            hasilDiv.innerHTML = hasilText;

            // Memunculkan alert untuk konfirmasi
            alert('ID Guru yang didapat adalah: ' + guruId);
        });
    }
});
</script>

</body>
</html>