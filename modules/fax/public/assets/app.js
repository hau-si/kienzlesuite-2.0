// Modul Fax: Rückfragen, weitere Empfänger, Suche im Telefonbuch, laufende Aufträge alle 5 Sekunden neu laden.
document.addEventListener('submit', (e) => {
  const f = e.target.closest('form[data-confirm]');
  if (f && !window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
  const b = e.submitter;
  if (b && b.hasAttribute('data-frage') && !window.confirm(b.getAttribute('data-frage'))) e.preventDefault();
});
document.addEventListener('click', (e) => {
  const mehr = e.target.closest('[data-mehr]');
  if (!mehr) return;
  const naechster = document.querySelector('fieldset[data-weitere][hidden]');
  if (naechster) naechster.hidden = false;
  if (!document.querySelector('fieldset[data-weitere][hidden]')) mehr.hidden = true;
});
document.querySelectorAll('input[data-filter]').forEach((feld) => {
  const tabelle = document.querySelector(feld.getAttribute('data-filter'));
  feld.addEventListener('input', () => {
    const q = feld.value.trim().toLowerCase();
    tabelle.querySelectorAll('tr[data-text]').forEach((tr) => { tr.hidden = q !== '' && !tr.getAttribute('data-text').includes(q); });
  });
});
const aktiv = document.getElementById('fx-aktiv');
if (aktiv) {
  setInterval(async () => {
    if (document.hidden) return;
    try {
      const r = await fetch(aktiv.getAttribute('data-status'), { credentials: 'same-origin', headers: { Accept: 'text/html' } });
      if (r.ok) aktiv.innerHTML = await r.text();
    } catch (_) { /* nächster Versuch */ }
  }, 5000);
}
// Aufbewahrung: „unbegrenzt“ sperrt das Zahlenfeld.
document.querySelectorAll('input[data-schalter]').forEach((haken) => {
  const feld = document.querySelector('input[name="' + haken.getAttribute('data-schalter') + '"]');
  const umschalten = () => { if (feld) feld.disabled = haken.checked; };
  haken.addEventListener('change', umschalten);
  umschalten();
});
// Fax senden: Dateien auf die Fläche ziehen (oder wählen); sie werden hochgeladen und unten ausgewählt.
const flaeche = document.getElementById('fx-drop');
if (flaeche) {
  const hochladen = async (dateien) => {
    if (!dateien || !dateien.length) return;
    const daten = new FormData();
    daten.append('kienzle-csrf', flaeche.getAttribute('data-csrf'));
    daten.append('aktion', 'hochladen');
    for (const d of dateien) daten.append('dateien[]', d, d.name);
    flaeche.classList.add('fx-laedt');
    let antwort = null;
    try {
      const r = await fetch(flaeche.getAttribute('data-ziel'), { method: 'POST', body: daten, credentials: 'same-origin', headers: { 'X-Requested-With': 'fx' } });
      antwort = await r.json();
    } catch (_) { antwort = { ok: false, fehler: 'Hochladen nicht möglich. Bitte die Seite neu laden.' }; }
    flaeche.classList.remove('fx-laedt');
    if (antwort && antwort.ok) {
      sessionStorage.setItem('fx-meldung', antwort.meldung || '');
      sessionStorage.setItem('fx-vor', JSON.stringify(antwort.dateien || []));
      window.location.href = flaeche.getAttribute('data-ziel') + '?quelle=Upload';
    } else {
      let m = document.getElementById('fx-drop-fehler');
      if (!m) { m = document.createElement('p'); m.id = 'fx-drop-fehler'; m.className = 'fehler'; m.setAttribute('role', 'alert'); flaeche.after(m); }
      m.textContent = (antwort && antwort.fehler) || 'Hochladen fehlgeschlagen.';
    }
  };
  ['dragenter', 'dragover'].forEach((n) => flaeche.addEventListener(n, (e) => { e.preventDefault(); flaeche.classList.add('fx-ueber'); }));
  ['dragleave', 'drop'].forEach((n) => flaeche.addEventListener(n, (e) => { e.preventDefault(); flaeche.classList.remove('fx-ueber'); }));
  flaeche.addEventListener('drop', (e) => hochladen(e.dataTransfer && e.dataTransfer.files));
  const wahl = document.getElementById('fx-datei');
  if (wahl) wahl.addEventListener('change', () => { hochladen(wahl.files); wahl.value = ''; });
  // Nach dem Hochladen: Meldung zeigen und die neuen Dateien ankreuzen.
  try {
    const vor = JSON.parse(sessionStorage.getItem('fx-vor') || '[]');
    const meldung = sessionStorage.getItem('fx-meldung');
    sessionStorage.removeItem('fx-vor'); sessionStorage.removeItem('fx-meldung');
    vor.forEach((name) => { const h = document.querySelector('input[name="dateien[]"][value="' + CSS.escape(name) + '"]'); if (h) h.checked = true; });
    if (meldung) { const m = document.createElement('p'); m.className = 'ok'; m.setAttribute('role', 'status'); m.textContent = meldung; flaeche.before(m); }
  } catch (_) { /* ohne Speicher: einfach ohne Vorauswahl */ }
}
