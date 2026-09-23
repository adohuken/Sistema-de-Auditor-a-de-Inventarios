        </main> <!-- /main-content -->

        <footer class="bg-white border-top py-3 mt-auto no-print">
            <div class="container-fluid px-4 text-center text-muted small">
                <div class="row align-items-center">
                    <div class="col-md-6 text-md-start mb-2 mb-md-0">
                        <span>&copy; <?= date('Y') ?> <strong>Sistema de Auditoría de Inventarios</strong>. Control & Conciliación.</span>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <span class="badge bg-light text-dark border me-2"><i class="bi bi-shield-check text-success me-1"></i> Sesión Segura (PDO)</span>
                        <span class="badge bg-light text-muted border">v2.0.0</span>
                    </div>
                </div>
            </div>
        </footer>

    </div> <!-- /main-wrapper -->
</div> <!-- /app-layout -->

<!-- Bootstrap 5 JavaScript Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Toggle de Menú Lateral en pantallas móviles
document.addEventListener('DOMContentLoaded', function() {
    const btnToggle = document.getElementById('btnToggleSidebar');
    const sidebar = document.getElementById('sidebarWrapper');
    if (btnToggle && sidebar) {
        btnToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });
    }
});
</script>

</body>
</html>
