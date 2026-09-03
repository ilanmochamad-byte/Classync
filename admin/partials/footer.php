</div> <!-- Menutup div.container dari header.php -->

<!-- 1. Bootstrap JS (WAJIB dimuat pertama) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- 2. "Wadah" untuk skrip kustom dari halaman lain -->
<?php if (isset($custom_script)) { echo $custom_script; } ?>

</body>
</html>