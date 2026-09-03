<?php
require 'includes/db.php';
echo "DB Connected: " . ($conn ? "YES" : "NO") . "<br>";
echo "API File: " . (file_exists('api/proses_absen_manual.php') ? "EXISTS" : "NOT FOUND");
?>