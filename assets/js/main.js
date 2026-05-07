/* ================================================================
   TUNEDIK — Main JavaScript
   ================================================================ */

'use strict';

/* ── Countdown Timer ──────────────────────────────────────── */
function initCountdown() {
  const el = document.getElementById('countdown-timer');
  if (!el) return;

  const expiryStr = el.dataset.expiry; // "YYYY-MM-DD"
  if (!expiryStr) return;

  const expiry = new Date(expiryStr + 'T23:59:59');

  function tick() {
    const now  = new Date();
    const diff = expiry - now;

    if (diff <= 0) {
      el.innerHTML = '<span class="text-danger fw-bold">EXPIRED</span>';
      return;
    }

    const days  = Math.floor(diff / 86400000);
    const hours = Math.floor((diff % 86400000) / 3600000);
    const mins  = Math.floor((diff % 3600000)  / 60000);
    const secs  = Math.floor((diff % 60000)    / 1000);

    el.innerHTML = `
      <div class="countdown-box"><div class="countdown-num">${String(days).padStart(2,'0')}</div><div class="countdown-lbl">Days</div></div>
      <div class="countdown-box"><div class="countdown-num">${String(hours).padStart(2,'0')}</div><div class="countdown-lbl">Hours</div></div>
      <div class="countdown-box"><div class="countdown-num">${String(mins).padStart(2,'0')}</div><div class="countdown-lbl">Mins</div></div>
      <div class="countdown-box"><div class="countdown-num">${String(secs).padStart(2,'0')}</div><div class="countdown-lbl">Secs</div></div>
    `;
  }

  tick();
  setInterval(tick, 1000);
}

/* ── Country/State Toggle ─────────────────────────────────── */
function initCountryStateToggle() {
  const countrySelect = document.getElementById('country');
  const stateGroup    = document.getElementById('state-group');
  const stateSelect   = document.getElementById('state');

  if (!countrySelect || !stateGroup) return;

  function toggle() {
    const isNigeria = countrySelect.value === 'Nigeria';
    stateGroup.style.display = isNigeria ? '' : 'none';
    if (stateSelect) stateSelect.required = isNigeria;
  }

  countrySelect.addEventListener('change', toggle);
  toggle(); // run on load
}

/* ── Payment Method Toggle ────────────────────────────────── */
function initPaymentMethodToggle() {
  const cards = document.querySelectorAll('.payment-method-card');
  if (!cards.length) return;

  cards.forEach(card => {
    card.addEventListener('click', function () {
      cards.forEach(c => c.classList.remove('selected'));
      this.classList.add('selected');

      const radio = this.querySelector('input[type="radio"]');
      if (radio) {
        radio.checked = true;
        radio.dispatchEvent(new Event('change'));
      }
    });
  });

  // Show/hide panels based on radio value
  const radios = document.querySelectorAll('input[name="payment_method"]');
  const panels = {
    paystack: document.getElementById('paystack-panel'),
    offline:  document.getElementById('offline-panel'),
  };

  function switchPanel(val) {
    Object.entries(panels).forEach(([key, panel]) => {
      if (panel) panel.style.display = key === val ? '' : 'none';
    });
  }

  radios.forEach(r => {
    r.addEventListener('change', () => switchPanel(r.value));
  });

  // Trigger for currently checked
  const checked = document.querySelector('input[name="payment_method"]:checked');
  if (checked) switchPanel(checked.value);
}

/* ── File Input Preview ───────────────────────────────────── */
function initFilePreview() {
  const fileInput   = document.getElementById('receipt-file');
  const previewWrap = document.getElementById('file-preview');
  if (!fileInput || !previewWrap) return;

  fileInput.addEventListener('change', function () {
    previewWrap.innerHTML = '';
    const file = this.files[0];
    if (!file) return;

    const maxMB = 5;
    if (file.size > maxMB * 1024 * 1024) {
      previewWrap.innerHTML = `<p class="text-danger small">File too large (max ${maxMB} MB).</p>`;
      this.value = '';
      return;
    }

    const allowed = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
    if (!allowed.includes(file.type)) {
      previewWrap.innerHTML = '<p class="text-danger small">Invalid file type. Use JPG, PNG, or PDF.</p>';
      this.value = '';
      return;
    }

    if (file.type.startsWith('image/')) {
      const reader = new FileReader();
      reader.onload = e => {
        previewWrap.innerHTML = `<img src="${e.target.result}" class="img-thumbnail mt-2" style="max-height:120px;">`;
      };
      reader.readAsDataURL(file);
    } else {
      previewWrap.innerHTML = `<p class="text-success small mt-2"><i class="bi bi-file-earmark-pdf me-1"></i>${file.name}</p>`;
    }
  });
}

/* ── Alert auto-dismiss ───────────────────────────────────── */
function initAlertDismiss() {
  setTimeout(() => {
    document.querySelectorAll('.alert.auto-dismiss').forEach(el => {
      const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
      bsAlert.close();
    });
  }, 6000);
}

/* ── Confirm dangerous actions ────────────────────────────── */
function initConfirmActions() {
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', function (e) {
      if (!confirm(this.dataset.confirm || 'Are you sure?')) {
        e.preventDefault();
      }
    });
  });
}

/* ── Logo / Favicon preview in settings ──────────────────── */
function initImagePreview(inputId, previewId) {
  const input   = document.getElementById(inputId);
  const preview = document.getElementById(previewId);
  if (!input || !preview) return;

  input.addEventListener('change', function () {
    const file = this.files[0];
    if (!file || !file.type.startsWith('image/')) return;
    const reader = new FileReader();
    reader.onload = e => { preview.src = e.target.result; preview.style.display = ''; };
    reader.readAsDataURL(file);
  });
}

/* ── Bootstrap tooltips ───────────────────────────────────── */
function initTooltips() {
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    new bootstrap.Tooltip(el);
  });
}

/* ── Init ─────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function () {
  initCountdown();
  initCountryStateToggle();
  initPaymentMethodToggle();
  initFilePreview();
  initAlertDismiss();
  initConfirmActions();
  initImagePreview('logo-upload', 'logo-preview');
  initImagePreview('favicon-upload', 'favicon-preview');
  initTooltips();
});
