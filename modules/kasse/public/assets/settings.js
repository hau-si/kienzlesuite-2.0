'use strict';
const $ = id => document.getElementById(id);
// Webpfad des Moduls in Praxisherz (z. B. /kasse/). Anmeldung und Rechte kommen von Praxisherz.
const BASE = document.body.dataset.base || '/';
let csrf = '';
const TESTS = {sumup: ['sumup', 'Verbindung zu SumUp prüfen'], printing: ['receipt_printer', 'Testbon drucken'],
  office_printing: ['office_printer', 'Testseite drucken'], invoice_export: ['export', 'Testdatei ablegen'], letterxpress: ['letterxpress', 'Verbindung und Guthaben prüfen']};
// Felder, die nur bei bestimmten Werten sichtbar sind.
const SHOW = {
  'sumup.api_key': v => v.mode === 'live', 'sumup.affiliate_key': v => v.mode === 'live', 'sumup.app_id': v => v.mode === 'live',
  'sumup.merchant_code': v => v.mode === 'live', 'sumup.reader_id': v => v.mode === 'live',
  'fhir.entry_code': v => v.mode === 'live', 'mail.from_name': v => v.enabled,
  'letterxpress.color': v => v.enabled,
  'letterxpress.duplex': v => v.enabled, 'letterxpress.shipping': v => v.enabled, 'letterxpress.registered': v => v.enabled,
  'tax.rate': v => !v.small_business, 'tax.manual_vat': v => !v.small_business, 'tax.exempt_note': v => !v.small_business, 'tax.small_business_note': v => v.small_business,
};

/** Zeitstempel (Sekunden) als „TT.MM.JJ, HH:MM“. */
function when(seconds) {
  return seconds ? new Date(seconds * 1000).toLocaleString('de-DE', {dateStyle: 'short', timeStyle: 'short'}) : '';
}

function el(tag, text, cls, attrs) {
  const node = document.createElement(tag);
  if (text != null) node.textContent = text;
  if (cls) node.className = cls;
  for (const [k, v] of Object.entries(attrs || {})) node.setAttribute(k, v);
  return node;
}
// Hilfe-Symbol mit Erklärung, Beispiel und Bedeutung der Auswahlmöglichkeiten (Maus, Tastatur, Antippen).
function tip(field) {
  if (!field.tip && !field.example && !field.options) return null;
  const box = el('span', null, 'tip-box', {role: 'tooltip'});
  if (field.tip) box.append(el('span', field.tip, 'tip-text'));
  if (field.options && field.choices) for (const [value, text] of Object.entries(field.options)) {
    const line = el('span', null, 'tip-option'); line.append(el('strong', (field.choices[value] || value) + ': '), document.createTextNode(text)); box.append(line);
  }
  if (field.example) { const line = el('span', null, 'tip-example'); line.append(el('strong', 'Beispiel: '), el('code', field.example)); box.append(line); }
  const button = el('button', 'i', 'tip', {type: 'button', 'aria-label': 'Hilfe zu ' + field.label});
  button.onclick = event => { event.preventDefault(); const open = !button.classList.contains('open');
    document.querySelectorAll('.tip.open').forEach(t => t.classList.remove('open')); button.classList.toggle('open', open); };
  button.append(box);
  const place = () => requestAnimationFrame(() => fitTip(button, box));
  button.addEventListener('mouseenter', place); button.addEventListener('focus', place); button.addEventListener('click', place);
  return button;
}
/** Tooltip im sichtbaren Bereich halten: am rechten (oder linken) Rand nach innen schieben, Pfeil bleibt beim Symbol. */
function fitTip(button, box) {
  box.style.left = '-12px'; box.style.removeProperty('--arrow');
  const margin = 8, rect = box.getBoundingClientRect(), width = document.documentElement.clientWidth;
  let shift = 0;
  if (rect.right > width - margin) shift = width - margin - rect.right;
  if (rect.left + shift < margin) shift = margin - rect.left;
  if (shift) { box.style.left = (-12 + shift) + 'px'; box.style.setProperty('--arrow', Math.max(10, 16 - shift) + 'px'); }
}
document.addEventListener('click', event => { if (!event.target.closest('.tip')) document.querySelectorAll('.tip.open').forEach(t => t.classList.remove('open')); });
document.addEventListener('keydown', event => { if (event.key === 'Escape') document.querySelectorAll('.tip.open').forEach(t => t.classList.remove('open')); });

