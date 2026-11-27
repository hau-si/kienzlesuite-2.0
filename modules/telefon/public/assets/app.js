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
// ---- Telefonverzeichnis aus T2med: Suchfeld oben, „Akte“, Wählen aus der Suche, Laden nach dem Aufruf aus T2med ----------
(() => {
  const box = document.querySelector('.tf-suche');
  const csrf = box ? box.dataset.csrf : (document.querySelector('input[name="kienzle-csrf"]') || {}).value || '';
  const basis = location.pathname.replace(/^(\/[a-z0-9-]+).*$/, '$1');
  const hinweis = (text, fehler, link) => {
    let h = document.getElementById('tf-hinweis');
    if (!h) { h = document.createElement('div'); h.id = 'tf-hinweis'; h.setAttribute('role', 'status'); document.body.appendChild(h); }
    h.className = 'tf-hinweis' + (fehler ? ' fehler' : '');
    h.textContent = text;
    if (link) { const a = document.createElement('a'); a.href = link; a.textContent = ' Jetzt wählen'; h.appendChild(a); }
    h.hidden = false;
    clearTimeout(h._t); h._t = setTimeout(() => { h.hidden = true; }, fehler ? 9000 : 4000);
  };
  const post = (url, daten, kopf) => fetch(url, {method: 'POST', credentials: 'same-origin', headers: Object.assign({'Content-Type': 'application/json', Accept: 'application/json'}, kopf || {}), body: JSON.stringify(daten)})
    .then(r => r.text().then(t => { let j = null; try { j = JSON.parse(t); } catch (e) { j = null; } return {ok: r.ok, status: r.status, j: j || {}}; }));
  // Akte in T2med öffnen: über die Basis an den Kienzle-Agent des eigenen PCs.
  const akte = (nr) => post('/arbeitsplatz/akte', {modul: 'telefon', csrf: csrf, patientennummer: String(nr)})
    .then(r => r.ok ? hinweis('Akte wird in T2med geöffnet (' + (r.j.platz || 'dieser PC') + ').') : hinweis(r.j.error || ('Akte öffnen nicht möglich (HTTP ' + r.status + ').'), true, r.j.waehlen))
    .catch(() => hinweis('Keine Verbindung zum Server.', true));
  const waehlen = (nr) => post(box.dataset.waehlen, {nummer: nr}, {'X-Kienzle-CSRF': csrf})
    .then(r => hinweis(r.ok ? r.j.meldung : (r.j.fehler || 'Wählen nicht möglich.'), !r.ok)).catch(() => hinweis('Keine Verbindung zum Server.', true));
  document.addEventListener('click', (e) => {
    const a = e.target.closest('.tf-akte');
    if (a) { e.preventDefault(); akte(a.dataset.akte); return; }
    const w = e.target.closest('.tf-tel-waehlen');
    if (w) { e.preventDefault(); waehlen(w.dataset.nummer); return; }
    const s = e.target.closest('.tf-suchen');
    if (s && box) { const f = box.querySelector('input'); f.value = s.dataset.suche; f.focus(); f.dispatchEvent(new Event('input')); }
  });
  if (box) {
    const feld = box.querySelector('input');
    const liste = box.querySelector('.tf-treffer');
    let timer = null; let nr = 0; let aktiv = -1;
    const markieren = (i) => {
      const z = Array.from(liste.querySelectorAll('.tf-treffer-zeile'));
      if (!z.length) return;
      aktiv = (i + z.length) % z.length;
      z.forEach((x, j) => x.classList.toggle('aktiv', j === aktiv));
    };
    const zeigen = (d) => {
      liste.innerHTML = ''; aktiv = -1;
      if (!d.verzeichnis) { liste.innerHTML = '<p class="muted">Kein Telefonverzeichnis geladen (Einstellungen → T2med-Telefonverzeichnis).</p>'; liste.hidden = false; return; }
      if (!d.treffer.length) { liste.innerHTML = '<p class="muted">Nichts gefunden.</p>'; liste.hidden = false; return; }
      d.treffer.forEach((p) => {
        const z = document.createElement('div'); z.className = 'tf-treffer-zeile'; z.setAttribute('role', 'option');
        const n = document.createElement('div');
        const b = document.createElement('strong'); b.textContent = p.name; n.appendChild(b);
        const m = document.createElement('span'); m.className = 'muted'; m.textContent = (p.geburt ? ' · ' + p.geburt : '') + (p.nummer ? ' · Nr. ' + p.nummer : ''); n.appendChild(m);
        const t = document.createElement('div'); t.className = 'tf-treffer-tel';
        p.telefone.forEach((tel) => {
          const k = document.createElement(box.dataset.waehlen ? 'button' : 'span');
          if (box.dataset.waehlen) { k.type = 'button'; k.className = 'link tf-tel-waehlen'; k.dataset.nummer = tel; k.title = 'Anrufen (Ihr Telefon klingelt zuerst)'; k.textContent = '📞 ' + tel; } else { k.textContent = tel; }
          t.appendChild(k);
        });
        n.appendChild(t);
        z.appendChild(n);
        if (p.nummer) { const a = document.createElement('button'); a.type = 'button'; a.className = 'small tf-akte'; a.dataset.akte = p.nummer; a.textContent = 'Akte'; z.appendChild(a); }
        liste.appendChild(z);
      });
      liste.hidden = false;
    };
    feld.addEventListener('input', () => {
      clearTimeout(timer);
      const q = feld.value.trim();
      if (q.length < 2) { liste.hidden = true; return; }
      timer = setTimeout(() => {
        const meine = ++nr;
        fetch(box.dataset.quelle + '?q=' + encodeURIComponent(q), {credentials: 'same-origin', headers: {Accept: 'application/json'}})
          .then(r => r.json()).then(d => { if (meine === nr) zeigen(d); }).catch(() => {});
      }, 200);
    });
    feld.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); markieren(aktiv + (e.key === 'ArrowDown' ? 1 : -1)); }
      else if (e.key === 'Enter') {
        e.preventDefault();
        const z = liste.querySelectorAll('.tf-treffer-zeile')[aktiv < 0 ? 0 : aktiv];
        const a = z && z.querySelector('.tf-akte');
        if (a) akte(a.dataset.akte);
      } else if (e.key === 'Escape') { liste.hidden = true; }
    });
    document.addEventListener('click', (e) => { if (!box.contains(e.target)) liste.hidden = true; });
  }
  // Aufruf „Telefonverzeichnis aus T2med laden“: Code aus #launch=…, Schritt für Schritt laden.
  const m = location.hash.match(/launch=([0-9a-f]{64})/);
  if (m) {
    history.replaceState(null, '', location.pathname + location.search);
    const anzeige = document.getElementById('tf-laden');
    const zeige = (t, fehler) => { if (anzeige) { anzeige.hidden = false; anzeige.className = 'tf-laden' + (fehler ? ' fehler' : ''); anzeige.textContent = t; } };
    zeige('Telefonverzeichnis wird aus T2med geladen …');
    const schritt = () => post(basis + '/verzeichnis/schritt', {code: m[1]}, {'X-Kienzle-CSRF': csrf}).then((r) => {
      if (!r.ok) { zeige(r.j.fehler || ('Laden abgebrochen (HTTP ' + r.status + ').'), true); return; }
      if (r.j.fertig) { zeige('Fertig: ' + r.j.anzahl.toLocaleString('de-DE') + ' Patienten im Telefonverzeichnis.'); return; }
      zeige('Telefonverzeichnis wird aus T2med geladen … ' + r.j.anzahl.toLocaleString('de-DE') + ' Patienten bisher. Bitte die Seite offen lassen.');
      schritt();
    }).catch(() => zeige('Keine Verbindung zum Server; Laden unterbrochen. Bitte erneut aus T2med starten.', true));
    schritt();
  }
})();
