<?php
// Middleware untuk otentikasi
require 'auth_middleware.php';

// Mendapatkan data guru yang sedang login
$guru = authenticate();
$guru_id = $guru['id'];

// --- Menentukan Aksi Berdasarkan Metode Request ---

// JIKA REQUEST ADALAH GET (Meminta Data Profil)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 1. Ambil data dasar guru
    $stmt = $conn->prepare("SELECT nip, nama_guru, username, kontak, foto_profil FROM guru WHERE id = ?");
    $stmt->bind_param("i", $guru_id);
    $stmt->execute();
    $profil_data = $stmt->get_result()->fetch_assoc();

    // 2. Ambil daftar mata pelajaran unik yang diampu
    $stmt_mapel = $conn->prepare("SELECT DISTINCT mata_pelajaran FROM jadwal_mengajar WHERE guru_id = ? ORDER BY mata_pelajaran ASC");
    $stmt_mapel->bind_param("i", $guru_id);
    $stmt_mapel->execute();
    $result_mapel = $stmt_mapel->get_result();
    $mapel_diampu = [];
    while ($row = $result_mapel->fetch_assoc()) {
        $mapel_diampu[] = $row['mata_pelajaran'];
    }

    $profil_data['mapel_diampu'] = $mapel_diampu;

    echo json_encode(['status' => 'success', 'data' => $profil_data]);
}

// JIKA REQUEST ADALAH POST (Mengubah Password atau Upload Foto)
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Cek aksi apa yang diminta
    $action = $_POST['action'] ?? '';

    // AKSI: Ubah Password
    if ($action === 'ubah_password') {
        $old_pass = $_POST['password_lama'] ?? '';
        $new_pass = $_POST['password_baru'] ?? '';
        $confirm_pass = $_POST['konfirmasi_password'] ?? '';

        if (empty($old_pass) || empty($new_pass) || empty($confirm_pass)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Semua kolom password wajib diisi.']);
            exit;
        }
        if ($new_pass !== $confirm_pass) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Password baru dan konfirmasi tidak cocok.']);
            exit;
        }

        // Verifikasi password lama
        $stmt_pass = $conn->prepare("SELECT password FROM guru WHERE id = ?");
        $stmt_pass->bind_param("i", $guru_id);
        $stmt_pass->execute();
        $guru_data = $stmt_pass->get_result()->fetch_assoc();

        if (password_verify($old_pass, $guru_data['password'])) {
            // Jika password lama benar, hash dan update password baru
            $new_hashed_password = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt_update = $conn->prepare("UPDATE guru SET password = ? WHERE id = ?");
            $stmt_update->bind_param("si", $new_hashed_password, $guru_id);
            if ($stmt_update->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Password berhasil diubah.']);
            } else {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Gagal mengubah password di database.']);
            }
        } else {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Password lama salah.']);
        }
    }
    
    // AKSI: Upload Foto Profil
    elseif ($action === 'upload_foto') {
        if (isset($_FILES['foto_profil']) && $_FILES['foto_profil']['error'] == 0) {
            $target_dir = "../uploads/profil/";
            $file_extension = strtolower(pathinfo($_FILES["foto_profil"]["name"], PATHINFO_EXTENSION));
            $file_name = "profil-" . $guru_id . "-" . time() . "." . $file_extension;
            $target_file = $target_dir . $file_name;
            $foto_path_db = "uploads/profil/" . $file_name;

            // Validasi file (gambar, ukuran, format)
            if (getimagesize($_FILES["foto_profil"]["tmp_name"]) === false) {
                 http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'File bukan gambar.']); exit;
            }
            if (!in_array($file_extension, ['jpg', 'jpeg', 'png'])) {
                 http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Hanya format JPG/PNG yang diizinkan.']); exit;
            }

            if (move_uploaded_file($_FILES["foto_profil"]["tmp_name"], $target_file)) {
                // Update path foto di database
                $stmt_foto = $conn->prepare("UPDATE guru SET foto_profil = ? WHERE id = ?");
                $stmt_foto->bind_param("si", $foto_path_db, $guru_id);
                if ($stmt_foto->execute()) {
                    echo json_encode(['status' => 'success', 'message' => 'Foto profil berhasil diubah.', 'foto_url' => $foto_path_db]);
                } else {
                     http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan path foto ke database.']);
                }
            } else {
                http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan file foto.']);
            }
        } else {
            http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Foto profil wajib diupload.']);
        }
    }

    else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Aksi tidak valid.']);
    }
} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'Metode request tidak diizinkan.']);
}

$conn->close();
?>