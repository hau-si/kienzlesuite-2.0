'use strict';
// Logo hochladen: Datei roh per fetch senden (kein Formular-Upload), danach Seite neu laden.
(() => {
  const los = document.getElementById('logo-los');
  if (!los) return;
  const status = document.getElementById('logo-status');
  los.addEventListener('click', () => {
    const datei = document.getElementById('logo-datei').files[0];
    const meta = document.querySelector('meta[name="kienzle-csrf"]');
    if (!datei) { status.textContent = 'Bitte zuerst ein Bild auswählen.'; return; }
    if (datei.size > 2097152) { status.textContent = 'Das Logo darf höchstens 2 MB groß sein.'; return; }
    los.disabled = true;
    status.textContent = 'Wird hochgeladen …';
    fetch(los.getAttribute('data-ziel'), {method: 'POST', credentials: 'same-origin', body: datei,
      headers: {'Content-Type': 'application/octet-stream', 'X-Kienzle-CSRF': meta ? meta.content : ''}})
      .then(r => r.text().then(t => { let j = null; try { j = JSON.parse(t); } catch (e) { j = null; } return {ok: r.ok, status: r.status, j}; }))
      .then(r => {
        if (r.ok && r.j && r.j.ok) { status.textContent = 'Logo gespeichert. Seite wird neu geladen …'; location.reload(); return; }
        status.textContent = (r.j && r.j.error) || ('Das Hochladen hat nicht geklappt (HTTP ' + r.status + ').');
        los.disabled = false;
      })
      .catch(() => { status.textContent = 'Keine Verbindung zum Server. Bitte noch einmal versuchen.'; los.disabled = false; });
  });
})();
// Live-Vorschau des Bons: schickt die Formularwerte (ohne zu speichern) und zeigt das Ergebnis.
(() => {
  const form = document.getElementById('layout');
  if (!form) return;
  const csrf = document.querySelector('meta[name="kienzle-csrf"]').content;
  const img = document.getElementById('bild'), hint = document.getElementById('hinweis');
  const dirty = document.getElementById('ungespeichert');
  let timer = null, seq = 0;
  const values = () => {
    const out = {};
    for (const el of form.elements) {
      if (!el.name || el.name === 'kienzle-csrf' || el.name === 'aktion') continue;
      if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; continue; }
      out[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value;
    }
    return out;
  };
  const initial = JSON.stringify(Object.entries(values()));
  const refresh = () => {
    clearTimeout(timer);
    if (dirty) dirty.hidden = JSON.stringify(Object.entries(values())) === initial;
    timer = setTimeout(async () => {
      const mine = ++seq;
      try {
        const r = await fetch(location.pathname.replace(/\/?$/, '/') + 'vorschau', {
          method: 'POST', credentials: 'same-origin',
          headers: {'Content-Type': 'application/json', 'X-Kienzle-Csrf': csrf},
          body: JSON.stringify({values: values()}),
        });
        const data = await r.json();
        if (mine !== seq) return;
        if (!r.ok) throw new Error(data.error || 'Vorschau fehlgeschlagen.');
        img.src = data.image; img.hidden = false;
        hint.textContent = (data.notes || []).join(' ');
        hint.className = 'muted';
      } catch (e) {
        if (mine !== seq) return;
        img.hidden = true; hint.textContent = e.message; hint.className = 'fehler';
      }
    }, 400);
  };
  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);
  refresh();
})();

// Sprung von der Einrichtungsübersicht (…/#drucker): den passenden Bereich aufklappen.
(() => {
  const open = () => {
    const el = location.hash.length > 1 ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null;
    if (el && el.tagName === 'DETAILS') { el.open = true; el.scrollIntoView(); }
  };
  open();
  window.addEventListener('hashchange', open);
})();

// Platzhalter für die Absage-Mail: an der Cursorstelle des zuletzt gewählten Felds (Betreff oder Text) einfügen.
(() => {
  const felder = ['f-cancel_subject', 'f-cancel_body'].map((id) => document.getElementById(id)).filter(Boolean);
  if (!felder.length) return;
  let ziel = document.getElementById('f-cancel_body') || felder[0];
  felder.forEach((f) => f.addEventListener('focus', () => { ziel = f; }));
  document.querySelectorAll('[data-platzhalter]').forEach((b) => b.addEventListener('click', () => {
    const t = b.dataset.platzhalter, a = ziel.selectionStart ?? ziel.value.length, e = ziel.selectionEnd ?? a;
    ziel.value = ziel.value.slice(0, a) + t + ziel.value.slice(e);
    ziel.focus(); ziel.setSelectionRange(a + t.length, a + t.length);
    ziel.dispatchEvent(new Event('input', { bubbles: true }));
  }));
})();
