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
