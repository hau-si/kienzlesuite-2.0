'use strict';
// Startseite der Kasse: offene T2med-Rechnungen mit Direktwahl der Zahlungsart, Tageswerte, Wechsel des Vorgangs links.
const BASE = document.body.dataset.base || '/';
const SUITE_CSRF = (document.querySelector('meta[name="kienzle-csrf"]') || {}).content || '';
const $ = id => document.getElementById(id);
const euro = cents => new Intl.NumberFormat('de-DE', {style: 'currency', currency: 'EUR'}).format(cents / 100);
const el = (tag, text, cls) => { const e = document.createElement(tag); if (text) e.textContent = text; if (cls) e.className = cls; return e; };
// Startlinks aus T2med (und der Testpatient) zeigen auf die Startadresse: gleich in die schlanke Kassenansicht weiterleiten.
(() => {
  let hash = location.hash;
  try { if (!hash.startsWith('#launch=')) hash = sessionStorage.getItem('kienzle-launch') || ''; } catch { hash = ''; }
  if (hash.startsWith('#launch=')) { try { sessionStorage.removeItem('kienzle-launch'); } catch { /* ohne sessionStorage */ } location.replace(BASE + 'kassieren' + hash); }
})();
let aktiv = null;

async function api(action, data = {}) {
  const response = await fetch(BASE + 'api.php', {method: 'POST', credentials: 'same-origin', cache: 'no-store',
    headers: {'Content-Type': 'application/json', 'X-Kienzle-Csrf': SUITE_CSRF}, body: JSON.stringify({action, ...data})});
  const result = await response.json().catch(() => ({}));
  if (!response.ok || result.error) throw new Error(result.error || 'Anfrage fehlgeschlagen.');
  return result;
}
function neuerVorgang() { aktiv = null; $('work-title').textContent = 'Freie Eingabe · IGeL · GOÄ'; $('work').src = BASE + 'kassieren?neu=1&t=' + Date.now(); markieren(); }
function markieren() { document.querySelectorAll('.start-inv').forEach(box => box.classList.toggle('aktiv', box.dataset.id === aktiv)); }
async function oeffnen(invoice, aktion) {
  try {
    const result = await api('inbox_visit', {invoice_id: invoice.id});
    const ticket = result.url.split('#')[1] || '';
    aktiv = invoice.id; $('work-title').textContent = 'Rechnung ' + invoice.number + ' · ' + invoice.patient;
    $('work').src = BASE + 'kassieren?' + new URLSearchParams({rechnung: invoice.id, aktion}).toString() + '#' + ticket;
    markieren();
  } catch (error) { alert(error.message); }
}
function zeile(invoice, letter) {
  const box = el('div', '', 'start-inv'); box.dataset.id = invoice.id;
  const kopf = el('div', '', 'start-inv-head');
  kopf.append(el('strong', invoice.patient || 'Patient'), el('strong', euro(invoice.amount_cents)));
  const datum = invoice.invoice_date ? invoice.invoice_date.split('-').reverse().join('.') : '';
  box.append(kopf, el('p', 'Rechnung ' + invoice.number + (datum ? ' vom ' + datum : ''), 'muted'));
  const knoepfe = el('div', '', 'start-pay');
  const mk = (text, aktion, cls) => { const b = el('button', text, cls || 'secondary'); b.type = 'button'; b.onclick = () => oeffnen(invoice, aktion); knoepfe.append(b); };
  mk('💳 Karte', 'card', 'primary'); mk('💶 Bar', 'cash'); mk('🏦 Überweisung', 'later');
  if (letter && invoice.postable) mk('✉ Per Post', 'post');
  box.append(knoepfe);
  return box;
}
async function liste() {
  try {
    const result = await api('open_inbox'), items = result.items || [];
    $('inbox').replaceChildren(...items.map(i => zeile(i, result.letter && result.letter.enabled)));
    $('inbox-empty').hidden = items.length > 0; markieren();
  } catch (error) { $('inbox-empty').hidden = false; $('inbox-empty').textContent = error.message; }
}
async function tag() {
  try {
    const s = await api('day_stats');
    $('day-stats').textContent = 'Heute: ' + euro(s.total_cents) + ' · ' + s.card + ' Karte · ' + s.cash + ' bar';
  } catch { /* Tageswerte sind nur Beiwerk */ }
}
$('work-new').onclick = neuerVorgang;
liste(); tag();
setInterval(() => { if (!document.hidden) { liste(); tag(); } }, 15000);
