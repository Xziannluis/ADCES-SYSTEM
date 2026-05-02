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

(function setupMobileScrollControls() {
    if (document.querySelector('.mobile-scroll-controls')) {
        return;
    }

    const controls = document.createElement('div');
    controls.className = 'mobile-scroll-controls no-print';
    controls.setAttribute('aria-label', 'Scroll controls');
    controls.innerHTML = `
        <div class="msc-row">
            <button type="button" class="mobile-scroll-btn" data-dir="up" aria-label="Scroll up"><i class="fas fa-arrow-up"></i></button>
            <button type="button" class="mobile-scroll-btn" data-dir="down" aria-label="Scroll down"><i class="fas fa-arrow-down"></i></button>
        </div>
        <div class="msc-row">
            <button type="button" class="mobile-scroll-btn" data-dir="left" aria-label="Scroll left"><i class="fas fa-arrow-left"></i></button>
            <button type="button" class="mobile-scroll-btn" data-dir="right" aria-label="Scroll right"><i class="fas fa-arrow-right"></i></button>
        </div>
    `;
    document.body.appendChild(controls);

    const buttons = {
        up: controls.querySelector('[data-dir="up"]'),
        down: controls.querySelector('[data-dir="down"]'),
        left: controls.querySelector('[data-dir="left"]'),
        right: controls.querySelector('[data-dir="right"]')
    };

    function scrollByDir(dir) {
        const xStep = Math.max(180, Math.floor(window.innerWidth * 0.75));
        const yStep = Math.max(220, Math.floor(window.innerHeight * 0.7));
        if (dir === 'left') window.scrollBy({ left: -xStep, behavior: 'smooth' });
        if (dir === 'right') window.scrollBy({ left: xStep, behavior: 'smooth' });
        if (dir === 'up') window.scrollBy({ top: -yStep, behavior: 'smooth' });
        if (dir === 'down') window.scrollBy({ top: yStep, behavior: 'smooth' });
    }

    Object.keys(buttons).forEach(function(dir) {
        buttons[dir].addEventListener('click', function() {
            scrollByDir(dir);
        });
    });

    function updateButtons() {
        const isMobile = window.innerWidth < 992;
        controls.style.display = isMobile ? 'flex' : 'none';
        if (!isMobile) return;

        const doc = document.scrollingElement || document.documentElement;
        const maxX = Math.max(0, doc.scrollWidth - window.innerWidth);
        const maxY = Math.max(0, doc.scrollHeight - window.innerHeight);
        const x = window.pageXOffset || doc.scrollLeft || 0;
        const y = window.pageYOffset || doc.scrollTop || 0;

        buttons.left.disabled = (x <= 2);
        buttons.right.disabled = (x >= maxX - 2);
        buttons.up.disabled = (y <= 2);
        buttons.down.disabled = (y >= maxY - 2);

        // Hide horizontal row if page doesn't overflow horizontally.
        buttons.left.parentElement.style.display = (maxX > 10) ? 'flex' : 'none';
    }

    window.addEventListener('scroll', updateButtons, { passive: true });
    window.addEventListener('resize', updateButtons);
    updateButtons();
})();
</script>

</body>
</html>
