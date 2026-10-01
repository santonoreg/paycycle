document.addEventListener('DOMContentLoaded', function () {
  // Μεταφράσεις/μορφές που δίνει ο server (βλ. includes/footer.php)
  var I18N = window.I18N || { freq: {}, js: {}, dateFormat: 'd/m/Y', locale: 'el-GR' };
  var FREQ_LABELS = I18N.freq;

  function tpl(str, params) {
    return String(str).replace(/\{(\w+)\}/g, function (m, k) { return params[k] !== undefined ? params[k] : m; });
  }

  function fmtDate(ymd) {
    if (!ymd) return '—';
    var parts = ymd.split('-');
    return I18N.dateFormat.replace(/[dmY]/g, function (c) {
      return c === 'd' ? parts[2] : (c === 'm' ? parts[1] : parts[0]);
    });
  }

  function fmtEuro(n) {
    var v = Number(n).toFixed(2);
    var parts = v.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return '€ ' + parts.join('.');
  }


  // Πεδία που αφορούν μόνο επαναλαμβανόμενες πληρωμές (δόσεις, μεταβλητό ποσό):
  // κρύβονται ΚΑΙ απενεργοποιούνται για συνδρομές, ώστε να μην αποστέλλονται.
  function applyKind(root, kind) {
    if (!root) return;
    root.querySelectorAll('.rec-only').forEach(function (el) {
      var on = kind === 'recurring';
      el.classList.toggle('d-none', !on);
      el.querySelectorAll('input, select, textarea').forEach(function (i) { i.disabled = !on; });
    });
  }

  var addKind = document.getElementById('add-kind');
  if (addKind) {
    var addForm = addKind.closest('form');
    addKind.addEventListener('change', function () { applyKind(addForm, addKind.value); });
    applyKind(addForm, addKind.value);
  }


  // --- Τρόπος πληρωμής: η επιλογή κάρτας φαίνεται μόνο όταν επιλεγεί "Κάρτα" ---
  function syncPm(scope) {
    if (!scope) return;
    var pm = scope.querySelector('.js-pm'), card = scope.querySelector('.js-card');
    if (!pm || !card) return;
    var on = pm.value === 'card';
    card.classList.toggle('d-none', !on);
    card.disabled = !on;
    var empty = scope.querySelector('.js-card-empty');
    if (empty) empty.classList.toggle('d-none', !on);
  }
  function setPm(prefix, method, cardId) {
    var pm = document.getElementById(prefix + '-pm');
    if (!pm) return;
    pm.querySelectorAll('option[data-legacy]').forEach(function (o) { o.remove(); });
    if (method && method !== 'cash' && method !== 'card') {
      // Παλιά εγγραφή με ελεύθερο κείμενο: διατηρείται όπως ήταν
      var o = document.createElement('option');
      o.value = method;
      o.textContent = method + ' (' + I18N.js.legacy_method + ')';
      o.setAttribute('data-legacy', '1');
      pm.appendChild(o);
    }
    pm.value = method || '';
    var card = document.getElementById(prefix + '-card');
    if (card && cardId) card.value = cardId;
    syncPm(pm.parentNode);
  }
  document.querySelectorAll('.js-pm').forEach(function (pm) {
    pm.addEventListener('change', function () { syncPm(pm.parentNode); });
  });

  // --- Μηνύματα (toast): εμφανίζονται και εξαφανίζονται μόνα τους ---
  document.querySelectorAll('.toast-container .toast').forEach(function (el) {
    bootstrap.Toast.getOrCreateInstance(el).show();
  });

  // --- Όνομα εγγραφής: άνοιγμα λεπτομερειών ---
  document.querySelectorAll('.js-name-open').forEach(function (el) {
    function go() {
      var b = el.closest('tr').querySelector('.js-open-details');
      if (b) b.click();
    }
    el.addEventListener('click', go);
    el.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); }
    });
  });

  // --- Αντιγραφή εγγραφής: ανοίγει τη φόρμα "Νέα πληρωμή" προσυμπληρωμένη ---
  var addModalEl = document.getElementById('addModal');
  var addFormEl = document.getElementById('addForm');
  function resetAddForm() {
    if (!addFormEl) return;
    addFormEl.reset();
    document.getElementById('add-copy-from').value = '';
    document.getElementById('dup-hint').classList.add('d-none');
    document.getElementById('addModalTitleText').textContent = I18N.js.new_title;
    var kindSel = document.getElementById('add-kind');
    if (kindSel) applyKind(addFormEl, kindSel.value);
    syncPm(addFormEl);
  }
  if (addModalEl && addFormEl) {
    addModalEl.addEventListener('hidden.bs.modal', resetAddForm);
    document.querySelectorAll('.js-duplicate').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var src = btn.closest('tr').querySelector('.js-open-details');
        if (!src) return;
        var d = src.dataset;
        resetAddForm();
        function field(n) { return addFormEl.elements.namedItem(n); }
        var kindSel = document.getElementById('add-kind');
        kindSel.value = d.kind;
        applyKind(addFormEl, d.kind);
        field('name').value = d.name || '';
        field('category').value = d.category || '';
        field('frequency').value = d.frequency || 'monthly';
        field('cost').value = d.currentPrice || '';
        field('notes').value = d.notes || '';
        if (d.installments) field('installments').value = d.installments;
        field('variable_amount').checked = d.variable === '1';
        setPm('add', d.paymentMethod, d.cardId);
        document.getElementById('add-copy-from').value = d.id;
        var hint = document.getElementById('dup-hint');
        hint.textContent = tpl(I18N.js.dup_hint, { name: d.name });
        hint.classList.remove('d-none');
        document.getElementById('addModalTitleText').textContent = I18N.js.dup_title;
        bootstrap.Modal.getOrCreateInstance(addModalEl).show();
      });
    });
  }

  var detailsModal = document.getElementById('detailsModal');
  if (detailsModal) {
    detailsModal.addEventListener('show.bs.modal', function (event) {
      var btn = event.relatedTarget;
      if (!btn) return;
      var d = btn.dataset;

      var titleEl = document.getElementById('detailsModalTitle');
      if (titleEl) titleEl.textContent = d.name;

      // Κατάσταση: πράσινο όταν η εγγραφή είναι ενεργή, πορτοκαλί όταν δεν είναι
      var isOk = d.status === 'active' || d.status === 'trial';
      var pill = document.getElementById('detailsStatus');
      if (pill) {
        pill.textContent = (I18N.status || {})[d.status] || d.status;
        pill.className = 'status-pill ms-2 ' + (isOk ? 'status-pill-ok' : 'status-pill-off');
      }
      var head = detailsModal.querySelector('.modal-header');
      if (head) {
        head.classList.toggle('modal-status-ok', isOk);
        head.classList.toggle('modal-status-off', !isOk);
      }

      // Editable fields (logged in)
      setVal('edit-id', d.id);
      setVal('edit-name', d.name);
      setVal('edit-category', d.category);
      setVal('edit-frequency', d.frequency);
      setPm('edit', d.paymentMethod, d.cardId);
      setVal('edit-end-date', d.endDate);
      setVal('edit-start-date', d.startDate);
      setVal('edit-notes', d.notes);
      setVal('edit-installments', d.installments);
      applyKind(detailsModal, d.kind);
      var editVariable = document.getElementById('edit-variable');
      if (editVariable) editVariable.checked = d.variable === '1';
      setVal('edit-estimate', d.estimate);
      syncEstimateVisibility();
      setVal('price-sub-id', d.id);

      // Read-only fields (not logged in)
      setText('ro-category', d.category);
      setText('ro-frequency', FREQ_LABELS[d.frequency] || d.frequency);
      setText('ro-payment-method', d.paymentLabel || '—');
      setText('ro-end-date', d.endDate ? fmtDate(d.endDate) : '—');
      setText('ro-start-date', fmtDate(d.startDate));
      setText('ro-notes', d.notes || '—');
      setText('ro-installments', d.installments || '—');
      setText('added-by', d.addedBy || '—');

      // Payments ledger
      var payments = [];
      try { payments = JSON.parse(d.payments || '[]'); } catch (e) {}
      var paymentsTotalEl = document.getElementById('payments-total');
      if (paymentsTotalEl) {
        var total = payments.reduce(function (sum, p) { return sum + Number(p.amount); }, 0);
        paymentsTotalEl.textContent = tpl(I18N.js.installments_total, { n: payments.length, total: fmtEuro(total) });
      }
      var sortedPayments = payments.slice().sort(function (a, b) { return b.payment_date.localeCompare(a.payment_date); });
      var paymentsList = document.getElementById('payments-list');
      if (paymentsList) {
        if (sortedPayments.length === 0) {
          paymentsList.innerHTML = '<div class="text-muted small">' + escapeHtml(I18N.js.no_payments) + '</div>';
        } else {
          var payCsrf = document.querySelector('#editPriceForm [name="csrf_token"]');
          var payBack = document.querySelector('#editPriceForm [name="back"]');
          // Μεταβλητό ποσό + συνδεδεμένος διαχειριστής: κάθε γραμμή έχει πεδίο για το πραγματικό ποσό του λογαριασμού
          var canEditAmounts = !!payCsrf && d.variable === '1';
          paymentsList.innerHTML = sortedPayments.map(function (p) {
            var badge = p.is_estimate ? ' <span class="awaiting-badge"><i class="bi bi-hourglass-split"></i> ' + escapeHtml(I18N.js.awaiting_bill) + '</span>' : '';
            if (canEditAmounts) {
              return '<div class="entry"><form method="post" action="actions/confirm_payment.php" class="d-flex align-items-center gap-2 flex-wrap">' +
                '<input type="hidden" name="csrf_token" value="' + escapeHtml(payCsrf.value) + '">' +
                (payBack ? '<input type="hidden" name="back" value="' + escapeHtml(payBack.value) + '">' : '') +
                '<input type="hidden" name="id" value="' + escapeHtml(d.id) + '">' +
                '<input type="hidden" name="payment_date" value="' + escapeHtml(p.payment_date) + '">' +
                '<span class="text-muted">' + fmtDate(p.payment_date) + '</span>' + badge +
                '<span class="ms-auto d-flex gap-1"><input type="number" step="0.01" min="0" name="amount" value="' + Number(p.amount).toFixed(2) + '" required ' +
                'class="form-control form-control-sm num' + (p.is_estimate ? ' estimate-input' : '') + '" style="width:110px">' +
                '<button type="submit" class="btn btn-sm btn-outline-primary py-0 px-2" title="' + escapeHtml(I18N.js.save_amount) + '"><i class="bi bi-check-lg"></i></button></span>' +
                '</form></div>';
            }
            return '<div class="entry"><strong class="num">' + (p.is_estimate ? '≈ ' : '') + fmtEuro(p.amount) + '</strong> ' +
              '<span class="text-muted">' + fmtDate(p.payment_date) + '</span>' + badge + '</div>';
          }).join('');
        }
      }

      // Price history
      var prices = [];
      try { prices = JSON.parse(d.prices || '[]'); } catch (e) {}
      prices.sort(function (a, b) { return b.effective_from.localeCompare(a.effective_from); });
      var pricesList = document.getElementById('prices-list');
      if (pricesList) {
        if (prices.length === 0) {
          pricesList.innerHTML = '<div class="text-muted small">' + escapeHtml(I18N.js.no_prices) + '</div>';
        } else {
          var csrfInput = document.querySelector('#editPriceForm [name="csrf_token"]');
          var backInput = document.querySelector('#editPriceForm [name="back"]');
          pricesList.innerHTML = prices.map(function (p) {
            var del = '';
            if (csrfInput && p.deletable) {
              del = ' <form method="post" action="actions/delete_price.php" class="d-inline ms-2" ' +
                'onsubmit="return confirm(' + escapeHtml(JSON.stringify(I18N.js.confirm_delete_price)) + ');">' +
                '<input type="hidden" name="csrf_token" value="' + csrfInput.value + '">' +
                (backInput ? '<input type="hidden" name="back" value="' + escapeHtml(backInput.value) + '">' : '') +
                '<input type="hidden" name="id" value="' + d.id + '">' +
                '<input type="hidden" name="price_id" value="' + p.id + '">' +
                '<button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" title="' + escapeHtml(I18N.js.delete_price) + '">' +
                '<i class="bi bi-trash"></i></button></form>';
            }
            return '<div class="entry"><strong class="num">' + fmtEuro(p.cost) + '</strong> ' +
              '<span class="text-muted">' + escapeHtml(tpl(I18N.js.from, { date: fmtDate(p.effective_from) })) + '</span>' + del + '</div>';
          }).join('');
        }
      }

      // Freeze history
      var freezes = [];
      try { freezes = JSON.parse(d.freezes || '[]'); } catch (e) {}
      freezes.sort(function (a, b) { return b.frozen_from.localeCompare(a.frozen_from); });
      var freezesList = document.getElementById('freezes-list');
      if (freezesList) {
        if (freezes.length === 0) {
          freezesList.innerHTML = '<div class="text-muted small">' + escapeHtml(I18N.js.no_freezes) + '</div>';
        } else {
          freezesList.innerHTML = freezes.map(function (f) {
            var until = f.frozen_until ? fmtDate(f.frozen_until) : escapeHtml(I18N.js.until_today);
            return '<div class="entry"><i class="bi bi-snow2 text-primary"></i> ' +
              fmtDate(f.frozen_from) + ' &rarr; ' + until + '</div>';
          }).join('');
        }
      }

      // Activity log (who did what)
      var activity = [];
      try { activity = JSON.parse(d.activity || '[]'); } catch (e) {}
      var activityList = document.getElementById('activity-list');
      if (activityList) {
        if (activity.length === 0) {
          activityList.innerHTML = '<div class="text-muted small">' + escapeHtml(I18N.js.no_activity) + '</div>';
        } else {
          activityList.innerHTML = activity.map(function (a) {
            return '<div class="entry"><div><i class="bi bi-person"></i> <strong>' + escapeHtml(a.who) + '</strong> ' +
              '<span class="text-muted small">' + escapeHtml(a.at) + '</span></div>' +
              '<div class="small">' + escapeHtml(a.text) + '</div></div>';
          }).join('');
        }
      }
    });
  }

  // Το πεδίο εκτίμησης φαίνεται μόνο όταν είναι τσεκαρισμένο το "Μεταβλητό ποσό"
  function syncEstimateVisibility() {
    var cb = document.getElementById('edit-variable');
    var wrap = document.getElementById('edit-estimate-wrap');
    if (cb && wrap) wrap.classList.toggle('d-none', !cb.checked);
  }
  var editVariableBox = document.getElementById('edit-variable');
  if (editVariableBox) editVariableBox.addEventListener('change', syncEstimateVisibility);

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function setVal(id, value) {
    var el = document.getElementById(id);
    if (el) el.value = value || '';
  }
  function setText(id, value) {
    var el = document.getElementById(id);
    if (el) el.textContent = value;
  }
});
