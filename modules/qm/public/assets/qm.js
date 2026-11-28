// Qualitätsmanagement: Editor, Bilder, Import, Sofortsuche, Rückfragen. Ohne fremde Bibliotheken (CSP: nur eigene Skripte).
(() => {
  'use strict';
  const meta = (n) => (document.querySelector('meta[name="' + n + '"]') || {}).content || '';
  const csrf = meta('kienzle-csrf');
  const basis = meta('qm-basis') || '/qm';

  // Rückfragen vor heiklen Knöpfen
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-frage]');
    if (b && !window.confirm(b.dataset.frage)) e.preventDefault();
    if (e.target.closest('[data-drucken]')) window.print();
  });

  // Sofortsuche in der Liste (Namensschnipsel im Titel und in den Tags)
  document.querySelectorAll('input[data-live]').forEach((feld) => {
    const tabelle = document.querySelector(feld.dataset.live);
    if (!tabelle) return;
    const leer = tabelle.parentElement.querySelector('.qm-leer');
    const filtern = () => {
      const q = feld.value.trim().toLowerCase();
      let sichtbar = 0;
      tabelle.querySelectorAll('tbody tr').forEach((tr) => {
        const an = q === '' || (tr.dataset.suche || '').includes(q);
        tr.hidden = !an;
        if (an) sichtbar++;
      });
      if (leer) leer.hidden = sichtbar > 0 || tabelle.querySelectorAll('tbody tr').length === 0;
    };
    // Volltextsuche bleibt beim Server; ohne Häkchen filtert die Seite sofort.
    const text = feld.form && feld.form.querySelector('input[name="text"]');
    feld.addEventListener('input', () => { if (!text || !text.checked) filtern(); });
  });

  // Vorlegen: Gruppe wählen = alle Personen der Gruppe wählen
  document.querySelectorAll('form.qm-vorlegen').forEach((f) => {
    f.addEventListener('change', (e) => {
      const g = e.target.dataset && e.target.dataset.gruppe;
      if (g === undefined) return;
      f.querySelectorAll('input[name="personen[]"]').forEach((p) => {
        if (g === '*' || (p.dataset.gruppen || '').split('\n').includes(g)) p.checked = e.target.checked;
      });
    });
    f.addEventListener('submit', (e) => {
      if (!f.querySelector('input[name="personen[]"]:checked, input[name="gruppen[]"]:checked')) { e.preventDefault(); window.alert('Bitte mindestens eine Person oder Gruppe wählen.'); }
    });
  });

  // Neues Dokument: Gerüst füllt Titel, Ordner und Tags vor (nur wenn noch leer)
  document.querySelectorAll('form.qm-neu input[name="geruest"]').forEach((r) => {
    r.addEventListener('change', () => {
      const f = r.form;
      const titel = f.querySelector('input[name="titel"]');
      if (r.dataset.titel && (titel.value === '' || titel.dataset.auto === '1')) { titel.value = r.dataset.titel; titel.dataset.auto = '1'; }
      if (r.dataset.ordner && r.dataset.ordner !== '0') f.querySelector('select[name="ordner"]').value = r.dataset.ordner;
      const tags = f.querySelector('input[name="tags"]');
      if (r.dataset.tags && tags.value === '') tags.value = r.dataset.tags;
    });
  });

  async function hochladen(datei) {
    const r = await fetch(basis + '/bild', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/octet-stream', 'X-Kienzle-Csrf': csrf }, body: datei });
    const d = await r.json().catch(() => ({ error: 'Antwort nicht lesbar (' + r.status + ').' }));
    if (!r.ok || !d.url) throw new Error(d.error || 'Hochladen nicht möglich.');
    return d.url;
  }

  // ---- Editor ----
  const editor = document.getElementById('qm-editor');
  if (editor) {
    const form = document.getElementById('qm-editor-form');
    const feld = document.getElementById('qm-inhalt');
    const status = document.getElementById('qm-status');
    const bildwahl = document.getElementById('qm-bilddatei');
    let geaendert = false;
    let bereich = null;
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); document.execCommand('styleWithCSS', false, false); } catch (_) { /* ältere Browser */ }
    if (editor.innerHTML.trim() === '') editor.innerHTML = '<p><br></p>';
    const merken = () => { const s = window.getSelection(); if (s.rangeCount && editor.contains(s.anchorNode)) bereich = s.getRangeAt(0).cloneRange(); };
    const zurueck = () => { editor.focus(); if (bereich) { const s = window.getSelection(); s.removeAllRanges(); s.addRange(bereich); } };
    editor.addEventListener('input', () => { geaendert = true; status.textContent = 'Nicht gespeichert'; });
    editor.addEventListener('keyup', merken);
    editor.addEventListener('mouseup', merken);
    window.addEventListener('beforeunload', (e) => { if (geaendert) { e.preventDefault(); e.returnValue = ''; } });
    form.addEventListener('submit', () => { feld.value = editor.innerHTML; geaendert = false; });

    /** Oberster Block (Kind des Editors), in dem der Cursor steht. */
    const block = () => { const s = window.getSelection(); let n = s.anchorNode; if (!n || !editor.contains(n) || n === editor) return null; while (n.parentNode !== editor) n = n.parentNode; return n; };
    const cursorIn = (el, ganz) => { const r = document.createRange(); r.selectNodeContents(el); if (!ganz) r.collapse(true); const s = window.getSelection(); s.removeAllRanges(); s.addRange(r); bereich = r.cloneRange(); };
    const zelle = () => { const s = window.getSelection(); const n = s.anchorNode && (s.anchorNode.nodeType === 1 ? s.anchorNode : s.anchorNode.parentElement); return n && editor.contains(n) ? n.closest('td,th') : null; };
    const einfuegen = (html) => { zurueck(); document.execCommand('insertHTML', false, html); geaendert = true; };
    const bilderEinfuegen = async (dateien) => {
      for (const d of dateien) {
        if (!/^image\/(png|jpeg|gif|webp)$/.test(d.type)) { status.textContent = d.name + ': nur PNG, JPEG, GIF oder WebP.'; continue; }
        status.textContent = 'Bild wird hochgeladen …';
        try { einfuegen('<img src="' + (await hochladen(d)) + '" alt="">'); status.textContent = 'Bild eingefügt, nicht gespeichert'; } catch (err) { status.textContent = err.message; }
      }
    };

    document.querySelector('.qm-leiste').addEventListener('click', (e) => {
      const b = e.target.closest('button[data-befehl]');
      if (!b) return;
      const c = b.dataset.befehl;
      zurueck();
      if (c === 'checkliste') {
        document.execCommand('insertUnorderedList');
        const s = window.getSelection();
        const ul = s.anchorNode && (s.anchorNode.nodeType === 1 ? s.anchorNode : s.anchorNode.parentElement).closest('ul');
        if (ul && editor.contains(ul)) ul.classList.toggle('checkliste');
      } else if (c === 'tabelle') {
        // Über das DOM statt insertHTML: Chrome zerlegt Tabellen sonst, z. B. direkt hinter einer Liste.
        const t = document.createElement('table');
        t.innerHTML = '<tbody><tr><th>Spalte 1</th><th>Spalte 2</th><th>Spalte 3</th></tr><tr><td><br></td><td><br></td><td><br></td></tr><tr><td><br></td><td><br></td><td><br></td></tr></tbody>';
        const b = block();
        if (!b) editor.appendChild(t);
        else if (b.nodeType === 1 && b.textContent.trim() === '' && !b.querySelector('img,table')) b.replaceWith(t);
        else b.after(t);
        if (!t.nextElementSibling) { const p = document.createElement('p'); p.innerHTML = '<br>'; t.after(p); }
        cursorIn(t.querySelector('th'), true);
      } else if (c === 'zeile' || c === 'spalte') {
        const z = zelle();
        if (!z) { status.textContent = 'Zuerst in eine Tabellenzelle klicken.'; return; }
        if (c === 'zeile') {
          const tr = z.parentElement; const neu = tr.cloneNode(true);
          neu.querySelectorAll('td,th').forEach((x) => { const td = document.createElement('td'); td.innerHTML = '<br>'; x.replaceWith(td); });
          tr.after(neu);
          cursorIn(neu.firstElementChild);
        } else {
          const i = Array.prototype.indexOf.call(z.parentElement.children, z);
          z.closest('table').querySelectorAll('tr').forEach((tr) => { const ref = tr.children[i]; const neu = document.createElement(ref && ref.tagName === 'TH' ? 'th' : 'td'); neu.innerHTML = '<br>'; if (ref) ref.after(neu); else tr.appendChild(neu); });
        }
        geaendert = true;
      } else if (c === 'bild') {
        merken(); bildwahl.click();
      } else if (c === 'link') {
        const url = window.prompt('Adresse (https://…)', 'https://');
        if (url && /^(https?:\/\/|mailto:)/i.test(url)) document.execCommand('createLink', false, url);
      } else if (c === 'formatBlock') {
        document.execCommand('formatBlock', false, b.dataset.wert);
      } else {
        document.execCommand(c);
      }
      geaendert = true;
      status.textContent = 'Nicht gespeichert';
      merken();
    });
    bildwahl.addEventListener('change', () => { bilderEinfuegen(Array.from(bildwahl.files)); bildwahl.value = ''; });
    editor.addEventListener('paste', (e) => {
      const dateien = Array.from((e.clipboardData || {}).files || []);
      if (dateien.length) { e.preventDefault(); merken(); bilderEinfuegen(dateien); }
    });
    editor.addEventListener('drop', (e) => {
      const dateien = Array.from((e.dataTransfer || {}).files || []);
      if (dateien.length) {
        e.preventDefault();
        if (document.caretRangeFromPoint) { const r = document.caretRangeFromPoint(e.clientX, e.clientY); if (r) bereich = r; }
        bilderEinfuegen(dateien);
      }
    });
    // Checkliste: Klick links neben einen Punkt hakt ihn ab
    editor.addEventListener('click', (e) => {
      const li = e.target.closest('ul.checkliste > li');
      if (li && e.offsetX < 22) { li.classList.toggle('erledigt'); geaendert = true; }
    });
  }

  // ---- Import ----
  const imp = document.getElementById('qm-import');
  if (imp) {
    const liste = document.getElementById('qm-import-ergebnis');
    const stand = document.getElementById('qm-import-stand');
    const zeile = (html, klasse) => { const li = document.createElement('li'); if (klasse) li.className = klasse; li.innerHTML = html; liste.appendChild(li); };
    const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    imp.addEventListener('submit', async (e) => {
      e.preventDefault();
      const dateien = [...imp.ordnerwahl.files, ...imp.dateien.files].filter((d) => /\.(docx|zip)$/i.test(d.name) && !/^(~\$|\.)/.test(d.name));
      const andere = imp.ordnerwahl.files.length + imp.dateien.files.length - dateien.length;
      if (!dateien.length) { stand.textContent = 'Bitte einen Ordner oder Dateien (.docx, .zip) wählen.'; return; }
      const knopf = document.getElementById('qm-import-los');
      knopf.disabled = true;
      liste.innerHTML = '';
      let angelegt = 0;
      for (let i = 0; i < dateien.length; i++) {
        const d = dateien[i];
        const pfad = d.webkitRelativePath || d.name;
        stand.textContent = (i + 1) + ' von ' + dateien.length + ': ' + d.name;
        try {
          const r = await fetch(basis + '/import', { method: 'POST', credentials: 'same-origin', body: d, headers: {
            'Content-Type': 'application/octet-stream', 'X-Kienzle-Csrf': csrf, 'X-Qm-Pfad': encodeURIComponent(pfad), 'X-Qm-Ordner': imp.ordner.value,
            'X-Qm-Tags': encodeURIComponent(imp.tags.value), 'X-Qm-Struktur': imp.struktur.checked ? '1' : '0' } });
          const a = await r.json().catch(() => ({ error: 'Antwort nicht lesbar (' + r.status + ').' }));
          if (!r.ok) { zeile(esc(d.name) + ': ' + esc(a.error || 'Fehler'), 'fehler'); continue; }
          (a.angelegt || []).forEach((x) => { angelegt++; zeile('<a href="' + basis + '/dokument/' + Number(x.id) + '">' + esc(x.titel) + '</a>', 'ok'); });
          (a.hinweise || []).forEach((h) => zeile(esc(h), 'hinweis'));
        } catch (err) {
          zeile(esc(d.name) + ': ' + esc(err.message), 'fehler');
        }
      }
      if (andere > 0) zeile(andere + ' andere Dateien (z. B. PDF, Excel) wurden nicht übernommen.', 'hinweis');
      stand.textContent = 'Fertig: ' + angelegt + ' Dokument(e) als Entwurf angelegt.';
      knopf.disabled = false;
    });
  }
})();
