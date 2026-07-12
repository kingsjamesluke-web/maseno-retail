/**
 * Maseno Retail ERP - POS Frontend Logic
 *
 * Handles:
 *   - Product search & grid display
 *   - Cart management (add, update qty, remove)
 *   - Dynamic totals calculation
 *   - Sale completion via AJAX
 *   - Barcode scanner input
 */

(function() {
    'use strict';

    // ── State ──
    const POS = {
        cart: [],
        searchTimer: null,
        currentCustomer: 0,
        currentCustomerName: 'Walk-in Customer',
    };

    // ── DOM References ──
    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => document.querySelectorAll(sel);

    const dom = {
        searchInput:    $('#pos-search'),
        productGrid:    $('#pos-products'),
        cartItems:      $('#cart-items'),
        cartCount:      $('#cart-count'),
        subtotal:       $('#cart-subtotal'),
        discountTotal:  $('#cart-discount'),
        taxAmount:      $('#cart-tax'),
        grandTotal:     $('#cart-grand-total'),
        checkoutBtn:    $('#btn-checkout'),
        clearBtn:       $('#btn-clear-cart'),
        customerSearch: $('#customer-search'),
        customerName:   $('#customer-name'),
        paymentMethod:  $('#payment-method'),
        amountTendered: $('#amount-tendered'),
        changeDue:      $('#change-due'),
        receiptModal:   $('#receipt-modal'),
        receiptContent: $('#receipt-content'),
    };

    // ── Initialize ──
    function init() {
        loadProducts();
        bindEvents();
        loadCart();
    }

    // ── Load Products ──
    function loadProducts(search = '') {
        const url = search
            ? `api/products.php?search=${encodeURIComponent(search)}`
            : 'api/products.php';

        fetch(url)
            .then(r => r.json())
            .then(data => {
                renderProducts(data.data || data);
            })
            .catch(err => console.error('Failed to load products:', err));
    }

    function renderProducts(products) {
        if (!dom.productGrid) return;
        if (!products || products.length === 0) {
            dom.productGrid.innerHTML = '<p class="text-muted">No products found.</p>';
            return;
        }

        dom.productGrid.innerHTML = products.map(p => `
            <div class="pos-product-card" data-id="${p.id}" data-price="${p.selling_price}" data-name="${escapeHtml(p.name)}" data-stock="${p.current_stock}">
                <div class="price">KES ${formatNum(p.selling_price)}</div>
                <div class="name">${escapeHtml(p.name)}</div>
                <div class="stock">${p.current_stock} ${p.sell_unit || 'pcs'}</div>
            </div>
        `).join('');

        // Click to add to cart
        dom.productGrid.querySelectorAll('.pos-product-card').forEach(card => {
            card.addEventListener('click', function() {
                const id = parseInt(this.dataset.id);
                const name = this.dataset.name;
                const price = parseFloat(this.dataset.price);
                addToCart(id, name, price, 1);
            });
        });
    }

    // ── Cart Management ──
    function addToCart(id, name, price, qty) {
        fetch('api/cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add', product_id: id, quantity: qty })
        })
        .then(r => r.json())
        .then(resp => {
            if (resp.success) {
                loadCart();
                showToast(resp.message, 'success');
            } else {
                showToast(resp.message, 'danger');
            }
        })
        .catch(err => showToast('Failed to add item', 'danger'));
    }

    function updateCartQty(id, qty) {
        if (qty <= 0) {
            removeFromCart(id);
            return;
        }
        fetch('api/cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update', product_id: id, quantity: qty })
        })
        .then(r => r.json())
        .then(resp => {
            if (resp.success) loadCart();
            else showToast(resp.message, 'danger');
        });
    }

    function removeFromCart(id) {
        fetch('api/cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'remove', product_id: id })
        })
        .then(r => r.json())
        .then(resp => {
            if (resp.success) loadCart();
            else showToast(resp.message, 'danger');
        });
    }

    function clearCart() {
        fetch('api/cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'clear' })
        })
        .then(r => r.json())
        .then(() => loadCart());
    }

    function loadCart() {
        fetch('api/cart.php?action=get')
            .then(r => r.json())
            .then(cart => {
                renderCart(cart);
            });
    }

    function renderCart(cart) {
        if (!dom.cartItems) return;

        POS.cart = cart.items || [];

        // Update count
        if (dom.cartCount) dom.cartCount.textContent = cart.item_count || 0;

        // Render items
        if (!cart.items || cart.items.length === 0) {
            dom.cartItems.innerHTML = '<p class="text-muted text-center" style="padding:40px 0;">Cart is empty. Scan or search products.</p>';
            updateTotals(cart);
            return;
        }

        dom.cartItems.innerHTML = cart.items.map(item => `
            <div class="pos-cart-item" data-id="${item.product_id}">
                <div class="item-info">
                    <strong>${escapeHtml(item.name)}</strong><br>
                    <span class="text-muted">KES ${formatNum(item.unit_price)} / ${item.sell_unit || 'pc'}</span>
                </div>
                <div class="item-qty">
                    <button class="btn btn-sm btn-outline" onclick="POSApp.updateCartQty(${item.product_id}, ${item.quantity - 1})">−</button>
                    <input type="number" value="${item.quantity}" min="0" step="0.5"
                           onchange="POSApp.updateCartQty(${item.product_id}, parseFloat(this.value) || 0)"
                           onfocus="this.select()">
                    <button class="btn btn-sm btn-outline" onclick="POSApp.updateCartQty(${item.product_id}, ${item.quantity + 1})">+</button>
                </div>
                <div class="item-total">KES ${formatNum(item.net_total || item.line_total)}</div>
                <div class="item-remove" onclick="POSApp.removeFromCart(${item.product_id})">✕</div>
            </div>
        `).join('');

        updateTotals(cart);
    }

    function updateTotals(cart) {
        if (dom.subtotal)      dom.subtotal.textContent = formatNum(cart.subtotal || 0);
        if (dom.discountTotal) dom.discountTotal.textContent = formatNum(cart.total_discount || 0);
        if (dom.taxAmount)     dom.taxAmount.textContent = formatNum(cart.tax_amount || 0);
        if (dom.grandTotal)    dom.grandTotal.textContent = formatNum(cart.grand_total || 0);
    }

    // ── Checkout ──
    function checkout() {
        const paymentMethod = dom.paymentMethod ? dom.paymentMethod.value : 'cash';
        const amountTendered = dom.amountTendered ? parseFloat(dom.amountTendered.value) || 0 : 0;

        if (POS.cart.length === 0) {
            showToast('Cart is empty.', 'warning');
            return;
        }

        if (paymentMethod === 'cash' && amountTendered <= 0) {
            showToast('Enter amount tendered for cash payment.', 'warning');
            return;
        }

        dom.checkoutBtn.disabled = true;
        dom.checkoutBtn.textContent = 'Processing...';

        fetch('api/checkout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                payment_method: paymentMethod,
                amount_tendered: amountTendered,
                customer_id: POS.currentCustomer,
            })
        })
        .then(r => r.json())
        .then(resp => {
            if (resp.success) {
                showReceipt(resp);
                loadCart();
                showToast(resp.message, 'success');
            } else {
                showToast(resp.message, 'danger');
            }
        })
        .catch(err => showToast('Checkout failed.', 'danger'))
        .finally(() => {
            dom.checkoutBtn.disabled = false;
            dom.checkoutBtn.textContent = 'Charge';
        });
    }

    function showReceipt(data) {
        if (!dom.receiptModal || !dom.receiptContent) return;

        const items = POS.cart.map(item => `
            <tr>
                <td>${escapeHtml(item.name)}</td>
                <td class="text-center">${item.quantity}</td>
                <td class="text-right">${formatNum(item.unit_price)}</td>
                <td class="text-right">${formatNum(item.net_total || item.line_total)}</td>
            </tr>
        `).join('');

        dom.receiptContent.innerHTML = `
            <div style="text-align:center; margin-bottom:15px;">
                <h3>${STORE_NAME || 'Maseno Retail'}</h3>
                <p>${STORE_PHONE || ''}</p>
                <hr>
                <h4>RECEIPT</h4>
                <p><strong>${data.receipt_no}</strong></p>
                <p>${new Date().toLocaleString()}</p>
                <p>Cashier: ${CASHIER_NAME || ''}</p>
                <p>Customer: ${POS.currentCustomerName}</p>
            </div>
            <table style="width:100%; font-size:.85rem;">
                <thead>
                    <tr><th>Item</th><th class="text-center">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr>
                </thead>
                <tbody>${items}</tbody>
            </table>
            <hr>
            <div style="text-align:right;">
                <p>Subtotal: KES ${formatNum(data.subtotal || 0)}</p>
                <p>Discount: KES ${formatNum(data.discount_amount || 0)}</p>
                <p>Tax (${TAX_RATE_PCT || 16}%): KES ${formatNum(data.tax_amount || 0)}</p>
                <h3>Total: KES ${formatNum(data.total || data.grand_total || 0)}</h3>
                <p>Paid: KES ${formatNum(data.amount_tendered || 0)}</p>
                <p>Change: KES ${formatNum(data.change_due || 0)}</p>
                <p>Payment: ${data.payment_method || 'cash'}</p>
            </div>
            <div style="text-align:center; margin-top:15px; font-size:.8rem; color:#666;">
                <p>Thank you for shopping with us!</p>
            </div>
        `;

        dom.receiptModal.classList.add('active');
    }

    // ── Customer Search ──
    function searchCustomer(query) {
        if (!query || query.length < 3) {
            POS.currentCustomer = 0;
            POS.currentCustomerName = 'Walk-in Customer';
            if (dom.customerName) dom.customerName.textContent = POS.currentCustomerName;
            return;
        }

        fetch(`api/customers.php?search=${encodeURIComponent(query)}`)
            .then(r => r.json())
            .then(data => {
                if (data && data.id) {
                    POS.currentCustomer = data.id;
                    POS.currentCustomerName = `${data.first_name} ${data.last_name}`;
                    if (dom.customerName) dom.customerName.textContent = POS.currentCustomerName;
                }
            });
    }

    // ── Amount Tendered → Change ──
    function calcChange() {
        const tendered = parseFloat(dom.amountTendered?.value || 0);
        const totalEl = dom.grandTotal;
        const total = totalEl ? parseFloat(totalEl.textContent.replace(/,/g, '')) || 0 : 0;
        const change = Math.max(0, tendered - total);
        if (dom.changeDue) dom.changeDue.textContent = formatNum(change);
    }

    // ── Event Binding ──
    function bindEvents() {
        // Product search with debounce
        if (dom.searchInput) {
            dom.searchInput.addEventListener('input', function() {
                clearTimeout(POS.searchTimer);
                POS.searchTimer = setTimeout(() => {
                    loadProducts(this.value.trim());
                }, 300);
            });

            // Barcode scanner support (Enter key)
            dom.searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && this.value.trim()) {
                    e.preventDefault();
                    // Try to find and add product directly
                    const query = this.value.trim();
                    fetch(`api/products.php?barcode=${encodeURIComponent(query)}`)
                        .then(r => r.json())
                        .then(product => {
                            if (product && product.id) {
                                addToCart(product.id, product.name, parseFloat(product.selling_price), 1);
                                this.value = '';
                            } else {
                                loadProducts(query);
                            }
                        });
                }
            });
        }

        // Checkout
        if (dom.checkoutBtn) {
            dom.checkoutBtn.addEventListener('click', checkout);
        }

        // Clear cart
        if (dom.clearBtn) {
            dom.clearBtn.addEventListener('click', function() {
                if (confirm('Clear entire cart?')) clearCart();
            });
        }

        // Customer search
        if (dom.customerSearch) {
            dom.customerSearch.addEventListener('input', function() {
                clearTimeout(POS.customerTimer);
                POS.customerTimer = setTimeout(() => searchCustomer(this.value), 400);
            });
        }

        // Amount tendered change
        if (dom.amountTendered) {
            dom.amountTendered.addEventListener('input', calcChange);
        }

        // Close receipt modal
        document.querySelectorAll('.modal-close, .modal-overlay').forEach(el => {
            el.addEventListener('click', function(e) {
                if (e.target === this) {
                    document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('active'));
                }
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // F1 = focus search
            if (e.key === 'F1') {
                e.preventDefault();
                dom.searchInput?.focus();
            }
            // F8 = checkout
            if (e.key === 'F8') {
                e.preventDefault();
                checkout();
            }
            // Escape = close modals
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('active'));
            }
        });
    }

    // ── Helpers ──
    function formatNum(n) {
        return Number(n || 0).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function showToast(msg, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `alert alert-${type}`;
        toast.style.cssText = 'position:fixed; top:20px; right:20px; z-index:9999; max-width:400px; animation:slideIn .3s;';
        toast.textContent = msg;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity .3s';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // ── Expose public methods ──
    window.POSApp = {
        init,
        addToCart,
        updateCartQty,
        removeFromCart,
        clearCart,
        loadCart,
        checkout,
        searchCustomer,
    };

    // Auto-init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();