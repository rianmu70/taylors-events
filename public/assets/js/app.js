/*
 * Small behaviours shared by every page. No library, no build step.
 * Everything here is an enhancement: each form still works with JavaScript off.
 */
(function () {
  'use strict';

  // <form data-confirm="Question?"> asks before it is sent.
  document.addEventListener('submit', function (event) {
    var form = event.target;
    var question = form.getAttribute('data-confirm');
    if (question && !window.confirm(question)) {
      event.preventDefault();
      return;
    }
    // <form data-once> can be sent only once, so a double click cannot book twice.
    // The server is protected as well, by the idempotency key in the form.
    if (form.hasAttribute('data-once')) {
      if (form.dataset.sent === '1') {
        event.preventDefault();
        return;
      }
      form.dataset.sent = '1';
      var button = form.querySelector('button[type="submit"]');
      if (button) { button.disabled = true; }
    }
  });

  // <button data-print> prints the page (ticket, certificate).
  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-print]')) {
      window.print();
    }
  });
})();
