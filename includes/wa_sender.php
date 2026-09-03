<?php

// PUSATKAN TOKEN FONNTE DI SINI
define('WA_GATEWAY_TOKEN', 'jSpvuqjfVsLPbTT9aFRn');

/**
 * Fungsi untuk memformat nomor HP ke format internasional (62)
 * Mengganti 0 di depan dengan 62.
 */
function formatNomorWA($nomor) {
    // Hilangkan spasi atau karakter non-angka
    $nomor = preg_replace('/[^0-9]/', '', $nomor);

    // Jika nomor diawali 0, ganti dengan 62
    if (substr($nomor, 0, 1) == '0') {
        return '62' . substr($nomor, 1);
    }
    // Jika sudah 62, biarkan
    if (substr($nomor, 0, 2) == '62') {
        return $nomor;
    }
    // Default (angka acak tanpa 0 atau 62)
    return $nomor;
}

/**
 * Fungsi untuk mengirim notifikasi WhatsApp via Gateway Fonnte
 *
 * @param string $target Nomor tujuan (sudah format 62)
 * @param string $message Isi pesan
 * @param string $token Token Fonnte (Otomatis mengambil dari konstanta WA_GATEWAY_TOKEN)
 * @return bool True jika berhasil, False jika gagal
 */
function kirimNotifikasiWA($target, $message, $token = WA_GATEWAY_TOKEN) {
    $url = 'https://api.fonnte.com/send'; 

    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 30, // Timeout 30 detik
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS => http_build_query(array(
        'target' => $target,
        'message' => $message,
        'countryCode' => '62', // Opsional, tergantung gateway
      )),
      CURLOPT_HTTPHEADER => array(
        'Authorization: ' . $token, // Kirim token di header
        'Content-Type: application/x-www-form-urlencoded'
      ),
    ));

    $response = curl_exec($curl);
    $curl_error = curl_error($curl);
    curl_close($curl);
    
    // Cek apakah ada error dari sisi server/koneksi
    if ($curl_error) {
        error_log('[Fonnte Error] Gagal koneksi cURL: ' . $curl_error);
        return false;
    }

    // --- FITUR BARU: Menangkap & Mencatat Respon JSON dari Fonnte ---
    $json_response = json_decode($response, true);
    
    // Jika Fonnte mengembalikan status false (gagal)
    if (isset($json_response['status']) && $json_response['status'] == false) {
        $reason = isset($json_response['reason']) ? $json_response['reason'] : 'Tidak diketahui';
        $detail = isset($json_response['detail']) ? $json_response['detail'] : 'Tidak ada detail';
        
        // Catat ke file error log server agar mudah di-debug
        error_log("[Fonnte Error] Gagal kirim pesan ke $target. Alasan: $reason | Detail: $detail");
        return false;
    }
    
    // Jika sukses
    return true; 
}

?>