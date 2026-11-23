'use strict';
const $ = id => document.getElementById(id);
// Webpfad des Moduls in der Kienzle-Suite (z. B. /kasse/). Anmeldung und Rechte kommen von der Suite.
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
  // Heikle Änderung: erst mit Passwort und Code bei der Suite bestätigen, danach kommt der Browser hierher zurück.
  if (response.status === 403 && result.bestaetigen) { location.href = result.bestaetigen; throw new Error(result.error); }
  if (response.status === 401) { location.href = '/anmelden?weiter=' + encodeURIComponent(location.pathname); throw new Error(result.error || 'Bitte anmelden.'); }
  if (!response.ok) throw new Error(result.error || 'Fehler ' + response.status);
  return result;
}
function message(text, error) {
  const m = $('message'); m.hidden = !text; m.textContent = text || ''; m.classList.toggle('error', !!error);
  if (text) window.scrollTo({top: 0, behavior: 'smooth'});
}
function show(view) { for (const id of ['settings']) if ($(id)) $(id).hidden = id !== view; }

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
  if (data.clients) renderClients(data.clients);
  if (data.export_years) renderExport(data.export_years, data.mode);
  if (data.export_years) t2medPaidSetup(data.export_years, data.mode);
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
      // Die T2med-Verbindung gehört der Suite: Stand zeigen und direkt zur Einrichtung verlinken.
      const ok = !!(data.connections && data.connections.t2med);
      const note = el('p', ok ? 'Die T2med-Verbindung der Suite ist eingerichtet. ' : 'Die T2med-Verbindung der Suite ist noch nicht eingerichtet. ', ok ? 'notice' : 'inline-error');
      note.append(el('a', ok ? 'Verbindung prüfen (Verwaltung → Verbindungen)' : 'Jetzt in der Suite einrichten (Verwaltung → Verbindungen)', null, {href: '/verwaltung/verbindungen'}));
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

// ---- Drucker „SumUp-Rechnung“ (wird beim Einschalten automatisch angelegt) ---------------
function renderInvoicePrinter(info) {
  $('invoice-printer-help').hidden = !info.enabled;
  const box = $('invoice-printer-status'); box.replaceChildren();
  if (!info.enabled) return;
  if (!info.queue) {
    const p = el('p', 'Der Drucker „SumUp-Rechnung“ fehlt in der Suite. Unter Verwaltung → Drucker „Virtuelle Drucker abgleichen“ wählen; legt die Suite ihn dann nicht an, das Modul unter Verwaltung → Module neu installieren.', 'inline-error');
    box.append(p); return;
  }
  if (!info.queue.ours) { box.append(el('p', 'Ein anderer Drucker heißt bereits „SumUp-Rechnung“. Bitte diesen umbenennen oder entfernen.', 'inline-error')); return; }
  box.append(el('p', 'Eingerichtet (' + info.queue.state + '). In T2med die Rechnung auf diesen Drucker drucken – sie erscheint dann beim Patienten zum Kassieren.', 'notice'));
  const ol = el('ol', null, 'steps');
  ol.append(el('li', 'Windows: Drucker hinzufügen → „Freigegebenen Drucker über den Namen auswählen“: ' + info.url + ' – Treiber „Microsoft IPP Class Driver“, Papier A4. ' + ippIpNote(info.url, info.ips)));
  const driver = el('li', 'Windows 11: Fehlt der Treiber „Microsoft IPP Class Driver“ oder akzeptiert Windows die Adresse nicht, PowerShell als Administrator öffnen und ausführen:');
  driver.append(el('pre', ippPowerShell('SumUp-Rechnung', info.url, info.ips, 'A4'), 'audit-list'));
  ol.append(driver);
  ol.append(el('li', 'Druckereigenschaften lassen sich nicht öffnen („Das Handle ist ungültig“) oder der Druck bleibt aus: Die Windows-Einrichtung ist veraltet, z. B. nach Neueinrichtung des Servers. Drucker entfernen und mit dem PowerShell-Skript oben neu einrichten (es startet den Druckspooler neu und entfernt den alten Drucker). Eine Meldung beim Löschen alter Anschlüsse („Anschluss unbekannt“) ist harmlos. Möglichst die IP-Adresse statt des Namens verwenden.'));
  const mac = el('li'); mac.append(document.createTextNode('Mac: „SumUp-Rechnung“ hinzufügen, als Treiber die Datei '), el('a', 'SumUp-Rechnung-PDF.ppd', null, {href: BASE + 'assets/SumUp-Rechnung-PDF.ppd', download: 'SumUp-Rechnung-PDF.ppd'}), document.createTextNode(' wählen.'));
  ol.append(mac); box.append(ol);
}

