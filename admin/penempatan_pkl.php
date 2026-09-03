<?php 
include 'partials/header.php'; 

// --- LOGIKA SIMPAN LOKASI PKL ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_lokasi'])) {
    $nama_instansi = $_POST['nama_instansi'];
    $alamat = $_POST['alamat'];
    $latitude = $_POST['latitude'];
    $longitude = $_POST['longitude'];
    $radius = (int)$_POST['radius'];
    
    if (isset($_POST['id']) && !empty($_POST['id'])) { 
        $stmt = $conn->prepare("UPDATE lokasi_pkl SET nama_instansi=?, alamat=?, latitude=?, longitude=?, radius=? WHERE id=?");
        $stmt->bind_param("ssssii", $nama_instansi, $alamat, $latitude, $longitude, $radius, $_POST['id']);
    } else { 
        $stmt = $conn->prepare("INSERT INTO lokasi_pkl (nama_instansi, alamat, latitude, longitude, radius) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssi", $nama_instansi, $alamat, $latitude, $longitude, $radius);
    }
    
    if($stmt->execute()) {
        $pesan = "Data Lokasi PKL berhasil disimpan.";
        $pesan_tipe = "success";
    } else {
        $pesan = "Gagal menyimpan lokasi: " . $stmt->error;
        $pesan_tipe = "danger";
    }
}

// --- LOGIKA SIMPAN PENEMPATAN SISWA ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_penempatan'])) {
    $lokasi_id = $_POST['lokasi_id'];
    $siswa_ids = $_POST['siswa_ids'] ?? [];
    
    $conn->query("DELETE FROM penempatan_pkl WHERE lokasi_id = $lokasi_id");
    
    if(!empty($siswa_ids)) {
        $stmt = $conn->prepare("INSERT IGNORE INTO penempatan_pkl (siswa_id, lokasi_id) VALUES (?, ?)");
        foreach($siswa_ids as $sid) {
            $stmt->bind_param("ii", $sid, $lokasi_id);
            $stmt->execute();
        }
    }
    $pesan = "Penempatan siswa berhasil diperbarui.";
    $pesan_tipe = "success";
}

// --- LOGIKA HAPUS LOKASI ---
if (isset($_GET['hapus'])) {
    $id = (int)$_GET['hapus'];
    $conn->query("DELETE FROM lokasi_pkl WHERE id = $id");
    header("Location: penempatan_pkl.php");
    exit;
}

$list_lokasi = $conn->query("SELECT * FROM lokasi_pkl ORDER BY nama_instansi ASC");

$siswa_list = $conn->query("SELECT id, nisn, nama_siswa, kelas FROM siswa WHERE kelas = '12-DKV' ORDER BY kelas, nama_siswa ASC");
$semua_siswa = [];
while($s = $siswa_list->fetch_assoc()) { $semua_siswa[] = $s; }

$penempatan_q = $conn->query("SELECT siswa_id, lokasi_id FROM penempatan_pkl");
$map_penempatan = [];
while($p = $penempatan_q->fetch_assoc()) {
    $map_penempatan[$p['lokasi_id']][] = $p['siswa_id'];
}
?>

