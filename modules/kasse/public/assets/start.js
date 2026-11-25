'use strict';
// Startseite der Kasse: links die Rechnungen aus dem Eingang (Filter, Namenssuche, Marken), rechts die Kassenansicht als eingebettete Seite.
const BASE = document.body.dataset.base || '/';
const SUITE_CSRF = (document.querySelector('meta[name="kienzle-csrf"]') || {}).content || '';
const $ = id => document.getElementById(id);
const euro = cents => new Intl.NumberFormat('de-DE', {style: 'currency', currency: 'EUR'}).format(cents / 100);
const el = (tag, text, cls) => { const e = document.createElement(tag); if (text) e.textContent = text; if (cls) e.className = cls; return e; };
const FRISCH = 600;   // Sekunden: Rechnung gilt als „neu“, wenn sie in den letzten 10 Minuten aus T2med kam
const FILTER = [['offen', 'Offen'], ['bezahlt', 'Bezahlt'], ['zurueckgestellt', 'Zurückgestellt'], ['alle', 'Alle']];
const ART = {card: '💳 Karte', cash: '💶 Bar', transfer: '🏦 Überweisung'};
const ARTEN = [['alle', 'Alle'], ['t2med', 'T2med'], ['igel', 'IGeL']];
let rechte = {}, art = 'alle', items = [], erledigt = [], jetzt = Math.floor(Date.now() / 1000), letter = false, aktiv = null, filter = 'offen', bekannt = null, ersterLauf = true;

// Startlinks aus T2med (und der Testpatient) zeigen auf die Startadresse: die Kassenansicht mit dem Patienten öffnet rechts in dieser Seite
// (mit Seitenleiste und Rechnungsliste); die Rechnungsliste wählt dann nicht von selbst eine andere Rechnung vor.
let startHash = '';
(() => {
  let hash = location.hash;
  try { if (!hash.startsWith('#launch=')) hash = sessionStorage.getItem('kienzle-launch') || ''; } catch { hash = ''; }
  if (hash.startsWith('#launch=')) {
    try { sessionStorage.removeItem('kienzle-launch'); } catch { /* ohne sessionStorage */ }
    startHash = hash; history.replaceState(null, '', location.pathname + location.search);
  }
})();
function startOeffnen() {
  if (!startHash) return;
  aktiv = 'start'; $('detail').hidden = true; $('work').hidden = false;
  $('work').src = BASE + 'kassieren?eingebettet=1' + startHash; startHash = '';
}

async function api(action, data = {}) {
  const response = await fetch(BASE + 'api.php', {method: 'POST', credentials: 'same-origin', cache: 'no-store',
    headers: {'Content-Type': 'application/json', 'X-Kienzle-Csrf': SUITE_CSRF}, body: JSON.stringify({action, ...data})});
  const result = await response.json().catch(() => ({}));
  if (!response.ok || result.error) throw new Error(result.error || 'Anfrage fehlgeschlagen.');
  return result;
}
const gruppe = i => i.erledigt ? (i.open_transfer ? 'zurueckgestellt' : 'bezahlt') : (i.parked || i.transfer) ? 'zurueckgestellt' : 'offen';
const zeit = i => i.erledigt ? i.at : i.received_at;
const istT2med = i => !i.erledigt || i.t2med;
const imFilter = i => (filter === 'alle' || gruppe(i) === filter) && (art === 'alle' || (art === 't2med') === istT2med(i));
const alter = i => { const s = Math.max(0, jetzt - zeit(i)), m = Math.floor(s / 60), h = Math.floor(m / 60); return s < 90 ? 'gerade eben' : m < 60 ? 'vor ' + m + ' Min' : h < 24 ? 'vor ' + h + ' Std' : 'vor ' + Math.floor(h / 24) + ' Tg'; };
const frisch = i => !i.erledigt && jetzt - i.received_at <= FRISCH;
const frei = () => { aktiv = null; $('detail').hidden = true; $('work').hidden = false; $('work').src = BASE + 'kassieren?neu=1&t=' + Date.now(); anzeigen(); };

