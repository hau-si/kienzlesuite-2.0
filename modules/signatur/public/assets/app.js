// Signatur: Aufklappbereiche per Adresse öffnen (#vorlagen) und Löschen bestätigen. Keine Inline-Skripte (CSP).
(function () {
  'use strict';
  function oeffneAusAdresse() {
    var id = (location.hash || '').replace(/^#/, '');
    if (!/^[a-z-]{1,30}$/.test(id)) return;
    var el = document.getElementById(id);
    if (el && el.tagName === 'DETAILS') { el.open = true; el.scrollIntoView(); }
  }
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-bestaetigen]') : null;
    if (b && !window.confirm(b.getAttribute('data-bestaetigen'))) e.preventDefault();
  });
  // Weitere Patientendaten (aus T2med oder der Kasse) in die Felder eintragen; das Aufklappfeld öffnet sich, sobald etwas drinsteht.
  function kontaktFuellen(d) {
    var geb = String(d.geburtsdatum || '');
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(geb);
    if (m) geb = m[3] + '.' + m[2] + '.' + m[1];
    var feldWert = function (id, w) { var f = document.getElementById(id); if (f && w) { f.value = w; return true; } return false; };
    feldWert('n-vorname', String(d.vorname || '').slice(0, 80)); feldWert('n-nachname', String(d.nachname || '').slice(0, 80));
    var g = String(d.geschlecht || ''), gf = document.getElementById('n-geschlecht');
    if (gf && (g === 'male' || g === 'female')) gf.value = g; else if (gf && g === 'other') gf.value = 'other';
    // Übernahme in T2med nur, wenn der Aufruf aus T2med kam (Patienten-ID vorhanden): Ausgangswerte merken, Häkchen anbieten.
    if (/^[A-Za-z0-9._-]{1,64}$/.test(String(d.patient_id || ''))) {
      feldWert('n-patient-id', d.patient_id); feldWert('n-telefon-alt', String(d.telefon || '').slice(0, 40)); feldWert('n-email-alt', String(d.email || '').slice(0, 254));
      var kb = document.getElementById('n-kontakt-box'); if (kb) kb.hidden = false;
    }
    var werte = { 'n-geburtsdatum': geb.slice(0, 20), 'n-telefon': String(d.telefon || '').slice(0, 40), 'n-email': String(d.email || '').slice(0, 254), 'n-adresse': String(d.adresse || '').slice(0, 200) };
    var etwas = false;
    Object.keys(werte).forEach(function (id) { var f = document.getElementById(id); if (f && werte[id]) { f.value = werte[id]; etwas = true; } });
    var box = document.getElementById('n-kontakt');
    if (box && etwas) box.open = true;
  }
  // Patient ist vorgewählt (aus T2med oder der Kasse): Kasten oben zeigen, Patientenfeld im Formular ausblenden.
  function patientZeigen(quelle, d) {
    var kasten = document.getElementById('n-gewaehlt'), feld = document.getElementById('n-patient-feld'), name = document.getElementById('n-patient');
    if (!kasten || !feld || !name || !name.value) return;
    document.getElementById('n-gewaehlt-quelle').textContent = quelle === 'kasse' ? 'PATIENT AUS DER KASSE' : 'PATIENT AUS T2MED';
    document.getElementById('n-gewaehlt-name').textContent = name.value;
    var geb = String(d.geburtsdatum || ''), m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(geb);
    document.getElementById('n-gewaehlt-info').textContent = geb ? 'Geboren am ' + (m ? m[3] + '.' + m[2] + '.' + m[1] : geb) : '';
    kasten.hidden = false; feld.hidden = true;
    kasten.scrollIntoView({ block: 'nearest' });
  }
  (function () {
    var weg = document.getElementById('n-gewaehlt-weg');
    if (!weg) return;
    weg.addEventListener('click', function () {
      var form = document.getElementById('n-patient').form;
      ['n-patient', 'n-kontext', 'n-patient-id', 'n-telefon-alt', 'n-email-alt', 'n-vorname', 'n-nachname', 'n-geburtsdatum', 'n-telefon', 'n-email', 'n-adresse', 'n-leistungen', 'n-quelle', 'n-quelle-ref'].forEach(function (id) { var f = document.getElementById(id); if (f) f.value = ''; });
      var g = document.getElementById('n-geschlecht'); if (g) g.value = '';
      ['n-leistung', 'n-betrag'].forEach(function (id) { var f = document.getElementById(id); if (f) { f.value = ''; f.readOnly = false; } });
      var l = document.getElementById('n-liste'); if (l) l.hidden = true;
      var kb = document.getElementById('n-kontakt-box'); if (kb) kb.hidden = true;
      Array.prototype.forEach.call(form.querySelectorAll('p.notice, p.error'), function (p) { p.remove(); });
      document.getElementById('n-gewaehlt').hidden = true; document.getElementById('n-patient-feld').hidden = false;
      document.getElementById('n-patient').focus();
    });
  })();
  // Platzhalter-Knöpfe in den Vorlagen: {{name}} an der Cursorstelle des Textfelds einfügen.
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-platzhalter]') : null;
    if (!b) return;
    var t = document.getElementById(b.getAttribute('data-ziel') || '');
    if (!t) return;
    var w = b.getAttribute('data-platzhalter'), von = t.selectionStart, bis = t.selectionEnd;
    if (typeof von !== 'number') { von = bis = t.value.length; }
    t.value = t.value.slice(0, von) + w + t.value.slice(bis);
    t.focus(); t.selectionStart = t.selectionEnd = von + w.length;
  });
  // Start aus T2med: #launch=<Code> einlösen (einmalig) und den Patienten in „Neuer Vorgang“ eintragen.
  (function () {
    var m = /^#launch=([0-9a-f]{64})$/.exec(location.hash || '');
    var feld = document.getElementById('n-patient');
    if (!m || !feld) return;
    history.replaceState(null, '', location.pathname + location.search);
    var csrf = document.querySelector('meta[name="kienzle-csrf"]');
    var daten = new URLSearchParams({ 'kienzle-csrf': csrf ? csrf.content : '', code: m[1] });
    fetch(location.pathname.replace(/\/$/, '') + '/start-einloesen', { method: 'POST', body: daten, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (a) {
        var hinweis = document.createElement('p');
        hinweis.className = a.ok ? 'notice' : 'error';
        if (a.ok) {
          feld.value = a.j.patient || '';
          var k = document.getElementById('n-kontext');
          if (k) k.value = a.j.kontext || '';
          kontaktFuellen(a.j);
          hinweis.textContent = 'Patient aus T2med übernommen: ' + (a.j.patient || '');
        } else {
          hinweis.textContent = a.j.error || 'Der Start aus T2med ist fehlgeschlagen.';
        }
        feld.form.insertBefore(hinweis, feld.form.firstChild);
        if (a.ok) patientZeigen('t2med', a.j);
      })
      .catch(function () { /* ohne Netz bleibt die Startseite wie gewohnt */ });
  })();
  // Übergabe aus der Kasse: #uebergabe=<base64url-JSON> mit Patient, Verweis und Leistungsliste in „Neuer Vorgang“ eintragen.
  // Das Fragment geht nie an den Server; geprüft wird die Liste beim Anlegen noch einmal auf dem Server.
  (function () {
    var m = /^#uebergabe=([A-Za-z0-9_-]{1,12000})$/.exec(location.hash || '');
    var feld = document.getElementById('n-patient');
    if (!m || !feld) return;
    history.replaceState(null, '', location.pathname + location.search);
    var hinweis = document.createElement('p');
    try {
      var b64 = m[1].replace(/-/g, '+').replace(/_/g, '/');
      var roh = atob(b64 + '==='.slice((b64.length + 3) % 4));
      var d = JSON.parse(new TextDecoder().decode(Uint8Array.from(roh, function (c) { return c.charCodeAt(0); })));
      var liste = Array.isArray(d.leistungen) ? d.leistungen.slice(0, 40).map(function (z) {
        return { code: String(z.code || '').slice(0, 12), label: String(z.label || '').slice(0, 120), cent: Math.max(0, Math.round(Number(z.cent) || 0)) };
      }).filter(function (z) { return z.label; }) : [];
      if (!liste.length) throw new Error('leer');
      feld.value = String(d.patient || '').slice(0, 80);
      kontaktFuellen(d);
      document.getElementById('n-leistungen').value = JSON.stringify(liste);
      document.getElementById('n-quelle').value = 'kasse';
      document.getElementById('n-quelle-ref').value = /^[A-Za-z0-9._-]{1,64}$/.test(String(d.ref || '')) ? String(d.ref) : '';
      var euro = function (c) { return (c / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'; };
      var ul = document.getElementById('n-liste-eintraege'), summe = 0;
      ul.textContent = '';
      liste.forEach(function (z) {
        var li = document.createElement('li');
        li.textContent = (z.code ? 'GOÄ ' + z.code + ': ' : '') + z.label + ' – ' + euro(z.cent);
        ul.appendChild(li); summe += z.cent;
      });
      document.getElementById('n-liste-summe').textContent = liste.length + ' Leistung(en), zusammen ' + euro(summe) + '. Summe und Leistungen gehen aus der Kasse in das Dokument.';
      document.getElementById('n-liste').hidden = false;
      var l = document.getElementById('n-leistung'), b = document.getElementById('n-betrag');
      if (l) { l.value = liste.map(function (z) { return z.label; }).join(', ').slice(0, 120); l.readOnly = true; }
      if (b) { b.value = (summe / 100).toFixed(2).replace('.', ','); b.readOnly = true; }
      hinweis.className = 'notice';
      hinweis.textContent = 'Aus der Kasse übernommen: ' + feld.value + ' mit ' + liste.length + ' Leistung(en). Bitte das Dokument wählen und den Vorgang anlegen.';
    } catch (e) {
      hinweis.className = 'error';
      hinweis.textContent = 'Die Übergabe aus der Kasse konnte nicht gelesen werden. Bitte in der Kasse erneut „Zur Unterschrift“ wählen.';
    }
    feld.form.insertBefore(hinweis, feld.form.firstChild);
    if (hinweis.className === 'notice') patientZeigen('kasse', d); else feld.form.scrollIntoView({ block: 'nearest' });
  })();
  // Vorgangsliste: Suche und Filter wirken sofort im Browser.
  var tabelle = document.getElementById('vg-tabelle');
  if (tabelle) {
    var suche = document.getElementById('vg-suche'), art = document.getElementById('vg-art'), stat = document.getElementById('vg-status');
    var zeilen = Array.prototype.slice.call(tabelle.tBodies[0].rows);
    var filtern = function () {
      var q = suche.value.trim().toLowerCase(), n = 0;
      zeilen.forEach(function (z) {
        var ok = (!q || (z.getAttribute('data-suche') || '').indexOf(q) !== -1) && (!art.value || z.getAttribute('data-art') === art.value) && (!stat.value || z.getAttribute('data-status') === stat.value);
        z.hidden = !ok;
        if (ok) n++;
      });
      document.getElementById('vg-zahl').textContent = n + ' von ' + zeilen.length + ' Vorgängen';
      document.getElementById('vg-leer').hidden = n !== 0;
    };
    [suche, art, stat].forEach(function (e) { e.addEventListener('input', filtern); e.addEventListener('change', filtern); });
    filtern();
  }
  // Solange ein Vorgang am Pad unterschrieben wird, die Liste alle 5 Sekunden neu laden (ohne das Formular erneut zu senden).
  if (document.querySelector('.us-status.s-am_pad')) setTimeout(function () { location.replace(location.pathname + location.search); }, 5000);
  // Pad-Software hochladen: ZIP roh senden (file_uploads ist für Module aus), Ergebnis in der Seite zeigen.
  var los = document.getElementById('pad-los');
  if (los) los.addEventListener('click', function () {
    var datei = document.getElementById('pad-datei').files[0];
    var status = document.getElementById('pad-status');
    var meta = document.querySelector('meta[name="kienzle-csrf"]');
    if (!datei) { status.textContent = 'Bitte zuerst die ZIP-Datei auswählen.'; return; }
    if (!document.getElementById('pad-lizenz').checked) { status.textContent = 'Bitte den Lizenzvertrag bestätigen.'; return; }
    if (datei.size > 8000000) { status.textContent = 'Die Datei ist zu groß (höchstens 8 MB).'; return; }
    los.disabled = true;
    status.textContent = 'Wird hochgeladen und geprüft …';
    fetch(los.getAttribute('data-ziel'), { method: 'POST', credentials: 'same-origin', body: datei,
      headers: { 'Content-Type': 'application/octet-stream', 'X-Kienzle-CSRF': meta ? meta.content : '', 'X-Lizenz': '1' } })
      .then(function (r) {
        // Antwort als Text lesen: Kommt keine JSON-Antwort (z. B. Fehlerseite des Servers), steht der Status in der Meldung.
        return r.text().then(function (t) {
          var j = null;
          try { j = JSON.parse(t); } catch (e) { j = null; }
          return { ok: r.ok, status: r.status, j: j, text: t };
        });
      })
      .then(function (x) {
        if (x.ok && x.j && x.j.ok) { status.textContent = 'Fertig. Die Seite wird neu geladen …'; setTimeout(function () { location.hash = '#pad'; location.reload(); }, 700); return; }
        if (x.j && x.j.error) status.textContent = x.j.error;
        else status.textContent = 'Der Server hat nicht wie erwartet geantwortet (HTTP ' + x.status + '): ' + x.text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160);
        los.disabled = false;
      })
      .catch(function (e) { status.textContent = 'Die Verbindung wurde unterbrochen (' + (e && e.message ? e.message : 'unbekannt') + '). Bitte noch einmal versuchen; steht das wieder da, bitte die Meldung weitergeben.'; los.disabled = false; });
  });
  window.addEventListener('hashchange', oeffneAusAdresse);
  oeffneAusAdresse();
})();
