// Unterschrift: Aufklappbereiche per Adresse öffnen (#vorlagen) und Löschen bestätigen. Keine Inline-Skripte (CSP).
(function () {
  'use strict';
  function oeffneAusAdresse() {
    var id = (location.hash || '').replace(/^#/, '');
    if (!/^[a-z-]{1,30}$/.test(id)) return;
    var el = document.getElementById(id);
    if (el && el.tagName === 'DETAILS') { el.open = true; el.scrollIntoView(); }
  }
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-bestaetigen]') : null;
    if (b && !window.confirm(b.getAttribute('data-bestaetigen'))) e.preventDefault();
  });
  window.addEventListener('hashchange', oeffneAusAdresse);
  oeffneAusAdresse();
})();