async function oeffnen(invoice, aktion) {
  try {
    const result = await api('inbox_visit', {invoice_id: invoice.id});
    aktiv = invoice.id; $('detail').hidden = true; $('work').hidden = false;
    const params = new URLSearchParams({rechnung: invoice.id}); if (aktion) params.set('aktion', aktion);
    $('work').src = BASE + 'kassieren?' + params.toString() + '#' + (result.url.split('#')[1] || '');
    anzeigen();
  } catch (error) { alert(error.message); }
}
async function marke(invoice, tag, an) {
  try { await api('inbox_tag', {invoice_id: invoice.id, tag, on: an}); await liste(); } catch (error) { alert(error.message); }
}
function detail(item) {
  aktiv = item.id; $('work').hidden = true; const box = $('detail'); box.hidden = false; box.replaceChildren();
  const pdf = BASE + 'beleg.php?p=' + encodeURIComponent(item.id);
  box.append(el('h2', (item.t2med ? 'T2med-Rechnung ' : 'Rechnung ') + item.number), el('p', (item.patient || 'Patient') + ' · ' + euro(item.amount_cents) + ' · ' + (ART[item.method] || item.method)));
  box.append(el('p', (item.open_transfer ? 'Überweisung offen (zurückgestellt) seit ' : 'Bezahlt am ') + new Date(item.at * 1000).toLocaleString('de-DE', {dateStyle: 'short', timeStyle: 'short'}), 'muted'));
  const aktionen = el('div', '', 'actions');
  const a = el('a', 'Rechnung / Beleg (PDF)', 'button secondary'); a.href = pdf; a.target = '_blank'; a.rel = 'noopener'; aktionen.append(a);
  box.append(aktionen);
  if (item.storno) {
    const s = el('div', '', 'storno-box'); s.append(el('p', 'Storniert am ' + new Date(item.storno.at * 1000).toLocaleString('de-DE', {dateStyle: 'short', timeStyle: 'short'}) + ' mit Stornorechnung ' + item.storno.number + '. Die ursprüngliche Rechnung bleibt für die Buchhaltung erhalten.', 'tag warn'));
    const l = el('a', 'Stornorechnung (PDF)', 'button secondary'); l.href = BASE + 'beleg.php?s=' + encodeURIComponent(item.storno.id); l.target = '_blank'; l.rel = 'noopener'; s.append(l); box.append(s);
  } else if (rechte.storno) {
    const f = el('details', '', 'fold'); f.append(el('summary', item.open_transfer ? 'Offene Rechnung stornieren …' : 'Stornieren …'));
    const grund = document.createElement('input'); grund.maxLength = 200; grund.placeholder = 'Grund (optional)'; grund.setAttribute('aria-label', 'Grund der Stornierung');
    const knopf = el('button', 'Stornorechnung mit neuer Nummer erstellen', 'secondary'); knopf.type = 'button';
    knopf.onclick = async () => {
      if (!confirm('Rechnung ' + item.number + ' über ' + euro(item.amount_cents) + ' wirklich stornieren?\nEs entsteht eine Stornorechnung mit neuer Nummer. ' + (item.open_transfer ? 'Es wurde noch nichts bezahlt; die offene Rechnung wird storniert.' : item.method === 'card' ? 'Die Kartenzahlung wird dabei NICHT automatisch erstattet (Rückzahlung am Terminal oder im SumUp-Konto).' : item.method === 'cash' ? 'Das Bargeld bitte dem Patienten zurückgeben.' : 'Eine Rückzahlung per Überweisung bitte selbst veranlassen.'))) return;
      try { await api('storno', {payment_id: item.id, note: grund.value}); await liste(); const neu = erledigt.find(x => x.id === item.id); if (neu) detail(neu); } catch (error) { alert(error.message); }
    };
    f.append(el('p', 'Die Rechnung bleibt für die Buchhaltung erhalten; es entsteht ein Stornobeleg mit eigener neuer Nummer. ' + (item.t2med ? 'Die T2med-Rechnung wird wieder offen.' : ''), 'muted small'), grund, knopf); box.append(f);
  }
  anzeigen();
}
function zeile(invoice) {
  if (invoice.erledigt) return zeileErledigt(invoice);
  const box = el('div', '', 'start-inv' + (invoice.id === aktiv ? ' aktiv' : '')); box.dataset.id = invoice.id;
  const kopf = el('button', '', 'start-inv-main'); kopf.type = 'button'; kopf.onclick = () => oeffnen(invoice);
  const name = el('span', '', 'start-inv-name'); name.append(el('strong', invoice.patient || 'Patient'), el('span', 'Rechnung ' + invoice.number, 'muted small'));
  kopf.append(name, el('strong', euro(invoice.amount_cents), 'start-inv-amount'));
  const meta = el('div', '', 'start-inv-meta');
  if (frisch(invoice)) meta.append(el('span', 'neu · ' + alter(invoice), 'tag ok')); else meta.append(el('span', alter(invoice), 'muted small'));
  if (invoice.t2med) { const h = el('span', '❤ T2med', 'tag ok'); h.title = 'Patient ist mit T2med verbunden: Zahlungen werden in der Akte dokumentiert'; meta.append(h); }
  if (invoice.parked) meta.append(el('span', 'zurückgestellt', 'tag warn'));
  if (invoice.transfer) meta.append(el('span', 'überwiesen', 'tag info'));
  if (invoice.letter && invoice.letter.status === 'sent') meta.append(el('span', 'per Post', 'tag info'));
  const knoepfe = el('span', '', 'start-inv-actions');
  const mk = (text, fn) => { const b = el('button', text, 'text-button'); b.type = 'button'; b.onclick = fn; knoepfe.append(b); };
  mk(invoice.parked ? 'Zurückholen' : 'Zurückstellen', () => marke(invoice, 'parked', !invoice.parked));
  mk(invoice.transfer ? 'Nicht überwiesen' : 'Überwiesen', () => marke(invoice, 'transfer', !invoice.transfer));
  if (rechte.inbox) mk('Löschen', async () => { if (!confirm('Rechnung ' + invoice.number + ' (' + (invoice.patient || 'Patient') + ', ' + euro(invoice.amount_cents) + ') aus dem Eingang löschen?')) return; try { await api('inbox_delete', {invoice_id: invoice.id}); await liste(); } catch (error) { alert(error.message); } });
  meta.append(knoepfe);
  box.append(kopf, meta);
  return box;
}
function zeileErledigt(item) {
  const box = el('div', '', 'start-inv' + (item.id === aktiv ? ' aktiv' : '')); box.dataset.id = item.id;
  const kopf = el('button', '', 'start-inv-main'); kopf.type = 'button'; kopf.onclick = () => detail(item);
  const name = el('span', '', 'start-inv-name'); name.append(el('strong', item.patient || 'Patient'), el('span', 'Rechnung ' + item.number, 'muted small'));
  kopf.append(name, el('strong', euro(item.amount_cents), 'start-inv-amount'));
  const meta = el('div', '', 'start-inv-meta');
  meta.append(el('span', alter(item), 'muted small'), el('span', item.open_transfer ? '🏦 Überweisung offen' : ART[item.method] || item.method, item.open_transfer ? 'tag warn' : 'tag ok'));
  if (item.t2med) meta.append(el('span', 'T2med', 'tag info'));
  if (item.storno) meta.append(el('span', 'storniert · ' + item.storno.number, 'tag err'));
  box.append(kopf, meta); return box;
}
function anzeigen() {
  const suche = $('inv-search').value.trim().toLowerCase().split(/\s+/).filter(Boolean);
  const alle = [...items, ...erledigt].sort((a, b) => zeit(b) - zeit(a)), zaehl = {offen: 0, bezahlt: 0, zurueckgestellt: 0, alle: alle.length};
  for (const i of alle) if (art === 'alle' || (art === 't2med') === istT2med(i)) { zaehl[gruppe(i)]++; }
  zaehl.alle = alle.filter(i => art === 'alle' || (art === 't2med') === istT2med(i)).length;
  $('kinds').replaceChildren(...ARTEN.map(([id, text]) => { const b = el('button', text, 'start-tab' + (id === art ? ' on' : '')); b.type = 'button'; b.onclick = () => { art = id; anzeigen(); }; return b; }));
  $('tabs').replaceChildren(...FILTER.map(([id, text]) => {
    const b = el('button', text + ' ' + zaehl[id], 'start-tab' + (id === filter ? ' on' : '')); b.type = 'button'; b.setAttribute('role', 'tab'); b.setAttribute('aria-selected', String(id === filter));
    b.onclick = () => { filter = id; anzeigen(); }; return b;
  }));
  const sichtbar = alle.filter(i => imFilter(i) && suche.every(t => ((i.patient || '') + ' ' + i.number).toLowerCase().includes(t)));
  $('inbox').replaceChildren(...sichtbar.map(zeile));
  $('inbox-empty').hidden = sichtbar.length > 0;
  $('free-entry').classList.toggle('aktiv', aktiv === null);
}
function inArbeit() { try { return $('work').contentDocument.body.dataset.dirty === '1'; } catch { return false; } }
async function liste() {
  const startSeite = ersterLauf && !!startHash;
  if (startSeite) startOeffnen();
  try {
    const result = await api('open_inbox'); items = result.items || [];
    try { const proc = await api('processed'); rechte = {...rechte, ...(proc.rights || {})}; erledigt = (proc.items || []).map(x => ({...x, erledigt: true})); } catch { erledigt = []; } letter = !!(result.letter && result.letter.enabled);
    jetzt = result.now || Math.floor(Date.now() / 1000); rechte = {...rechte, ...(result.rights || {})};
    items.sort((a, b) => b.received_at - a.received_at);
    // Die neueste Rechnung ist zuerst vorgewählt; später eingehende nur, wenn gerade nichts in Arbeit ist (nie mitten im Vorgang umschalten).
    const offen = items.filter(i => gruppe(i) === 'offen'), neueste = offen[0];
    const neu = neueste && bekannt && !bekannt.has(neueste.id);
    bekannt = new Set(items.map(i => i.id));
    if (!startSeite && neueste && (ersterLauf || (neu && frisch(neueste) && aktiv === null && !inArbeit()))) oeffnen(neueste);
    if (ersterLauf && !neueste && !startSeite) frei();
    ersterLauf = false;
    anzeigen();
  } catch (error) { if (ersterLauf) { ersterLauf = false; if (!startSeite) frei(); } $('inbox-empty').hidden = false; $('inbox-empty').textContent = error.message; }
}
async function tag() {
  try {
    const s = await api('day_stats');
    $('day-stats').textContent = 'Heute: ' + euro(s.total_cents) + ' · ' + s.card + ' Karte · ' + s.cash + ' bar';
  } catch { /* Tageswerte sind nur Beiwerk */ }
}
async function einrichtung() {
  const z = $('setup-count'); if (!z) return;
  try { const r = await api('setup_count'); z.hidden = !r.count; z.textContent = String(r.count); z.title = r.red ? r.red + ' Punkt(e) erneut nötig oder fehlerhaft' : 'Offene Einrichtungspunkte'; } catch { /* nur Beiwerk */ }
}
$('free-entry').onclick = frei;
$('inv-search').addEventListener('input', anzeigen);
window.addEventListener('message', event => { if (event.origin === location.origin && event.data === 'ks-refresh') { liste(); tag(); } });
liste(); tag(); einrichtung();
setInterval(() => { if (!document.hidden) { liste(); tag(); } }, 15000);
