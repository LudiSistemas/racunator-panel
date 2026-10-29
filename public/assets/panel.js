/*
 * Računator panel — minimalan JavaScript (bez inline skripti; CSP ih zabranjuje).
 * - potvrde za opasne radnje: atribut data-confirm na formi, linku ili dugmetu
 * - crtanje QR koda iz atributa data-qr (biblioteka qrcode.min.js, lokalno)
 * - kopiranje teksta: dugme sa data-copy="<id elementa>" (razmaci se izbacuju; element sa data-copy-exact ih zadržava)
 * - štampa: dugme sa data-print
 * - automatsko slanje forme pri izboru: <select data-autosubmit>
 */
(function () {
  'use strict';

  var SVG_NS = 'http://www.w3.org/2000/svg';

  // Potvrda pre slanja forme.
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    var message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }, true);

  // Potvrda za linkove i dugmad van formi sa potvrdom.
  document.addEventListener('click', function (event) {
    var target = event.target instanceof Element ? event.target.closest('a[data-confirm], button[data-confirm]') : null;
    if (!target) {
      return;
    }
    var message = target.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }, true);

  document.addEventListener('click', function (event) {
    if (!(event.target instanceof Element)) {
      return;
    }
    var copyButton = event.target.closest('[data-copy]');
    if (copyButton) {
      event.preventDefault();
      copyFrom(copyButton);
      return;
    }
    if (event.target.closest('[data-print]')) {
      event.preventDefault();
      window.print();
    }
  });

  document.addEventListener('change', function (event) {
    var select = event.target;
    if (select instanceof HTMLSelectElement && select.hasAttribute('data-autosubmit') && select.form) {
      select.form.submit();
    }
  });

  function copyFrom(button) {
    var source = document.getElementById(button.getAttribute('data-copy') || '');
    if (!source) {
      return;
    }
    // Razmaci služe samo za čitljivost (npr. TOTP tajna u grupama); data-copy-exact: razmak je deo vrednosti
    // (npr. PFR vreme „20.10.2026. 15:51").
    var text = source.hasAttribute('data-copy-exact')
      ? (source.textContent || '').replace(/\s+/g, ' ').trim()
      : (source.textContent || '').replace(/\s+/g, '');
    var done = function () { flash(button, 'Kopirano'); };
    var fallback = function () {
      var range = document.createRange();
      range.selectNodeContents(source);
      var selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
      try {
        document.execCommand('copy');
        done();
      } catch (e) {
        flash(button, 'Označeno — pritisnite Ctrl+C');
      }
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, fallback);
    } else {
      fallback();
    }
  }

  function flash(button, label) {
    var original = button.getAttribute('data-label') || button.textContent;
    button.setAttribute('data-label', original);
    button.textContent = label;
    window.setTimeout(function () { button.textContent = original; }, 1800);
  }

  function drawQr(element) {
    var text = element.getAttribute('data-qr') || '';
    if (typeof window.qrcode !== 'function') {
      element.textContent = 'QR kod ne može da se prikaže (biblioteka nije učitana).';
      return;
    }
    try {
      // JSON sadrži i slova č, ć, š, ž, đ — kodiramo kao UTF-8.
      if (window.qrcode.stringToBytesFuncs && window.qrcode.stringToBytesFuncs['UTF-8']) {
        window.qrcode.stringToBytes = window.qrcode.stringToBytesFuncs['UTF-8'];
      }
      var qr = window.qrcode(0, 'M');
      qr.addData(text, 'Byte');
      qr.make();
      var count = qr.getModuleCount();
      var margin = 4;
      var size = count + margin * 2;
      var path = '';
      for (var row = 0; row < count; row += 1) {
        for (var col = 0; col < count; col += 1) {
          if (qr.isDark(row, col)) {
            path += 'M' + (col + margin) + ' ' + (row + margin) + 'h1v1h-1z';
          }
        }
      }
      var svg = document.createElementNS(SVG_NS, 'svg');
      svg.setAttribute('viewBox', '0 0 ' + size + ' ' + size);
      svg.setAttribute('shape-rendering', 'crispEdges');
      svg.setAttribute('aria-hidden', 'true');
      var background = document.createElementNS(SVG_NS, 'rect');
      background.setAttribute('width', String(size));
      background.setAttribute('height', String(size));
      background.setAttribute('fill', '#ffffff');
      var modules = document.createElementNS(SVG_NS, 'path');
      modules.setAttribute('d', path);
      modules.setAttribute('fill', '#000000');
      svg.appendChild(background);
      svg.appendChild(modules);
      element.textContent = '';
      element.appendChild(svg);
    } catch (e) {
      element.textContent = 'QR kod ne može da se prikaže: ' + (e && e.message ? e.message : e);
    }
  }

  function init() {
    var codes = document.querySelectorAll('[data-qr]');
    for (var i = 0; i < codes.length; i += 1) {
      drawQr(codes[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