async function api(action, data = {}) { return rawApi(action, data); }
async function rawApi(action, data = {}) {
  const response = await fetch(BASE + 'settings-api.php', {method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json', 'X-Kienzle-Csrf': csrf}, body: JSON.stringify({action, ...data})});
  const result = await response.json().catch(() => ({}));
  // Heikle Änderung: erst mit Passwort und Code bei Praxisherz bestätigen, danach kommt der Browser hierher zurück.
  if (response.status === 403 && result.bestaetigen) { location.href = result.bestaetigen; throw new Error(result.error); }
  if (response.status === 401) { location.href = '/anmelden?weiter=' + encodeURIComponent(location.pathname); throw new Error(result.error || 'Bitte anmelden.'); }
  if (!response.ok) throw new Error(result.error || 'Fehler ' + response.status);
  return result;
}
function message(text, error) {
  if (text && $('lade')) $('lade').hidden = true; const m = $('message'); m.hidden = !text; m.textContent = text || ''; m.classList.toggle('error', !!error);
  if (text) window.scrollTo({top: 0, behavior: 'smooth'});
}
function show(view) { if ($('lade')) $('lade').hidden = true; for (const id of ['settings']) if ($(id)) $(id).hidden = id !== view; }

function values(form, section) {
  const out = {};
  for (const field of section.fields) {
    const input = form.elements[field.key]; if (!input) continue;
    out[field.key] = field.type === 'bool' ? input.checked : input.value;
  }
  return out;
}
function applyVisibility(form, section) {
  const current = values(form, section);
  const enabled = section.fields.some(f => f.key === 'enabled') ? current.enabled : true;
  for (const field of section.fields) {
    const row = form.querySelector('[data-field="' + field.key + '"]');
    const rule = SHOW[section.id + '.' + field.key];
    row.hidden = (field.key !== 'enabled' && !enabled) || (rule ? !rule(current) : false);
  }
}
function render(data) {
  csrf = data.csrf || csrf;
  $('mode').hidden = false;
  $('mode').textContent = data.mode === 'live' ? (data.fhir_mode === 'live' ? 'LIVE' : 'LIVE · T2MED TESTMODUS') : 'TESTMODUS'; $('mode').classList.toggle('test', /TESTMODUS/.test($('mode').textContent));
  $('mode').classList.toggle('live', data.mode === 'live');
  $('warning').hidden = !data.warning; $('warning').textContent = data.warning || '';
  renderInvoicePrinter(data.invoice_printer || {});
  renderLetterPrinter(data.letter_printer || {});
  const root = $('sections');
  document.querySelectorAll('[data-generated]').forEach(node => node.remove());
  for (const section of data.sections) {
    const card = el('section', null, 'card settings-card', {id: 's-' + section.id, 'data-generated': '1'});
    card.append(el('h2', section.label));
    const form = el('form', null, 'settings-form');
    for (const field of section.fields) {
      const row = el('div', null, 'field', {'data-field': field.key});
      const id = 'f-' + section.id + '-' + field.key;
      let input;
      if (field.type === 'bool') {
        const label = el('label', null, 'check'); input = el('input', null, null, {type: 'checkbox', id, name: field.key});
        input.checked = !!field.value; label.append(input, document.createTextNode(' ' + field.label));
        const line = el('div', null, 'label-row'); line.append(label); const t = tip(field); if (t) line.append(t); row.append(line);
      } else {
        const line = el('div', null, 'label-row'); line.append(el('label', field.label, null, {for: id}));
        const t = tip(field); if (t) line.append(t); row.append(line);
        if (field.type === 'choice') {
          input = el('select', null, null, {id, name: field.key});
          for (const [value, label] of Object.entries(field.choices)) { const o = el('option', label, null, {value}); if (value === field.value) o.selected = true; input.append(o); }
        } else if (field.type === 'list' || field.type === 'pem' || field.type === 'text') {
          input = el('textarea', null, field.type === 'text' ? 'plain' : null, {id, name: field.key, rows: field.type === 'pem' ? '6' : '3', spellcheck: field.type === 'text' ? 'true' : 'false'});
          if (field.type !== 'pem') input.value = field.value || '';
          else input.placeholder = field.value.set ? 'Zertifikat gespeichert – leer lassen zum Behalten, „-“ zum Entfernen' : '-----BEGIN CERTIFICATE----- …';
        } else {
          input = el('input', null, null, {id, name: field.key, type: field.type === 'secret' ? 'password' : (field.type === 'int' || field.type === 'money' ? 'number' : 'text'), autocomplete: 'off', spellcheck: 'false'});
          if (field.type === 'secret') input.placeholder = field.value.set ? 'gespeichert – leer lassen zum Behalten' : 'noch nicht gesetzt';
          else input.value = field.value ?? '';
          if (field.type === 'money') { input.min = '1'; input.step = '1'; }
        }
        if (field.example && field.type !== 'secret' && field.type !== 'pem' && field.type !== 'choice') input.placeholder = 'z. B. ' + field.example.replace(/\n/g, ' · ');
        row.append(input);
        if (field.type === 'choice' && field.options) {
          // Erklärung der gerade gewählten Möglichkeit direkt unter der Auswahl.
          const explain = el('p', null, 'muted small option-help');
          const update = () => { explain.textContent = field.options[input.value] || ''; };
          input.addEventListener('change', update); update(); row.append(explain);
        }
      }
      if (field.help) row.append(el('p', field.help, 'muted small'));
      form.append(row);
    }
    const buttons = el('div', null, 'row-buttons');
    const save = el('button', 'Speichern', 'primary', {type: 'submit'}); buttons.append(save);
    if (TESTS[section.id]) {
      const [kind, label] = TESTS[section.id]; const test = el('button', label, 'secondary', {type: 'button'});
      test.onclick = () => busy(test, async () => { message((await api('test', {kind})).message); });
      buttons.append(test);
    }
    form.append(buttons);
    if (section.id === 'fhir') {
      // Die T2med-Verbindung gehört von Praxisherz: Stand zeigen und direkt zur Einrichtung verlinken.
      const ok = !!(data.connections && data.connections.t2med);
      const note = el('p', ok ? 'Die T2med-Verbindung von Praxisherz ist eingerichtet. ' : 'Die T2med-Verbindung von Praxisherz ist noch nicht eingerichtet. ', ok ? 'notice' : 'inline-error');
      note.append(el('a', ok ? 'Verbindung prüfen (Praxis → T2med)' : 'Jetzt in Praxisherz einrichten (Praxis → T2med)', null, {href: '/verwaltung/t2med'}));
      form.prepend(note);
      form.append(t2medSetupHelp());
    }
    form.oninput = form.onchange = event => {
      // Anderes Druckermodell: Breite leeren, der Server setzt die passende Breite.
      if (section.id === 'printing' && event.target.name === 'model' && form.elements.width_dots) form.elements.width_dots.value = '';
      applyVisibility(form, section);
    };
    form.onsubmit = event => { event.preventDefault(); busy(save, async () => {
      const result = await api('save', {section: section.id, values: values(form, section)});
      render(result); message(result.message); location.hash = 's-' + section.id;
    }); };
    card.append(form); root.append(card);
    applyVisibility(form, section);
  }
  layoutTabs(data);
}

// ---- Virtuelle Drucker „vKasse“ und „vBriefversand“ (legt Praxisherz aus module.toml an) ---------------
const PRINTERS = {
  invoice: {box: 'invoice-printer', queue: 'vKasse', ppd: 'vKasse-PDF.ppd', use: 'In T2med die Rechnung auf diesen Drucker drucken – sie erscheint dann beim Patienten zum Kassieren.'},
  letter: {box: 'letter-printer', queue: 'vBriefversand', ppd: 'vBriefversand-PDF.ppd', use: 'Für den Sammelversand in T2med vorübergehend den Rechnungsdrucker auf diesen Drucker umstellen: jede gedruckte Rechnung geht sofort per Post an Letterxpress (und erscheint unter Vorgänge). Danach wieder zurückstellen.'},
};
function renderInvoicePrinter(info) { renderPrinterHelp(PRINTERS.invoice, info); }
function renderLetterPrinter(info) { renderPrinterHelp(PRINTERS.letter, info); }
function renderPrinterHelp(cfg, info) {
  $(cfg.box + '-help').hidden = !info.enabled;
  const box = $(cfg.box + '-status'); box.replaceChildren();
  if (!info.enabled) return;
  printerFold(cfg.box, !!(info.queue && info.queue.ours));
  if (!info.queue) {
    box.append(el('p', 'Der Drucker „' + cfg.queue + '“ fehlt in Praxisherz. Unter Praxis → Drucker „Virtuelle Drucker abgleichen“ wählen; legt Praxisherz ihn dann nicht an, das Modul unter Betrieb → Module neu installieren.', 'inline-error'));
    return;
  }
  if (!info.queue.ours) { box.append(el('p', 'Ein anderer Drucker heißt bereits „' + cfg.queue + '“. Bitte diesen umbenennen oder entfernen.', 'inline-error')); return; }
  box.append(el('p', 'Eingerichtet (' + info.queue.state + '), per IPP freigegeben. ' + cfg.use, 'notice'));
  box.append(agentNote('Diesen Drucker richtet der Praxisherz-Agent auf jedem Windows-PC selbst ein'));
  const how = handweg();
  const ol = el('ol', null, 'steps');
  ol.append(el('li', 'Windows: Drucker hinzufügen → „Freigegebenen Drucker über den Namen auswählen“: ' + info.url + ' – Treiber „Microsoft IPP Class Driver“, Papier A4. ' + ippIpNote(info.url, info.ips)));
  const driver = el('li', 'Windows 11: Fehlt der Treiber „Microsoft IPP Class Driver“ oder akzeptiert Windows die Adresse nicht, PowerShell als Administrator öffnen und ausführen:');
  driver.append(el('pre', ippPowerShell(cfg.queue, info.url, info.ips, 'A4'), 'audit-list'));
  ol.append(driver);
  ol.append(el('li', 'Druckereigenschaften lassen sich nicht öffnen („Das Handle ist ungültig“) oder der Druck bleibt aus: Die Windows-Einrichtung ist veraltet, z. B. nach Neueinrichtung des Servers. Drucker entfernen und mit dem PowerShell-Skript oben neu einrichten (es startet den Druckspooler neu und entfernt den alten Drucker). Eine Meldung beim Löschen alter Anschlüsse („Anschluss unbekannt“) ist harmlos. Möglichst die IP-Adresse statt des Namens verwenden.'));
  const mac = el('li'); mac.append(document.createTextNode('Mac: „' + cfg.queue + '“ hinzufügen, als Treiber die Datei '), el('a', cfg.ppd, null, {href: BASE + 'assets/' + cfg.ppd, download: cfg.ppd}), document.createTextNode(' wählen.'));
  ol.append(mac); how.append(ol); box.append(how);
}

// Ein Satz zum Praxisherz-Agent mit Verweis auf Praxis → Arbeitsplätze von Praxisherz.
function agentNote(text) {
  const p = el('p', text + ' (');
  p.append(el('a', 'Praxis → Arbeitsplätze', null, {href: '/hilfe/arbeitsplatz', target: '_top'}), ').');
  return p;
}
// Notweg ohne Agent: dezent eingeklappt (details.how aus dem Stil von Praxisherz).
function handweg() {
  const d = el('details', null, 'how');
  d.append(el('summary', 'Von Hand einrichten (ohne Agent, Mac/Linux oder wenn etwas schiefgeht)'));
  return d;
}

// Karte als Ausklappzeile (nur wo das HTML sie so anlegt, z. B. „vBriefversand“): fertig = zugeklappt mit „erledigt“, sonst offen mit „fehlt“.
function printerFold(id, done) {
  const fold = $(id + '-fold'); if (!fold) return;
  const mark = $(id + '-mark'), tag = $(id + '-tag');
  mark.textContent = done ? '✓' : '!'; mark.className = 'mark-icon ' + (done ? 'done' : 'error');
  tag.textContent = done ? 'erledigt' : 'fehlt'; tag.className = 'tag ' + (done ? 'ok' : 'err');
  // Von Hand auf- oder zugeklappt bleibt so; das toggle-Ereignis kommt auch nach dem Setzen hier, daher mit dem gesetzten Wert vergleichen.
  if (!fold.dataset.touched) { fold.open = !done; fold.dataset.auto = String(!done); }
  fold.ontoggle = () => { if (String(fold.open) !== fold.dataset.auto) fold.dataset.touched = '1'; };
}

// ---- Reiter: laufende Arbeit, Praxisangaben, Drucker, einmalige Einrichtung ---------------
// Einmalige Einrichtung in einklappbaren Themenblöcken (alles Weitere steht in den Reitern daneben).
const GROUPS = [
  {id: 'praxis', label: 'Praxis & Rechnungen', cards: ['s-practice', 's-tax', 's-app', 's-mail', 's-letterxpress', 'letter-printer-help', 's-invoice_export']},
  {id: 'zahlung', label: 'Zahlung & T2med', cards: ['s-sumup', 's-fhir', 's-invoice_inbox', 'invoice-printer-help']},
  {id: 'drucker', label: 'Drucker', cards: ['s-printing', 's-office_printing']},
  {id: 'netz', label: 'Arbeitsplätze', cards: ['s-clients']},
  {id: 'sicherheit', label: 'Protokoll', cards: ['s-audit']},
];
// Die Einstellungsseite hat nur noch einen Bereich (keine Reiter mehr); die Leistungsliste liegt auf der eigenen Seite „IGeL“.
const TABS = [
  {id: 'einrichtung', label: 'Einrichtung', hint: 'einmalig', cards: ['s-checklist', ...GROUPS.flatMap(g => g.cards)], groups: GROUPS},
];
let lastOpenCards = [];
function openGroupOf(cardId) { const card = $(cardId); card?.closest('details.setup-group')?.setAttribute('open', ''); card?.querySelector(':scope > details.fold')?.setAttribute('open', ''); }
let activeTab = null;
function tabOf(cardId) { return (TABS.find(t => t.cards.includes(cardId)) || {}).id; }
function layoutTabs(data) {
  const panels = $('tab-panels'), bar = $('tabs');
  if (!bar.querySelector('.tab')) {
    for (const tab of TABS) {
      const button = el('button', null, 'tab', {type: 'button', role: 'tab', id: 'tab-' + tab.id, 'aria-controls': 'panel-' + tab.id});
      button.append(el('span', tab.label));
      if (tab.hint) button.append(el('small', tab.hint));
      button.append(el('span', null, 'tab-badge', {id: 'badge-' + tab.id, hidden: ''}));
      button.onclick = () => { activate(tab.id); history.replaceState(null, '', '#' + tab.id); };
      bar.append(button);
      panels.append(el('div', null, 'tab-panel', {id: 'panel-' + tab.id, role: 'tabpanel', 'aria-labelledby': 'tab-' + tab.id}));
    }
  }
  for (const tab of TABS) {
    if (!tab.groups) { for (const id of tab.cards) { const card = $(id); if (card) $('panel-' + tab.id).append(card); } continue; }
    const panel = $('panel-' + tab.id); const lead = $('s-checklist'); if (lead) panel.append(lead);
    for (const group of tab.groups) {
      let box = $('group-' + group.id);
      if (!box) { box = el('details', null, 'setup-group fold', {id: 'group-' + group.id}); const sum = el('summary'); sum.append(el('span', '○', 'mark-icon'), el('strong', group.label), el('span', '', 'tag', {id: 'tag-' + group.id})); box.append(sum); }
      panel.append(box);
      for (const id of group.cards) { const card = $(id); if (card) box.append(card); }
    }
    const eingang = $('s-inbox-list'); if (eingang) panel.append(eingang);
  }
  const open = renderChecklist(data);
  rankGroups();
  if (!activeTab) {
    for (const id of lastOpenCards) openGroupOf(id);
    openGroupOf(location.hash.slice(1));
    const hash = location.hash.slice(1);
    activate('einrichtung');
    if (tabOf(hash)) setTimeout(() => $(hash)?.scrollIntoView({block: 'start'}), 50);
  } else activate(activeTab);
}
// Themenblöcke wie in der Basis: Rot (erneut nötig/Fehler) zuerst, dann Offenes, dann Fertiges; Rotes und Offenes ist aufgeklappt.
let lastItems = [];
function rankGroups() {
  const panel = $('panel-einrichtung'); if (!panel) return;
  const rows = [];
  for (const group of GROUPS) {
    const box = $('group-' + group.id); if (!box) continue;
    const mine = lastItems.filter(i => !i.optional && !i.info && group.cards.includes(i.card));
    const bad = mine.filter(i => i.error).length, need = mine.filter(i => !i.done).length;
    const rank = bad ? 0 : need ? 1 : 2;
    box.dataset.rank = String(rank);
    const mark = box.querySelector('.mark-icon'), tag = $('tag-' + group.id);
    mark.textContent = bad ? '!' : need ? '○' : '✓'; mark.className = 'mark-icon ' + (bad ? 'error' : need ? 'open' : 'done');
    tag.textContent = bad ? bad + ' rot' : need ? need + ' offen' : 'fertig'; tag.className = 'tag ' + (bad ? 'err' : need ? 'warn' : 'ok');
    if (rank < 2 && !box.dataset.touched) box.open = true;
    rows.push([rank, GROUPS.indexOf(group), box]);
  }
  rows.sort((a, b) => a[0] - b[0] || a[1] - b[1]);
  for (const [, , box] of rows) panel.append(box);
}
function activate(id) {
  activeTab = id;
  document.querySelectorAll('details.setup-group').forEach(d => { d.ontoggle = () => { d.dataset.touched = '1'; }; });
  for (const tab of TABS) {
    $('panel-' + tab.id).hidden = tab.id !== id;
    $('tab-' + tab.id).setAttribute('aria-selected', String(tab.id === id));
  }
}
// Verweise wie #s-printers öffnen den passenden Reiter und springen zur Karte.
window.addEventListener('hashchange', () => {
  const hash = location.hash.slice(1), tab = TABS.some(t => t.id === hash) ? hash : tabOf(hash);
  if (!tab) return;
  activate(tab);
  openGroupOf(hash);
  if (tabOf(hash)) $(hash)?.scrollIntoView({behavior: 'smooth', block: 'start'});
});
function badge(tab, count) { const b = $('badge-' + tab); if (!b) return; b.hidden = !count; b.textContent = count ? String(count) : ''; }

const GUIDE_URL = BASE + 'hilfe/t2med';
/** Anleitung für T2med: Drittanbieter-Zugriff mit Menüleisten-Button (Aufruf-Adresse zum Kopieren). */
function t2medSetupHelp() {
  const d = el('details', null, 'how'); d.append(el('summary', 'In T2med einrichten: Button „Kartenzahlung“ in der Funktionsleiste'));
  const ol = el('ol');
  ol.append(el('li', 'In T2med in der Suche „Drittanbieter-Zugriffe verwalten“ öffnen (fehlt die Funktion, muss ein Admin sie für den Benutzer erst freischalten) und den Eintrag mit dem Stift bearbeiten.'));
  const li = el('li', 'Als Adresse eintragen (genau so, mit den Platzhaltern) und eine Bezeichnung vergeben, z. B. „Kartenzahlung“:'); li.append(el('br'), el('code', 'praxisherz-kasse://?kontextId=${kontextId}&fhirBasisUrl=${fhirBasisUrl}&oAuthToken=${oAuthToken}', 'code-copy')); ol.append(li);
  ol.append(el('li', 'Auf Plus klicken, oben rechts mit dem Häkchen speichern und in der Liste davor auf „aktualisieren“ (Pfeile) klicken.'));
  ol.append(el('li', 'Dann „Funktionsleisten verwalten“: Konfiguration anlegen oder bearbeiten (Name, Benutzer, Benutzergruppe, Station), „Aktion hinzufügen“, die Aktion unter der vergebenen Bezeichnung suchen, Farbe und Kürzel oder Icon wählen und bestätigen.'));
  ol.append(el('li', 'Der Starter-Aufruf funktioniert erst, wenn auf dem Arbeitsplatz der Praxisherz-Agent installiert ist (Praxis → Arbeitsplätze).'));
  ol.append(el('li', 'Die FHIR-Adresse, die T2med beim Aufruf mitgibt, muss in Praxisherz unter Praxis → T2med bei „Erlaubte fhirBasisUrl aus T2med“ stehen (die Adresse des FHIR-Servers gilt immer als erlaubt). Bei einer Fehlermeldung zeigt die Seite die tatsächlichen Werte.'));
  const guide = el('li'); guide.append('Mit Bildern: ', el('a', 'Anleitung „T2med einrichten“', null, {href: GUIDE_URL, target: '_blank', rel: 'noopener'})); ol.append(guide);
  d.append(ol);
  d.append(el('p', 'Alle Aufrufadressen stehen gesammelt unter Praxis → T2med.', 'muted'));
  return d;
}

/** Überblick der einmaligen Schritte in der Reihenfolge, in der sie nötig sind (wie die Einrichtung der Basis); liefert die Zahl offener Pflichtpunkte. */
const PLACES = {web: 'In diesem Webfenster', pc: 'Auf den Kassen-PCs', t2med: 'In T2med'};
function renderChecklist(data) {
  const invoiceQueue = !!(data.invoice_printer && data.invoice_printer.queue);
  const letterQueue = !!(data.letter_printer && data.letter_printer.queue && data.letter_printer.enabled);
  // Punkte der Kasse kommen vom Server (dieselben wie im Einrichtungs-Überblick von Praxisherz), schon in der nötigen Reihenfolge.
  const agentPrinters = [...(invoiceQueue ? ['vKasse'] : []), ...(letterQueue ? ['vBriefversand'] : [])];
  const own = (data.setup || []).map(i => ({optional: !!i.optional, ort: /Starter/.test(i.title) ? 'pc' : 'web', done: i.state === 'done', error: i.state === 'error', label: i.title, card: (i.link || '').split('#')[1] || 's-checklist', detail: i.hint}));
  const web = own.filter(i => i.ort === 'web'), pc = own.filter(i => i.ort === 'pc');
  const items = [
    ...web,
    {ort: 'pc', info: true, label: 'Praxisherz-Agent auf den Kassen-PCs installieren (Praxis → Arbeitsplätze) – richtet Starter, Zertifikat' + (agentPrinters.length ? ' und ' + (agentPrinters.length > 1 ? 'die Drucker ' : 'den Drucker ') + agentPrinters.join('/') : '') + ' ein', card: 's-clients', href: '/hilfe/arbeitsplatz#paket', hrefLabel: 'Seite öffnen', note: 'Ohne Agent (Mac/Linux oder wenn etwas schiefgeht): „Von Hand einrichten“ bei den Druckerkarten und auf der Seite Arbeitsplätze.'},
    ...pc,
    {ort: 't2med', info: true, label: 'Drittanbieter-Zugriff mit Funktionsleisten-Button „Kartenzahlung“ anlegen (Aufruf-Adresse steht bei der T2med-Anbindung)', card: 's-fhir', href: GUIDE_URL, hrefLabel: 'Anleitung mit Bildern'},
    ...(invoiceQueue ? [{ort: 't2med', info: true, label: 'Rechnungen in T2med auf den Drucker „vKasse“ drucken (richtet der Agent am PC ein)', card: 'invoice-printer-help'}] : []),
  ];
  const list = $('checklist'); list.replaceChildren();
  const required = items.filter(i => !i.optional && !i.info);
  const open = required.filter(i => !i.done).length;
  const bar = el('progress', null, 'progress', {max: String(required.length || 1), value: String(required.length - open)});
  list.append(el('p', (required.length - open) + ' von ' + required.length + ' nötigen Schritten erledigt (Hinweise für PC und T2med und optionale Punkte zählen nicht mit)', 'muted'), bar);
  list.append(el('p', 'In dieser Reihenfolge abarbeiten. Das Schild zeigt, wo der Schritt erledigt wird: ' + Object.values(PLACES).join(', ') + '.', 'muted'));
  const ol = el('ol', null, 'checklist numbered');
  for (const item of items) {
    const li = el('li', null, item.info ? 'info' : item.done ? 'done' : item.error ? 'open error' : item.optional ? 'optional' : 'open');
    li.append(el('span', item.info ? 'i' : item.done ? '✓' : item.error ? '!' : '○', 'mark-icon'));
    const text = el('span'); text.append(el('a', item.label, null, {href: '#' + item.card}));
    if (item.href) text.append(' · ', el('a', item.hrefLabel || 'Anleitung', null, {href: item.href, target: '_blank', rel: 'noopener'}));
    text.append(' ', el('span', PLACES[item.ort], 'tag'));
    if (item.optional) text.append(' ', el('span', 'optional', 'tag opt'));
    if (item.detail) { text.append(el('br'), el('small', item.detail, 'mono')); }
    if (item.note) text.append(el('br'), el('small', item.note, 'muted'));
    li.append(text);
    ol.append(li);
  }
  list.append(ol);
  lastOpenCards = required.filter(i => !i.done).map(i => i.card);
  lastItems = items;
  badge('einrichtung', open);
  const fold = $('checklist-fold'), tag = $('checklist-tag');
  if (tag) { tag.textContent = open ? open + ' offen' : 'fertig'; tag.className = 'tag ' + (open ? 'warn' : 'ok'); }
  if (fold && !fold.dataset.touched) { fold.open = open > 0; fold.dataset.auto = String(open > 0); fold.ontoggle = () => { if (String(fold.open) !== fold.dataset.auto) fold.dataset.touched = '1'; }; }
  return open;
}
async function busy(button, action) {
  button.disabled = true;
  try { await action(); } catch (error) { message(error.message, true); } finally { button.disabled = false; }
}
async function loadSettings() {
  const data = await api('get');
  if (document.body.dataset.seite === 'igel') { show('settings'); loadCatalog(); return; }
  if (data.role === 'staff') { staffMode(data); return; }
  document.body.classList.remove('staff');
  render(data); show('settings'); loadInbox();
}

// Mitarbeiter-Zugang: nur die freigegebenen Bereiche, keine Einstellungen oder Schlüssel.
function staffMode(data) {
  csrf = csrf || ''; document.body.classList.add('staff');
  $('mode').hidden = false; $('mode').textContent = data.mode === 'live' ? (data.fhir_mode === 'live' ? 'LIVE' : 'LIVE · T2MED TESTMODUS') : 'TESTMODUS'; $('mode').classList.toggle('test', /TESTMODUS/.test($('mode').textContent)); $('mode').classList.toggle('live', data.mode === 'live');
  $('tabs').hidden = true; $('sections').replaceChildren(); $('warning').hidden = true;
  const panel = $('tab-panels'); panel.replaceChildren(); panel.hidden = false;
  const rights = data.rights || [], can = r => rights.includes(r);
  const take = id => { const card = $(id); if (card) { card.hidden = false; panel.append(card); } };
  if (can('inbox') || can('letter')) { take('s-inbox-list'); loadInbox(); }
  if (can('catalog')) { take('s-catalog'); loadCatalog(); }
  if (!panel.children.length && can('catalog')) { location.replace(BASE + 'igel'); return; }
  if (!panel.children.length) panel.append(el('p', 'Für diesen Zugang sind keine Bereiche freigegeben. Bitte beim Administrator melden.', 'muted'));
  show('settings');
}
let previewTimer = null, previewSeq = 0;
// Druckeradresse (http://Name:631/printers/X) zusätzlich mit den IP-Adressen des Servers, z. B. für Zugriffe aus dem VPN.
function ippByIp(url, ips) {
  const path = String(url).replace(/^http:\/\/[^/]+/, '');
  return (ips || []).map(i => ({name: i.name, ip: i.ip, url: 'http://' + i.ip + ':631' + path}));
}
function ippPowerShell(queue, url, ips, paper) {
  const byIp = ippByIp(url, ips), use = byIp.length ? byIp[0].url : url;
  return 'Enable-WindowsOptionalFeature -Online -FeatureName Printing-Foundation-InternetPrinting-Client -NoRestart | Out-Null\n# Druckspooler neu starten und auf den laufenden Dienst warten; alten Drucker entfernen (behebt „Das Handle ist ungültig“)\nRestart-Service Spooler -Force\n(Get-Service Spooler).WaitForStatus(\'Running\', \'00:00:30\')\nStart-Sleep 3\nGet-Printer -Name "' + queue + '" -ErrorAction SilentlyContinue | Remove-Printer\nAdd-PrinterDriver -Name "Microsoft IPP Class Driver"\n$url = "' + use + '"\n# Anschluss und Drucker anlegen, bis zu viermal (direkt nach dem Neustart des Spoolers ist der Anschluss manchmal noch nicht nutzbar)\nfor ($i = 1; $i -le 4 -and -not (Get-Printer -Name "' + queue + '" -ErrorAction SilentlyContinue); $i++) {\n  Add-PrinterPort -Name $url -ErrorAction SilentlyContinue\n  Start-Sleep 3\n  Add-Printer -Name "' + queue + '" -DriverName "Microsoft IPP Class Driver" -PortName $url -ErrorAction SilentlyContinue\n}\nGet-Printer -Name "' + queue + '" | Select-Object Name, DriverName, PortName';
}
function ippIpNote(url, ips) {
  const byIp = ippByIp(url, ips);
  return byIp.length ? 'Per IP-Adresse (z. B. aus dem VPN): ' + byIp.map(i => i.url + ' (' + i.name + ')').join(' oder ') + '. ' : '';
}

(async () => {
  try {
    const status = await api('status');
    csrf = status.csrf || '';
    await loadSettings();
  } catch (error) { message(error.message, true); }
})();

// Sicherheitsprotokoll: letzte Vorgänge der Einstellungsseite anzeigen.
if ($('audit-load')) $('audit-load').onclick = async () => {
  try {
    const result = await api('audit');
    const box = $('audit-list');
    box.textContent = result.lines.length ? result.lines.join('\n') : 'Noch keine Einträge.';
    box.hidden = false;
  } catch (error) { message(error.message, true); }
};

// ---- Leistungen und GOÄ-Positionen --------------------------------------------------------
let catalogData = null;
const eur = c => (c / 100).toLocaleString('de-DE', {style: 'currency', currency: 'EUR'});
const feeLabels = {standard: 'Ärztlich', technical: 'Technisch (A, E, O)', lab: 'Labor (M / Nr. 437)'};
async function loadCatalog() {
  try { renderCatalog(await api('catalog')); }
  catch (error) { $('catalog-box').replaceChildren(el('p', error.message, 'muted')); }
}
function catField(labelText, input, hint) {
  const f = el('div', null, 'field'); f.append(el('label', labelText), input); if (hint) f.append(el('small', hint, 'muted')); return f;
}
function renderCatalog(data) {
  catalogData = data;
  const box = $('catalog-box'); box.replaceChildren();
  const apply = (result) => { renderCatalog(result); message(result.message); };
  // Liste
  if (!data.services.length) box.append(el('p', 'Noch keine Leistungen angelegt.', 'muted'));
  else {
    const table = el('table', null, 'fw-table'), head = el('tr');
    for (const h of ['Bezeichnung', 'GOÄ', 'Faktor', 'Preis', 'MwSt.', 'Unterschrift', '']) head.append(el('th', h));
    table.append(head);
    for (const sv of data.services) {
      const tr = el('tr');
      const name = el('td', sv.label + (sv.on_request ? ' (auf Verlangen)' : ''));
      const factor = sv.goae_code && sv.factor ? sv.factor.replace('.', ',') + (sv.fee_type !== 'standard' ? ' · ' + (feeLabels[sv.fee_type] || '') : '') : '–';
      tr.append(name, el('td', sv.goae_code || '–'), el('td', factor), el('td', eur(sv.price_cents)), el('td', sv.vat ? 'ja' : '–'), el('td', sv.signature ? 'ja' : '–'));
      const actions = el('td'); const edit = el('button', 'Bearbeiten', 'secondary small-button', {type: 'button'});
      edit.onclick = () => fillCatalogForm(sv);
      const del = el('button', 'Löschen', 'secondary small-button', {type: 'button'});
      del.onclick = () => { if (!confirm('„' + sv.label + '“ aus der Auswahl löschen? Frühere Zahlungen bleiben erhalten.')) return; busy(del, async () => apply(await api('catalog_service_delete', {id: sv.id}))); };
      actions.append(edit, ' ', del); tr.append(actions); table.append(tr);
    }
    box.append(table);
  }
  // Formular
  box.append(el('h3', 'Leistung anlegen oder ändern', null, {id: 'cat-form-title'}));
  const form = el('form', null, 'settings-form', {id: 'cat-form'});
  const id = el('input', null, null, {type: 'hidden', id: 'cat-id'});
  const label = el('input', null, null, {type: 'text', id: 'cat-label', maxlength: '160', required: '', placeholder: 'z. B. Attest'});
  const price = el('input', null, null, {type: 'text', id: 'cat-price', inputmode: 'decimal', maxlength: '12', required: '', placeholder: '10,00'});
  const vat = el('input', null, null, {type: 'checkbox', id: 'cat-vat'});
  const vatRow = el('label', null, 'check'); vatRow.append(vat, document.createTextNode(' mit MwSt. (im Preis enthalten)'));
  const signature = el('input', null, null, {type: 'checkbox', id: 'cat-signature'});
  const signRow = el('label', null, 'check'); signRow.append(signature, document.createTextNode(' Unterschrift erforderlich (Knopf „Zur Unterschrift“ in der Kasse, Modul Signatur)'));
  if (data.tax.small_business) { vat.disabled = true; vatRow.append(el('small', ' – Kleinunternehmerregelung ist eingeschaltet, Rechnungen werden ohne MwSt. erstellt.', 'muted')); }
  const onRequest = el('input', null, null, {type: 'checkbox', id: 'cat-on-request'});
  const onRow = el('label', null, 'check'); onRow.append(onRequest, document.createTextNode(' Leistung auf Verlangen'));
  const onTip = tip({label: 'Leistung auf Verlangen', tip: 'Für Leistungen, die der Patient ausdrücklich wünscht und die medizinisch nicht notwendig sind. Nach § 1 Abs. 2 GOÄ dürfen solche Leistungen nur auf Verlangen berechnet werden und müssen in der Rechnung entsprechend gekennzeichnet sein. Die App vermerkt „Auf Verlangen“ bei der Position im Beleg und im Akteneintrag in T2med; Preis, Faktor und Steuer ändern sich dadurch nicht. Bei medizinisch notwendigen Leistungen nicht ankreuzen.'});
  const onWrap = el('div', null, 'tip-row'); onWrap.append(onRow); if (onTip) onWrap.append(onTip);
  // GOÄ
  const goae = el('details', null, 'catalog-details', {id: 'cat-goae'}); goae.append(el('summary', 'GOÄ-Angaben und Rechner (optional)'));
  const code = el('input', null, null, {type: 'text', id: 'cat-code', maxlength: '60', placeholder: 'Ziffer oder Stichwort, z. B. 250 oder Blutentnahme'});
  const base = el('input', null, null, {type: 'text', id: 'cat-base', inputmode: 'decimal', maxlength: '12', placeholder: 'z. B. 10,72'});
  const target = el('input', null, null, {type: 'text', id: 'cat-target', inputmode: 'decimal', maxlength: '12', placeholder: 'z. B. 25,00'});
  const factor = el('input', null, null, {type: 'text', id: 'cat-factor', inputmode: 'decimal', maxlength: '8', value: '2,3'});
  const type = el('select', null, null, {id: 'cat-type'});
  for (const [k, v] of Object.entries(data.fee_types)) type.append(el('option', (feeLabels[k] || k) + ' · Schwelle ' + commaFactor(v.threshold * 100) + ' / max. ' + commaFactor(v.max * 100), null, {value: k}));
  const reason = el('textarea', null, null, {id: 'cat-reason', maxlength: '800', rows: '2', placeholder: 'z. B. überdurchschnittlicher Zeitaufwand wegen …'});
  const result = el('p', null, 'goae-result', {role: 'status', hidden: ''});
  goae.append(catField('GOÄ-Ziffer', code), catField('Einfachsatz (1,0-fach) in Euro', base), catField('Gewünschter Endbetrag in Euro', target), result,
    catField('Steigerungsfaktor', factor), catField('Gebührenrahmen', type),
    catField('Feste Begründung bei Faktor über dem Schwellenwert (optional)', reason), el('p', 'Wird an der Kasse vorausgefüllt und kann dort pro Abrechnung geändert werden. Steht auf der Rechnung und im Beleg.', 'muted small'),
    el('p', 'Rechner: Einfachsatz und gewünschten Endbetrag eintragen, Faktor und Preis werden berechnet (Gebühr = Einfachsatz × Faktor, auf Cent gerundet; bis zu 4 Nachkommastellen). Ohne Einfachsatz ergänzen GOÄ und Faktor nur den Beleg, der Preis bleibt wie eingetragen. Oberhalb der Schwelle wird bei der Abrechnung eine Begründung erfasst. Ins Feld GOÄ-Ziffer lässt sich auch ein Stichwort eingeben; mit einer Ziffer aus dem amtlichen Gebührenverzeichnis werden Einfachsatz und Rahmen vorgeschlagen und die Gebühr genau nach § 5 GOÄ gerechnet (Punktzahl × Punktwert × Faktor).', 'muted small'));
  // GOÄ-Verzeichnis: Ziffer oder Stichwort → Vorschlag; „Übernehmen“ füllt Einfachsatz, Rahmen, Faktor (und leere Bezeichnung).
  const vorschlag = goaeHelfer(code, e => {
    base.value = (e.cent / 100).toFixed(2).replace('.', ','); type.value = e.rahmen; factor.value = goaeStartfaktor(e, data.fee_types);
    if (!label.value.trim()) label.value = goaeKurz(e.text, 160);
    goae.open = true; calc('factor');
  });
  code.addEventListener('input', () => { if (parseCents(base.value)) calc('factor'); });
  goaeVerzeichnis().catch(() => {});
  const show = (text, level) => { result.hidden = !text; result.textContent = text || ''; result.className = 'goae-result' + (level ? ' ' + level : ''); };
  const calc = source => {
    const b = parseCents(base.value), limits = data.fee_types[type.value];
    if (!b) { show(''); return; }
    const punkte = goaePunkte(code.value, b);
    let h, p;
    if (source === 'factor') {
      const f = String(factor.value).replace(',', '.');
      if (!/^\d(\.\d{1,4})?$/.test(f)) { show('Bitte einen Faktor wie 2,3 oder 2,332 eingeben (höchstens 4 Nachkommastellen).', 'warn'); return; }
      h = factorUnits(f); p = fee(b, h, punkte); target.value = (p / 100).toFixed(2).replace('.', ',');
    } else {
      const t = parseCents(target.value);
      if (!t) { show('Gewünschten Endbetrag eintragen, dann wird der Faktor berechnet.'); return; }
      [h, p] = bestFactor(b, t, punkte);
      if (h > limits.max * 100) { show('Über dem Höchstsatz (' + commaFactor(limits.max * 100) + '-fach): höchstens ' + eur(fee(b, limits.max * 100, punkte)) + ' möglich. Bitte anderen Endbetrag oder Gebührenrahmen wählen.', 'error'); return; }
      factor.value = commaFactor(h);
    }
    if (h > limits.max * 100) { show('Faktor über dem Höchstsatz (' + commaFactor(limits.max * 100) + ').', 'error'); return; }
    price.value = (p / 100).toFixed(2).replace('.', ',');
    const t2 = parseCents(target.value), parts = [(punkte ? 'Faktor ' + commaFactor(h) + ' × ' + punkte + ' Punkte × 5,82873 Cent' : 'Faktor ' + commaFactor(h) + ' × ' + eur(b)) + ' = ' + eur(p) + ' → als Preis übernommen.'];
    if (source !== 'factor' && t2 && p !== t2) parts.push('Genau ' + eur(t2) + ' ist auch mit vier Nachkommastellen beim Faktor nicht möglich.');
    else if (source !== 'factor' && h % 100 !== 0) parts.push('Der Faktor hat ' + (h % 10 === 0 ? '3' : '4') + ' Nachkommastellen, damit der Betrag genau ' + eur(p) + ' ergibt.');
    if (h > limits.threshold * 100) parts.push('Über dem Schwellenwert ' + commaFactor(limits.threshold * 100) + ': Bei der Abrechnung ist eine Begründung nötig.');
    show(parts.join(' '), h > limits.threshold * 100 ? 'warn' : 'ok');
  };
  for (const [input, source] of [[base, 'target'], [target, 'target'], [factor, 'factor']]) input.addEventListener('input', () => calc(source));
  type.addEventListener('change', () => calc('target'));
  const save = el('button', 'Leistung hinzufügen', 'primary', {type: 'submit', id: 'cat-save'});
  const reset = el('button', 'Abbrechen', 'secondary', {type: 'button', hidden: ''});
  const clear = () => { form.reset(); id.value = ''; factor.value = '2,3'; goae.open = false; show(''); vorschlag.hide(); save.textContent = 'Leistung hinzufügen'; reset.hidden = true; $('cat-form-title').textContent = 'Leistung anlegen oder ändern'; };
  reset.onclick = clear;
  const buttons = el('div', null, 'row-buttons'); buttons.append(save, reset);
  form.append(id, catField('Bezeichnung', label), catField('Preis in Euro', price), vatRow, signRow, goae, onWrap, buttons);
  form.onsubmit = e => { e.preventDefault(); busy(save, async () => { goaePruefen(code.value); apply(await api('catalog_service_save', {id: id.value, label: label.value, price: price.value,
    goae_code: code.value, fee_type: type.value, factor: factor.value, base: base.value, on_request: onRequest.checked, default_reason: reason.value, vat: vat.checked && !vat.disabled, signature: signature.checked})); }); };
  window.fillCatalogForm = sv => {
    id.value = sv.id; label.value = sv.label; price.value = (sv.price_cents / 100).toFixed(2).replace('.', ','); code.value = sv.goae_code || '';
    factor.value = (sv.factor || '2.3').replace('.', ','); type.value = sv.fee_type; onRequest.checked = !!sv.on_request; reason.value = sv.default_reason || ''; vat.checked = !!sv.vat; signature.checked = !!sv.signature;
    base.value = sv.base_cents ? (sv.base_cents / 100).toFixed(2).replace('.', ',') : ''; target.value = sv.base_cents ? price.value : '';
    goae.open = !!sv.goae_code; show(''); save.textContent = 'Änderung speichern'; reset.hidden = false;
    $('cat-form-title').textContent = 'Leistung ändern: ' + sv.label; label.focus();
  };
  box.append(form);
  // Kombinationen
  box.append(el('h3', 'Kombinationen'));
  box.append(el('p', 'Mehrere Einzelleistungen, die an der Kasse mit einem Klick gewählt werden (z. B. Laborprofil mit Blutentnahme).', 'muted small'));
  for (const g of data.groups) {
    const sum = data.services.filter(s => g.services.includes(s.id)).reduce((t, s) => t + s.price_cents, 0);
    const row = el('div', null, 'manage-row');
    row.append(el('span', g.label + ' · ' + eur(sum) + ' (' + data.services.filter(s => g.services.includes(s.id)).map(s => s.label).join(', ') + ')'));
    const edit = el('button', 'Bearbeiten', 'secondary small-button', {type: 'button'});
    const del = el('button', 'Löschen', 'secondary small-button', {type: 'button'});
    del.onclick = () => { if (!confirm('Kombination „' + g.label + '“ löschen? Die Einzelleistungen bleiben erhalten.')) return; busy(del, async () => apply(await api('catalog_group_delete', {id: g.id}))); };
    edit.onclick = () => { gid.value = g.id; glabel.value = g.label; members.querySelectorAll('input').forEach(c => { c.checked = g.services.includes(c.value); }); gsave.textContent = 'Kombination speichern'; greset.hidden = false; glabel.focus(); };
    row.append(edit, ' ', del); box.append(row);
  }
  const gform = el('form', null, 'settings-form');
  const gid = el('input', null, null, {type: 'hidden'});
  const glabel = el('input', null, null, {type: 'text', maxlength: '160', required: '', placeholder: 'z. B. Laborprofil mit Blutentnahme'});
  const members = el('div');
  for (const sv of data.services) { const l = el('label', null, 'check'); const c = el('input', null, null, {type: 'checkbox', value: sv.id}); l.append(c, document.createTextNode(' ' + sv.label + ' · ' + eur(sv.price_cents))); members.append(l); }
  const gsave = el('button', 'Kombination hinzufügen', 'primary', {type: 'submit'});
  const greset = el('button', 'Abbrechen', 'secondary', {type: 'button', hidden: ''});
  greset.onclick = () => { gform.reset(); gid.value = ''; gsave.textContent = 'Kombination hinzufügen'; greset.hidden = true; };
  const gbuttons = el('div', null, 'row-buttons'); gbuttons.append(gsave, greset);
  gform.append(gid, catField('Name der Kombination', glabel), members, gbuttons);
  gform.onsubmit = e => { e.preventDefault(); busy(gsave, async () => apply(await api('catalog_group_save', {id: gid.value, label: glabel.value, services: [...members.querySelectorAll('input:checked')].map(c => c.value)}))); };
  if (data.services.length) box.append(gform);
}

// ---- Rechnungseingang -------------------------------------------------------------------
const inboxState = {offen: 'Offen', bezahlt: 'Bezahlt', 'am Terminal': 'Am Terminal', ersetzt: 'Ersetzt', abgelaufen: 'Abgelaufen'};
function renderInbox(data) {
  const box = $('inbox-box');
  box.replaceChildren();
  if (!data.enabled) { box.append(el('p', '„T2med-Rechnungen übernehmen“ ist ausgeschaltet (Karte weiter unten).', 'muted')); return; }
  const euro = c => (c / 100).toLocaleString('de-DE', {style: 'currency', currency: 'EUR'});
  const link = el('a', 'Vorgänge öffnen', 'button secondary'); link.href = BASE + 'vorgaenge';
  box.append(el('p', 'Die Liste der eingegangenen, bezahlten und stornierten Rechnungen mit Excel-Export und Abhaken steht jetzt unter „Vorgänge“ (' + data.items.length + ' Rechnungen zuletzt im Eingang).', 'muted'), link);
  if (data.rejected.length) {
    box.append(el('h3', 'Abgelehnte Drucke'));
    box.append(el('p', 'Diese Rechnungen kamen an, wurden aber nicht übernommen. Die Zeilenübersicht zeigt die erkannten Beschriftungen (Namen und Daten sind unkenntlich gemacht). Fehlt eine Beschriftung, trage sie unter „T2med-Rechnungen übernehmen“ als eigenen Textschnipsel ein.', 'muted small'));
    for (const r of data.rejected) {
      const details = el('details', null, 'inbox-reject');
      details.append(el('summary', when(r.at) + ' – ' + r.reason + (r.source ? ' [' + r.source + ']' : '')));
      details.append(el('pre', r.lines.length ? r.lines.join('\n') : 'Keine Beschriftungszeilen erkannt.', 'audit-list'));
      if (r.pdf) {
        const fix = el('button', 'Von Hand nachbearbeiten', 'secondary', {type: 'button'});
        const panel = el('div', null, 'review');
        panel.hidden = true;
        fix.onclick = () => busy(fix, async () => { if (!panel.hidden) { panel.hidden = true; return; } await openReview(r.id, panel); panel.hidden = false; });
        details.append(fix, panel);
      } else {
        details.append(el('p', 'Die Rechnung liegt nicht mehr zur Nachbearbeitung vor (sie wird nach 7 Tagen gelöscht). Bitte in T2med erneut drucken.', 'muted small'));
      }
      box.append(details);
    }
    const clear = el('button', 'Liste leeren', 'secondary', {type: 'button'});
    clear.onclick = () => busy(clear, async () => { const r = await api('inbox_clear'); renderInbox(r); message(r.message); });
    box.append(clear);
  }
}
// Nicht erkannte Rechnung von Hand zuordnen: je Feld Bildausschnitte der Rechnung, Vorschläge und Eingabefelder.
async function openReview(id, panel) {
  const {review} = await api('inbox_review', {id});
  panel.replaceChildren();
  panel.append(el('p', 'Prüfe die Werte neben den Ausschnitten, korrigiere sie bei Bedarf und übernimm die Rechnung. Gelesen wurde per ' + review.source + '. Die PDF wird danach aus der Nachbearbeitung gelöscht.', 'muted small'));
  const chip = {gelesen: ['gelesen', 'review-ok'], unsicher: ['unsicher', 'review-warn'], fehlt: ['nicht gefunden', 'review-miss']};
  const inputs = {};
  for (const key of ['number', 'date', 'total', 'patient']) {
    const f = review.fields[key];
    const row = el('div', null, 'review-row');
    const left = el('div');
    const head = el('p', f.title + ' ', 'review-label');
    head.append(el('span', chip[f.status][0], 'review-chip ' + chip[f.status][1]));
    left.append(head);
    if (f.snippets.length) for (const src of f.snippets) left.append(el('img', null, 'review-snip', {src, alt: 'Ausschnitt der Rechnung: ' + f.title}));
    else left.append(el('p', 'Die Beschriftung wurde nicht gefunden. Bitte unten in der Seitenansicht nachsehen.', 'muted small'));
    const right = el('div');
    if (key === 'patient') {
      const [name = '', birth = ''] = (f.guess || '').split(' | ');
      inputs.name = el('input', null, null, {type: 'text', placeholder: 'Vorname Nachname', autocomplete: 'off', value: name.trim(), 'aria-label': 'Patientenname'});
      inputs.birth = el('input', null, null, {type: 'text', placeholder: 'TT.MM.JJJJ', autocomplete: 'off', value: birth.trim(), 'aria-label': 'Geburtsdatum'});
      right.append(inputs.name, inputs.birth);
    } else {
      inputs[key] = el('input', null, null, {type: 'text', autocomplete: 'off', value: f.guess || '', placeholder: {number: 'z. B. IG2026-309', date: 'TT.MM.JJJJ', total: 'z. B. 84,00'}[key], 'aria-label': f.title});
      right.append(inputs[key]);
      if (f.values.length > 1) {
        const picks = el('p', null, 'review-picks');
        for (const v of f.values) { const b = el('button', v, 'secondary review-pick', {type: 'button'}); b.onclick = () => { inputs[key].value = v; }; picks.append(b); }
        right.append(picks);
      }
    }
    row.append(left, right);
    panel.append(row);
  }
  const pages = el('details', null, 'review-pages');
  pages.append(el('summary', 'Ganze Rechnung ansehen (' + review.pages + ' Seite' + (review.pages > 1 ? 'n' : '') + ')'));
  let loaded = false;
  pages.addEventListener('toggle', async () => {
    if (!pages.open || loaded) return;
    loaded = true;
    for (let page = 1; page <= review.pages; page++) {
      try { const r = await api('inbox_review_page', {id, page}); pages.append(el('img', null, 'review-page', {src: r.image, alt: 'Seite ' + page + ' der Rechnung'})); }
      catch (error) { pages.append(el('p', error.message, 'muted small')); }
    }
  });
  const apply = el('button', 'Übernehmen und zuordnen', null, {type: 'button'});
  apply.onclick = () => busy(apply, async () => {
    const r = await api('inbox_review_apply', {id, number: inputs.number.value, date: inputs.date.value, total: inputs.total.value, name: inputs.name.value, birth: inputs.birth.value});
    renderInbox(r); message(r.message);
  });
  const drop = el('button', 'Verwerfen', 'secondary', {type: 'button'});
  drop.onclick = () => busy(drop, async () => { if (!confirm('Diese Rechnung aus der Nachbearbeitung entfernen? Die PDF wird gelöscht.')) return; const r = await api('inbox_review_drop', {id}); renderInbox(r); message(r.message); });
  panel.append(pages, el('div', null, 'review-actions'));
  panel.lastChild.append(drop, apply);
}
async function loadInbox() {
  try { renderInbox(await api('inbox_overview')); }
  catch (error) { $('inbox-box').replaceChildren(el('p', error.message, 'muted')); }
}
if ($('inbox-load')) $('inbox-load').onclick = loadInbox;