<style>
    /* Paksa kotak saran Google Maps tampil paling depan (di atas Modal Bootstrap) */
    .pac-container {
        z-index: 100000 !important;
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold text-dark m-0"><i class="bi bi-buildings text-primary me-2"></i> Penempatan PKL</h2>
    <button class="btn btn-primary rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#lokasiModal">
        <i class="bi bi-plus-circle"></i> Tambah Lokasi PKL
    </button>
</div>

<?php if(isset($pesan)): ?>
    <div class="alert alert-<?php echo $pesan_tipe; ?> border-0 shadow-sm rounded-4" role="alert">
        <i class="bi <?php echo $pesan_tipe == 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> me-2"></i> <?php echo $pesan; ?>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-muted">
                    <tr>
                        <th class="ps-4">No.</th>
                        <th>Nama Instansi / Perusahaan</th>
                        <th>Kordinat Geofencing</th>
                        <th class="text-center">Jumlah Siswa</th>
                        <th class="text-center pe-4">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; while($lok = $list_lokasi->fetch_assoc()): 
                        $jml_siswa = isset($map_penempatan[$lok['id']]) ? count($map_penempatan[$lok['id']]) : 0;
                    ?>
                    <tr>
                        <td class="ps-4"><?php echo $no++; ?></td>
                        <td>
                            <strong class="text-dark d-block"><?php echo htmlspecialchars($lok['nama_instansi']); ?></strong>
                            <small class="text-muted text-truncate d-inline-block" style="max-width: 250px;"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($lok['alamat']); ?></small>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border"><i class="bi bi-crosshair text-danger"></i> Lat: <?php echo $lok['latitude']; ?></span><br>
                            <span class="badge bg-light text-dark border mt-1"><i class="bi bi-crosshair text-danger"></i> Lng: <?php echo $lok['longitude']; ?></span><br>
                            <small class="text-success fw-bold"><i class="bi bi-radar"></i> Radius: <?php echo $lok['radius']; ?>m</small>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-primary rounded-pill fs-6"><?php echo $jml_siswa; ?> Siswa</span>
                        </td>
                        <td class="text-center pe-4">
                            <button class="btn btn-sm btn-info text-white rounded-pill px-3 me-1" 
                                    data-bs-toggle="modal" data-bs-target="#penempatanModal_<?php echo $lok['id']; ?>">
                                <i class="bi bi-people-fill"></i> Kelola Siswa
                            </button>
                            <a href="penempatan_pkl.php?hapus=<?php echo $lok['id']; ?>" class="btn btn-sm btn-outline-danger rounded-circle" onclick="return confirm('Hapus lokasi PKL ini beserta data penempatannya?')"><i class="bi bi-trash"></i></a>
                        </td>
                    </tr>

                    <div class="modal fade" id="penempatanModal_<?php echo $lok['id']; ?>" tabindex="-1">
                      <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content border-0 shadow rounded-4">
                          <div class="modal-header border-0 pb-0">
                            <h5 class="modal-title fw-bold text-primary"><i class="bi bi-people me-2"></i> Penempatan Siswa: <?php echo htmlspecialchars($lok['nama_instansi']); ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                          </div>
                          <form method="POST" action="">
                              <div class="modal-body">
                                  <input type="hidden" name="lokasi_id" value="<?php echo $lok['id']; ?>">
                                  <div class="row g-2">
                                      <?php foreach($semua_siswa as $siswa): 
                                          $isChecked = (isset($map_penempatan[$lok['id']]) && in_array($siswa['id'], $map_penempatan[$lok['id']])) ? 'checked' : '';
                                      ?>
                                      <div class="col-md-6">
                                          <div class="form-check border rounded-3 p-2 ps-5 <?php echo $isChecked ? 'bg-primary bg-opacity-10 border-primary' : 'bg-light'; ?>">
                                            <input class="form-check-input" type="checkbox" name="siswa_ids[]" value="<?php echo $siswa['id']; ?>" id="chk_<?php echo $lok['id'].'_'.$siswa['id']; ?>" <?php echo $isChecked; ?>>
                                            <label class="form-check-label d-block cursor-pointer" for="chk_<?php echo $lok['id'].'_'.$siswa['id']; ?>">
                                              <strong><?php echo htmlspecialchars($siswa['nama_siswa']); ?></strong><br>
                                              <small class="text-muted">Kelas: <?php echo htmlspecialchars($siswa['kelas']); ?> | NISN: <?php echo htmlspecialchars($siswa['nisn']); ?></small>
                                            </label>
                                          </div>
                                      </div>
                                      <?php endforeach; ?>
                                  </div>
                              </div>
                              <div class="modal-footer border-0 pt-0">
                                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
                                <button type="submit" name="simpan_penempatan" class="btn btn-primary rounded-pill px-4 fw-bold">Simpan</button>
                              </div>
                          </form>
                        </div>
                      </div>
                    </div>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="lokasiModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow rounded-4">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold text-primary"><i class="bi bi-geo-alt-fill me-2"></i> Tambah Lokasi PKL & Geofencing</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="">
          <div class="modal-body">
              <input type="hidden" name="id" id="modal-id">
              
              <div class="mb-3 position-relative">
                  <label class="form-label fw-semibold text-muted">Cari Instansi / Perusahaan <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-lg border-primary shadow-sm" id="search_box" placeholder="Ketik nama pabrik / perusahaan / kantor...">
                  <small class="text-muted d-block mt-1">Google Maps akan otomatis melengkapi nama, alamat, dan koordinat.</small>
              </div>

              <div class="mb-3">
                  <label class="form-label fw-semibold text-muted">Nama Instansi</label>
                  <input type="text" class="form-control bg-light" name="nama_instansi" id="nama_instansi" required readonly>
              </div>
              <div class="mb-3">
                  <label class="form-label fw-semibold text-muted">Alamat Lengkap</label>
                  <textarea class="form-control bg-light" name="alamat" id="alamat" rows="2" readonly></textarea>
              </div>
              
              <div class="mb-3">
                  <div id="map" style="height: 250px; border-radius: 12px; border: 2px solid #e9ecef;"></div>
              </div>
              
              <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-semibold text-muted">Latitude</label>
                    <input type="text" class="form-control bg-light" name="latitude" id="lat" required readonly>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-semibold text-muted">Longitude</label>
                    <input type="text" class="form-control bg-light" name="longitude" id="lng" required readonly>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-semibold text-muted">Radius (Meter)</label>
                    <input type="number" class="form-control border-warning" name="radius" id="radius_input" value="50" required min="10" max="1000">
                </div>
              </div>
          </div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
            <button type="submit" name="simpan_lokasi" class="btn btn-primary rounded-pill px-4 fw-bold">Simpan Lokasi</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyAU7_Blxw_OJ2EbBYoOQFbjHt2R07Q6R2o&libraries=places"></script>

<script>
    let map, marker, cityCircle;

    function initMap() {
        // Koordinat Default: Pusat Kota Ciamis
        const defaultLocation = { lat: -7.3274, lng: 108.3533 };
        
        map = new google.maps.Map(document.getElementById("map"), {
            center: defaultLocation,
            zoom: 13,
            mapTypeId: "satellite", // Mode satelit agar atap pabrik terlihat jelas
        });

        marker = new google.maps.Marker({
            map: map,
            position: defaultLocation,
        });

        cityCircle = new google.maps.Circle({
            strokeColor: "#FF0000",
            strokeOpacity: 0.8,
            strokeWeight: 2,
            fillColor: "#FF0000",
            fillOpacity: 0.35,
            map: map,
            center: defaultLocation,
            radius: 50,
        });

        // Hubungkan Input Search dengan Google Places Autocomplete
        const input = document.getElementById("search_box");
        const autocomplete = new google.maps.places.Autocomplete(input);
        autocomplete.bindTo("bounds", map);

        autocomplete.addListener("place_changed", () => {
            const place = autocomplete.getPlace();
            if (!place.geometry || !place.geometry.location) {
                alert("Data lokasi tidak ditemukan oleh Google Maps!");
                return;
            }

            // Pindahkan Peta dan Marker
            if (place.geometry.viewport) {
                map.fitBounds(place.geometry.viewport);
            } else {
                map.setCenter(place.geometry.location);
                map.setZoom(17);
            }
            marker.setPosition(place.geometry.location);
            cityCircle.setCenter(place.geometry.location);

            // Isi Form secara Otomatis
            document.getElementById("nama_instansi").value = place.name;
            document.getElementById("alamat").value = place.formatted_address;
            document.getElementById("lat").value = place.geometry.location.lat().toFixed(8);
            document.getElementById("lng").value = place.geometry.location.lng().toFixed(8);
        });

        // Update Radius Lingkaran secara Real-Time saat Admin Mengetik
        document.getElementById("radius_input").addEventListener("input", function() {
            let radiusValue = parseInt(this.value);
            if(radiusValue > 0) {
                cityCircle.setRadius(radiusValue);
            }
        });
    }

    // Inisialisasi peta hanya saat Modal HTML dibuka
    document.getElementById('lokasiModal').addEventListener('shown.bs.modal', function () {
        initMap();
    });
</script>

<?php include 'partials/footer.php'; ?>