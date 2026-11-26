'use strict';
// Vorgänge: Liste je Tag/Woche/Monat/Jahr, Filter, Abhaken der T2med-Rechnungen, Excel-Export.
const BASE = document.body.dataset.base || '/';
const SUITE_CSRF = (document.querySelector('meta[name="kienzle-csrf"]') || {}).content || '';
const $ = id => document.getElementById(id);
const el = (tag, text, cls) => { const e = document.createElement(tag); if (text !== undefined && text !== '') e.textContent = text; if (cls) e.className = cls; return e; };
const euro = c => new Intl.NumberFormat('de-DE', {style: 'currency', currency: 'EUR'}).format(c / 100);
const ARTEN = [['tag', 'Tag'], ['woche', 'Woche'], ['monat', 'Monat'], ['jahr', 'Jahr']];
const KINDS = [['alle', 'Alle'], ['t2med', 'T2med'], ['igel', 'IGeL']];
const PAYS = [['alle', 'Alle Zahlungen'], ['card', '💳 SumUp (Karte)'], ['cash', '💶 Bar'], ['transfer', '🏦 Überweisung']];
const STATI = [['alle', 'Alle Status'], ['bezahlt', 'Bezahlt'], ['offen', 'Offen'], ['zurueck', 'Zurückgestellt'], ['post', 'Per Post'], ['storniert', 'Storniert']];
const ZAHLUNG = {card: '💳 Karte', cash: '💶 Bar', transfer: '🏦 Überweisung'};
let sortKey = 'date', sortDir = -1;
let art = 'tag', kind = 'alle', pay = 'alle', status = 'alle', data = {items: [], rights: {periods: false}}, auswahl = new Set();
const heute = () => { const d = new Date(), p = n => String(n).padStart(2, '0'); return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()); };
async function api(action, body = {}) {
  const r = await fetch(BASE + 'api.php', {method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: {'Content-Type': 'application/json', 'X-Kienzle-Csrf': SUITE_CSRF}, body: JSON.stringify({action, ...body})});
  const j = await r.json().catch(() => ({}));
  if (!r.ok || j.error) throw new Error(j.error || 'Anfrage fehlgeschlagen.');
  return j;
}
const gruppeStatus = s => s === 'bezahlt' ? 'bezahlt' : s === 'storniert' ? 'storniert' : s === 'offen' ? 'offen' : /Letterxpress|Postversand/.test(s) ? 'post' : 'zurueck';
function chips(box, list, current, set) {
  box.replaceChildren(...list.map(([id, text]) => { const b = el('button', text, 'start-tab' + (id === current ? ' on' : '')); b.type = 'button'; b.onclick = () => set(id); return b; }));
}
const sortWert = (r, k) => k === 'date' ? r.date + ' ' + r.time : k === 'invoice_cents' ? r.invoice_cents : String(r[k] || '');
function sortieren(rows) {
  return rows.slice().sort((a, b) => {
    const x = sortWert(a, sortKey), y = sortWert(b, sortKey);
    const c = typeof x === 'number' ? x - y : String(x).localeCompare(String(y), 'de', {numeric: true, sensitivity: 'base'});
    return (c || (b.date + b.time).localeCompare(a.date + a.time)) * sortDir;
  });
}
function kopfZeile() {
  for (const th of document.querySelectorAll('#vg-table th[data-key]')) {
    th.dataset.label = th.dataset.label || th.textContent;
    const aktiv = th.dataset.key === sortKey;
    th.setAttribute('aria-sort', aktiv ? (sortDir > 0 ? 'ascending' : 'descending') : 'none');
    const b = el('button', th.dataset.label + (aktiv ? (sortDir > 0 ? ' ▲' : ' ▼') : ''), 'vg-sort'); b.type = 'button'; b.title = 'Nach „' + th.dataset.label + '“ sortieren';
    b.onclick = () => { if (sortKey === th.dataset.key) sortDir = -sortDir; else { sortKey = th.dataset.key; sortDir = th.dataset.key === 'date' || th.dataset.key === 'invoice_cents' ? -1 : 1; } zeigen(); };
    th.replaceChildren(b);
  }
  $('vg-th-post').hidden = !data.rights.letter; $('vg-th-storno').hidden = !data.rights.storno;
}
async function stornieren(r) {
  const hinweis = r.status === 'Überweisung offen' ? 'Es wurde noch nichts bezahlt; die offene Rechnung wird storniert.' : r.method === 'card' ? 'Die Kartenzahlung wird dabei NICHT automatisch erstattet (Rückzahlung am Terminal oder im SumUp-Konto).' : r.method === 'cash' ? 'Das Bargeld bitte dem Patienten zurückgeben.' : 'Eine Rückzahlung bitte selbst veranlassen.';
  if (!confirm('Rechnung ' + r.number + ' über ' + euro(r.invoice_cents) + ' wirklich stornieren?\nEs entsteht eine Stornorechnung mit neuer Nummer. ' + hinweis + (r.art === 'T2med' ? '\nDie T2med-Rechnung wird wieder offen.' : ''))) return;
  const grund = prompt('Grund der Stornierung (optional, höchstens 200 Zeichen):', '');
  if (grund === null) return;
  try { await api('storno', {payment_id: r.id, note: grund.slice(0, 200)}); await laden(); } catch (e) { alert(e.message); }
}
async function perPost(r, knopf) {
  if (!confirm('Rechnung ' + r.number + ' über ' + euro(r.invoice_cents) + ' für ' + (r.patient || 'den Patienten') + ' jetzt per Post über Letterxpress versenden?\nDas geht nur einmal.')) return;
  knopf.disabled = true;
  try { const j = await api('vorgaenge_post', {invoice_id: r.id}); alert(j.message || 'Gesendet.'); } catch (e) { alert(e.message); }
  await laden();
}
function sichtbar() {
  const q = $('vg-search').value.trim().toLowerCase().split(/\s+/).filter(Boolean);
  return sortieren(data.items).filter(r => (kind === 'alle' || (kind === 't2med') === (r.art === 'T2med')) && (pay === 'alle' || r.method === pay) && (status === 'alle' || gruppeStatus(r.status) === status)
    && q.every(t => (r.patient + ' ' + r.number).toLowerCase().includes(t)));
}
function zeigen() {
  chips($('vg-art'), ARTEN.filter(([id]) => data.rights.periods || (id !== 'monat' && id !== 'jahr')), art, id => { art = id; laden(); });
  chips($('vg-pay'), PAYS, pay, id => { pay = id; zeigen(); });
  chips($('vg-kind'), KINDS, kind, id => { kind = id; zeigen(); });
  chips($('vg-status'), STATI, status, id => { status = id; zeigen(); });
  kopfZeile();
  const rows = sichtbar(), body = $('vg-body'); body.replaceChildren();
  let summe = 0, paid = 0;
  for (const r of rows) {
    summe += r.invoice_cents; paid += r.paid_cents;
    const tr = el('tr', '', r.status === 'storniert' ? 'vg-storno' : '');
    const c0 = el('td'); if (r.can_done && data.rights.paid) { const cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = auswahl.has(r.id); cb.setAttribute('aria-label', 'Rechnung ' + r.number + ' auswählen'); cb.onchange = () => { cb.checked ? auswahl.add(r.id) : auswahl.delete(r.id); }; c0.append(cb); }
    const status_td = el('td'); status_td.append(el('span', r.status, 'tag ' + (r.status === 'bezahlt' ? 'ok' : r.status === 'storniert' ? 'err' : r.post ? 'info' : 'warn')));
    if (r.storno) status_td.append(' ', el('span', r.storno, 'muted small'));
    const betrag = el('td', euro(r.invoice_cents), 'num');
    const ist = el('td'); if (r.art === 'T2med' && r.can_done) ist.append(el('span', r.done_date ? '✓ ' + r.done_date.split('-').reverse().join('.') : '–', r.done_date ? 'tag ok' : 'muted'));
    tr.append(c0, el('td', r.date.split('-').reverse().join('.') + ' ' + r.time), el('td', r.art, 'tag ' + (r.art === 'T2med' ? 'info' : 'ok')), el('td', r.number), el('td', r.patient || '–'), el('td', ZAHLUNG[r.method] || ''), status_td, betrag, ist);
    if (data.rights.letter) { const pk = el('td'); if (r.can_post) { const pb = el('button', 'Per Post senden', 'secondary small'); pb.type = 'button'; pb.setAttribute('aria-label', 'Rechnung ' + r.number + ' per Post senden'); pb.onclick = () => perPost(r, pb); pk.append(pb); } tr.append(pk); }
    if (data.rights.storno) { const sk = el('td'); if (r.can_storno) { const sb = el('button', 'Stornieren', 'secondary small'); sb.type = 'button'; sb.setAttribute('aria-label', 'Rechnung ' + r.number + ' stornieren'); sb.onclick = () => stornieren(r); sk.append(sb); } tr.append(sk); }
    body.append(tr);
  }
  $('vg-empty').hidden = rows.length > 0;
  $('vg-label').textContent = data.period ? data.period.label : '';
  $('vg-sum').textContent = rows.length + ' Vorgänge · Rechnungsbetrag ' + euro(summe) + ' · davon bezahlt (ohne Storno) ' + euro(paid);
  const q = new URLSearchParams({art, date: $('vg-date').value, kind, pay}); $('vg-export').href = BASE + 'vorgaenge-export.php?' + q;
  $('vg-export').hidden = !data.rights.export; $('vg-done').hidden = $('vg-undone').hidden = !data.rights.paid;
  $('vg-all').hidden = !data.rights.paid;
}
async function laden() {
  try { data = await api('vorgaenge', {art, date: $('vg-date').value || heute()}); auswahl = new Set(); zeigen(); }
  catch (e) { $('vg-sum').textContent = e.message; }
}
function schieben(n) {
  const d = new Date($('vg-date').value + 'T12:00:00');
  if (art === 'tag') d.setDate(d.getDate() + n); else if (art === 'woche') d.setDate(d.getDate() + 7 * n); else if (art === 'monat') d.setMonth(d.getMonth() + n); else d.setFullYear(d.getFullYear() + n);
  const p = x => String(x).padStart(2, '0'); $('vg-date').value = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()); laden();
}
$('vg-prev').onclick = () => schieben(-1); $('vg-next').onclick = () => schieben(1);
$('vg-today').onclick = () => { $('vg-date').value = heute(); laden(); };
$('vg-date').onchange = laden; $('vg-search').addEventListener('input', zeigen);
$('vg-all').onchange = () => { auswahl = new Set(); if ($('vg-all').checked) for (const r of sichtbar()) if (r.can_done) auswahl.add(r.id); zeigen(); };
async function abhaken(flag) {
  if (!auswahl.size) { alert('Bitte zuerst T2med-Rechnungen auswählen.'); return; }
  try { await api('vorgaenge_done', {ids: [...auswahl], done: flag}); await laden(); } catch (e) { alert(e.message); }
}
$('vg-done').onclick = () => abhaken(true); $('vg-undone').onclick = () => abhaken(false);
$('vg-date').value = heute(); laden();
