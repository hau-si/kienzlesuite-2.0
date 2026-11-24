// Kienzle-Suite, Basis: Passkeys (WebAuthn) und Drucken. Keine Fremdbibliotheken, kein Inline-Skript.
'use strict';
(function () {
  const b64 = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const bin = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), (c) => c.charCodeAt(0));

  async function post(url, data, csrf) {
    const body = new FormData();
    if (data !== undefined) body.append('json', JSON.stringify(data));
    const headers = { Accept: 'application/json' };
    if (csrf) headers['X-Kienzle-Csrf'] = csrf;
    const res = await fetch(url, { method: 'POST', body, headers, credentials: 'same-origin' });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.error || 'Fehler ' + res.status);
    return json;
  }

  function credentialJson(c) {
    const r = c.response;
    const out = { id: c.id, type: c.type, response: { clientDataJSON: b64(r.clientDataJSON) } };
    if (r.attestationObject) out.response.attestationObject = b64(r.attestationObject);
    if (r.authenticatorData) {
      out.response.authenticatorData = b64(r.authenticatorData);
      out.response.signature = b64(r.signature);
      out.response.userHandle = r.userHandle ? b64(r.userHandle) : null;
    }
    return out;
  }

  function requestOptions(o) {
    return { ...o, challenge: bin(o.challenge), allowCredentials: (o.allowCredentials || []).map((c) => ({ ...c, id: bin(c.id) })) };
  }

  function wire(box, run) {
    const button = box.querySelector('button');
    const error = box.querySelector('.error');
    if (!window.PublicKeyCredential) {
      error.textContent = 'Dieser Browser unterstützt keine Passkeys.';
      error.hidden = false;
      button.disabled = true;
      return;
    }
    button.addEventListener('click', async () => {
      error.hidden = true;
      button.disabled = true;
      try {
        const result = await run();
        if (result && result.weiter) location.assign(result.weiter);
      } catch (e) {
        error.textContent = e.name === 'NotAllowedError' ? 'Abgebrochen oder Zeit abgelaufen.' : e.message;
        error.hidden = false;
      } finally {
        button.disabled = false;
      }
    });
  }

  document.querySelectorAll('[data-passkey-login]').forEach((box) => wire(box, async () => {
    const options = await post('/anmelden/passkey/optionen');
    const c = await navigator.credentials.get({ publicKey: requestOptions(options) });
    const remember = document.querySelector('input[name="remember"]');
    return post('/anmelden/passkey', { ...credentialJson(c), weiter: box.dataset.next || '/', remember: !!(remember && remember.checked) });
  }));

  document.querySelectorAll('[data-passkey-confirm]').forEach((box) => wire(box, async () => {
    const options = await post('/bestaetigen/passkey/optionen', undefined, box.dataset.csrf);
    const c = await navigator.credentials.get({ publicKey: requestOptions(options) });
    return post('/bestaetigen/passkey', { ...credentialJson(c), weiter: box.dataset.next || '/' }, box.dataset.csrf);
  }));

  document.querySelectorAll('[data-passkey-register]').forEach((box) => wire(box, async () => {
    const o = await post('/konto/passkey/optionen', undefined, box.dataset.csrf);
    const options = { ...o, challenge: bin(o.challenge), user: { ...o.user, id: bin(o.user.id) },
      excludeCredentials: (o.excludeCredentials || []).map((c) => ({ ...c, id: bin(c.id) })) };
    const c = await navigator.credentials.create({ publicKey: options });
    const label = box.querySelector('input[name="label"]');
    return post('/konto/passkey', { ...credentialJson(c), label: label ? label.value : '' }, box.dataset.csrf);
  }));

  document.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));

  // Rückmeldung bleibt bei der Aktion: Nach dem Absenden eines Formulars zeigt der Server die Meldung oben auf der Seite.
  // Hier wird sie direkt hinter den Block mit dem angeklickten Knopf gesetzt (und ins Bild gerollt), statt ganz oben zu stehen.
  (function meldungNahe() {
    const KEY = 'kienzle.meldung';
    document.addEventListener('submit', (e) => {
      const form = e.target;
      if (!(form instanceof HTMLFormElement) || (form.method || '').toLowerCase() !== 'post') return;
      try {
        sessionStorage.setItem(KEY, JSON.stringify({
          path: location.pathname, index: Array.prototype.indexOf.call(document.forms, form), action: form.getAttribute('action') || '',
          knopf: e.submitter ? (e.submitter.name || '') + '=' + (e.submitter.value || '') : '', at: Date.now(),
        }));
      } catch (err) { /* ohne sessionStorage bleibt die Meldung oben */ }
    }, true);
    const start = () => {
      let ziel = null;
      try { ziel = JSON.parse(sessionStorage.getItem(KEY) || 'null'); sessionStorage.removeItem(KEY); } catch (err) { return; }
      if (!ziel || ziel.path !== location.pathname || Date.now() - ziel.at > 30000) return;
      const meldung = document.querySelector('main > p.notice[role=status], main > p.error');
      if (!meldung || meldung.closest('.working')) return;
      let form = document.forms[ziel.index];
      if (!form || (form.getAttribute('action') || '') !== ziel.action) form = Array.prototype.find.call(document.forms, (f) => (f.getAttribute('action') || '') === ziel.action);
      if (!form) return;
      const block = form.closest('.mod-card, .box, .vp-karte, section.card, .fold-body') || form;
      const offen = block.closest('details');
      if (offen) offen.open = true;
      meldung.classList.add('nahe');
      block.insertAdjacentElement(block.matches('.fold-body, section.card') ? 'beforeend' : 'afterend', meldung);
      meldung.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
  })();

  // Arbeitsplatz-Starter (z. B. Kasse aus T2med): Der Starter öffnet https://<server>/#launch=<einmaliger Code>.
  // Ohne Anmeldung den Code bis nach der Anmeldung im Tab merken, danach an das Modul weitergeben (data-starter).
  (function starter() {
    const target = document.body && document.body.dataset.starter;
    if (!target) return;
    let hash = /^#launch=[0-9a-f]{64}$/.test(location.hash) ? location.hash : '';
    if (hash) history.replaceState(null, '', location.pathname + location.search);
    const login = location.pathname === '/anmelden' || location.pathname.startsWith('/anmelden/') || location.pathname === '/konto/otp';
    try {
      if (login) { if (hash) sessionStorage.setItem('kienzle-launch', hash); return; }
      hash = hash || sessionStorage.getItem('kienzle-launch') || '';
      sessionStorage.removeItem('kienzle-launch');
    } catch (e) { /* ohne sessionStorage: nur direkte Weitergabe */ }
    if (/^#launch=[0-9a-f]{64}$/.test(hash)) location.replace(target + '/' + hash);
  })();
})();

