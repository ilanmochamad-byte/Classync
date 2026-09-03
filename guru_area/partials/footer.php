</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Cari semua form absensi yang memiliki class 'absen-form'
    const absenForms = document.querySelectorAll('.absen-form');

    absenForms.forEach(form => {
        form.addEventListener('submit', function() {
            // Cari tombol submit di dalam form yang sedang disubmit
            const submitButton = form.querySelector('button[type="submit"]');
            
            if (submitButton) {
                // Nonaktifkan tombol
                submitButton.disabled = true;
                // Ubah teks tombol untuk memberi umpan balik
                submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Mengirim...';
            }
        });
    });
});
</script>

</body>
</html>