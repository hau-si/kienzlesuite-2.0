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
  // Jede Datei einzeln roh senden (kein Formular-Upload: der hing auf dem Server ohne Meldung), mit Fortschrittsbalken und
  // höchstens 60 Sekunden je Datei. XMLHttpRequest, weil fetch beim Hochladen keinen Fortschritt meldet.
  const fehlerZeigen = (text) => {
    let m = document.getElementById('fx-drop-fehler');
    if (!m) { m = document.createElement('p'); m.id = 'fx-drop-fehler'; m.className = 'fehler'; m.setAttribute('role', 'alert'); flaeche.after(m); }
    m.textContent = text;
  };
  const anzeige = () => {
    let a = document.getElementById('fx-fortschritt');
    if (!a) {
      a = document.createElement('div'); a.id = 'fx-fortschritt'; a.className = 'fx-fortschritt'; a.setAttribute('role', 'status');
      a.innerHTML = '<span class="fx-fortschritt-text"></span><progress max="100" value="0"></progress>';
      flaeche.appendChild(a);
    }
    a.hidden = false;
    return a;
  };
  const senden = (d, schritt) => new Promise((fertig) => {
    const x = new XMLHttpRequest();
    x.open('POST', flaeche.getAttribute('data-hochladen'));
    x.timeout = 60000;
    x.setRequestHeader('Content-Type', 'application/octet-stream');
    x.setRequestHeader('X-Kienzle-CSRF', flaeche.getAttribute('data-csrf'));
    x.setRequestHeader('X-Dateiname', encodeURIComponent(d.name));
    x.upload.onprogress = (e) => { if (e.lengthComputable) schritt(Math.round(e.loaded / e.total * 100)); };
    x.onload = () => {
      let j = null; try { j = JSON.parse(x.responseText); } catch (_) { j = null; }
      fertig(x.status >= 200 && x.status < 300 && j && j.ok ? { datei: j.datei } : { fehler: (j && j.fehler) || ('„' + d.name + '“ ließ sich nicht hochladen (HTTP ' + x.status + ').') });
    };
    x.onerror = () => fertig({ fehler: '„' + d.name + '“: keine Verbindung zum Server.' });
    x.ontimeout = () => fertig({ fehler: '„' + d.name + '“: keine Antwort vom Server (Zeit überschritten).' });
    x.send(d);
  });
  const hochladen = async (dateien) => {
    if (!dateien || !dateien.length) return;
    const liste = Array.from(dateien);
    if (liste.length > 20) { fehlerZeigen('Höchstens 20 Dateien auf einmal.'); return; }
    const alt = document.getElementById('fx-drop-fehler'); if (alt) alt.remove();
    flaeche.classList.add('fx-laedt');
    const a = anzeige(); const text = a.querySelector('.fx-fortschritt-text'); const balken = a.querySelector('progress');
    const neu = []; const fehler = [];
    for (let i = 0; i < liste.length; i++) {
      const d = liste[i];
      const kopf = (liste.length > 1 ? 'Datei ' + (i + 1) + ' von ' + liste.length + ': ' : '') + d.name;
      const schritt = (p) => { balken.value = p; text.textContent = kopf + ' – ' + (p < 100 ? p + ' %' : 'wird umgewandelt …'); };
      schritt(0);
      if (d.size > 7 * 1024 * 1024) { fehler.push('„' + d.name + '“ ist zu groß (höchstens 7 MB).'); continue; }
      const r = await senden(d, schritt);
      if (r.datei) neu.push(r.datei); else fehler.push(r.fehler);
    }
    flaeche.classList.remove('fx-laedt');
    a.hidden = true;
    if (neu.length) {
      sessionStorage.setItem('fx-meldung', neu.length + ' Datei(en) übernommen und unten ausgewählt.' + (fehler.length ? ' Nicht übernommen: ' + fehler.join(' ') : ''));
      sessionStorage.setItem('fx-vor', JSON.stringify(neu));
      window.location.href = flaeche.getAttribute('data-ziel') + '?quelle=Upload';
    } else {
      fehlerZeigen(fehler.join(' ') || 'Hochladen fehlgeschlagen.');
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
