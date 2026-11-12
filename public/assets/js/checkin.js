/*
 * Door scanner.
 *
 * Reads QR codes from the phone or laptop camera with the bundled jsQR
 * library and posts each one to the server, which decides whether to admit.
 * This file decides nothing: it shows what the server answered.
 * All wording comes from the page (data-text-* attributes and the server's
 * "message"), so it is edited in app/Views/lang/en.php like everything else.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-scanner]');
  if (!root) { return; }

  var endpoint = root.dataset.endpoint;
  var csrf = root.dataset.csrf;
  var video = root.querySelector('[data-video]');
  var canvas = root.querySelector('[data-canvas]');
  var view = root.querySelector('[data-view]');
  var status = root.querySelector('[data-status]');
  var result = root.querySelector('[data-result]');
  var startButton = root.querySelector('[data-start]');
  var stopButton = root.querySelector('[data-stop]');
  var manual = root.querySelector('[data-manual]');
  var countIn = root.querySelector('[data-count-in]');
  var countTotal = root.querySelector('[data-count-total]');

  var stream = null;
  var busy = false;
  var lastCode = '';
  var lastTime = 0;

  function show(admitted, message) {
    result.hidden = false;
    result.textContent = message;
    result.className = 'scanner__result ' + (admitted ? 'scanner__result--ok' : 'scanner__result--no');
    if (navigator.vibrate) { navigator.vibrate(admitted ? 80 : [80, 60, 80]); }
  }

  function send(body) {
    if (busy) { return Promise.resolve(); }
    busy = true;
    return fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body)
    })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        show(data.admitted === true, data.message || root.dataset.textOffline);
        if (data.admitted && data.registration_id) {
          var pill = root.parentNode.querySelector('tr[data-registration="' + data.registration_id + '"] .pill');
          if (pill) {
            pill.className = 'pill pill--checked_in';
            pill.textContent = data.status_label;
          }
        }
        if (data.counts) {
          countIn.textContent = data.counts.checked_in;
          countTotal.textContent = data.counts.confirmed;
        }
      })
      .catch(function () { show(false, root.dataset.textOffline); })
      .then(function () { busy = false; });
  }

  function tick() {
    if (!stream) { return; }
    if (video.readyState === video.HAVE_ENOUGH_DATA && !busy) {
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      var context = canvas.getContext('2d', { willReadFrequently: true });
      context.drawImage(video, 0, 0, canvas.width, canvas.height);
      var image = context.getImageData(0, 0, canvas.width, canvas.height);
      var code = window.jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' });
      var now = Date.now();
      // The same code held in front of the camera is sent once, not thirty times a second.
      if (code && code.data && (code.data !== lastCode || now - lastTime > 4000)) {
        lastCode = code.data;
        lastTime = now;
        send({ payload: code.data });
      }
    }
    window.requestAnimationFrame(tick);
  }

  function start() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.jsQR) {
      status.textContent = root.dataset.textDenied;
      return;
    }
    status.textContent = root.dataset.textStarting;
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false })
      .then(function (media) {
        stream = media;
        video.srcObject = media;
        view.hidden = false;
        startButton.hidden = true;
        stopButton.hidden = false;
        status.textContent = root.dataset.textReady;
        return video.play();
      })
      .then(function () { window.requestAnimationFrame(tick); })
      .catch(function () { status.textContent = root.dataset.textDenied; });
  }

  function stop() {
    if (stream) {
      stream.getTracks().forEach(function (track) { track.stop(); });
      stream = null;
    }
    view.hidden = true;
    startButton.hidden = false;
    stopButton.hidden = true;
    status.textContent = '';
  }

  startButton.addEventListener('click', start);
  stopButton.addEventListener('click', stop);
  window.addEventListener('pagehide', stop);

  manual.addEventListener('submit', function (event) {
    event.preventDefault();
    var input = manual.querySelector('input[name="code"]');
    send({ code: input.value }).then(function () { input.value = ''; input.focus(); });
  });
})();
