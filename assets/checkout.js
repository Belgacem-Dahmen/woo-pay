/**
 * Woo Pay – Stellar checkout helpers.
 *
 * Beginners: this file runs in the browser on checkout + thank-you pages.
 * It only adds "Copy" buttons and (on thank-you) auto-refreshes every 30s
 * so the customer sees the paid status without manual reloads.
 */
(function () {
  'use strict';

  function copyText(text, btn) {
    var done = function () {
      var original = btn.textContent;
      var label = (window.WooPayStellar && window.WooPayStellar.copied) || 'Copied!';
      btn.textContent = label;
      setTimeout(function () { btn.textContent = original; }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, done);
    } else {
      var ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (e) { /* ignore */ }
      document.body.removeChild(ta);
      done();
    }
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;

    // Copy wallet address (checkout + thank-you).
    if (t.classList.contains('woo-pay-copy')) {
      var box = t.closest('.woo-pay-stellar-box, .woo-pay-instructions');
      var addr = box ? box.querySelector('.woo-pay-address') : null;
      if (addr) copyText(addr.textContent.trim(), t);
    }

    // Copy memo (thank-you page).
    if (t.classList.contains('woo-pay-copy-memo')) {
      var memo = document.querySelector('.woo-pay-memo');
      if (memo) copyText(memo.textContent.trim(), t);
    }
  });

  // Auto-refresh thank-you page every 30s while order is on-hold.
  // Server marks it paid via Horizon check; refresh reveals the new status.
  if (document.querySelector('.woo-pay-instructions')) {
    setTimeout(function () { window.location.reload(); }, 30000);
  }
})();
