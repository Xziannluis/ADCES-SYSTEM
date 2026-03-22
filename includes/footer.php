<!-- Include JavaScript files -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
<script src="../assets/js/main.js"></script>

<!-- Custom JavaScript for current page -->
<script>
// Auto-hide alerts after 5 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);

// Initialize tooltips
const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
});

// Confirm before actions
function confirmAction(message) {
    return confirm(message || 'Are you sure you want to perform this action?');
}

(function setupResponsiveSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content');
    if (!sidebar || !mainContent || document.querySelector('.mobile-sidebar-toggle')) {
        return;
    }

    const openButton = document.createElement('button');
    openButton.type = 'button';
    openButton.className = 'mobile-sidebar-toggle';
    openButton.setAttribute('aria-label', 'Open navigation');
    openButton.innerHTML = '<i class="fas fa-bars"></i>';

    // If there's a dashboard-topbar, insert the hamburger as its first child
    // Otherwise, create a mobile-sidebar-header bar
    const topbar = mainContent.querySelector('.dashboard-topbar');
    if (topbar) {
        topbar.insertBefore(openButton, topbar.firstChild);
    } else {
        const header = document.createElement('div');
        header.className = 'mobile-sidebar-header';
        const title = document.createElement('div');
        title.className = 'fw-semibold text-dark';
        title.textContent = document.title || 'Navigation';
        header.appendChild(openButton);
        header.appendChild(title);
        mainContent.insertBefore(header, mainContent.firstChild);
    }

    const backdrop = document.createElement('div');
    backdrop.className = 'sidebar-backdrop';
    document.body.appendChild(backdrop);

    const openSidebar = () => document.body.classList.add('sidebar-open');
    const closeSidebar = () => document.body.classList.remove('sidebar-open');

    openButton.addEventListener('click', openSidebar);
    backdrop.addEventListener('click', closeSidebar);

    sidebar.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth < 992) {
                closeSidebar();
            }
        });
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth >= 992) {
            closeSidebar();
        }
    });
})();
</script>

</body>
</html>