// ---- Reiter: laufende Arbeit, Praxisangaben, Drucker, einmalige Einrichtung ---------------
// Einmalige Einrichtung in einklappbaren Themenblöcken (alles Weitere steht in den Reitern daneben).
const GROUPS = [
  {id: 'praxis', label: 'Praxis & Rechnungen', cards: ['s-practice', 's-tax', 's-app', 's-mail', 's-letterxpress', 's-invoice_export']},
  {id: 'zahlung', label: 'Zahlung & T2med', cards: ['s-sumup', 's-fhir', 's-invoice_inbox', 'invoice-printer-help']},
  {id: 'drucker', label: 'Drucker', cards: ['s-printing', 's-office_printing']},
  {id: 'netz', label: 'Arbeitsplätze', cards: ['s-clients']},
  {id: 'sicherheit', label: 'Protokoll', cards: ['s-audit']},
];
const TABS = [
  {id: 'abrechnung', label: 'Abrechnung', hint: 'laufend', cards: ['s-inbox-list', 's-t2med-paid', 's-export']},
  {id: 'leistungen', label: 'Leistungen', hint: 'GOÄ', cards: ['s-catalog']},
  {id: 'einrichtung', label: 'Einrichtung', hint: 'einmalig', cards: ['s-checklist', ...GROUPS.flatMap(g => g.cards)], groups: GROUPS},
];
let lastOpenCards = [];
function openGroupOf(cardId) { $(cardId)?.closest('details.setup-group')?.setAttribute('open', ''); }
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
      if (!box) { box = el('details', null, 'setup-group', {id: 'group-' + group.id}); box.append(el('summary', group.label)); }
      panel.append(box);
      for (const id of group.cards) { const card = $(id); if (card) box.append(card); }
    }
  }
  const open = renderChecklist(data);
  if (!activeTab) {
    for (const id of lastOpenCards) openGroupOf(id);
    openGroupOf(location.hash.slice(1));
    const hash = location.hash.slice(1);
    activate(TABS.some(t => t.id === hash) ? hash : tabOf(hash) || (open ? 'einrichtung' : 'abrechnung'));
    if (tabOf(hash)) setTimeout(() => $(hash)?.scrollIntoView({block: 'start'}), 50);
  } else activate(activeTab);
}
function activate(id) {
  activeTab = id;
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
  const d = el('details', null, 'tz-how'); d.append(el('summary', 'In T2med einrichten: Button „Kartenzahlung“ in der Funktionsleiste'));
  const ol = el('ol');
  ol.append(el('li', 'In T2med in der Suche „Drittanbieter-Zugriffe verwalten“ öffnen (fehlt die Funktion, muss ein Admin sie für den Benutzer erst freischalten) und den Eintrag mit dem Stift bearbeiten.'));
  const li = el('li', 'Als Adresse eintragen (genau so, mit den Platzhaltern) und eine Bezeichnung vergeben, z. B. „Kartenzahlung“:'); li.append(el('br'), el('code', 'kienzle-sumup://?kontextId=${kontextId}&fhirBasisUrl=${fhirBasisUrl}&oAuthToken=${oAuthToken}', 'code-copy')); ol.append(li);
  ol.append(el('li', 'Auf Plus klicken, oben rechts mit dem Häkchen speichern und in der Liste davor auf „aktualisieren“ (Pfeile) klicken.'));
  ol.append(el('li', 'Dann „Funktionsleisten verwalten“: Konfiguration anlegen oder bearbeiten (Name, Benutzer, Benutzergruppe, Station), „Aktion hinzufügen“, die Aktion unter der vergebenen Bezeichnung suchen, Farbe und Kürzel oder Icon wählen und bestätigen.'));
  ol.append(el('li', 'Der Starter-Aufruf funktioniert erst, wenn auf dem Arbeitsplatz das Starter-Paket installiert ist (Karte „Arbeitsplätze einrichten“).'));
  ol.append(el('li', 'Die FHIR-Adresse, die T2med beim Aufruf mitgibt, muss in der Suite unter Verwaltung → Verbindungen → T2med bei „Erlaubte fhirBasisUrl aus T2med“ stehen (die Adresse des FHIR-Servers gilt immer als erlaubt). Bei einer Fehlermeldung zeigt die Seite die tatsächlichen Werte.'));
  const guide = el('li'); guide.append('Mit Bildern: ', el('a', 'Anleitung „T2med einrichten“', null, {href: GUIDE_URL, target: '_blank', rel: 'noopener'})); ol.append(guide);
  d.append(ol); return d;
}

