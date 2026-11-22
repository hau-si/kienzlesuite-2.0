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
})();
