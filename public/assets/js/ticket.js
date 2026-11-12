/*
 * Draws the QR code on the ticket page from the signed text in data-qr.
 * The QR library is bundled in vendor/, so this works with no network.
 */
(function () {
  'use strict';
  var box = document.querySelector('[data-qr]');
  if (!box || !window.QRCode) { return; }
  window.QRCode.toString(
    box.getAttribute('data-qr'),
    { type: 'svg', errorCorrectionLevel: 'M', margin: 1 },
    function (error, svg) {
      if (!error) { box.innerHTML = svg; }
    }
  );
})();