/** Überblick der einmaligen Schritte in der Reihenfolge, in der sie nötig sind (wie die Einrichtung der Basis); liefert die Zahl offener Pflichtpunkte. */
const PLACES = {web: 'In diesem Webfenster', pc: 'Auf den Kassen-PCs', t2med: 'In T2med'};
function renderChecklist(data) {
  const invoiceQueue = !!(data.invoice_printer && data.invoice_printer.queue);
  const flag = key => !!(data.sections.find(s => s.id === key)?.fields.find(f => f.key === 'enabled') || {}).value;
  // Punkte der Kasse kommen vom Server (dieselben wie im Einrichtungs-Überblick der Suite), schon in der nötigen Reihenfolge.
  const own = (data.setup || []).map(i => ({ort: /Starter/.test(i.title) ? 'pc' : 'web', done: i.state === 'done', error: i.state === 'error', label: i.title, card: (i.link || '').split('#')[1] || 's-checklist', detail: i.hint}));
  const web = own.filter(i => i.ort === 'web'), pc = own.filter(i => i.ort === 'pc');
  const items = [
    ...web,
    {ort: 'web', done: flag('letterxpress'), optional: true, label: 'Rechnung per Post über Letterxpress versenden', card: 's-letterxpress'},
    {ort: 'web', done: flag('invoice_export'), optional: true, label: 'Rechnungen im Netzwerkspeicher ablegen (z. B. für die Steuerberatung)', card: 's-invoice_export'},
    {ort: 'pc', info: true, label: 'Zertifikat der Suite einrichten (einmal je PC, siehe Arbeitsplatz einrichten der Suite)', card: 's-clients'},
    ...pc,
    ...(invoiceQueue ? [{ort: 'pc', info: true, label: 'Drucker „SumUp-Rechnung“ am Arbeitsplatz installieren (Windows: IPP-Drucker hinzufügen, Papier A4)', card: 'invoice-printer-help', detail: (data.invoice_printer && data.invoice_printer.url) || ''}] : []),
    {ort: 't2med', info: true, label: 'Drittanbieter-Zugriff mit Funktionsleisten-Button „Kartenzahlung“ anlegen (Aufruf-Adresse steht bei der T2med-Anbindung)', card: 's-fhir', href: GUIDE_URL, hrefLabel: 'Anleitung mit Bildern'},
    ...(invoiceQueue ? [{ort: 't2med', info: true, label: 'Rechnungen in T2med auf den zuvor installierten Drucker „SumUp-Rechnung“ drucken', card: 'invoice-printer-help'}] : []),
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
    li.append(text);
    ol.append(li);
  }
  list.append(ol);
  lastOpenCards = required.filter(i => !i.done).map(i => i.card);
  badge('einrichtung', open);
  return open;
}
async function busy(button, action) {
  button.disabled = true;
  try { await action(); } catch (error) { message(error.message, true); } finally { button.disabled = false; }
}
async function loadSettings() {
  const data = await api('get');
  if (data.role === 'staff') { staffMode(data); return; }
  document.body.classList.remove('staff');
  render(data); show('settings'); loadInbox(); loadCatalog();
}

