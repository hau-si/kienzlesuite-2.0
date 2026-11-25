// Signatur: Aufklappbereiche per Adresse öffnen (#vorlagen) und Löschen bestätigen. Keine Inline-Skripte (CSP).
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
  // Start aus T2med: #launch=<Code> einlösen (einmalig) und den Patienten in „Neuer Vorgang“ eintragen.
  (function () {
    var m = /^#launch=([0-9a-f]{64})$/.exec(location.hash || '');
    var feld = document.getElementById('n-patient');
    if (!m || !feld) return;
    history.replaceState(null, '', location.pathname + location.search);
    var csrf = document.querySelector('meta[name="kienzle-csrf"]');
    var daten = new URLSearchParams({ 'kienzle-csrf': csrf ? csrf.content : '', code: m[1] });
    fetch(location.pathname.replace(/\/$/, '') + '/start-einloesen', { method: 'POST', body: daten, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (a) {
        var hinweis = document.createElement('p');
        hinweis.className = a.ok ? 'notice' : 'error';
        if (a.ok) {
          feld.value = a.j.patient || '';
          var k = document.getElementById('n-kontext');
          if (k) k.value = a.j.kontext || '';
          hinweis.textContent = 'Patient aus T2med übernommen: ' + (a.j.patient || '');
        } else {
          hinweis.textContent = a.j.error || 'Der Start aus T2med ist fehlgeschlagen.';
        }
        feld.form.insertBefore(hinweis, feld.form.firstChild);
        if (a.ok) feld.form.scrollIntoView({ block: 'nearest' });
      })
      .catch(function () { /* ohne Netz bleibt die Startseite wie gewohnt */ });
  })();
  // Vorgangsliste: Suche und Filter wirken sofort im Browser.
  var tabelle = document.getElementById('vg-tabelle');
  if (tabelle) {
    var suche = document.getElementById('vg-suche'), art = document.getElementById('vg-art'), stat = document.getElementById('vg-status');
    var zeilen = Array.prototype.slice.call(tabelle.tBodies[0].rows);
    var filtern = function () {
      var q = suche.value.trim().toLowerCase(), n = 0;
      zeilen.forEach(function (z) {
        var ok = (!q || (z.getAttribute('data-suche') || '').indexOf(q) !== -1) && (!art.value || z.getAttribute('data-art') === art.value) && (!stat.value || z.getAttribute('data-status') === stat.value);
        z.hidden = !ok;
        if (ok) n++;
      });
      document.getElementById('vg-zahl').textContent = n + ' von ' + zeilen.length + ' Vorgängen';
      document.getElementById('vg-leer').hidden = n !== 0;
    };
    [suche, art, stat].forEach(function (e) { e.addEventListener('input', filtern); e.addEventListener('change', filtern); });
    filtern();
  }
  // Solange ein Vorgang am Pad unterschrieben wird, die Liste alle 5 Sekunden neu laden (ohne das Formular erneut zu senden).
  if (document.querySelector('.us-status.s-am_pad')) setTimeout(function () { location.replace(location.pathname + location.search); }, 5000);
  // Pad-Software hochladen: ZIP roh senden (file_uploads ist für Module aus), Ergebnis in der Seite zeigen.
  var los = document.getElementById('pad-los');
  if (los) los.addEventListener('click', function () {
    var datei = document.getElementById('pad-datei').files[0];
    var status = document.getElementById('pad-status');
    var meta = document.querySelector('meta[name="kienzle-csrf"]');
    if (!datei) { status.textContent = 'Bitte zuerst die ZIP-Datei auswählen.'; return; }
    if (!document.getElementById('pad-lizenz').checked) { status.textContent = 'Bitte den Lizenzvertrag bestätigen.'; return; }
    if (datei.size > 8000000) { status.textContent = 'Die Datei ist zu groß (höchstens 8 MB).'; return; }
    los.disabled = true;
    status.textContent = 'Wird hochgeladen und geprüft …';
    fetch(los.getAttribute('data-ziel'), { method: 'POST', credentials: 'same-origin', body: datei,
      headers: { 'Content-Type': 'application/octet-stream', 'X-Kienzle-CSRF': meta ? meta.content : '', 'X-Lizenz': '1' } })
      .then(function (r) {
        // Antwort als Text lesen: Kommt keine JSON-Antwort (z. B. Fehlerseite des Servers), steht der Status in der Meldung.
        return r.text().then(function (t) {
          var j = null;
          try { j = JSON.parse(t); } catch (e) { j = null; }
          return { ok: r.ok, status: r.status, j: j, text: t };
        });
      })
      .then(function (x) {
        if (x.ok && x.j && x.j.ok) { status.textContent = 'Fertig. Die Seite wird neu geladen …'; setTimeout(function () { location.hash = '#pad'; location.reload(); }, 700); return; }
        if (x.j && x.j.error) status.textContent = x.j.error;
        else status.textContent = 'Der Server hat nicht wie erwartet geantwortet (HTTP ' + x.status + '): ' + x.text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160);
        los.disabled = false;
      })
      .catch(function (e) { status.textContent = 'Die Verbindung wurde unterbrochen (' + (e && e.message ? e.message : 'unbekannt') + '). Bitte noch einmal versuchen; steht das wieder da, bitte die Meldung weitergeben.'; los.disabled = false; });
  });
  window.addEventListener('hashchange', oeffneAusAdresse);
  oeffneAusAdresse();
})();
