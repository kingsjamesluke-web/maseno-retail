/**
 * Maseno Retail ERP - General Application JavaScript
 *
 * Provides shared utilities and UI behaviors across all pages.
 */

(function() {
    'use strict';

    // ── Auto-hide flash messages after 5 seconds ──
    document.querySelectorAll('.alert').forEach(alert => {
        if (!alert.classList.contains('alert-permanent')) {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            }, 5000);
        }
    });

    // ── Sidebar active link highlighting ──
    const currentPage = window.location.pathname.split('/').pop() || 'index.php';
    document.querySelectorAll('.sidebar-nav a').forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage) {
            link.classList.add('active');
        }
    });

    // ── Confirm dialogs for destructive actions ──
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function(e) {
            if (!confirm(this.dataset.confirm || 'Are you sure?')) {
                e.preventDefault();
            }
        });
    });

    // ── Print receipt ──
    window.printReceipt = function() {
        window.print();
    };

    // ── Format number as KES ──
    window.formatCurrency = function(amount) {
        return 'KES ' + Number(amount || 0).toLocaleString('en-KE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    };

    // ── Format date ──
    window.formatDate = function(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-KE', {
            year: 'numeric', month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });
    };

    // ── Debounce utility ──
    window.debounce = function(fn, delay = 300) {
        let timer;
        return function(...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        };
    };

    // ── Modal helpers ──
    window.openModal = function(id) {
        const modal = document.getElementById(id);
        if (modal) modal.classList.add('active');
    };

    window.closeModal = function(id) {
        const modal = document.getElementById(id);
        if (modal) modal.classList.remove('active');
    };

    // ── Keyboard shortcuts (non-POS pages) ──
    if (!window.location.pathname.includes('pos.php')) {
        document.addEventListener('keydown', function(e) {
            // Alt+D → Dashboard
            if (e.altKey && e.key === 'd') {
                e.preventDefault();
                window.location.href = 'index.php';
            }
            // Alt+S → POS
            if (e.altKey && e.key === 's') {
                e.preventDefault();
                window.location.href = 'pos.php';
            }
            // Alt+I → Inventory
            if (e.altKey && e.key === 'i') {
                e.preventDefault();
                window.location.href = 'inventory.php';
            }
        });
    }

    console.log(`🏪 Maseno Retail ERP loaded | ${new Date().toLocaleString()}`);
})();