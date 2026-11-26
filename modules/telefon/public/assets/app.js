// Modul Telefon: Rückfrage vor Löschen und anderen folgenreichen Knöpfen (Formular mit data-confirm).
document.addEventListener('submit', (e) => {
  const f = e.target.closest('form[data-confirm]');
  if (f && !window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
});
// Eigene Wartemusik: Datei roh per fetch senden (Module haben keine Formular-Uploads), danach Seite neu laden.
(() => {
  const los = document.getElementById('wm-los');
  if (!los) return;
  const status = document.getElementById('wm-status');
  los.addEventListener('click', () => {
    const datei = document.getElementById('wm-datei').files[0];
    if (!datei) { status.textContent = 'Bitte zuerst eine Musikdatei auswählen.'; return; }
    if (datei.size > Number(los.dataset.max)) { status.textContent = 'Die Datei darf höchstens 7 MB groß sein.'; return; }
    los.disabled = true;
    status.textContent = 'Wird hochgeladen und umgewandelt … (bei langen Stücken bis zu einer Minute)';
    fetch(los.dataset.ziel, {method: 'POST', credentials: 'same-origin', body: datei,
      headers: {'Content-Type': 'application/octet-stream', 'X-Kienzle-CSRF': los.dataset.csrf}})
      .then(r => r.text().then(t => { let j = null; try { j = JSON.parse(t); } catch (e) { j = null; } return {ok: r.ok, status: r.status, j}; }))
      .then(r => {
        if (r.ok && r.j && r.j.ok) { location.href = location.pathname + '?hochgeladen=1#wartemusik'; return; }
        status.textContent = (r.j && r.j.fehler) || ('Das Hochladen hat nicht geklappt (HTTP ' + r.status + ').');
        los.disabled = false;
      })
      .catch(() => { status.textContent = 'Keine Verbindung zum Server. Bitte noch einmal versuchen.'; los.disabled = false; });
  });
})();
// Vorgänge: alle 20 Sekunden nur die Anzahl abfragen; bei neuen Vorgängen Hinweis zeigen und kurz piepen (kein Neuladen, damit
// angefangene Kommentare und SMS-Texte nicht verloren gehen).
(() => {
  const seite = document.getElementById('tf-vorgaenge');
  if (!seite || !window.fetch) return;
  let neu = Number(seite.dataset.neu || 0);
  const hinweis = seite.querySelector('.tf-neu-hinweis');
  const piep = () => {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const o = ctx.createOscillator(); const g = ctx.createGain();
      o.frequency.value = 880; g.gain.value = 0.08; o.connect(g); g.connect(ctx.destination);
      o.start(); o.stop(ctx.currentTime + 0.25);
    } catch (e) { /* Ton ist nur eine Hilfe */ }
  };
  setInterval(() => {
    fetch(seite.dataset.anzahl, {credentials: 'same-origin', cache: 'no-store'}).then(r => r.ok ? r.json() : null).then(d => {
      if (!d || typeof d.neu !== 'number') return;
      if (d.neu > neu && hinweis) { hinweis.hidden = false; piep(); document.title = '(' + d.neu + ') ' + document.title.replace(/^\(\d+\) /, ''); }
      neu = d.neu;
    }).catch(() => {});
  }, 20000);
})();
// Anrufe: Live-Teil alle 3 Sekunden neu laden (nur wenn die Seite sichtbar ist).
(() => {
  const live = document.getElementById('tf-anrufe-live');
  if (!live || !window.fetch) return;
  setInterval(() => {
    if (document.hidden) return;
    fetch(live.dataset.quelle, {credentials: 'same-origin', cache: 'no-store'}).then(r => r.ok ? r.text() : null).then(t => { if (t !== null) live.innerHTML = t; }).catch(() => {});
  }, 3000);
})();
// Zeitschemata: Zeiten der Praxis (Öffnungszeiten) mit einem Klick in ein anderes Schema kopieren; gespeichert wird erst mit „Speichern“.
document.addEventListener('click', (e) => {
  const knopf = e.target.closest('button[data-zeiten-von]');
  if (!knopf) return;
  const von = knopf.getAttribute('data-zeiten-von');
  const form = knopf.closest('form');
  let n = 0;
  form.querySelectorAll('input[name^="zeit["]').forEach((feld) => {
    const tag = feld.name.replace(/^zeit\[[^\]]*\]\[/, '').replace(/\]$/, '');
    const quelle = document.querySelector('input[name="zeit[' + von + '][' + tag + ']"]');
    if (quelle) { feld.value = quelle.value; n++; }
  });
  const status = form.querySelector('[data-zeiten-status]');
  if (status) status.textContent = n ? 'Übernommen – bitte unten auf „Speichern“ drücken.' : 'Keine Öffnungszeiten gefunden.';
});