// Verbindungen: Felder je nach Art (SMB/SFTP) ein- und ausblenden.
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('ziel-form');
  if (!form) return;
  const art = form.elements.art;
  const update = () => form.querySelectorAll('[data-nur]').forEach((el) => { el.hidden = el.dataset.nur !== art.value; });
  art.addEventListener('change', update);
  update();
});

// Rechte-Matrix: Rollen wählen, Häkchen folgen der Rolle; einzeln geänderte Rechte bleiben gelb markiert.
document.addEventListener('DOMContentLoaded', function () {
  const rows = document.querySelectorAll('tr.recht[data-grants]');
  if (!rows.length) return;
  const roles = () => Array.from(document.querySelectorAll('input[data-rolle]:checked')).map((i) => i.value);
  const base = (row) => { const g = row.dataset.grants ? row.dataset.grants.split('|') : []; return roles().some((r) => g.includes(r)); };
  const mark = (row) => row.classList.toggle('abw', row.querySelector('input[data-erg]').checked !== base(row));
  const columns = () => document.querySelectorAll('.gcol').forEach((c) => c.classList.toggle('aktiv', roles().includes(c.dataset.gcol)));
  document.querySelectorAll('input[data-rolle]').forEach((i) => i.addEventListener('change', () => {
    const before = new Map();
    rows.forEach((row) => before.set(row, row.classList.contains('abw')));
    columns();
    rows.forEach((row) => { if (!before.get(row)) row.querySelector('input[data-erg]').checked = base(row); mark(row); });
  }));
  rows.forEach((row) => row.querySelector('input[data-erg]').addEventListener('change', () => mark(row)));
  const reset = document.querySelector('[data-reset]');
  if (reset) reset.addEventListener('click', () => rows.forEach((row) => { row.querySelector('input[data-erg]').checked = base(row); mark(row); }));
  columns();
});

// Adresse in die Zwischenablage (Knopf mit data-copy) und Schalter, die sofort speichern (Formular mit data-auto).
document.addEventListener('click', function (e) {
  const b = e.target.closest('[data-copy]');
  if (!b) return;
  const text = b.dataset.copy, done = () => { const old = b.textContent; b.textContent = 'Kopiert'; setTimeout(() => { b.textContent = old; }, 1500); };
  if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, () => {});
});
document.addEventListener('change', function (e) {
  const f = e.target.closest('form[data-auto]');
  if (f) f.requestSubmit();
});

// Tooltips (.hilfe mit data-tip, .tip mit .tip-box) bleiben im sichtbaren Bereich: Reicht der Platz nicht, wird der ganze Tooltip
// verschoben (im Zweifel in die Fenstermitte), nie schmaler gemacht.
(function () {
  const M = 12;
  const fit = (t) => {
    const vw = document.documentElement.clientWidth;
    if (t.classList.contains('hilfe')) {
      const w = Math.min(280, vw - 2 * M), r = t.getBoundingClientRect();
      const left = r.left + r.width / 2 - w / 2;
      const target = Math.max(M, Math.min(left, vw - w - M));
      t.style.setProperty('--tip-shift', Math.round(target - left) + 'px');
      return;
    }
    const box = t.querySelector('.tip-box');
    if (!box) return;
    box.style.setProperty('--tip-shift', '0px');
    const r = box.getBoundingClientRect();
    if (r.width === 0) return;
    const target = r.width > vw - 2 * M ? (vw - r.width) / 2 : Math.max(M, Math.min(r.left, vw - r.width - M));
    box.style.setProperty('--tip-shift', Math.round(target - r.left) + 'px');
  };
  const on = (e) => { const t = e.target.closest && e.target.closest('.hilfe, .tip'); if (t) requestAnimationFrame(() => fit(t)); };
  document.addEventListener('mouseover', on);
  document.addEventListener('focusin', on);
  document.addEventListener('click', on);
})();

