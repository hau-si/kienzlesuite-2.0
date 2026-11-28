/* Homepage: Felder je Art zeigen, Tage ergänzen, Rückfragen vor dem Löschen, Vorschau beim Tippen (POST /vorschau). */
(() => {
  'use strict';
  document.documentElement.classList.add('js');
  const basis = document.querySelector('meta[name="hp-basis"]')?.content || '/homepage';
  const csrf = document.querySelector('meta[name="kienzle-csrf"]')?.content || '';
  const vorschau = document.getElementById('hp-vorschau');
  const datum = document.getElementById('hp-datum');
  let aktiv = null, zeit = null, folge = 0;

  // Felder passend zur Art (Praxen nur bei Vertretung, Tage nur bei Sonderöffnungszeiten)
  function artZeigen(form) {
    const art = form.querySelector('input[name="art"]:checked')?.value || '';
    form.querySelectorAll('[data-art]').forEach(f => { f.hidden = f.dataset.art !== art; });
    const titel = form.querySelector('input[name="titel"]'), r = form.querySelector('input[name="art"]:checked');
    if (titel && r) titel.placeholder = r.dataset.vorgabe || 'z. B. Praxis am Freitag geschlossen';
    const hinweis = form.querySelector('.hp-vorgabe');
    if (hinweis && r) hinweis.textContent = r.dataset.vorgabe ? '(leer = „' + r.dataset.vorgabe + '“)' : '(optional)';
  }

  async function vorschauLaden() {
    if (!vorschau) return;
    const daten = aktiv ? new URLSearchParams(new FormData(aktiv)) : new URLSearchParams();
    daten.delete('kienzle-csrf');
    daten.set('datum', datum?.value || '');
    const nr = ++folge;
    try {
      const antwort = await fetch(basis + '/vorschau', {method: 'POST', headers: {'X-Kienzle-Csrf': csrf, 'Content-Type': 'application/x-www-form-urlencoded'}, body: daten, credentials: 'same-origin'});
      if (!antwort.ok || nr !== folge) return;
      vorschau.innerHTML = await antwort.text();
    } catch (e) { /* Vorschau ist nur eine Hilfe */ }
  }
  const spaeter = () => { clearTimeout(zeit); zeit = setTimeout(vorschauLaden, 250); };

  document.querySelectorAll('.hp-form').forEach(form => {
    artZeigen(form);
    form.addEventListener('change', e => { if (e.target.name === 'art') artZeigen(form); aktiv = form; spaeter(); });
    form.addEventListener('input', () => { aktiv = form; spaeter(); });
    form.addEventListener('focusin', () => { if (aktiv !== form) { aktiv = form; spaeter(); } });
    form.querySelector('[data-tag-dazu]')?.addEventListener('click', () => {
      const liste = form.querySelector('.hp-tage'), zeile = liste.lastElementChild.cloneNode(true);
      zeile.querySelectorAll('input').forEach(i => { i.value = ''; });
      liste.append(zeile);
      zeile.querySelector('input').focus();
    });
  });
  // Beim Zuklappen einer Zeile zeigt die Vorschau wieder den gespeicherten Stand.
  document.querySelectorAll('details.hp-zeile, details.hp-neu').forEach(d => d.addEventListener('toggle', () => {
    if (!d.open && aktiv && d.contains(aktiv)) { aktiv = null; spaeter(); }
  }));

  datum?.addEventListener('change', spaeter);
  document.querySelectorAll('.hp-datum [data-tage]').forEach(b => b.addEventListener('click', () => {
    const d = new Date(); d.setDate(d.getDate() + Number(b.dataset.tage));
    const z = n => String(n).padStart(2, '0');
    datum.value = d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate());
    spaeter();
  }));

  // „+ Neue Mitteilung“ klappt die Karte auf, ohne die Seite neu zu laden.
  document.querySelector('[data-neu]')?.addEventListener('click', e => {
    const neu = document.getElementById('neu');
    if (!neu) return;
    e.preventDefault();
    neu.open = true;
    neu.scrollIntoView({behavior: 'smooth', block: 'start'});
    neu.querySelector('input[name="titel"]')?.focus({preventScroll: true});
  });

  document.addEventListener('click', e => {
    const b = e.target.closest('[data-frage]');
    if (b && !confirm(b.dataset.frage)) e.preventDefault();
  });
})();
