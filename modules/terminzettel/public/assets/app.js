'use strict';
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