// Mitarbeiter-Zugang: nur die freigegebenen Bereiche, keine Einstellungen oder Schlüssel.
function staffMode(data) {
  csrf = csrf || ''; document.body.classList.add('staff');
  $('mode').hidden = false; $('mode').textContent = data.mode === 'live' ? (data.fhir_mode === 'live' ? 'LIVE' : 'LIVE · T2MED TESTMODUS') : 'TESTMODUS'; $('mode').classList.toggle('test', /TESTMODUS/.test($('mode').textContent)); $('mode').classList.toggle('live', data.mode === 'live');
  $('tabs').hidden = true; $('sections').replaceChildren(); $('warning').hidden = true;
  const panel = $('tab-panels'); panel.replaceChildren(); panel.hidden = false;
  const rights = data.rights || [], can = r => rights.includes(r);
  const take = id => { const card = $(id); if (card) { card.hidden = false; panel.append(card); } };
  if (can('paid')) { take('s-t2med-paid'); t2medPaidSetup(data.export_years || [], data.mode); }
  if (can('inbox') || can('letter')) { take('s-inbox-list'); loadInbox(); }
  if (can('catalog')) { take('s-catalog'); loadCatalog(); }
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

// ---- Export für die Steuerberatung ------------------------------------------------------
const MONTH_NAMES = ['Ganzes Jahr', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
function renderExport(years, mode) {
  const form = $('export-form'); if (form.dataset.ready) return; form.dataset.ready = '1';
  const row = el('div', null, 'export-row');
  const year = el('select', null, null, {name: 'year', 'aria-label': 'Jahr'}); for (const y of years) year.append(el('option', String(y), null, {value: String(y)}));
  const month = el('select', null, null, {name: 'month', 'aria-label': 'Zeitraum'}); MONTH_NAMES.forEach((n, i) => month.append(el('option', n, null, {value: String(i)})));
  const yearField = el('div', null, 'field'); yearField.append(el('label', 'Jahr'), year);
  const monthField = el('div', null, 'field'); const ml = el('div', null, 'label-row'); ml.append(el('label', 'Zeitraum'));
  const t = tip({label: 'Zeitraum', tip: 'Ganzes Jahr für den Jahresabschluss, ein Monat z. B. für die monatliche Umsatzsteuer-Voranmeldung. Maßgeblich ist das Zahlungsdatum.'}); if (t) ml.append(t);
  monthField.append(ml, month); row.append(yearField, monthField); form.append(row);
  const names = el('label', null, 'check'); const nameBox = el('input', null, null, {type: 'checkbox', name: 'names'}); names.append(nameBox, document.createTextNode(' Patientennamen aufnehmen'));
  const nl = el('div', null, 'label-row'); nl.append(names);
  const nt = tip({label: 'Patientennamen', tip: 'Nur ankreuzen, wenn die Steuerberatung die Namen braucht. Ohne Namen reichen Rechnungsnummer, Datum und Betrag für die Buchhaltung.'}); if (nt) nl.append(nt);
  form.append(nl);
  let testBox = null;
  if (mode !== 'live') { const test = el('label', null, 'check'); testBox = el('input', null, null, {type: 'checkbox', name: 'test'}); test.append(testBox, document.createTextNode(' Testzahlungen einbeziehen (nur zum Ausprobieren)')); form.append(test); }
  const now = new Date(); month.value = '0'; year.value = String(now.getFullYear());
  const buttons = el('div', null, 'row-buttons'); const link = el('a', 'Excel-Tabelle herunterladen', 'button primary', {download: ''}); buttons.append(link); form.append(buttons);
  const update = () => { link.href = BASE + 'tax-export.php?' + new URLSearchParams({year: year.value, month: month.value, names: nameBox.checked ? '1' : '0', test: testBox && testBox.checked ? '1' : '0'}); };
  form.onchange = update; update();
  link.onclick = () => message('Excel-Tabelle für ' + (month.value === '0' ? 'das Jahr ' + year.value : MONTH_NAMES[month.value] + ' ' + year.value) + ' wird geladen.');
}

// ---- Bezahlte T2med-Rechnungen abhaken -------------------------------------------------
let t2medFilter = {status: 'open', year: 0, month: 0, test: false};
function t2medPaidSetup(years, mode) {
  const box = $('t2med-paid'); if (box.dataset.ready) return; box.dataset.ready = '1';
  const bar = el('div', null, 'export-row t2med-bar');
  const status = el('select', null, null, {'aria-label': 'Anzeigen'}); status.append(el('option', 'Noch nicht abgehakt', null, {value: 'open'}), el('option', 'Alle', null, {value: 'all'}));
  const year = el('select', null, null, {'aria-label': 'Jahr'}); year.append(el('option', 'Alle Jahre', null, {value: '0'})); for (const y of years) year.append(el('option', String(y), null, {value: String(y)}));
  const month = el('select', null, null, {'aria-label': 'Monat'}); MONTH_NAMES.forEach((n, i) => month.append(el('option', i ? n : 'Alle Monate', null, {value: String(i)})));
  for (const [label, input] of [['Anzeigen', status], ['Jahr', year], ['Monat', month]]) { const f = el('div', null, 'field'); f.append(el('label', label), input); bar.append(f); }
  box.append(bar);
  let test = null;
  if (mode !== 'live') { const l = el('label', null, 'check'); test = el('input', null, null, {type: 'checkbox'}); l.append(test, document.createTextNode(' Testzahlungen anzeigen (nur zum Ausprobieren – nie in T2med abhaken)')); box.append(l); }
  const summary = el('p', null, 'muted small', {id: 't2med-summary'}); const table = el('div', null, 't2med-table', {id: 't2med-table'});
  const buttons = el('div', null, 'row-buttons');
  const done = el('button', 'Markierte als in T2med abgehakt', 'primary', {type: 'button'});
  const undo = el('button', 'Markierte wieder öffnen', 'secondary', {type: 'button'});
  const link = el('a', 'Liste als Excel herunterladen', 'button secondary', {download: ''});
  buttons.append(done, undo, link); box.append(summary, table, buttons);
  const read = () => { t2medFilter = {status: status.value, year: Number(year.value), month: year.value === '0' ? 0 : Number(month.value), test: !!(test && test.checked)}; month.disabled = year.value === '0';
    link.href = BASE + 'tax-export.php?' + new URLSearchParams({kind: 't2med', status: t2medFilter.status, year: t2medFilter.year, month: t2medFilter.month, test: t2medFilter.test ? '1' : '0'}); };
  const load = async () => { read(); try { renderT2medPaid(await api('t2med_paid', t2medFilter)); } catch (error) { summary.textContent = error.message; } };
  const mark = (flag, button) => busy(button, async () => {
    const ids = [...table.querySelectorAll('input[type=checkbox]:checked')].map(c => c.value);
    if (!ids.length) throw new Error('Bitte zuerst Rechnungen ankreuzen.');
    const result = await api('t2med_done', {ids, done: flag, filter: t2medFilter}); renderT2medPaid(result); message(result.message);
  });
  done.onclick = () => mark(true, done); undo.onclick = () => mark(false, undo);
  for (const input of [status, year, month, test].filter(Boolean)) input.onchange = load;
  load();
}
function renderT2medPaid(data) {
  const table = $('t2med-table'); table.replaceChildren();
  const euro = c => (c / 100).toLocaleString('de-DE', {style: 'currency', currency: 'EUR'});
  const date = d => d ? d.split('-').reverse().join('.') : '';
  const sum = data.rows.reduce((t, r) => t + r.amount_cents, 0);
  const voided = data.rows.filter(r => r.voided).length;
  badge('abrechnung', data.open_total);
  $('t2med-summary').textContent = data.rows.length ? (data.rows.length - voided) + ' Rechnungen, zusammen ' + euro(sum) + (voided ? ' (' + voided + ' storniert, nicht mitgezählt)' : '') + '. Noch nicht abgehakt insgesamt: ' + data.open_total + '.' : 'Keine Rechnungen für diese Auswahl.' + (data.open_total ? '' : ' Alles abgehakt.');
  if (!data.rows.length) return;
  const t = el('table'); const head = el('tr');
  const all = el('input', null, null, {type: 'checkbox', 'aria-label': 'Alle auswählen'}); const th = el('th'); th.append(all); head.append(th);
  for (const h of ['Re.-Nr.', 'Re.-Datum', 'Patient', 'Geb.', 'Betrag', 'Bezahlt', 'Abgehakt', '']) head.append(el('th', h));
  t.append(head);
  for (const r of data.rows) {
    const tr = el('tr', null, (r.done_date ? 'done ' : '') + (r.voided ? 'voided' : '')); const td = el('td'); const c = el('input', null, null, {type: 'checkbox', value: r.id, 'aria-label': 'Rechnung ' + r.number}); td.append(c); tr.append(td);
    const amount = r.voided ? 'storniert' : euro(r.amount_cents) + (r.corrected ? ' (bezahlt: ' + euro(r.original_cents) + ')' : '');
    for (const v of [r.number + (r.test ? ' (Test)' : ''), date(r.invoice_date), r.patient, date(r.birthdate), amount, date(r.paid_date) + ' ' + r.paid_time, date(r.done_date) || '–']) tr.append(el('td', v));
    const fix = el('button', 'Korrigieren', 'secondary small-button', {type: 'button'});
    fix.onclick = e => { e.stopPropagation(); correctionPanel(r, table, euro); };
    const last = el('td'); last.append(fix); tr.append(last);
    tr.onclick = e => { if (e.target === c || e.target === fix) return; c.checked = !c.checked; };
    t.append(tr);
  }
  all.onchange = () => t.querySelectorAll('td input').forEach(c => { c.checked = all.checked; });
  table.append(t);
}
// Nachträgliche Korrektur einer bezahlten Rechnung (nur Statistik und Excel; keine Erstattung bei SumUp).
function correctionPanel(r, table, euro) {
  const old = table.querySelector('.t2med-correct'); if (old) old.remove();
  const box = el('div', null, 't2med-correct');
  box.append(el('h3', 'Korrektur: Rechnung ' + r.number + (r.patient ? ' – ' + r.patient : '')));
  box.append(el('p', 'Per Karte bezahlt: ' + euro(r.original_cents) + '. Die Korrektur ändert nur die Statistik und die Excel-Liste. Eine Erstattung an den Patienten erfolgt nicht automatisch, sie muss im SumUp-Konto vorgenommen werden. Das Original bleibt gespeichert.', 'muted small'));
  const f1 = el('div', null, 'field'); f1.append(el('label', 'Richtiger Betrag in Euro'));
  const amount = el('input', null, null, {type: 'text', inputmode: 'decimal', value: ((r.voided ? r.original_cents : r.amount_cents) / 100).toFixed(2).replace('.', ',')}); f1.append(amount);
  const f2 = el('div', null, 'field'); f2.append(el('label', 'Notiz (optional, z. B. Grund)'));
  const note = el('input', null, null, {type: 'text', maxlength: '200', value: r.note || ''}); f2.append(note);
  const buttons = el('div', null, 'row-buttons');
  const send = (change, button, confirmText) => { if (confirmText && !confirm(confirmText)) return; busy(button, async () => {
    const result = await api('t2med_correct', {id: r.id, change, amount: amount.value, note: note.value, filter: t2medFilter}); renderT2medPaid(result); message(result.message); }); };
  const save = el('button', 'Betrag ändern', 'primary', {type: 'button'}); save.onclick = () => send('amount', save);
  const cancel = el('button', 'Zahlung stornieren', 'secondary', {type: 'button'}); cancel.onclick = () => send('void', cancel, 'Zahlung für Rechnung ' + r.number + ' stornieren? Sie zählt dann nicht mehr in der Statistik. Das Geld wird dadurch NICHT erstattet.');
  buttons.append(save, cancel);
  if (r.voided || r.corrected) { const undo = el('button', 'Korrektur zurücknehmen', 'secondary', {type: 'button'}); undo.onclick = () => send('restore', undo); buttons.append(undo); }
  const close = el('button', 'Schließen', 'secondary', {type: 'button'}); close.onclick = () => box.remove(); buttons.append(close);
  box.append(f1, f2, buttons);
  table.append(box); box.scrollIntoView({block: 'nearest'});
}

// ---- Arbeitsplätze ---------------------------------------------------------------------
const CLIENT_STEPS = {
  windows: ['ZIP-Datei mit Rechtsklick → „Alle extrahieren …“ entpacken.', '„install-windows.cmd“ doppelklicken. Bei „Windows hat den PC geschützt“: „Weitere Informationen“ → „Trotzdem ausführen“.',
    'Die Zertifikatswarnung mit „Ja“ bestätigen – das Fenster liegt manchmal hinter anderen Fenstern (Taskleiste prüfen).', 'Fertig, wenn „Kienzle-SumUp eingerichtet“ erscheint.'],
  macos: ['ZIP-Datei doppelklicken.', '„install-macos.command“ mit Rechtsklick → „Öffnen“ starten.', 'Mac-Passwort für das Zertifikat eingeben.'],
  linux: ['ZIP-Datei entpacken.', 'Im entpackten Ordner im Terminal: bash install-linux.sh', 'server.crt im Browser als vertrauenswürdig importieren.'],
};
function guessSystem() { const p = navigator.userAgent; return /Windows/.test(p) ? 'windows' : /Mac OS X|Macintosh/.test(p) ? 'macos' : /Linux|X11/.test(p) ? 'linux' : 'windows'; }
function renderClients(clients) {
  const box = $('client-downloads'); box.replaceChildren(); const steps = $('client-steps'); steps.replaceChildren();
  const mine = guessSystem();
  for (const c of clients || []) {
    const link = el('a', 'Paket für ' + c.label + ' laden', c.id === mine ? 'button primary' : 'button secondary', {href: BASE + 'client-download.php?os=' + c.id, download: ''});
    link.onclick = () => { renderSteps(c.id); message('Paket für ' + c.label + ' wird geladen. Anleitung steht unten und in LIESMICH.txt.'); };
    box.append(link);
  }
  const t = tip({label: 'Arbeitsplätze', tip: 'Jeder Arbeitsplatz, an dem aus T2med kassiert wird, braucht den Starter einmal. Danach öffnet der Knopf in T2med die Kartenzahlung.' }); if (t) box.append(t);
  renderSteps(mine);
  function renderSteps(id) {
    steps.replaceChildren(el('strong', 'So geht es unter ' + (clients.find(c => c.id === id) || {}).label + ':'));
    const ol = el('ol'); for (const s of CLIENT_STEPS[id]) ol.append(el('li', s)); steps.append(ol);
    const c = clients.find(x => x.id === id);
    if (c && !c.bundled) steps.append(el('p', 'Hinweis: Der Server hat die Starterprogramme nicht vorrätig; der Arbeitsplatz lädt sie bei der Einrichtung von GitHub (Internet nötig).', 'muted small'));
  }
}

(async () => {
  try {
    const status = await api('status');
    csrf = status.csrf || '';
    await loadSettings();
  } catch (error) { message(error.message, true); }
})();

// Sicherheitsprotokoll: letzte Vorgänge der Einstellungsseite anzeigen.
$('audit-load').onclick = async () => {
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
    for (const h of ['Bezeichnung', 'GOÄ', 'Faktor', 'Preis', 'MwSt.', '']) head.append(el('th', h));
    table.append(head);
    for (const sv of data.services) {
      const tr = el('tr');
      const name = el('td', sv.label + (sv.on_request ? ' (auf Verlangen)' : ''));
      const factor = sv.goae_code && sv.factor ? sv.factor.replace('.', ',') + (sv.fee_type !== 'standard' ? ' · ' + (feeLabels[sv.fee_type] || '') : '') : '–';
      tr.append(name, el('td', sv.goae_code || '–'), el('td', factor), el('td', eur(sv.price_cents)), el('td', sv.vat ? 'ja' : '–'));
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
  if (data.tax.small_business) { vat.disabled = true; vatRow.append(el('small', ' – Kleinunternehmerregelung ist eingeschaltet, Rechnungen werden ohne MwSt. erstellt.', 'muted')); }
  const onRequest = el('input', null, null, {type: 'checkbox', id: 'cat-on-request'});
  const onRow = el('label', null, 'check'); onRow.append(onRequest, document.createTextNode(' Leistung auf Verlangen'));
  const onTip = tip({label: 'Leistung auf Verlangen', tip: 'Für Leistungen, die der Patient ausdrücklich wünscht und die medizinisch nicht notwendig sind. Nach § 1 Abs. 2 GOÄ dürfen solche Leistungen nur auf Verlangen berechnet werden und müssen in der Rechnung entsprechend gekennzeichnet sein. Die App vermerkt „Auf Verlangen“ bei der Position im Beleg und im Akteneintrag in T2med; Preis, Faktor und Steuer ändern sich dadurch nicht. Bei medizinisch notwendigen Leistungen nicht ankreuzen.'});
  const onWrap = el('div', null, 'tip-row'); onWrap.append(onRow); if (onTip) onWrap.append(onTip);
  // GOÄ
  const goae = el('details', null, 'catalog-details', {id: 'cat-goae'}); goae.append(el('summary', 'GOÄ-Angaben und Rechner (optional)'));
  const code = el('input', null, null, {type: 'text', id: 'cat-code', maxlength: '20', placeholder: 'z. B. 250'});
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
    el('p', 'Rechner: Einfachsatz und gewünschten Endbetrag eintragen, Faktor und Preis werden berechnet (Gebühr = Einfachsatz × Faktor, auf Cent gerundet; bis zu 4 Nachkommastellen). Ohne Einfachsatz ergänzen GOÄ und Faktor nur den Beleg, der Preis bleibt wie eingetragen. Oberhalb der Schwelle wird bei der Abrechnung eine Begründung erfasst.', 'muted small'));
  const show = (text, level) => { result.hidden = !text; result.textContent = text || ''; result.className = 'goae-result' + (level ? ' ' + level : ''); };
  const calc = source => {
    const b = parseCents(base.value), limits = data.fee_types[type.value];
    if (!b) { show(''); return; }
    let h, p;
    if (source === 'factor') {
      const f = String(factor.value).replace(',', '.');
      if (!/^\d(\.\d{1,4})?$/.test(f)) { show('Bitte einen Faktor wie 2,3 oder 2,332 eingeben (höchstens 4 Nachkommastellen).', 'warn'); return; }
      h = factorUnits(f); p = fee(b, h); target.value = (p / 100).toFixed(2).replace('.', ',');
    } else {
      const t = parseCents(target.value);
      if (!t) { show('Gewünschten Endbetrag eintragen, dann wird der Faktor berechnet.'); return; }
      [h, p] = bestFactor(b, t);
      if (h > limits.max * 100) { show('Über dem Höchstsatz (' + commaFactor(limits.max * 100) + '-fach): höchstens ' + eur(fee(b, limits.max * 100)) + ' möglich. Bitte anderen Endbetrag oder Gebührenrahmen wählen.', 'error'); return; }
      factor.value = commaFactor(h);
    }
    if (h > limits.max * 100) { show('Faktor über dem Höchstsatz (' + commaFactor(limits.max * 100) + ').', 'error'); return; }
    price.value = (p / 100).toFixed(2).replace('.', ',');
    const t2 = parseCents(target.value), parts = ['Faktor ' + commaFactor(h) + ' × ' + eur(b) + ' = ' + eur(p) + ' → als Preis übernommen.'];
    if (source !== 'factor' && t2 && p !== t2) parts.push('Genau ' + eur(t2) + ' ist auch mit vier Nachkommastellen beim Faktor nicht möglich.');
    else if (source !== 'factor' && h % 100 !== 0) parts.push('Der Faktor hat ' + (h % 10 === 0 ? '3' : '4') + ' Nachkommastellen, damit der Betrag genau ' + eur(p) + ' ergibt.');
    if (h > limits.threshold * 100) parts.push('Über dem Schwellenwert ' + commaFactor(limits.threshold * 100) + ': Bei der Abrechnung ist eine Begründung nötig.');
    show(parts.join(' '), h > limits.threshold * 100 ? 'warn' : 'ok');
  };
  for (const [input, source] of [[base, 'target'], [target, 'target'], [factor, 'factor']]) input.addEventListener('input', () => calc(source));
  type.addEventListener('change', () => calc('target'));
  const save = el('button', 'Leistung hinzufügen', 'primary', {type: 'submit', id: 'cat-save'});
  const reset = el('button', 'Abbrechen', 'secondary', {type: 'button', hidden: ''});
  const clear = () => { form.reset(); id.value = ''; factor.value = '2,3'; goae.open = false; show(''); save.textContent = 'Leistung hinzufügen'; reset.hidden = true; $('cat-form-title').textContent = 'Leistung anlegen oder ändern'; };
  reset.onclick = clear;
  const buttons = el('div', null, 'row-buttons'); buttons.append(save, reset);
  form.append(id, catField('Bezeichnung', label), catField('Preis in Euro', price), vatRow, goae, onWrap, buttons);
  form.onsubmit = e => { e.preventDefault(); busy(save, async () => apply(await api('catalog_service_save', {id: id.value, label: label.value, price: price.value,
    goae_code: code.value, fee_type: type.value, factor: factor.value, base: base.value, on_request: onRequest.checked, default_reason: reason.value, vat: vat.checked && !vat.disabled}))); };
  window.fillCatalogForm = sv => {
    id.value = sv.id; label.value = sv.label; price.value = (sv.price_cents / 100).toFixed(2).replace('.', ','); code.value = sv.goae_code || '';
    factor.value = (sv.factor || '2.3').replace('.', ','); type.value = sv.fee_type; onRequest.checked = !!sv.on_request; reason.value = sv.default_reason || ''; vat.checked = !!sv.vat;
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
  if (!data.items.length) box.append(el('p', 'Noch keine Rechnung eingegangen.', 'muted'));
  else {
    const table = el('table', null, 'fw-table'), head = el('tr');
    const selected = new Set();
    const bar = el('div', null, 'row-buttons');
    const countText = el('span', '', 'muted');
    const toggleAll = el('input', null, null, {type: 'checkbox', 'aria-label': 'Alle auswählbaren Rechnungen markieren'});
    const boxes = [];
    const refresh = () => {
      for (const b of bar.querySelectorAll('button')) b.disabled = selected.size === 0 || b.dataset.busy === '1';
      countText.textContent = selected.size ? selected.size + ' ausgewählt' : 'Nichts ausgewählt';
      toggleAll.checked = boxes.length > 0 && boxes.every(b => b.checked);
    };
    toggleAll.onchange = () => { for (const b of boxes) { b.checked = toggleAll.checked; b.onchange(); } };
    const selectedRows = () => data.items.filter(r => selected.has(r.id));
    const bulk = (label, action, ask, filter) => {
      const b = el('button', label, 'secondary', {type: 'button'});
      b.onclick = () => {
        const rows = selectedRows().filter(filter);
        if (!rows.length) { message('Für diese Aktion ist keine der markierten Rechnungen geeignet.'); return; }
        if (!confirm(ask(rows))) return;
        busy(b, async () => { const res = await api(action, {ids: rows.map(r => r.id)}); renderInbox(res); message(res.message); });
      };
      return b;
    };
    if (data.letter_enabled) bar.append(bulk('Auswahl per Post senden', 'inbox_letter_many',
      rows => rows.length + ' Rechnung(en) (' + euro(rows.reduce((a, r) => a + r.amount_cents, 0)) + ') ' + (data.letter_mode === 'live' ? 'jetzt per Post verschicken' : 'im TESTMODUS an Letterxpress übergeben (es wird nichts verschickt)') + '?\n\nDabei gehen die Rechnungen mit Patientendaten an Letterxpress. Bereits live gesendete Rechnungen werden nicht erneut gesendet.',
      r => r.postable && !(r.letter || {}).locked));
    bar.append(bulk('Auswahl löschen', 'inbox_delete_many', rows => rows.length + ' Rechnung(en) aus dem Eingang löschen?', r => r.deletable), countText);
    if (data.letter_enabled && data.letter_mode !== 'live') bar.append(el('span', 'TESTBETRIEB Post', 'badge test', {title: 'Letterxpress im Testbetrieb: es wird nichts gedruckt oder verschickt'}));
    box.append(bar);
    const first = el('th'); first.append(toggleAll); head.append(first);
    for (const h of ['Eingang', 'Nummer', 'Betrag', 'Patient', 'Geb.', 'Status', ...(data.letter_enabled ? ['Post'] : []), '']) head.append(el('th', h));
    table.append(head);
    for (const r of data.items) {
      const tr = el('tr');
      const pick = el('td'), check = el('input', null, null, {type: 'checkbox', 'aria-label': 'Rechnung ' + r.number + ' auswählen'});
      check.disabled = !(r.deletable || (data.letter_enabled && r.postable));
      check.checked = false;
      check.onchange = () => { if (check.checked) selected.add(r.id); else selected.delete(r.id); refresh(); };
      if (!check.disabled) boxes.push(check);
      pick.append(check); tr.append(pick);
      const born = r.birthdate ? r.birthdate.split('-').reverse().join('.') : '';
      for (const v of [when(r.received_at), r.number, euro(r.amount_cents), r.patient, born, inboxState[r.status] || r.status]) tr.append(el('td', v));
      if (data.letter_enabled) {
        const l = r.letter || {};
        const cell = el('td');
        if (l.status) {
          const text = l.status === 'unknown' ? 'Postversand unklar – bitte den Postausgang bei Letterxpress prüfen' : (l.mode === 'live' ? 'Per Post gesendet am ' : 'Testbrief an Letterxpress am ') + when(l.sent_at);
          const icon = el('span', l.status === 'unknown' ? '✉ ?' : '✉', 'post-glyph ' + (l.status === 'unknown' ? 'post-unknown' : l.mode === 'live' ? 'post-sent' : 'post-test'), {title: text, 'aria-label': text, role: 'img'});
          cell.append(icon);
        }
        tr.append(cell);
      }
      const last = el('td');
      if (data.letter_enabled && r.postable && !(r.letter || {}).locked) {
        const l = r.letter || {};
        const post = el('button', 'Per Post', 'secondary small-button', {type: 'button', 'aria-label': 'Rechnung ' + r.number + ' per Post senden'});
        post.onclick = () => {
          const live = data.letter_mode === 'live';
          const text = 'Rechnung ' + r.number + ' (' + r.patient + ', ' + euro(r.amount_cents) + ') ' + (live ? 'jetzt per Post über Letterxpress versenden?' : 'im TESTMODUS an Letterxpress übergeben (es wird nichts verschickt)?') + '\n\nDabei gehen die Rechnung mit Patientendaten an Letterxpress.' + (live ? ' Das kostet Porto und Druck.' : '');
          if (!confirm(text)) return;
          busy(post, async () => { const res = await api('inbox_letter', {id: r.id}); renderInbox(res); message(res.message); });
        };
        last.append(post, ' ');
      }
      if (r.deletable) {
        const del = el('button', 'Löschen', 'secondary small-button', {type: 'button', 'aria-label': 'Rechnung ' + r.number + ' löschen'});
        del.onclick = () => { if (!confirm('Rechnung ' + r.number + ' (' + r.patient + ', ' + euro(r.amount_cents) + ') aus dem Eingang löschen?')) return; busy(del, async () => { const res = await api('inbox_delete', {id: r.id}); renderInbox(res); message(res.message); }); };
        last.append(del);
      }
      tr.append(last); table.append(tr);
    }
    box.append(table);
    refresh();
  }
  if (data.rejected.length) {
    box.append(el('h3', 'Abgelehnte Drucke'));
    box.append(el('p', 'Diese Rechnungen kamen an, wurden aber nicht übernommen. Die Zeilenübersicht zeigt die erkannten Beschriftungen (Namen und Daten sind unkenntlich gemacht). Fehlt eine Beschriftung, trage sie unter „T2med-Rechnungen übernehmen“ als eigenen Textschnipsel ein.', 'muted small'));
    for (const r of data.rejected) {
      const details = el('details', null, 'inbox-reject');
      details.append(el('summary', when(r.at) + ' – ' + r.reason + (r.source ? ' [' + r.source + ']' : '')));
      details.append(el('pre', r.lines.length ? r.lines.join('\n') : 'Keine Beschriftungszeilen erkannt.', 'audit-list'));
      box.append(details);
    }
    const clear = el('button', 'Liste leeren', 'secondary', {type: 'button'});
    clear.onclick = () => busy(clear, async () => { const r = await api('inbox_clear'); renderInbox(r); message(r.message); });
    box.append(clear);
  }
}
async function loadInbox() {
  try { renderInbox(await api('inbox_overview')); }
  catch (error) { $('inbox-box').replaceChildren(el('p', error.message, 'muted')); }
}
$('inbox-load').onclick = loadInbox;
