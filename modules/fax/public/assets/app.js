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
