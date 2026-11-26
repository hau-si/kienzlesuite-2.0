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
