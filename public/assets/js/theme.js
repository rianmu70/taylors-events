/*
 * Light and dark theme. Loaded in <head> before the page paints, so there is
 * no flash of the wrong theme. The visitor's choice is remembered on their
 * device; without a choice the system setting is followed.
 */
(function () {
  var KEY = 'taylors-events-theme';
  var root = document.documentElement;

  function stored() {
    try { return localStorage.getItem(KEY); } catch (e) { return null; }
  }
  function system() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function apply(theme) {
    root.setAttribute('data-theme', theme);
  }

  apply(stored() || system());

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-theme-toggle]');
    if (!button) { return; }
    var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    apply(next);
    try { localStorage.setItem(KEY, next); } catch (e) { /* private mode: the choice lasts for this page */ }
  });
})();