// Seitenleiste: Gruppen (Anwendungen, Praxis, Betrieb) einklappen und die Anwendungen per Ziehen sortieren (oder Alt + Pfeil nach oben/unten).
// Beides wird im Browser gemerkt (localStorage, je Browser); die Gruppe mit der aktuellen Seite bleibt offen.
(function () {
  'use strict';
  var ZU = 'kienzle.menu.zu', REIHE = 'kienzle.menu.apps';
  function lies(k) { try { var v = JSON.parse(localStorage.getItem(k) || 'null'); return Array.isArray(v) ? v : []; } catch (e) { return []; } }
  function merke(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* Speicher gesperrt: Einstellung gilt nur für diese Seite */ } }
  var gruppen = [].slice.call(document.querySelectorAll('nav.menu details[data-menu]'));
  var apps = document.querySelector('nav.menu details[data-menu="anwendungen"]');
  var schmal = window.matchMedia && window.matchMedia('(max-width: 760px)').matches;   // dort sind die Überschriften ausgeblendet: nichts einklappen
  var bereit = false;
  function zeilen() { return [].slice.call(apps.querySelectorAll('.menu-app')); }
  function speichereReihe() { merke(REIHE, zeilen().map(function (e) { return e.getAttribute('data-app'); })); }

  if (apps) {
    // gemerkte Reihenfolge zuerst, neue Anwendungen hinten in der Reihenfolge des Servers
    var reihe = lies(REIHE), alle = zeilen();
    var rang = function (e) { var i = reihe.indexOf(e.getAttribute('data-app')); return i < 0 ? 1e6 : i; };
    alle.map(function (e, i) { return { e: e, r: rang(e), i: i }; }).sort(function (a, b) { return a.r - b.r || a.i - b.i; })
      .forEach(function (x) { apps.appendChild(x.e); });

    var gezogen = null;
    function marke() { zeilen().forEach(function (z) { z.classList.remove('davor', 'danach'); }); }
    apps.addEventListener('dragstart', function (e) {
      var z = e.target.closest ? e.target.closest('.menu-app') : null;
      if (!z) return;
      gezogen = z;
      z.classList.add('zieht');
      if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', z.getAttribute('data-app')); } catch (x) { /* ältere Browser */ } }
    });
    apps.addEventListener('dragover', function (e) {
      var z = e.target.closest ? e.target.closest('.menu-app') : null;
      if (!gezogen || !z) return;
      e.preventDefault();
      marke();
      if (z === gezogen) return;
      var r = z.getBoundingClientRect();
      z.classList.add(e.clientY < r.top + r.height / 2 ? 'davor' : 'danach');
    });
    apps.addEventListener('drop', function (e) {
      var z = e.target.closest ? e.target.closest('.menu-app') : null;
      if (!gezogen || !z) return;
      e.preventDefault();
      if (z !== gezogen) {
        var r = z.getBoundingClientRect();
        if (e.clientY < r.top + r.height / 2) apps.insertBefore(gezogen, z); else apps.insertBefore(gezogen, z.nextSibling);
        speichereReihe();
      }
    });
    apps.addEventListener('dragend', function () { if (gezogen) gezogen.classList.remove('zieht'); gezogen = null; marke(); });
    // Tastatur: Alt + Pfeil nach oben/unten verschiebt die fokussierte Anwendung
    apps.addEventListener('keydown', function (e) {
      if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
      var z = e.target.closest ? e.target.closest('.menu-app') : null;
      if (!z) return;
      var nachbar = e.key === 'ArrowUp' ? z.previousElementSibling : z.nextElementSibling;
      if (!nachbar || !nachbar.classList.contains('menu-app')) return;
      e.preventDefault();
      if (e.key === 'ArrowUp') apps.insertBefore(z, nachbar); else apps.insertBefore(nachbar, z);
      speichereReihe();
      var a = z.querySelector('a'); if (a) a.focus();
    });
  }

  // Gruppen: zugeklappte merken; die Gruppe mit der aktuellen Seite wird nie zugeklappt.
  if (!schmal) {
    var zu = lies(ZU);
    gruppen.forEach(function (g) {
      if (zu.indexOf(g.getAttribute('data-menu')) >= 0 && !g.querySelector('a.current')) g.open = false;
    });
    gruppen.forEach(function (g) {
      g.addEventListener('toggle', function () {
        if (!bereit) return;
        var liste = lies(ZU).filter(function (n) { return n !== g.getAttribute('data-menu'); });
        if (!g.open) liste.push(g.getAttribute('data-menu'));
        merke(ZU, liste);
      });
    });
  }
  setTimeout(function () { bereit = true; }, 0);
})();
