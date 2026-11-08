/* =============================================================================
   Printer Shop POS — shared JavaScript
   Handles the POS screen (catalog search, cart, discount, payment, saving),
   the product/service modal forms, and automatic receipt printing.
   Plain vanilla JS — no framework.
   ========================================================================== */
(function () {
  'use strict';

  /* ------------------------------------------------------------------------
   * Small helpers
   * ---------------------------------------------------------------------- */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function fmt(n) {
    return 'PKR ' + Number(n || 0).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function num(value) {
    var v = parseFloat(value);
    return isNaN(v) ? 0 : v;
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /* ------------------------------------------------------------------------
   * Modals (used on products.php and services.php)
   * ---------------------------------------------------------------------- */
  function setupModals() {
    $all('[data-modal-open]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-modal-open');
        var modal = document.getElementById(id);
        if (!modal) { return; }

        // "Add" buttons reset the form; "Edit" buttons fill it afterwards.
        if (btn.hasAttribute('data-modal-reset')) {
          var form = modal.querySelector('form');
          if (form) { form.reset(); }
          var idInput = modal.querySelector('[name="id"]');
          if (idInput) { idInput.value = ''; }
          var addTitle = modal.querySelector('[data-modal-title]');
          if (addTitle) { addTitle.textContent = btn.getAttribute('data-modal-title') || 'Add'; }
        }

        modal.classList.add('is-open');
      });
    });

    $all('.modal').forEach(function (modal) {
      $all('[data-modal-close]', modal).forEach(function (el) {
        el.addEventListener('click', function () { modal.classList.remove('is-open'); });
      });
      modal.addEventListener('click', function (e) {
        if (e.target === modal) { modal.classList.remove('is-open'); }
      });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        $all('.modal.is-open').forEach(function (m) { m.classList.remove('is-open'); });
      }
    });

    // Fill the modal form when "Edit" is clicked (data-* attributes on the row).
    $all('[data-edit-target]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var modal = document.getElementById(btn.getAttribute('data-edit-target'));
        if (!modal) { return; }
        ['id', 'name', 'sku', 'cost_price', 'selling_price', 'stock_quantity', 'price', 'description']
          .forEach(function (key) {
            var input = modal.querySelector('[name="' + key + '"]');
            if (input && btn.hasAttribute('data-' + key)) {
              input.value = btn.getAttribute('data-' + key);
            }
          });
        var activeInput = modal.querySelector('[name="is_active"]');
        if (activeInput && btn.hasAttribute('data-is_active')) {
          activeInput.checked = btn.getAttribute('data-is_active') === '1';
        }
        var title = modal.querySelector('[data-modal-title]');
        if (title) { title.textContent = btn.getAttribute('data-modal-title') || 'Edit'; }
      });
    });
  }

  /* ------------------------------------------------------------------------
   * POS screen
   * ---------------------------------------------------------------------- */
  function setupPOS() {
    var root = document.getElementById('pos-app');
    if (!root || !window.POS_DATA) { return; }

    var items = window.POS_DATA.items || [];
    var csrf  = window.POS_DATA.csrf || '';

    // Cart state: each line { kind, id, name, qty, price }
    var cart = [];
    var activeTab = 'all';
    var searchTerm = '';

    var searchInput  = $('.pos-search__input', root);
    var gridEl       = $('.pos-grid', root);
    var cartLinesEl  = $('.cart-lines', root);
    var cartEmptyEl  = $('.cart-empty', root);
    var discountType = 'fixed';
    var discountOver = false;

    var subtotalEl   = $('#subtotal', root);
    var discountAmtEl= $('#discountAmount', root);
    var totalEl      = $('#grandTotal', root);
    var changeEl     = $('#changeRow', root);
    var discountVal  = $('#discountValue', root);
    var paidInput    = $('#amountReceived', root);
    var saveBtn      = $('#saveBill', root);
    var statusEl     = $('#posStatus', root);

    function showStatus(msg, type) {
      statusEl.textContent = msg || '';
      statusEl.className = 'pos-status' + (type ? ' pos-status--' + type : '');
    }

    function matches(item, term) {
      term = term.trim().toLowerCase();
      if (!term) { return true; }
      var hay = (item.name + ' ' + (item.sku || '')).toLowerCase();
      return hay.indexOf(term) !== -1;
    }

    function renderCatalog() {
      gridEl.innerHTML = '';
      var visible = items.filter(function (it) {
        if (activeTab === 'product' && it.kind !== 'product') { return false; }
        if (activeTab === 'service' && it.kind !== 'service') { return false; }
        return matches(it, searchTerm);
      });

      if (visible.length === 0) {
        gridEl.innerHTML = '<div class="pos-empty">No items found.</div>';
        return;
      }

      visible.forEach(function (it) {
        var card = document.createElement('button');
        card.type = 'button';
        card.className = 'pos-item';

        var priceText = (it.price === null || it.price === undefined)
          ? '<span class="pos-item__price pos-item__price--variable">Enter price</span>'
          : '<span class="pos-item__price">' + fmt(it.price) + '</span>';

        var tag = it.kind === 'service'
          ? '<span class="pos-item__tag pos-item__tag--service">Service</span>'
          : '<span class="pos-item__tag">Product</span>';

        var stock = '';
        if (it.kind === 'product' && it.stock !== null && it.stock !== undefined) {
          var low = Number(it.stock) <= 0;
          stock = '<span class="pos-item__stock' + (low ? ' is-low' : '') + '">Stock: ' + it.stock + '</span>';
        }

        card.innerHTML =
          '<span class="pos-item__name">' + escapeHtml(it.name) + '</span>' +
          '<span class="pos-item__meta">' + priceText + tag + '</span>' +
          stock;

        card.addEventListener('click', function () { addToCart(it); });
        gridEl.appendChild(card);
      });
    }

    function addToCart(item) {
      // Same product/service id merges by incrementing quantity.
      if (item.kind !== 'custom' && item.id) {
        var existing = cart.filter(function (l) { return l.kind === item.kind && l.id === item.id; })[0];
        if (existing) {
          existing.qty += 1;
          renderCart();
          return;
        }
      }
      var variable = (item.kind === 'service' && (item.price === null || item.price === undefined));
      var price = variable ? 0 : Number(item.price || 0);
      cart.push({ kind: item.kind, id: item.id || null, name: item.name, qty: 1, price: price, variable: variable });
      renderCart();
      // Keep focus in search for fast entry.
      if (searchInput) { searchInput.focus(); }
    }

    function addCustomLine() {
      cart.push({ kind: 'custom', id: null, name: 'Item', qty: 1, price: 0 });
      renderCart();
    }

    function renderCart() {
      cartLinesEl.innerHTML = '';
      cartEmptyEl.style.display = cart.length === 0 ? 'block' : 'none';

      cart.forEach(function (line, index) {
        var row = document.createElement('div');
        row.className = 'cart-line';
        row.dataset.index = index;

        var nameCell = line.kind === 'custom'
          ? '<input type="text" class="cart-line__name-input" value="' + escapeHtml(line.name) + '" data-field="name">'
          : '<span class="cart-line__name">' + escapeHtml(line.name) + '</span>';

        var isVariable = !!line.variable;
        var priceValue = (isVariable && !(line.price > 0)) ? '' : Number(line.price).toFixed(2);
        var priceInput = '<input type="number" min="0" step="0.01" value="' + priceValue + '" data-field="price"' +
          (isVariable ? ' placeholder="Price"' : '') +
          (isVariable && !(line.price > 0) ? ' class="is-missing"' : '') + '>';

        row.innerHTML =
          '<div class="cart-line__top">' + nameCell +
            '<button type="button" class="cart-line__remove" data-action="remove" title="Remove">&#215;</button>' +
          '</div>' +
          '<div class="cart-line__qty">' +
            '<button type="button" data-action="dec">&#8722;</button>' +
            '<input type="number" min="1" step="1" value="' + line.qty + '" data-field="qty">' +
            '<button type="button" data-action="inc">+</button>' +
          '</div>' +
          '<div class="cart-line__price">' + priceInput + '</div>' +
          '<div class="cart-line__total">' + fmt(line.qty * line.price) + '</div>';

        cartLinesEl.appendChild(row);
      });

      bindCartEvents();
      updateTotals();
    }

    function bindCartEvents() {
      $all('.cart-line', cartLinesEl).forEach(function (row) {
        var index = Number(row.dataset.index);

        $all('[data-action]', row).forEach(function (btn) {
          btn.addEventListener('click', function () {
            var action = btn.getAttribute('data-action');
            if (action === 'remove') {
              cart.splice(index, 1);
              renderCart();
            } else if (action === 'dec') {
              if (cart[index].qty > 1) { cart[index].qty -= 1; }
              renderCart();
            } else if (action === 'inc') {
              cart[index].qty += 1;
              renderCart();
            }
          });
        });

        // Live update for typed quantity/price/name without losing focus.
        $all('input[data-field]', row).forEach(function (input) {
          input.addEventListener('input', function () {
            var field = input.getAttribute('data-field');
            var line = cart[index];
            if (field === 'qty') {
              line.qty = Math.max(1, Math.floor(num(input.value)) || 1);
            } else if (field === 'price') {
              line.price = Math.max(0, num(input.value));
              if (line.variable) {
                input.classList.toggle('is-missing', !(line.price > 0));
              }
            } else if (field === 'name') {
              line.name = input.value || 'Item';
            }
            var totalCell = row.querySelector('.cart-line__total');
            if (totalCell) { totalCell.textContent = fmt(line.qty * line.price); }
            updateTotals();
          });
          input.addEventListener('change', function () {
            // On blur, sanitise the displayed value and refresh fully.
            renderCart();
          });
        });
      });
    }

    function updateTotals() {
      var subtotal = cart.reduce(function (s, l) { return s + (l.qty * l.price); }, 0);
      var value = num(discountVal.value);
      var amount = discountType === 'percent' ? subtotal * (value / 100) : value;
      discountOver = amount > subtotal + 0.005;            // total would go negative
      var shownAmount = discountOver ? subtotal : amount;  // clamp only for display
      var total = subtotal - shownAmount;
      var paid = num(paidInput.value);
      var change = paid - total;                          // + = change back, - = still due

      discountVal.classList.toggle('is-invalid', discountOver);
      subtotalEl.textContent = fmt(subtotal);
      discountAmtEl.textContent = '-' + fmt(shownAmount);
      totalEl.textContent = fmt(total);

      if (change >= 0.005) {
        changeEl.innerHTML = 'Change to return: <b>' + fmt(change) + '</b>';
        changeEl.style.display = 'block';
      } else if (change <= -0.005) {
        changeEl.innerHTML = 'Balance due: <b style="color:#b3261e">' + fmt(-change) + '</b>';
        changeEl.style.display = 'block';
      } else {
        changeEl.style.display = 'none';
      }
    }

    function saveBill() {
      if (cart.length === 0) {
        showStatus('Add at least one item before saving.', 'error');
        return;
      }
      if (discountOver) {
        showStatus('Discount cannot be greater than the subtotal.', 'error');
        return;
      }
      var missing = cart.filter(function (l) { return l.variable && !(l.price > 0); });
      if (missing.length > 0) {
        showStatus('Please enter a price for "' + missing[0].name + '".', 'error');
        return;
      }
      if (paidInput.value.trim() === '') {
        showStatus('Please enter the amount received (enter 0 if nothing was paid).', 'error');
        paidInput.focus();
        return;
      }
      var payload = {
        csrf_token: csrf,
        items: cart.map(function (l) {
          return { kind: l.kind, id: l.id, name: l.name, qty: l.qty, price: l.price };
        }),
        discount_type: discountType,
        discount_value: num(discountVal.value),
        amount_paid: num(paidInput.value)
      };

      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving…';
      showStatus('', '');

      fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data && data.ok) {
            window.location.href = 'bill-view.php?id=' + data.bill_id + '&print=1';
          } else {
            showStatus((data && data.error) || 'Could not save the bill.', 'error');
          }
        })
        .catch(function () {
          showStatus('Network error. Could not save the bill.', 'error');
        })
        .finally(function () {
          saveBtn.disabled = false;
          saveBtn.textContent = 'Save & Print';
        });
    }

    // --- Wire up catalog controls ---
    $all('.pos-tab', root).forEach(function (tab) {
      tab.addEventListener('click', function () {
        $all('.pos-tab', root).forEach(function (t) { t.classList.remove('is-active'); });
        tab.classList.add('is-active');
        activeTab = tab.getAttribute('data-tab') || 'all';
        renderCatalog();
      });
    });

    searchInput.addEventListener('input', function () {
      searchTerm = searchInput.value;
      renderCatalog();
    });
    searchInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        var first = gridEl.querySelector('.pos-item');
        if (first) { first.click(); }
      }
    });

    var customBtn = $('#addCustom', root);
    if (customBtn) {
      customBtn.addEventListener('click', addCustomLine);
    }

    var newBillBtn = $('#newBill', root);
    if (newBillBtn) {
      newBillBtn.addEventListener('click', function () {
        if (cart.length === 0) { return; }
        if (confirm('Start a new bill? The current items will be cleared.')) {
          cart = [];
          discountVal.value = '0';
          paidInput.value = '';
          renderCart();
          showStatus('', '');
        }
      });
    }

    // Discount type toggle + live recalculation.
    $all('.cart-discount__toggle button', root).forEach(function (btn) {
      btn.addEventListener('click', function () {
        $all('.cart-discount__toggle button', root).forEach(function (b) { b.classList.remove('is-active'); });
        btn.classList.add('is-active');
        discountType = btn.getAttribute('data-type') || 'fixed';
        updateTotals();
      });
    });
    discountVal.addEventListener('input', updateTotals);
    paidInput.addEventListener('input', updateTotals);

    saveBtn.addEventListener('click', saveBill);

    // Keyboard shortcuts: "/" or F2 focuses search; Ctrl+Enter saves.
    document.addEventListener('keydown', function (e) {
      var tag = (document.activeElement && document.activeElement.tagName) || '';
      var typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
      if ((e.key === '/' || e.key === 'F2') && !typing) {
        e.preventDefault();
        searchInput.focus();
      } else if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        saveBill();
      }
    });

    renderCatalog();
    renderCart();
  }

  /* ------------------------------------------------------------------------
   * Auto-print receipt (bill-view.php?print=1)
   * ---------------------------------------------------------------------- */
  function setupAutoPrint() {
    if (document.body.getAttribute('data-autoprint') === '1') {
      window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 200);
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    setupModals();
    setupPOS();
    setupAutoPrint();
  });
})();
