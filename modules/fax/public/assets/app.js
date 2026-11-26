// Modul Fax: Rückfragen, weitere Empfänger, Suche im Telefonbuch, laufende Aufträge alle 5 Sekunden neu laden.
document.addEventListener('submit', (e) => {
  const f = e.target.closest('form[data-confirm]');
  if (f && !window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
  const b = e.submitter;
  if (b && b.hasAttribute('data-frage') && !window.confirm(b.getAttribute('data-frage'))) e.preventDefault();
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
      window.location.href = flaeche.getAttribute('data-ziel');
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
    window.fxVorUpload = vor.map((n) => 'Upload/' + n);
    if (meldung) { const m = document.createElement('p'); m.className = 'ok'; m.setAttribute('role', 'status'); m.textContent = meldung; flaeche.before(m); }
  } catch (_) { /* ohne Speicher: einfach ohne Vorauswahl */ }
}

// ---- Fax senden: Dokumente anhaken (Reihenfolge = Reihenfolge des Anhakens), Vorschau, Empfänger mit Suche, Favoriten ----
const formular = document.getElementById('fx-senden');
if (formular) {
  const reihenfolge = formular.querySelector('input[name="reihenfolge"]');
  const haken = () => Array.from(formular.querySelectorAll('input[name="auswahl[]"]'));
  let folge = reihenfolge.value.split('\n').filter((x) => x !== '');
  const bild = document.getElementById('fx-vorschau');
  const vorschau = (li) => {
    if (!bild || !li) return;
    let img = bild.querySelector('img');
    if (!img) { img = document.createElement('img'); img.alt = 'Vorschau: oberer Teil der ersten Seite'; const leer = bild.querySelector('.fx-leer'); if (leer) leer.remove(); bild.prepend(img); }
    if (img.getAttribute('src') !== li.getAttribute('data-vorschau')) img.src = li.getAttribute('data-vorschau');
    formular.querySelectorAll('.fx-dateien li.fx-aktuell').forEach((x) => x.classList.remove('fx-aktuell'));
    li.classList.add('fx-aktuell');
  };
  const zeilen = () => Array.from(formular.querySelectorAll('.fx-empfaenger'));
  const sichtbare = () => zeilen().filter((z) => !z.hidden);
  const feld = (z, n) => z.querySelector('input[name$="[' + n + ']"]');
  const leer = (z) => feld(z, 'name').value.trim() === '' && feld(z, 'nummer').value.trim() === '';
  const aktualisieren = () => {
    const an = haken();
    folge = folge.filter((k) => an.some((h) => h.checked && h.value === k));
    an.forEach((h) => { if (h.checked && !folge.includes(h.value)) folge.push(h.value); });
    reihenfolge.value = folge.join('\n');
    let seiten = 0; let unbekannt = false;
    an.forEach((h) => {
      const li = h.closest('li'); const nr = li.querySelector('.fx-nr'); const i = folge.indexOf(h.value);
      nr.textContent = i >= 0 ? String(i + 1) : '';
      li.classList.toggle('fx-gewaehlt', i >= 0);
      if (i >= 0) { const s = parseInt(li.getAttribute('data-seiten'), 10); if (s > 0) seiten += s; else unbekannt = true; }
    });
    const n = folge.length;
    const einzeln = document.getElementById('fx-einzeln');
    const getrennt = einzeln && einzeln.checked && n > 1;
    const dok = n === 1 ? '1 Dokument' : n + ' Dokumente';
    const seitenText = seiten > 0 ? (unbekannt ? 'mind. ' : '') + seiten + (seiten === 1 ? ' Seite' : ' Seiten') : '';
    const s = seitenText ? ' (' + seitenText + ')' : '';
    const summe = document.getElementById('fx-summe');
    if (summe) summe.textContent = n === 0 ? 'Kein Dokument angehakt.' : dok + (seitenText ? ' · ' + seitenText : '') + (n > 1 ? (getrennt ? ' · jedes als eigenes Fax' : ' · ein Fax, Reihenfolge wie angehakt') : '');
    const namen = sichtbare().map((z) => feld(z, 'name').value.trim() || feld(z, 'nummer').value.trim()).filter((x) => x !== '');
    const an_ = namen.length === 0 ? '' : namen.length === 1 ? ' an ' + namen[0] : ' an ' + namen.length + ' Empfänger';
    const los = document.getElementById('fx-los');
    if (los) los.textContent = n === 0 ? 'Fax senden' : dok + s + an_ + ' faxen';
    const viele = document.getElementById('fx-viele');
    if (viele) viele.hidden = !(seiten > 15 && n > 1 && !getrennt);
    // Speichern-Haken nur bei einer neuen Nummer (nicht aus dem Telefonbuch)
    zeilen().forEach((z) => {
      const m = z.querySelector('.fx-merken');
      const nr = feld(z, 'nummer').value.replace(/\D/g, '');
      m.hidden = feld(z, 'kontakt').value !== '0' || nr.length < 6 || buch.some((k) => k.nummer.replace(/\D/g, '') === nr);
      if (m.hidden) m.querySelector('input').checked = false;
    });
  };
  window.fxAuswahlSetzen = (schluessel) => {
    haken().forEach((h) => { h.checked = schluessel.includes(h.value); });
    folge = schluessel.filter((k) => haken().some((h) => h.value === k));
    aktualisieren();
    const erstes = haken().find((h) => h.value === folge[0]);
    if (erstes) vorschau(erstes.closest('li'));
  };
  let buch = [];
  try { buch = JSON.parse(formular.getAttribute('data-telefonbuch') || '[]'); } catch (_) { buch = []; }
  formular.addEventListener('change', (e) => {
    const h = e.target.closest('input[name="auswahl[]"]');
    if (h) {
      const li = h.closest('li');
      vorschau(li);
      // Faxdrucker mit fester Nummer: in einen leeren ersten Empfänger übernehmen
      if (h.checked && li.hasAttribute('data-fest-nummer')) { const z = sichtbare().find(leer); if (z) eintragen(z, { name: li.getAttribute('data-fest-name'), nummer: li.getAttribute('data-fest-nummer') }); }
    }
    aktualisieren();
  });
  formular.addEventListener('mouseover', (e) => { const li = e.target.closest('.fx-dateien li'); if (li) vorschau(li); });
  const eintragen = (z, k) => {
    feld(z, 'name').value = k.name || '';
    feld(z, 'nummer').value = k.nummer || '';
    feld(z, 'kontakt').value = k.id ? String(k.id) : '0';
    z.hidden = false;
    const v = z.querySelector('.fx-vorschlaege'); v.hidden = true; v.innerHTML = '';
    aktualisieren();
  };
  // Favoriten und „Zuletzt“: in die erste leere Zeile, sonst die letzte sichtbare ersetzen.
  formular.addEventListener('click', (e) => {
    const chip = e.target.closest('[data-wahl]');
    if (chip) {
      const vis = sichtbare();
      eintragen(vis.find(leer) || vis[vis.length - 1], { id: parseInt(chip.getAttribute('data-kontakt') || '0', 10), name: chip.getAttribute('data-name'), nummer: chip.getAttribute('data-nummer') });
      return;
    }
    const mehr = e.target.closest('[data-mehr]');
    if (mehr) {
      const z = zeilen().find((x) => x.hidden);
      if (z) { z.hidden = false; feld(z, 'name').focus(); }
      mehr.hidden = !zeilen().some((x) => x.hidden);
      return;
    }
    const weg = e.target.closest('[data-entfernen]');
    if (weg) {
      const z = weg.closest('.fx-empfaenger');
      feld(z, 'name').value = ''; feld(z, 'nummer').value = ''; feld(z, 'kontakt').value = '0';
      z.hidden = true;
      formular.querySelector('[data-mehr]').hidden = false;
      aktualisieren();
      return;
    }
    const v = e.target.closest('.fx-vorschlaege li[data-id]');
    if (v) {
      const k = buch.find((x) => String(x.id) === v.getAttribute('data-id'));
      if (k) eintragen(v.closest('.fx-empfaenger'), k);
    }
  });
  // Suche im Telefonbuch: Namensteile (alle müssen vorkommen) oder Ziffern der Nummer.
  const treffer = (q) => {
    const teile = q.toLowerCase().split(/\s+/).filter((x) => x !== '');
    const ziffern = q.replace(/\D/g, '');
    if (!teile.length) return [];
    return buch.filter((k) => teile.every((t) => k.name.toLowerCase().includes(t)) || (ziffern.length >= 3 && /^[\d\s+/().-]+$/.test(q) && (k.nummer.replace(/\D/g, '').includes(ziffern) || k.n.includes(ziffern)))).slice(0, 8);
  };
  const vorschlaegeZeigen = (z) => {
    const eingabe = feld(z, 'name');
    const liste = z.querySelector('.fx-vorschlaege');
    const t = treffer(eingabe.value.trim());
    liste.innerHTML = '';
    t.forEach((k, i) => {
      const li = document.createElement('li');
      li.setAttribute('data-id', String(k.id)); li.setAttribute('role', 'option');
      if (i === 0) li.classList.add('fx-aktiv-vorschlag');
      const b = document.createElement('strong'); b.textContent = k.name;
      const n = document.createElement('span'); n.className = 'muted klein'; n.textContent = ' ' + k.nummer;
      li.append(b, n); liste.appendChild(li);
    });
    liste.hidden = t.length === 0;
  };
  formular.addEventListener('input', (e) => {
    const z = e.target.closest('.fx-empfaenger');
    if (z) {
      if (e.target === feld(z, 'name') || e.target === feld(z, 'nummer')) feld(z, 'kontakt').value = '0';
      if (e.target === feld(z, 'name')) vorschlaegeZeigen(z);
    }
    aktualisieren();
  });
  formular.addEventListener('keydown', (e) => {
    const z = e.target.closest('.fx-suchfeld') && e.target.closest('.fx-empfaenger');
    if (!z) return;
    const liste = z.querySelector('.fx-vorschlaege');
    if (liste.hidden) return;
    const alle = Array.from(liste.querySelectorAll('li'));
    let i = alle.findIndex((x) => x.classList.contains('fx-aktiv-vorschlag'));
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      i = (i + (e.key === 'ArrowDown' ? 1 : alle.length - 1)) % alle.length;
      alle.forEach((x, j) => x.classList.toggle('fx-aktiv-vorschlag', j === i));
    } else if (e.key === 'Enter' && i >= 0) {
      e.preventDefault();
      const k = buch.find((x) => String(x.id) === alle[i].getAttribute('data-id'));
      if (k) eintragen(z, k);
    } else if (e.key === 'Escape') {
      liste.hidden = true;
    }
  });
  formular.addEventListener('focusout', (e) => {
    const z = e.target.closest('.fx-suchfeld');
    if (z) setTimeout(() => { if (!z.contains(document.activeElement)) z.querySelector('.fx-vorschlaege').hidden = true; }, 150);
  });
  aktualisieren();
  if (window.fxVorUpload && window.fxVorUpload.length) window.fxAuswahlSetzen(window.fxVorUpload);
  const erstes = haken().find((h) => h.value === folge[0]);
  if (erstes) erstes.closest('li').classList.add('fx-aktuell');
}
// ---- Faxeingang: Vorschau beim Überfahren einer Zeile mit der Maus ----
const eingangZeilen = document.querySelectorAll('tr[data-vorschau]');
if (eingangZeilen.length) {
  const box = document.createElement('div');
  box.className = 'fx-schwebe'; box.hidden = true;
  const img = document.createElement('img'); img.alt = 'Vorschau';
  box.appendChild(img); document.body.appendChild(box);
  let timer = null;
  const setzen = (e) => {
    const x = e.clientX > window.innerWidth / 2 ? e.clientX - 396 : e.clientX + 18;   // nicht über die Knöpfe rechts
    const y = Math.min(e.clientY + 14, window.innerHeight - 280);
    box.style.left = Math.max(8, x) + 'px'; box.style.top = Math.max(8, y) + 'px';
  };
  eingangZeilen.forEach((tr) => {
    tr.addEventListener('mouseenter', (e) => {
      clearTimeout(timer);
      timer = setTimeout(() => { img.src = tr.getAttribute('data-vorschau'); box.hidden = false; }, 150);
      setzen(e);
    });
    tr.addEventListener('mousemove', setzen);
    tr.addEventListener('mouseleave', () => { clearTimeout(timer); box.hidden = true; });
  });
  img.addEventListener('error', () => { box.hidden = true; });
}
