'use strict';
const $ = id => document.getElementById(id);
// Webpfad des Moduls in der Kienzle-Suite (z. B. /kasse/) und Marke der Suite für schreibende Anfragen.
const BASE = document.body.dataset.base || '/';
const SUITE_CSRF = (document.querySelector('meta[name="kienzle-csrf"]') || {}).content || '';
const euro = cents => new Intl.NumberFormat('de-DE', {style:'currency', currency:'EUR'}).format(cents / 100);
let state = null, visitId = new URL(location.href).searchParams.get('v'), csrf = '', timer = null, busy = false;
let selected = new Set(), retrySelection = false, startRequest = null;
let selectedGroups = new Set(), reasons = {};
let readerTimer = null, readerChecking = false, readerMessage = '';
let receiptShare = null, receiptRecipient = null, receiptMailRequest = null;
let receiptPrintPayment = null; const quittungNote = {};
const exportTried = new Set();
const printTried = new Set();
let printTouched = false;
function message(text='', ok=false) { $('message').textContent = text; $('message').hidden = !text; $('message').classList.toggle('ok', !!ok); }
async function api(action, data={}) {
  const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 75000);
  try {
    const response = await fetch(BASE+'api.php', {method:'POST', credentials:'same-origin', cache:'no-store', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf,'X-Kienzle-Csrf':SUITE_CSRF}, body:JSON.stringify({action, visit_id:visitId, ...data}), signal:controller.signal});
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Anfrage fehlgeschlagen.');
    return result;
  } catch(error) {
    if (error.name === 'AbortError' || error instanceof TypeError) throw new Error('Verbindung unterbrochen. Bitte erneut versuchen oder Seite laden. Der bestehende Vorgang bleibt gespeichert.');
    throw error;
  } finally { clearTimeout(timeout); }
}
async function run(fn) {
  if (busy) return; busy = true; message();
  document.querySelectorAll('button').forEach(b => b.disabled = true);
  try { await fn(); } catch(e) { message(e.message); }
  finally { busy = false; document.querySelectorAll('button').forEach(b => b.disabled = false); if(state) renderPayment(); updateTotal(); }
}
function element(tag, text, className='') { const el = document.createElement(tag); el.textContent = text; if(className) el.className=className; return el; }
function manualCents() {
  const value = $('manual').value.trim(); if (!value) return 0;
  const match = /^(0|[1-9]\d{0,6})(?:[,.](\d{1,2}))?$/.exec(value);
  return match ? Number(match[1])*100 + Number((match[2] || '').padEnd(2,'0')) : NaN;
}
function payMethod() { return document.querySelector('input[name="pay-method"]:checked')?.value || 'card'; }
document.querySelectorAll('input[name="pay-method"]').forEach(r => r.addEventListener('change', () => { startRequest=null; updateTotal(); }));
// Zur Unterschrift: Leistungsliste samt Patient an das Modul Signatur übergeben (Fragment in der Adresse, geht nicht an den Server).
const SIGNATUR = document.body.dataset.signatur || '';
function signaturKnopf(sum) {
  const knopf = $('sign-send'); if (!knopf) return;
  // Nur anzeigen, wenn mindestens eine gewählte Leistung eine Unterschrift verlangt (Haken im Leistungskatalog).
  const ids = state ? selectedIds() : new Set();
  const noetig = !!state && state.services.some(s => ids.has(s.id) && s.signature);
  knopf.hidden = !SIGNATUR || !state || !state.patient || !noetig;
  const hinweis = $('sign-note'); if (hinweis && knopf.hidden) hinweis.hidden = true;
  knopf.disabled = !(sum > 0) || busy;
}
function signaturListe() {
  const ids = selectedIds(), liste = state.services.filter(s => ids.has(s.id)).map(s => ({code: s.goae_code || '', label: s.label, cent: s.price_cents}));
  const manual = manualCents();
  if (Number.isFinite(manual) && manual > 0) liste.push({code: '', label: 'Sonstiger Betrag', cent: manual});
  return liste;
}
async function signaturSenden() {
  const liste = signaturListe();
  if (!liste.length) return;
  // Kontaktdaten frisch aus T2med; ohne sie öffnet Signatur trotzdem, die Felder bleiben dann zum Ausfüllen leer.
  let kontakt = {};
  try { kontakt = await api('patient_kontakt'); } catch (e) { kontakt = {}; }
  const roh = JSON.stringify({patient: state.patient.name, geburtsdatum: kontakt.geburtsdatum || state.patient.birthdate || '', vorname: kontakt.vorname || '', nachname: kontakt.nachname || '', geschlecht: kontakt.geschlecht || '', telefon: kontakt.telefon || '', email: kontakt.email || '', adresse: kontakt.adresse || '', ref: visitId || '', leistungen: liste});
  const bytes = new TextEncoder().encode(roh); let bin = ''; bytes.forEach(b => { bin += String.fromCharCode(b); });
  const kodiert = btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  if (kodiert.length > 12000) { alert('Die Liste ist für die Übergabe zu lang. Bitte weniger Positionen wählen.'); return; }
  // T2med-Zugang dieses Besuchs für die Ablage des unterschriebenen PDFs vormerken (bleibt auch nach dem Kassieren, höchstens 4 Stunden).
  try { await api('signatur_offen'); } catch (e) { /* ohne T2med wird das PDF nur in Signatur abgelegt */ }
  window.open(SIGNATUR + '#uebergabe=' + kodiert, '_blank', 'noopener');
  const note = $('sign-note'); note.hidden = false; note.textContent = 'In Signatur geöffnet. Dort Dokument wählen und den Vorgang anlegen; danach hier wie gewohnt kassieren.' + (kontakt.hinweis ? ' ' + kontakt.hinweis : '');
}
$('sign-send').addEventListener('click', signaturSenden);
function updateTotal() {
  if (!state) return;
  const ids=selectedIds();
  const sum = state.services.filter(s => ids.has(s.id)).reduce((total,s) => total+s.price_cents, 0) + manualCents();
  $('total').textContent = Number.isFinite(sum) ? euro(sum) : '—';
  const method = payMethod(), cash = method === 'cash', transfer = method === 'transfer';
  $('pay-transfer-label').hidden = !state.bank_ready;
  const art = transfer ? 'Überweisung' : cash ? 'Bar' : 'Karte';
  $('pay').textContent = sum > 0 ? euro(sum) + ' · ' + art : art;
  $('pay').classList.toggle('cash', cash || transfer);
  signaturKnopf(sum);
  // Barzahlung braucht kein freies Terminal, aber Praxisdaten für die Rechnung.
  $('pay').disabled = busy || (!cash && !!state.reader_payment) || ((cash || transfer) && !state.local_receipts) || !(sum > 0 && sum <= state.max_amount_cents);
  $('pay-method-note').hidden = !((cash || transfer) && !state.local_receipts);
  $('pay-method-note').textContent = 'Für Bar und Überweisung bitte zuerst Praxisname und Anschrift unter Einstellungen eintragen.';
  document.querySelectorAll('.invoice-pay').forEach(b => b.disabled = busy || !!state.reader_payment);
  document.querySelectorAll('.invoice-go').forEach(b => b.disabled = busy || (!!state.reader_payment && !!document.querySelector('input[name^="inv-method-"][value="card"]:checked')));
}
let invoiceSeen = null, invoiceLater = new Set(), renderedInvoices = '';
const invoiceSignature = list => JSON.stringify((list || []).map(i => [i.id, i.number, i.amount_cents, i.printed_at, i.letter]));
function invoiceRequest(invoice, method) {
  const key = 'ks-invoice-'+visitId;
  // Schlüssel vor dem Netzaufruf speichern; bei verlorener Antwort denselben Vorgang prüfen.
  let request = null; try { request = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch {}
  if (!request || request.invoice_id !== invoice.id || (request.method || 'card') !== method) request = {invoice_id: invoice.id, method, request_id: crypto.randomUUID(), ...printChoice()};
  sessionStorage.setItem(key, JSON.stringify(request));
  return [key, request];
}
function renderInvoices() {
  renderedInvoices = invoiceSignature(state.invoices);
  const all = state.invoices || [], list = all.filter(i => !invoiceLater.has(i.id));
  const fresh = invoiceSeen === null ? new Set() : new Set(list.filter(i => !invoiceSeen.has(i.id)).map(i => i.id));
  invoiceSeen = new Set(all.map(i => i.id));
  $('invoices').replaceChildren(); $('invoices-section').hidden = !list.length;
  for (const invoice of list) {
    const box = element('div','','invoice'), line = element('div','','invoice-line');
    box.dataset.id = invoice.id;
    if (fresh.has(invoice.id)) box.classList.add('invoice-new');
    line.append(element('span','Rechnung '+invoice.number+' vom '+invoice.invoice_date.split('-').reverse().join('.')+(fresh.has(invoice.id)?' – neu':'')), element('strong',euro(invoice.amount_cents)));
    const pay = method => () => run(async() => {
      const [key, request] = invoiceRequest(invoice, method);
      try { state.payment = await api('start_invoice', request); }
      catch(error) { try { await load(); } catch {} throw error; }
      retrySelection = false; state.reader_payment = null; readerMessage = '';
      sessionStorage.removeItem(key); renderPayment();
    });
    const card = element('button',euro(invoice.amount_cents)+' · Karte','primary wide invoice-pay');
    card.onclick = pay('card');
    const cash = element('button','Bar','secondary wide invoice-cash');
    cash.onclick = () => { if (confirm(euro(invoice.amount_cents)+' bar erhalten? Die Zahlung wird als bezahlt erfasst.')) pay('cash')(); };
    const later = element('button','Überweisung','text-button invoice-later');
    later.onclick = () => run(async() => {
      // Ist der Rechnungsdruck eingeschaltet, kommt die Rechnung jetzt einmal auf den Praxisdrucker (für die Überweisung).
      const result = await api('invoice_later', {invoice_id: invoice.id}); if (result.message) message(result.message, true);
      invoiceLater.add(invoice.id); await load();
    });
    // Einheitliche Zahlungswahl wie in der freien Eingabe: Chips mit Symbolen + ein Knopf. Die Einzelknöpfe bleiben (versteckt) als Auslöser.
    for (const b of [card, cash, later]) b.hidden = true;
    const chips = element('div', '', 'pay-method'); chips.setAttribute('role', 'radiogroup'); chips.setAttribute('aria-label', 'Zahlungsart');
    const options = [['card', '💳 Karte (SumUp)'], ['cash', '💶 Bar'], ['later', '🏦 Überweisung']];
    const canPost = !!(state.letter?.enabled && invoice.letter?.postable && !invoice.letter?.locked);
    if (canPost) options.push(['post', '✉ Per Post']);
    const go = element('button', '', 'primary wide invoice-go');
    const labels = {card: euro(invoice.amount_cents) + ' · Karte', cash: euro(invoice.amount_cents) + ' · Bar', later: 'Überweisung', post: 'Per Post senden'};
    const radios = {};
    // Die Liste wird alle paar Sekunden neu aufgebaut: eine von Hand gewählte Zahlart bleibt erhalten, „Karte“ ist nur die Vorauswahl.
    const chosen = options.some(o => o[0] === chosenMethod.get(invoice.id)) ? chosenMethod.get(invoice.id) : 'card';
    for (const [value, text] of options) {
      const l = document.createElement('label'), r = document.createElement('input'); r.type = 'radio'; r.name = 'inv-method-' + invoice.id; r.value = value; r.checked = value === chosen;
      r.onchange = () => { chosenMethod.set(invoice.id, value); go.textContent = labels[value]; go.classList.toggle('cash', value !== 'card'); };
      radios[value] = r; l.append(r, element('span', text)); chips.append(l);
    }
    go.textContent = labels[chosen]; go.classList.toggle('cash', chosen !== 'card');
    go.onclick = () => {
      const value = Object.keys(radios).find(k => radios[k].checked);
      const target = {card, cash, later}[value] || box.querySelector('.invoice-letter');
      if (target) target.click();
    };
    box.append(line, chips, go, card, cash, later);
    if (invoice.printed_at) box.append(element('p', '🖨 Rechnung gedruckt am ' + new Date(invoice.printed_at * 1000).toLocaleString('de-DE', {dateStyle: 'short', timeStyle: 'short'}), 'muted invoice-letter-note'));
    if (state.letter?.enabled && invoice.letter?.postable) {
      const sent = invoice.letter || {};
      const when = sent.sent_at ? new Date(sent.sent_at * 1000).toLocaleString('de-DE', {dateStyle: 'short', timeStyle: 'short'}) : '';
      const stamp = sent.status === 'unknown' ? 'Postversand unklar – bitte den Postausgang bei Letterxpress prüfen.'
        : sent.status === 'sent' ? (sent.mode === 'live' ? 'Per Post gesendet am ' : 'Testbrief an Letterxpress am ') + when : '';
      if (stamp) {
        const icon = element('p', '', 'invoice-letter-note post-icon ' + (sent.status === 'unknown' ? 'post-unknown' : sent.mode === 'live' ? 'post-sent' : 'post-test'));
        icon.append(element('span', sent.status === 'unknown' ? '✉ ?' : '✉', 'post-glyph'), document.createTextNode(' ' + stamp));
        icon.title = stamp; box.append(icon);
      }
      if (!sent.locked) {
        const post = element('button', 'Per Post senden (Letterxpress)', 'text-button invoice-letter'); post.hidden = true;
        post.onclick = () => run(async() => {
          const live = state.letter.mode === 'live';
          const text = 'Rechnung ' + invoice.number + ' über ' + euro(invoice.amount_cents) + ' für ' + state.patient.name + ' ' + (live ? 'jetzt per Post über Letterxpress versenden?' : 'im TESTMODUS an Letterxpress übergeben (es wird nichts verschickt)?')
            + '\n\nDabei gehen die Rechnung mit Patientendaten an Letterxpress.' + (live ? ' Das kostet Porto und Druck. Ein zweiter Versand ist nicht möglich.' : '');
          if (!confirm(text)) return;
          const result = await api('invoice_letter', {invoice_id: invoice.id});
          message(result.message, true); await load();
        });
        box.append(post);
      }
    }
    $('invoices').append(box);
  }
  updateTotal();
}
const chosenMethod = new Map();   // Rechnungs-ID → von Hand gewählte Zahlart (übersteht das Neuaufbauen der Liste)
// Neue gedruckte Rechnungen erscheinen ohne Neuladen, solange kein Zahlungsvorgang angezeigt wird.
setInterval(async () => {
  if (busy || document.hidden || !$('payment').hidden || !state?.patient) return;
  // Nur neu zeichnen, wenn sich die Rechnungen wirklich geändert haben: sonst verliert eine gerade gewählte Zahlart (und der Fokus) ihren Platz.
  try { const s = await api('state'); if (invoiceSignature(s.invoices) === renderedInvoices) return; state.invoices = s.invoices; renderInvoices(); } catch {}
}, 10000);
function selectedIds() {
  const ids=new Set(selected);
  for(const group of state.groups) if(selectedGroups.has(group.id)) for(const id of group.services) ids.add(id);
  return ids;
}
function vatText(s) {
  if(!s.vat || !state.tax || state.tax.small_business) return '';
  return 'inkl. '+(state.tax.rate/100).toLocaleString('de-DE')+' % MwSt.';
}
function serviceDescription(s) {
  return [s.goae_code ? 'GOÄ '+s.goae_code+' · Faktor '+s.factor.replace('.',',') : '', vatText(s)].filter(Boolean).join(' · ');
}
// Für die Startseite: „in Arbeit“ (Auswahl, Betrag oder laufende Zahlung), damit sie nicht mitten im Vorgang auf eine neue Rechnung umschaltet.
function markDirty() { document.body.dataset.dirty = (selected.size || selectedGroups.size || $('manual').value.trim() || (typeof state !== 'undefined' && state && state.payment)) ? '1' : ''; }
$('manual').addEventListener('input', markDirty);
function renderBasket() {
  markDirty();
  const ids=selectedIds(); $('basket-lines').replaceChildren(); $('basket').hidden=!ids.size;
  for(const s of state.services.filter(s=>ids.has(s.id))) {
    const row=element('div','','basket-line'), line=element('div','','position');
    line.append(element('span',s.label),element('strong',euro(s.price_cents))); row.append(line);
    if(serviceDescription(s)) row.append(element('small',serviceDescription(s),'muted'));
    const needsReason=s.goae_code && Math.round(Number(s.factor)*10000)>state.fee_types[s.fee_type].threshold*100;
    if(needsReason) {
      const label=element('label','Begründung für diese Abrechnung (erforderlich)');
      if(reasons[s.id]===undefined && s.default_reason) reasons[s.id]=s.default_reason;
      const input=document.createElement('textarea'); input.maxLength=800; input.required=true; input.value=reasons[s.id] || '';
      input.oninput=()=>{reasons[s.id]=input.value;startRequest=null;}; label.append(input); row.append(label);
    }
    $('basket-lines').append(row);
  }
  updateTotal();
}
// Suche in der Leistungsliste: Ziffer oder Namensteile (alle Wörter müssen vorkommen); ausgewählte Positionen bleiben immer sichtbar.
function filterServices() {
  const terms = ($('service-search').value || '').toLowerCase().split(/\s+/).filter(Boolean);
  for (const row of document.querySelectorAll('#services > label, #groups > label')) {
    const text = row.dataset.search || '', checked = row.querySelector('input')?.checked;
    row.hidden = terms.length > 0 && !checked && !terms.every(t => text.includes(t));
  }
  // Gruppenüberschrift nur zeigen, wenn darunter noch etwas sichtbar ist.
  document.querySelectorAll('#services > .cat-head').forEach(head => {
    let next = head.nextElementSibling, any = false;
    while (next && !next.classList.contains('cat-head')) { if (!next.hidden) any = true; next = next.nextElementSibling; }
    head.hidden = !any;
  });
}
function renderServices() {
  $('services').replaceChildren(); $('manage-list').replaceChildren(); $('groups').replaceChildren(); $('group-list').replaceChildren(); $('group-members').replaceChildren();
  const active = new Set(state.services.map(s => s.id)); selected = new Set([...selected].filter(id => active.has(id)));
  const activeGroups = new Set(state.groups.map(g=>g.id)); selectedGroups=new Set([...selectedGroups].filter(id=>activeGroups.has(id)));
  $('groups-section').hidden=!state.groups.length;
  $('empty').hidden = state.services.length > 0;
  const hasCats = state.services.some(s => s.category);
  let lastCat = null;
  $('category-list').replaceChildren(...[...new Set(['Blutwerte', 'Sonographie', 'Bescheinigungen', 'Beratung', 'Untersuchungen', ...state.services.map(s => s.category).filter(Boolean)])].map(c => { const o = document.createElement('option'); o.value = c; return o; }));
  for (const service of state.services) {
    if (hasCats && (service.category || '') !== lastCat) { lastCat = service.category || ''; $('services').append(element('div', lastCat || 'Weitere Leistungen', 'cat-head')); }
    const label = element('label','','service'); const checkbox = document.createElement('input'); checkbox.type='checkbox'; checkbox.checked=selected.has(service.id);
    checkbox.addEventListener('change', () => { checkbox.checked ? selected.add(service.id) : selected.delete(service.id); startRequest=null; renderBasket(); });
    const name=element('span',service.label,'label'); if(serviceDescription(service)) name.append(element('small',serviceDescription(service),'muted'));
    label.dataset.search = [service.label, service.goae_code, serviceDescription(service)].join(' ').toLowerCase();
    label.append(checkbox, name, element('span',euro(service.price_cents),'price')); $('services').append(label);
    const row = element('div','','manage-row'); row.append(element('span', (service.category ? service.category+' · ' : '')+service.label+' · '+euro(service.price_cents)+(serviceDescription(service)?' · '+serviceDescription(service):'')));
    const edit = element('button','Bearbeiten','text-button'); edit.onclick=() => {
      $('service-id').value=service.id; $('service-label').value=service.label; $('service-category').value=service.category || ''; $('service-price').value=(service.price_cents/100).toFixed(2).replace('.',',');
      $('service-goae').value=service.goae_code; $('service-factor').value=(service.factor || '2.3').replace('.',','); $('service-fee-type').value=service.fee_type;
      $('service-on-request').checked=!!service.on_request; $('service-reason').value=service.default_reason || ''; $('goae-fields').open=!!service.goae_code; $('service-vat').checked=!!service.vat;
      $('service-base').value=service.base_cents?(service.base_cents/100).toFixed(2).replace('.',','):''; $('service-target').value=service.base_cents?$('service-price').value:''; goaeResult('');
      $('service-save').textContent='Änderung speichern'; $('edit-reset').hidden=false; $('service-label').focus();
    };
    const del = element('button','Löschen','text-button danger'); del.onclick=() => run(async() => { if(!confirm('„'+service.label+'“ aus der Auswahl löschen? Frühere Zahlungen bleiben erhalten.')) return; await api('service_delete',{id:service.id}); resetForm(); await load(); });
    row.append(edit,del); $('manage-list').append(row);
    const member=element('label','','service'), memberCheck=document.createElement('input'); memberCheck.type='checkbox'; memberCheck.value=service.id;
    member.append(memberCheck,element('span',service.label,'label'),element('span',euro(service.price_cents),'price')); $('group-members').append(member);
  }
  for(const group of state.groups) {
    const sum=state.services.filter(s=>group.services.includes(s.id)).reduce((total,s)=>total+s.price_cents,0);
    const label=element('label','','service'), checkbox=document.createElement('input'); checkbox.type='checkbox'; checkbox.checked=selectedGroups.has(group.id);
    checkbox.onchange=()=>{checkbox.checked?selectedGroups.add(group.id):selectedGroups.delete(group.id);startRequest=null;renderBasket();};
    const name=element('span',group.label,'label'); name.append(element('small',state.services.filter(s=>group.services.includes(s.id)).map(s=>s.label).join(', '),'muted'));
    label.dataset.search = [group.label, ...state.services.filter(s=>group.services.includes(s.id)).map(s=>s.label+' '+s.goae_code)].join(' ').toLowerCase();
    label.append(checkbox,name,element('span',euro(sum),'price')); $('groups').append(label);
    const row=element('div','','manage-row'); row.append(element('span',group.label+' · '+euro(sum)));
    const edit=element('button','Bearbeiten','text-button'); edit.onclick=()=>{
      $('group-id').value=group.id; $('group-label').value=group.label;
      $('group-members').querySelectorAll('input').forEach(el=>el.checked=group.services.includes(el.value));
      $('group-save').textContent='Kombination speichern'; $('group-reset').hidden=false; $('group-label').focus();
    };
    const del=element('button','Löschen','text-button danger'); del.onclick=()=>run(async()=>{
      if(!confirm('Kombination „'+group.label+'“ löschen? Die Einzelleistungen bleiben erhalten.')) return;
      await api('group_delete',{id:group.id}); resetGroup(); await load();
    });
    row.append(edit,del); $('group-list').append(row);
  }
  renderBasket();
  filterServices();
}
function renderVatOption() {
  const tax=state.tax||{small_business:false,rate:1900}, rate=(tax.rate/100).toLocaleString('de-DE');
  $('service-vat-label').textContent='mit '+rate+' % MwSt. (im Preis enthalten)';
  $('service-vat').disabled=tax.small_business; $('service-vat-note').hidden=!tax.small_business;
  $('service-vat-note').textContent='Kleinunternehmerregelung ist eingeschaltet: Rechnungen werden ohne MwSt. erstellt (Einstellungen → Umsatzsteuer).';
}
// ---- GOÄ-Rechner: Einfachsatz + Endbetrag → Faktor und Preis -------------------------
function goaeResult(text, level) { const r=$('goae-result'); r.hidden=!text; r.textContent=text||''; r.className='goae-result'+(level?' '+level:''); }
function goaeCalc(source) {
  const base=parseCents($('service-base').value), limits=state.fee_types[$('service-fee-type').value];
  if(!base) { goaeResult(''); return; }
  const punkte=goaePunkte($('service-goae').value, base);
  let h, price;
  if(source==='factor') {
    const f=String($('service-factor').value).replace(',','.'); if(!/^\d(\.\d{1,4})?$/.test(f)) { goaeResult('Bitte einen Faktor wie 2,3 oder 2,332 eingeben (höchstens 4 Nachkommastellen).','warn'); return; }
    h=factorUnits(f); price=fee(base,h,punkte); $('service-target').value=(price/100).toFixed(2).replace('.',',');
  } else {
    const target=parseCents($('service-target').value); if(!target) { goaeResult('Gewünschten Endbetrag eintragen, dann wird der Faktor berechnet.'); return; }
    [h,price]=bestFactor(base,target,punkte);
    if(h>limits.max*100) { goaeResult('Über dem Höchstsatz ('+commaFactor(limits.max*100)+'-fach): höchstens '+euro(fee(base,limits.max*100,punkte))+' möglich. Bitte anderen Endbetrag oder Gebührenrahmen wählen.','error'); return; }
    $('service-factor').value=commaFactor(h);
  }
  if(h>limits.max*100) { goaeResult('Faktor über dem Höchstsatz ('+commaFactor(limits.max*100)+').','error'); return; }
  $('service-price').value=(price/100).toFixed(2).replace('.',',');
  const target=parseCents($('service-target').value);
  const parts=[(punkte?'Faktor '+commaFactor(h)+' × '+punkte+' Punkte × 5,82873 Cent':'Faktor '+commaFactor(h)+' × '+euro(base))+' = '+euro(price)+' → als Preis übernommen.'];
  if(source!=='factor' && target && price!==target) parts.push('Genau '+euro(target)+' ist auch mit vier Nachkommastellen beim Faktor nicht möglich.');
  else if(source!=='factor' && h%100!==0) parts.push('Der Faktor hat '+(h%10===0?'3':'4')+' Nachkommastellen, damit der Betrag genau '+euro(price)+' ergibt.');
  if(h>limits.threshold*100) parts.push('Über dem Schwellenwert '+commaFactor(limits.threshold*100)+': Bei der Abrechnung ist eine Begründung nötig.');
  goaeResult(parts.join(' '), h>limits.threshold*100?'warn':'ok');
}
for(const [id,source] of [['service-base','target'],['service-target','target'],['service-factor','factor'],['service-fee-type','target']]) $(id).addEventListener('input',()=>goaeCalc(source));
$('service-fee-type').addEventListener('change',()=>goaeCalc('target'));
// GOÄ-Verzeichnis: Ziffer oder Stichwort im Ziffernfeld → Vorschlag; „Übernehmen“ füllt Einfachsatz, Rahmen, Faktor (und leere Bezeichnung).
const goaeVorschlag=goaeHelfer($('service-goae'), e=>{
  $('service-base').value=(e.cent/100).toFixed(2).replace('.',','); $('service-fee-type').value=e.rahmen; $('service-factor').value=goaeStartfaktor(e,state.fee_types);
  if(!$('service-label').value.trim()) $('service-label').value=goaeKurz(e.text,160);
  $('goae-fields').open=true; goaeCalc('factor');
});
$('service-goae').addEventListener('input',()=>{ if(parseCents($('service-base').value)) goaeCalc('factor'); });

function resetForm() { goaeResult(''); goaeVorschlag.hide(); $('service-form').reset(); $('service-id').value=''; $('goae-fields').open=false; $('service-save').textContent='Leistung hinzufügen'; $('edit-reset').hidden=true; }
function resetGroup() { $('group-form').reset(); $('group-id').value=''; $('group-save').textContent='Kombination hinzufügen'; $('group-reset').hidden=true; }
function manage(show) { if(show) goaeVerzeichnis().catch(()=>{}); $('manager').hidden=!show; $('manage-toggle').setAttribute('aria-expanded',String(show)); if(show) $('manager').scrollIntoView({behavior:'smooth',block:'nearest'}); }
function renderReader() {
  clearTimeout(readerTimer);
  const p=state.reader_payment;
  $('reader-busy').hidden=$('selection').hidden || (!p && !readerMessage);
  $('reader-title').textContent=p ? (p.payment_status==='cancel_requested' ? 'Abbruch wird geprüft' : 'Terminal belegt') : 'Terminal bereit';
  $('reader-description').textContent=p
    ? euro(p.amount_cents)+' · '+(p.error_message || readerMessage || 'Hier läuft noch ein anderer Vorgang. Du kannst ihn am Terminal abbrechen.')
    : readerMessage;
  $('reader-reference').textContent=p?.reference || ''; $('reader-reference').hidden=!p;
  for(const id of ['reader-cancel','reader-status']) { $(id).hidden=!p; $(id).disabled=busy || readerChecking; }
  $('reader-cancel').textContent=p?.payment_status==='cancel_requested' ? 'Abbruch erneut anfragen' : 'Anderen Vorgang abbrechen';
  if(p && !$('selection').hidden && !readerChecking) readerTimer=setTimeout(pollReader,3000);
}
async function refreshReader(action) {
  const id=state.reader_payment?.id; if(!id) return;
  const result=await api(action,{payment_id:id});
  if(state.reader_payment?.id!==id) return;
  state.reader_payment=result.reader_payment; readerMessage=result.message;
}
async function pollReader() {
  if(busy || readerChecking) { readerTimer=setTimeout(pollReader,3000); return; }
  readerChecking=true; renderReader();
  try { await refreshReader('reader_status'); }
  catch(e) { readerMessage=e.message; }
  finally { readerChecking=false; renderReader(); updateTotal(); }
}
function renderPayment() {
  const p = state.payment; const showing = p && !retrySelection;
  $('selection').hidden=!!showing; $('payment').hidden=!showing;
  $('receipt-data-note').hidden=!showing || p.payment_status!=='successful' || !p.local_receipt;
  renderReader();
  if (showing) manage(false);
  for (const id of ['document','receipt','receipt-mail','receipt-print','receipt-share','retry','discard','cancel','mock-controls','mock-fhir-label','transfer-box','confirm-transfer','switch-cash','switch-transfer','switch-later']) $(id).hidden=true;
  if(!showing || receiptPrintPayment!==p.id) { $('receipt-print-status').hidden=true; $('receipt-print').textContent='Beleg drucken'; }
  if(!showing || p.payment_status!=='successful' || receiptShare?.paymentId!==p.id) { $('receipt-share-panel').hidden=true; receiptShare=null; }
  if(!showing || p.payment_status!=='successful' || receiptRecipient?.paymentId!==p.id) {
    receiptRecipient=null; receiptMailRequest=null; $('receipt-email').value=''; mailStatus('');
  }
  clearTimeout(timer);
  if (!showing) return;
  // Bar bezahlte T2med-Rechnung: Quittung statt Rechnungsbeleg; kein SumUp-Link.
  const quittung = ['cash','transfer'].includes(p.method) && !p.local_receipt;
  const open=p.payment_status==='transfer_open';
  $('receipt').hidden=p.payment_status!=='successful' && !open;
  $('receipt').textContent=open ? 'Überweisungsdaten als PDF' : quittung ? 'Quittung als PDF' : p.local_receipt ? 'Rechnung / Leistungsbeleg als PDF' : 'Zahlungsbeleg / PDF';
  $('receipt-share').hidden=p.payment_status!=='successful' || quittung;
  $('receipt-print').hidden=(p.payment_status!=='successful' && !open) || !state.printing_enabled;
  if(open && !$('receipt-print').hidden) $('receipt-print').textContent='Überweisungsdaten drucken';
  if(quittung && !$('receipt-print').hidden) $('receipt-print').textContent='Quittung drucken';
  if(p.quittung_message) quittungNote[p.id]=p.quittung_message;
  if(quittung && quittungNote[p.id] && receiptPrintPayment!==p.id) { $('receipt-print-status').hidden=false; $('receipt-print-status').textContent='Quittung: '+quittungNote[p.id]; }
  if(p.payment_status==='successful') renderReceiptMail();
  renderInvoiceExport(p);
  renderInvoicePrint(p);
  $('payment-amount').textContent=euro(p.amount_cents); $('payment-reference').textContent=p.t2med_invoice ? 'T2med-Rechnung '+p.t2med_invoice : p.invoice_number ? 'Rechnungsnummer: '+p.invoice_number : p.reference;
  $('payment-error').textContent=p.error_message; $('payment-error').hidden=!p.error_message;
  let title, description, symbol='…';
  if(p.doc_status==='written') {
    title='Vorgang abgeschlossen'; symbol='✓';
    description=state.manual ? 'Die manuelle Zahlung ist abgeschlossen. Dieses Fenster kann geschlossen werden.' : state.fhir_mock ? 'Testdokumentation gespeichert. Es wurde nichts in t2med geschrieben. Dieses Fenster kann geschlossen werden.' : 'Die Zahlung wurde in t2med dokumentiert. Dieses Fenster kann geschlossen werden.';
  } else if(p.payment_status==='successful') {
    title=p.method==='cash' ? 'Barzahlung erfasst' : p.method==='transfer' ? 'Überweisung bestätigt' : 'Zahlung erfolgreich'; symbol='✓';
    description=state.manual ? 'Mit „Vorgang abschließen“ ist die manuelle Zahlung erledigt. In T2med wird nichts dokumentiert.' : 'Mit „Dokumentation in der Akte“ wird die Zahlung in t2med dokumentiert und der Vorgang abgeschlossen.';
    $('document').hidden=false;
    $('document').textContent=p.doc_status==='unknown' || p.doc_status==='writing' ? 'Dokumentation prüfen / erneut versuchen' : state.manual ? 'Vorgang abschließen' : 'Dokumentation in der Akte';
    $('mock-fhir-label').hidden=!state.mock;
    $('mock-fhir-fail').checked=state.mock_document_fail;
  } else if(p.payment_status==='transfer_open') {
    title='Überweisung offen'; symbol='🏦';
    description='Der Patient überweist mit den Kontodaten oder dem QR-Code. Sobald das Geld eingegangen ist, den Zahlungseingang bestätigen. Bis dahin kann auf eine andere Zahlart gewechselt werden.';
    renderTransfer(p); $('confirm-transfer').hidden=false; $('switch-cash').hidden=false; $('cancel').hidden=false; $('cancel').textContent='Überweisung abbrechen';
  } else if(['failed','cancelled'].includes(p.payment_status)) {
    title=p.payment_status==='failed'?'Zahlung nicht erfolgt':'Zahlung abgebrochen'; symbol='×'; description='Du kannst die Zahlung erneut starten oder die Zahlart wechseln.'; $('retry').hidden=false; $('discard').hidden=!p.discardable;
    $('switch-cash').hidden=false; $('switch-transfer').hidden=!state.bank_ready || !!p.t2med_invoice; $('switch-later').hidden=!p.t2med_invoice;
  } else {
    title=p.payment_status==='unknown' || p.payment_status==='starting'?'Zahlung wird geprüft':p.payment_status==='cancel_requested'?'Abbruch wird geprüft':'Zahlung läuft';
    description='Bitte den Hinweisen am Terminal folgen. Dieses Fenster bis zum Abschluss geöffnet lassen.';
    $('cancel').hidden=false; $('cancel').textContent='Zahlung abbrechen'; $('mock-controls').hidden=!state.mock;
    timer=setTimeout(poll,2200);
  }
  $('payment-title').textContent=title; $('payment-description').textContent=description; $('status-symbol').textContent=symbol;
}
function renderTransfer(p) {
  const t = p.transfer; $('transfer-box').hidden = !t; if (!t) return;
  // Das SVG stammt vom eigenen Server (Qr::svg) und enthält nur Formen.
  $('transfer-qr').innerHTML = t.qr_svg;
  const rows = [['Empfänger', t.holder], ['IBAN', t.iban], ['BIC', t.bic], ['Bank', t.bank], ['Betrag', euro(p.amount_cents)], ['Verwendungszweck', t.purpose]].filter(r => r[1]);
  $('transfer-data').replaceChildren(...rows.flatMap(([k, v]) => [element('dt', k), element('dd', v)]));
}
function printChoice() { return $('print-box').hidden ? {} : {auto_print: $('auto-print').checked}; }
function renderPrintBox() {
  const s = state.print;
  $('print-box').hidden = !s || !(s.office || s.receipt);
  if ($('print-box').hidden) return;
  if (!printTouched) $('auto-print').checked = s.auto_print;
  const parts = [];
  if (s.t2med_possible && (state.invoices || []).length) parts.push('T2med-Rechnung → ' + s.office_label + (s.t2med_auto ? ' (immer, auch bei „Später“)' : ''));
  if (s.app_possible) parts.push('Rechnung aus der Leistungsauswahl → ' + (s.app_target === 'office' ? s.office_label : s.receipt_label));
  if (!parts.length) parts.push(s.office ? 'T2med-Rechnungen → ' + s.office_label : 'Für App-Rechnungen Praxisdaten in den Einstellungen eintragen.');
  $('print-targets').textContent = parts.join(' · ');
  $('target-office').disabled = !s.office; $('target-receipt').disabled = !s.receipt;
  $('target-office-label').textContent = s.office ? s.office_label : 'Praxisdrucker (nicht eingerichtet)';
  $('target-receipt-label').textContent = s.receipt ? s.receipt_label : 'Bondrucker (nicht eingerichtet)';
}
$('auto-print').onchange = () => { printTouched = true; };
$('print-settings-toggle').onclick = () => {
  const open = $('print-settings').hidden; $('print-settings').hidden = !open; $('print-settings-toggle').setAttribute('aria-expanded', String(open));
  if (open) { $('print-default').checked = state.print.auto_print; $('target-' + state.print.app_target).checked = true; }
};
$('print-settings-save').onclick = () => run(async () => {
  const target = document.querySelector('input[name="print-target"]:checked')?.value || state.print.app_target;
  state.print = await api('print_settings', {auto_print: $('print-default').checked, app_target: target});
  printTouched = false; $('print-settings').hidden = true; renderPrintBox(); message('Druckeinstellungen gespeichert.');
});
function renderInvoicePrint(p) {
  const e = p.payment_status === 'successful' ? p.invoice_print : null;
  $('invoice-print-status').hidden = !e; $('invoice-print').hidden = !e || e.status === 'pending';
  if (!e) return;
  $('invoice-print').textContent = e.status === 'failed' ? 'Rechnung erneut drucken' : 'Rechnung nochmals drucken';
  $('invoice-print-status').textContent = e.status === 'done' ? 'Rechnung gedruckt: ' + e.info
    : e.status === 'simulated' ? 'Testmodus – Rechnung aufbereitet, nicht gedruckt (' + e.info + ').'
    : e.status === 'failed' ? 'Rechnung nicht gedruckt: ' + e.error : 'Rechnung wird gedruckt (' + e.label + ') …';
  // Einmal automatisch nach erfolgreicher Zahlung; der Server druckt dieselbe Rechnung nie doppelt.
  if (e.status === 'pending' && !printTried.has(p.id)) { printTried.add(p.id); printInvoice(p.id, false); }
}
async function printInvoice(id, again) {
  try { const result = await api('invoice_print', {payment_id: id, again}); if (state.payment?.id === id) { state.payment = result; renderPayment(); } }
  catch (error) { if (state.payment?.id === id) { $('invoice-print-status').textContent = 'Rechnung nicht gedruckt: ' + error.message; $('invoice-print').hidden = false; } }
}
$('invoice-print').onclick = () => run(() => printInvoice(state.payment.id, true));
function renderInvoiceExport(p) {
  const e = p.payment_status==='successful' ? p.invoice_export : null;
  $('invoice-export-status').hidden = !e; $('invoice-export').hidden = !e || e.status!=='failed';
  if(!e) return;
  $('invoice-export-status').textContent = e.status==='done' ? 'Rechnungs-PDF im Netzwerkordner abgelegt: '+e.path
    : e.status==='failed' ? 'Rechnungs-PDF nicht abgelegt: '+e.error : 'Rechnungs-PDF wird im Netzwerkordner abgelegt …';
  // Einmal automatisch nach erfolgreicher Zahlung; der Server legt dieselbe Rechnung nie doppelt ab.
  if(e.status==='pending' && !exportTried.has(p.id)) { exportTried.add(p.id); exportInvoice(p.id); }
}
async function exportInvoice(id) {
  try { const result = await api('invoice_export',{payment_id:id}); if(state.payment?.id===id) { state.payment = result; renderPayment(); } }
  catch(error) { if(state.payment?.id===id) { $('invoice-export-status').textContent='Rechnungs-PDF nicht abgelegt: '+error.message; $('invoice-export').hidden=false; } }
}
$('invoice-export').onclick = () => run(() => exportInvoice(state.payment.id));
async function load() {
  state=await api('state'); csrf=state.csrf;
  $('welcome').hidden=true; $('workspace').hidden=false;
  $('mode').hidden=!state.mock && !state.fhir_mock;
  $('mode').textContent=state.mock?'TESTMODUS':state.fhir_mock?'T2MED TESTMODUS':'';$('mode').classList.toggle('test',/TESTMODUS/.test($('mode').textContent));
  $('patient-name').textContent=state.patient.name; document.querySelector('.patient .eyebrow').textContent=state.manual ? 'OHNE PATIENTENAKTE' : 'AKTUELLER PATIENT';
  $('patient-link').hidden=!state.t2med;
  $('patient-birth').textContent=state.patient.birthdate ? 'Geboren am '+state.patient.birthdate.split('-').reverse().join('.') : '';
  $('free-patient').hidden=!state.manual; $('practice-hint').hidden=state.local_receipts;
  if(!$('service-date').value) $('service-date').value=state.today;
  $('service-date').max=state.today;
  renderVatOption(); renderServices(); renderInvoices(); renderPrintBox(); renderPayment(); markDirty();
  if (window.parent !== window) parent.postMessage('ks-refresh', location.origin);   // Startseite: Rechnungsliste aktualisieren
}
async function poll() {
  if(busy) { timer=setTimeout(poll,2200); return; }
  try { state.payment=await api('status',{payment_id:state.payment.id}); renderPayment(); }
  catch(e) { message(e.message); timer=setTimeout(poll,5000); }
}
$('manual').addEventListener('input',()=>{ startRequest=null; updateTotal(); });
$('service-date').oninput=()=>{startRequest=null;};
$('manage-toggle').onclick=()=>manage($('manager').hidden);
$('manage-close').onclick=()=>manage(false);
$('edit-reset').onclick=resetForm;
$('service-fee-type').onchange=()=>{
  const fee=state.fee_types[$('service-fee-type').value];
  if(Number($('service-factor').value.replace(',','.'))*100>fee.max) $('service-factor').value=String(fee.threshold/100).replace('.',',');
};
$('service-form').onsubmit=e=>{ e.preventDefault(); run(async()=>{ goaePruefen($('service-goae').value); await api('service_save',{id:$('service-id').value,label:$('service-label').value,category:$('service-category').value,price:$('service-price').value,goae_code:$('service-goae').value,factor:$('service-factor').value,fee_type:$('service-fee-type').value,on_request:$('service-on-request').checked,default_reason:$('service-reason').value,vat:$('service-vat').checked,base:$('service-goae').value?$('service-base').value:''}); resetForm(); await load(); }); };
$('group-reset').onclick=resetGroup;
$('group-form').onsubmit=e=>{e.preventDefault();run(async()=>{
  await api('group_save',{id:$('group-id').value,label:$('group-label').value,services:[...$('group-members').querySelectorAll('input:checked')].map(el=>el.value)});
  resetGroup(); await load();
});};
$('pay').onclick=()=>run(async()=>{
  if(!$('service-date').reportValidity()) return;
  for(const input of $('basket').querySelectorAll('textarea')) if(!input.reportValidity()) return;
  const ids=selectedIds();
  const data={services:[...selected].sort(),groups:[...selectedGroups].sort(),reasons:Object.fromEntries(Object.entries(reasons).filter(([id,value])=>ids.has(id) && value.trim())),service_date:$('service-date').value,manual_amount:$('manual').value.trim(),method:payMethod(),...(state.manual?{patient_name:$('free-name').value.trim(),patient_birthdate:$('free-birth').value}:{}),...printChoice()};
  // Schlüssel und Anfrage vor dem Netzaufruf speichern; bei verlorener Antwort wiederverwenden.
  if(!startRequest) startRequest={...data,request_id:crypto.randomUUID()};
  sessionStorage.setItem('ks-start-'+visitId,JSON.stringify(startRequest));
  try { state.payment=await api('start',startRequest); }
  catch(error) { try { await load(); } catch {} throw error; }
  retrySelection=false; state.reader_payment=null; readerMessage='';
  sessionStorage.removeItem('ks-start-'+visitId); startRequest=null;
  document.querySelector('input[name="pay-method"][value="card"]').checked=true; renderPayment();
});
$('discard').onclick=()=>run(async()=>{
  if(!confirm('Diesen Zahlungsversuch verwerfen? Es ist noch keine Rechnungsnummer vergeben; die Eingaben werden zurückgesetzt.')) return;
  await api('discard',{payment_id:state.payment.id});
  retrySelection=false; startRequest=null; selected.clear(); selectedGroups.clear(); reasons={}; $('manual').value='';
  try { sessionStorage.removeItem('ks-start-'+visitId); } catch {}
  document.querySelector('input[name="pay-method"][value="card"]').checked=true;
  await load();
});
$('retry').onclick=()=>{ retrySelection=true; startRequest=null; renderPayment(); updateTotal(); };
$('switch-cash').onclick=()=>run(async()=>{
  if(!confirm(euro(state.payment.amount_cents)+' bar erhalten? Die Zahlung wird als bezahlt erfasst.')) return;
  state.payment=await api('switch_method',{payment_id:state.payment.id,request_id:crypto.randomUUID(),method:'cash'}); retrySelection=false; renderPayment();
});
$('switch-transfer').onclick=()=>run(async()=>{ state.payment=await api('switch_method',{payment_id:state.payment.id,request_id:crypto.randomUUID(),method:'transfer'}); retrySelection=false; renderPayment(); });
$('switch-later').onclick=()=>run(async()=>{
  // T2med-Rechnung: Der Überweisungs-QR-Code steht auf der Rechnung. Wie „Patient zahlt später“ in der Rechnungsliste: Rechnung drucken (wenn eingeschaltet) und zurück zur Auswahl.
  const id=state.payment.invoice_id, invoice=(state.invoices||[]).find(i=>i.id===id);
  if(invoice && state.print?.t2med_auto && !invoice.printed_at) { const result=await api('invoice_later',{invoice_id:id}); if(result.message) message(result.message, true); }
  if(id) invoiceLater.add(id);
  retrySelection=true; startRequest=null; await load(); renderPayment(); updateTotal();
});
$('confirm-transfer').onclick=()=>run(async()=>{
  if(!confirm('Ist der Betrag von '+euro(state.payment.amount_cents)+' auf dem Konto eingegangen? Die Zahlung wird dann als bezahlt erfasst.')) return;
  state.payment=await api('confirm_transfer',{payment_id:state.payment.id}); renderPayment();
});
$('cancel').onclick=()=>run(async()=>{ state.payment=await api('cancel',{payment_id:state.payment.id}); });
$('reader-cancel').onclick=()=>run(()=>refreshReader('reader_cancel'));
$('reader-status').onclick=()=>run(()=>refreshReader('reader_status'));
$('receipt').onclick=()=>{
  if(busy || !['successful','transfer_open'].includes(state?.payment?.payment_status)) return;
  const query=new URLSearchParams({v:visitId,p:state.payment.id});
  window.open(BASE+'receipt.php?'+query, '_blank', 'noopener,noreferrer');
};
function mailStatus(text) { $('receipt-mail-status').textContent=text; $('receipt-mail-status').hidden=!text; }
function printStatus(text) { $('receipt-print-status').textContent=text; $('receipt-print-status').hidden=!text; }
$('receipt-print').onclick=()=>run(async()=>{
  if(!state.printing_enabled || !['successful','transfer_open'].includes(state.payment?.payment_status)) return;
  const key='ks-print-'+visitId+'-'+state.payment.id;
  // Auch nach Neuladen wird bei verlorener Antwort derselbe Auftrag geprüft.
  let requestId=sessionStorage.getItem(key);
  if(!requestId) { requestId=crypto.randomUUID(); sessionStorage.setItem(key,requestId); }
  receiptPrintPayment=state.payment.id;
  printStatus('Beleg wird für den Druck aufbereitet …');
  try {
    const result=await api('receipt_print',{payment_id:state.payment.id,request_id:requestId});
    printStatus(result.message); sessionStorage.removeItem(key);
    $('receipt-print').textContent=['submitted','simulated'].includes(result.status) ? 'Beleg erneut drucken' : 'Druck erneut versuchen';
  } catch(error) {
    printStatus('Druckübergabe nicht bestätigt. Ein weiterer Klick prüft denselben Druckauftrag.');
    $('receipt-print').textContent='Druckauftrag prüfen / erneut versuchen'; throw error;
  }
});
function renderReceiptMail() {
  const button=$('receipt-mail'); button.hidden=false;
  if(!state.mail_enabled) { button.textContent='Beleg mailen – E-Mail-Versand nicht eingerichtet'; button.disabled=true; return; }
  if(!receiptRecipient) {
    const recipient=receiptRecipient={paymentId:state.payment.id,email:'',loading:true,edited:false,sent:false};
    // Nur die Adresse lesen; der Versand startet ausschließlich mit dem Mail-Button.
    api('receipt_recipient',{payment_id:recipient.paymentId}).then(result=>{
      if(receiptRecipient!==recipient) return;
      recipient.loading=false;
      if(!recipient.edited) { recipient.email=result.email; $('receipt-email').value=result.email; }
      if(result.message) mailStatus(result.message);
      renderReceiptMail();
    }).catch(()=>{
      if(receiptRecipient!==recipient) return;
      recipient.loading=false; mailStatus('E-Mail-Adresse konnte nicht geladen werden. Bitte selbst eintragen.'); renderReceiptMail();
    });
  }
  button.disabled=busy || receiptRecipient.loading;
  button.textContent=receiptRecipient.loading ? 'E-Mail-Adresse wird geladen …' : receiptRecipient.email
    ? (receiptRecipient.sent ? 'Beleg erneut mailen an „' : 'Beleg mailen an „')+receiptRecipient.email+'“'
    : 'Beleg mailen – E-Mail-Adresse eingeben';
}
function showReceiptDetails() {
  receiptShare ??= {paymentId:state.payment.id,url:''};
  $('receipt-share-panel').hidden=false; $('receipt-share-details').hidden=false;
  $('receipt-pdf-download').href=BASE+'receipt.php?'+new URLSearchParams({v:visitId,p:state.payment.id,download:'1'});
  $('receipt-original-link').hidden=!receiptShare?.url;
  $('receipt-email-form').hidden=!state.mail_enabled;
  $('receipt-mail-unconfigured').hidden=state.mail_enabled;
}
$('receipt-mail').onclick=()=>{
  if(busy || !state.mail_enabled || !receiptRecipient || receiptRecipient.loading) return;
  if(!receiptRecipient.email) { showReceiptDetails(); $('receipt-email').focus(); return; }
  sendReceiptEmail();
};
$('receipt-share').onclick=()=>run(async()=>{
  const paymentId=state.payment.id;
  showReceiptDetails();
  const result=await api('receipt_share',{payment_id:paymentId});
  receiptShare={...result,paymentId};
  showReceiptDetails();
  $('receipt-share-message').textContent=result.message;
  $('receipt-link').value=result.url;
  if(result.url) $('receipt-open').href=result.url; else $('receipt-open').removeAttribute('href');
});
$('receipt-copy').onclick=()=>run(async()=>{
  if(!receiptShare?.url) return;
  try { await navigator.clipboard.writeText(receiptShare.url); $('receipt-share-message').textContent='Beleglink kopiert.'; }
  catch { $('receipt-link').focus(); $('receipt-link').select(); $('receipt-share-message').textContent='Bitte den markierten Link kopieren.'; }
});
$('receipt-email').oninput=()=>{
  if(!receiptRecipient) return;
  receiptRecipient.email=$('receipt-email').value.trim(); receiptRecipient.edited=true; receiptRecipient.sent=false;
  renderReceiptMail();
};
function sendReceiptEmail() {
  if(busy || !state.mail_enabled || receiptRecipient?.paymentId!==state.payment.id) return;
  const email=$('receipt-email').value.trim();
  if(!email || /[\r\n]/.test(email) || !$('receipt-email').checkValidity()) { showReceiptDetails(); $('receipt-email').reportValidity(); return; }
  run(async()=>{
    if(!receiptMailRequest || receiptMailRequest.email!==email) receiptMailRequest={request_id:crypto.randomUUID(),email,payment_id:state.payment.id};
    mailStatus('PDF wird erstellt und E-Mail versendet …');
    try {
      const result=await api('receipt_email',receiptMailRequest);
      mailStatus(result.message);
      receiptRecipient.sent=['sent','simulated'].includes(result.status) && receiptRecipient.email===email;
      receiptMailRequest=null;
    } catch(error) { mailStatus('Versand nicht bestätigt. Ein weiterer Klick prüft denselben Versandversuch.'); throw error; }
  });
}
$('receipt-email-form').onsubmit=e=>{ e.preventDefault(); sendReceiptEmail(); };
$('document').onclick=()=>run(async()=>{
  state.payment=await api('document',{payment_id:state.payment.id}); renderPayment();
  if(state.payment.doc_status==='written') { state.completed=true; setTimeout(()=>window.close(),900); }
});
$('mock-success').onclick=()=>run(async()=>{ await api('mock',{payment_id:state.payment.id,status:'successful'}); await load(); });
$('mock-fail').onclick=()=>run(async()=>{ await api('mock',{payment_id:state.payment.id,status:'failed'}); await load(); });
$('mock-fhir-fail').onchange=()=>run(async()=>{ await api('mock',{document_fail:$('mock-fhir-fail').checked}); await load(); });
$('manual-form').onsubmit=event=>{ event.preventDefault(); run(async()=>{
  const key='ks-manual'; let request_id=sessionStorage.getItem(key); if(!request_id) { request_id=crypto.randomUUID(); sessionStorage.setItem(key,request_id); }
  const result=await api('manual',{label:$('manual-label').value.trim(),amount:$('manual-amount').value.trim(),request_id});
  sessionStorage.removeItem(key); location.href=result.url; location.reload();
}); };
$('demo').onclick=()=>run(async()=>{ const result=await api('demo'); location.href=result.url; location.reload(); });
// Aus der Startseite: gewünschte Zahlungsart einer Rechnung gleich auslösen (die Abfragen bleiben wie bei Klick auf den Knopf).
$('service-search').addEventListener('input', filterServices);
function autoAction(invoiceId, action) {
  const box = document.querySelector('.invoice[data-id="'+invoiceId+'"]'); if (!box) { message('Diese Rechnung ist nicht mehr offen.'); return; }
  const sel = {card:'.invoice-pay', cash:'.invoice-cash', later:'.invoice-later', post:'.invoice-letter'}[action];
  const button = sel && box.querySelector(sel);
  if (button) button.click(); else if (action === 'post') message('Per Post senden ist für diese Rechnung nicht möglich (Letterxpress aus oder Anschrift fehlt).');
}
document.querySelectorAll('.work-tab').forEach(b => b.onclick = () => {
  document.body.dataset.tab = b.dataset.tab;
  document.querySelectorAll('.work-tab').forEach(x => x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
  if (b.dataset.tab === 'igel') $('service-search').focus();
  if (window.parent !== window) parent.postMessage('ks-refresh', location.origin);
});
async function init() {
  const query = new URL(location.href).searchParams, auto = {invoice: query.get('rechnung'), action: query.get('aktion')};
  if (query.get('neu') || query.get('rechnung') || query.get('eingebettet')) { $('welcome').hidden = true; document.querySelector('.top .brand').hidden = true; }   // eingebettet in der Startseite: gleich die Arbeitsfläche zeigen, nicht die Willkommenskarte
  // Start aus T2med: #launch=<code>. Musste sich der Benutzer erst an der Suite anmelden, hat die Basis den Code im Tab gemerkt.
  let hash=location.hash.slice(1);
  try { if(!hash.startsWith('launch=')) hash=(sessionStorage.getItem('kienzle-launch')||'').slice(1); sessionStorage.removeItem('kienzle-launch'); } catch { /* ohne sessionStorage */ }
  const fragment=new URLSearchParams(hash); const ticket=fragment.get('launch');
  if(ticket) {
    history.replaceState(null,'',location.pathname);
    const result=await api('exchange',{ticket}); visitId=result.visit_id; csrf=result.csrf;
    history.replaceState(null,'',BASE+'kassieren?'+(query.get('eingebettet')?'eingebettet=1&':'')+'v='+encodeURIComponent(visitId));
  }
  if(!ticket && !visitId && query.get('neu')) {   // Startseite: freie Eingabe ohne Patientenakte
    const opened=await api('manual_open'); const t=new URLSearchParams(opened.url.split('#')[1]||'').get('launch');
    const result=await api('exchange',{ticket:t}); visitId=result.visit_id; csrf=result.csrf;
    history.replaceState(null,'',BASE+'kassieren?v='+encodeURIComponent(visitId));
  }
  if(visitId) {
    const saved=sessionStorage.getItem('ks-start-'+visitId);
    if(saved) { try { startRequest=JSON.parse(saved); selected=new Set(startRequest.services); selectedGroups=new Set(startRequest.groups || []); reasons=startRequest.reasons || {}; $('service-date').value=startRequest.service_date || ''; $('manual').value=startRequest.manual_amount; if(startRequest.method==='cash') document.querySelector('input[name="pay-method"][value="cash"]').checked=true; } catch { sessionStorage.removeItem('ks-start-'+visitId); } }
    await load();
    if(auto.invoice && auto.action) autoAction(auto.invoice, auto.action);
  }
}
init().catch(e=>message(e.message));

// Hilfe-Symbole („i“): Antippen öffnet und schließt den Hinweis.
document.addEventListener('click', event => {
  const button = event.target.closest('.tip');
  document.querySelectorAll('.tip.open').forEach(t => { if (t !== button) t.classList.remove('open'); });
  if (button) { event.preventDefault(); button.classList.toggle('open'); }
});
document.addEventListener('keydown', event => { if (event.key === 'Escape') document.querySelectorAll('.tip.open').forEach(t => t.classList.remove('open')); });
