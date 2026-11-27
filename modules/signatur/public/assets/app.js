// Signatur: Aufklappbereiche per Adresse öffnen (#vorlagen) und Löschen bestätigen. Keine Inline-Skripte (CSP).
(function () {
  'use strict';
  var ANFANG = location.hash || '';   // vor dem Aufräumen der Adresse merken (Start aus T2med oder Übergabe der Kasse)
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
  // Patient ist vorgewählt (aus T2med oder der Kasse): Patientenkarte oben füllen (Entwurf B3), Namensfeld ausblenden.
  var RUECKWEG = /^\/[a-z0-9-]{1,31}\/$/;
  function patientZeigen(quelle, d) {
    var kasten = document.getElementById('n-gewaehlt'), feld = document.getElementById('n-patient-feld'), name = document.getElementById('n-patient');
    if (!kasten || !feld || !name || !name.value) return;
    document.getElementById('n-gewaehlt-anzeige').hidden = false; document.getElementById('n-gewaehlt-weg').hidden = false;
    var t2med = quelle === 't2med' || d.t2med === true;
    document.getElementById('n-gewaehlt-marke').classList.toggle('neutral', !t2med);
    document.getElementById('n-gewaehlt-quelle').textContent = t2med ? 'T2med' : (quelle === 'kasse' ? 'Kasse' : 'Patient');
    document.getElementById('n-gewaehlt-name').textContent = name.value;
    var geb = String(d.geburtsdatum || ''), m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(geb);
    document.getElementById('n-gewaehlt-info').textContent = geb ? 'geb. ' + (m ? m[3] + '.' + m[2] + '.' + m[1] : geb) : '';
    var zk = document.getElementById('n-zur-kasse');
    if (zk) {
      // Aus der Kasse: Rückweg dorthin. Direkt aus T2med: Übergabe an die Kasse mit dem T2med-Zugang (Zahlung wird dort in die Akte geschrieben).
      var weg = quelle === 'kasse' && RUECKWEG.test(String(d.zurueck || ''));
      var uebergabe = quelle === 't2med' && !!kasten.getAttribute('data-kasse') && /^[A-Za-z0-9._-]{1,128}$/.test(String(d.kontext || ''));
      zk.hidden = !weg && !uebergabe;
      zk.href = weg ? d.zurueck : '#';
      if (uebergabe) zk.setAttribute('data-kontext', d.kontext); else zk.removeAttribute('data-kontext');
    }
    feld.hidden = true;
  }
  // Der Patient bleibt im Tab erhalten (Seitenwechsel, neuer Vorgang, Wechsel zur Kasse und zurück), bis „Entfernen“.
  var SP = 'sg-patient', ZURUECK = 'sg-zurueck';
  function merken(quelle, d) { try { sessionStorage.setItem(SP, JSON.stringify({ quelle: quelle, d: d })); } catch (e) { /* ohne sessionStorage */ } }
  function gemerkt() { try { var g = JSON.parse(sessionStorage.getItem(SP) || 'null'); return g && g.d && (g.quelle === 'kasse' || g.quelle === 't2med') ? g : null; } catch (e) { return null; } }
  (function () {
    var weg = document.getElementById('n-gewaehlt-weg');
    if (!weg) return;
    weg.addEventListener('click', function () {
      var form = document.getElementById('n-form');
      ['n-patient', 'n-kontext', 'n-patient-id', 'n-telefon-alt', 'n-email-alt', 'n-vorname', 'n-nachname', 'n-geburtsdatum', 'n-telefon', 'n-email', 'n-adresse', 'n-leistungen', 'n-quelle', 'n-quelle-ref'].forEach(function (id) { var f = document.getElementById(id); if (f) f.value = ''; });
      var g = document.getElementById('n-geschlecht'); if (g) g.value = '';
      ['n-leistung', 'n-betrag'].forEach(function (id) { var f = document.getElementById(id); if (f) { f.value = ''; f.readOnly = false; } });
      var l = document.getElementById('n-liste'); if (l) l.hidden = true;
      var kb = document.getElementById('n-kontakt-box'); if (kb) kb.hidden = true;
      Array.prototype.forEach.call(form.querySelectorAll('p.notice, p.error'), function (p) { p.remove(); });
      document.getElementById('n-gewaehlt-anzeige').hidden = true; document.getElementById('n-gewaehlt-weg').hidden = true; document.getElementById('n-gewaehlt-quelle').textContent = 'Patient'; document.getElementById('n-patient-feld').hidden = false;
      document.getElementById('n-gewaehlt-marke').classList.add('neutral');
      var zk = document.getElementById('n-zur-kasse'); if (zk) zk.hidden = true;
      try { sessionStorage.removeItem(SP); sessionStorage.removeItem(ZURUECK); } catch (e) { /* ohne sessionStorage */ }
      document.getElementById('n-patient').focus();
    });
  })();
  // „Zur Kasse“ bei Patienten direkt aus T2med: Übergabe anlegen und im selben Tab zur Kasse (dort erscheint der Patient oben).
  document.addEventListener('click', function (e) {
    var zk = e.target && e.target.closest ? e.target.closest('#n-zur-kasse[data-kontext]') : null;
    if (!zk) return;
    e.preventDefault();
    var csrf = document.querySelector('meta[name="kienzle-csrf"]');
    var basis = (location.pathname.match(/^\/[a-z0-9-]{1,31}/) || ['/signatur'])[0];
    zk.setAttribute('aria-busy', 'true');
    fetch(basis + '/zur-kasse', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ 'kienzle-csrf': csrf ? csrf.content : '', kontext: zk.getAttribute('data-kontext') }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (a) {
        zk.removeAttribute('aria-busy');
        if (a.ok && /^\/[a-z0-9-]{1,31}\/#uebergabe=[0-9a-f]{32}$/.test(a.j.url || '')) { location.href = a.j.url; return; }
        window.alert(a.j.error || 'Die Übergabe an die Kasse ist nicht möglich.');
      })
      .catch(function () { zk.removeAttribute('aria-busy'); window.alert('Keine Verbindung zum Server.'); });
  });
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
  function t2medUebernehmen(j) {
    var feld = document.getElementById('n-patient'); if (!feld) return;
    feld.value = String(j.patient || '').slice(0, 80);
    var k = document.getElementById('n-kontext');
    if (k) k.value = j.kontext || '';
    kontaktFuellen(j);
    patientZeigen('t2med', j);
  }
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
          t2medUebernehmen(a.j);
          merken('t2med', a.j);
          hinweis.textContent = 'Patient aus T2med übernommen: ' + (a.j.patient || '');
        } else {
          hinweis.textContent = a.j.error || 'Der Start aus T2med ist fehlgeschlagen.';
        }
        feld.form.insertBefore(hinweis, feld.form.firstChild);
      })
      .catch(function () { /* ohne Netz bleibt die Startseite wie gewohnt */ });
  })();
  // Übergabe aus der Kasse: Patient, Verweis und Leistungsliste in „Neuer Vorgang“ eintragen. Liefert false, wenn die Liste fehlt.
  function kasseUebernehmen(d) {
    var feld = document.getElementById('n-patient'); if (!feld) return false;
    var liste = Array.isArray(d.leistungen) ? d.leistungen.slice(0, 40).map(function (z) {
      return { code: String(z.code || '').slice(0, 12), label: String(z.label || '').slice(0, 120), cent: Math.max(0, Math.round(Number(z.cent) || 0)) };
    }).filter(function (z) { return z.label; }) : [];
    if (!liste.length) return false;
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
    // Aus der Kasse (IGeL): den IGeL-Vertrag vorwählen.
    var igel = document.querySelector('input[name="vorlage_id"][data-art="igel"]'); if (igel) igel.checked = true;
    patientZeigen('kasse', d);
    return true;
  }
  // #uebergabe=<base64url-JSON> aus der Kasse. Das Fragment geht nie an den Server; geprüft wird die Liste beim Anlegen noch einmal.
  (function () {
    var m = /^#uebergabe=([A-Za-z0-9_-]{1,12000})$/.exec(location.hash || '');
    var feld = document.getElementById('n-patient');
    if (!m || !feld) return;
    history.replaceState(null, '', location.pathname + location.search);
    var hinweis = document.createElement('p'), d = null;
    try {
      var b64 = m[1].replace(/-/g, '+').replace(/_/g, '/');
      var roh = atob(b64 + '==='.slice((b64.length + 3) % 4));
      d = JSON.parse(new TextDecoder().decode(Uint8Array.from(roh, function (c) { return c.charCodeAt(0); })));
      if (!kasseUebernehmen(d)) throw new Error('leer');
      merken('kasse', d);
      hinweis.className = 'notice';
      hinweis.textContent = 'Aus der Kasse übernommen: ' + feld.value + '. Bitte das Dokument wählen und den Vorgang anlegen; nach der Unterschrift geht es zurück zur Kasse zum Bezahlen.';
    } catch (e) {
      hinweis.className = 'error';
      hinweis.textContent = 'Die Übergabe aus der Kasse konnte nicht gelesen werden. Bitte in der Kasse erneut „Zur Unterschrift“ wählen.';
    }
    feld.form.insertBefore(hinweis, feld.form.firstChild);
  })();
  // Gemerkten Patienten wieder eintragen (ohne neuen Aufruf aus T2med oder der Kasse).
  (function () {
    if (/^#(launch|uebergabe)=/.test(ANFANG) || !document.getElementById('n-patient')) return;
    var g = gemerkt(); if (!g) return;
    if (g.quelle === 'kasse') kasseUebernehmen(g.d); else t2medUebernehmen(g.d);
  })();
  // Vorgang aus der Kasse angelegt: nach der Unterschrift zurück zur Kasse (dort wird bezahlt, der Patient steht noch oben).
  (function () {
    var form = document.getElementById('n-form');
    if (form) form.addEventListener('submit', function () {
      var g = gemerkt(), ref = (document.getElementById('n-quelle-ref') || {}).value || '';
      if (g && g.quelle === 'kasse' && ref && RUECKWEG.test(String(g.d.zurueck || ''))) {
        try { sessionStorage.setItem(ZURUECK, JSON.stringify({ ref: ref, zurueck: g.d.zurueck, zeit: Date.now() })); } catch (e) { /* ohne sessionStorage */ }
      }
    });
    var z = null; try { z = JSON.parse(sessionStorage.getItem(ZURUECK) || 'null'); } catch (e) { z = null; }
    if (!z || !RUECKWEG.test(String(z.zurueck || '')) || Date.now() - z.zeit > 2 * 3600 * 1000) { try { sessionStorage.removeItem(ZURUECK); } catch (e) { /* */ } return; }
    var karte = null;
    Array.prototype.forEach.call(document.querySelectorAll('.us-vz[data-ref]'), function (k) { if (!karte && k.getAttribute('data-ref') === z.ref) karte = k; });
    if (!karte || !/^(unterschrieben|uebertragen)$/.test(karte.getAttribute('data-status') || '')) return;
    try { sessionStorage.removeItem(ZURUECK); } catch (e) { /* */ }
    var hinweis = document.createElement('p');
    hinweis.className = 'ok'; hinweis.setAttribute('role', 'status');
    hinweis.textContent = 'Unterschrieben. Zurück zur Kasse zum Bezahlen …';
    var us = document.querySelector('.us'); if (us) us.insertBefore(hinweis, us.children[1] || null);
    setTimeout(function () { location.href = z.zurueck; }, 2000);
  })();
  // Vorgangsliste: Suche und Filter wirken sofort im Browser.
  var tabelle = document.getElementById('vg-tabelle');
  if (tabelle) {
    var suche = document.getElementById('vg-suche'), art = document.getElementById('vg-art'), stat = document.getElementById('vg-status'), archiv = document.getElementById('vg-archiv');
    var zeilen = Array.prototype.slice.call(tabelle.tBodies[0].rows);
    var filtern = function () {
      var q = suche.value.trim().toLowerCase(), n = 0;
      zeilen.forEach(function (z) {
        var ok = (!q || (z.getAttribute('data-suche') || '').indexOf(q) !== -1) && (!art.value || z.getAttribute('data-art') === art.value) && (!stat.value || z.getAttribute('data-status') === stat.value) && (!archiv.value || z.getAttribute('data-archiv') === archiv.value);
        z.hidden = !ok;
        if (ok) n++;
      });
      document.getElementById('vg-zahl').textContent = n + ' von ' + zeilen.length + ' Vorgängen';
      document.getElementById('vg-leer').hidden = n !== 0;
    };
    [suche, art, stat, archiv].forEach(function (e) { e.addEventListener('input', filtern); e.addEventListener('change', filtern); });
    filtern();
  }
  // Solange ein Vorgang am Pad unterschrieben wird, die Liste alle 5 Sekunden neu laden (ohne das Formular erneut zu senden).
  // Liegt ein Vorgang am Pad: Mit Live-Vorschau (Startseite) Bild und Stand alle 1,5 s holen und neu laden, sobald der Vorgang
  // das Pad verlässt (Vorschau weg); sonst wie bisher alle 5 s neu laden.
  var vorschau = document.querySelector('.us-padvorschau[data-vorschau]');
  if (vorschau) {
    var basis = vorschau.getAttribute('data-vorschau'), bild = vorschau.querySelector('img'), text = vorschau.querySelector('.us-pv-text'), zuletzt = '', fehlt = 0, gesehen = false;
    var holen = function () {
      fetch(basis + '.json', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
        if (!j) { fehlt++; if (gesehen || fehlt > 20) { location.replace(location.pathname + location.search); return; } setTimeout(holen, 1500); return; }
        gesehen = true; fehlt = 0;
        var marke = j.seite + '/' + j.seiten + '/' + j.zeit;
        if (j.seite + '/' + j.seiten !== zuletzt.split('/').slice(0, 2).join('/')) { bild.src = basis + '.png?t=' + Date.now(); bild.hidden = false; }
        zuletzt = marke;
        vorschau.classList.add('live');
        text.textContent = 'Live · ' + (j.art === 'unterschrift' ? 'Unterschriftsseite' : 'Seite') + ' ' + j.seite + ' von ' + j.seiten + (j.art === 'unterschrift' ? (j.punkte > 0 ? ' · wird unterschrieben' : ' · noch keine Unterschrift') : '');
        setTimeout(holen, 1500);
      }).catch(function () { setTimeout(holen, 3000); });
    };
    holen();
  } else if (document.querySelector('.us-status.s-am_pad')) setTimeout(function () { location.replace(location.pathname + location.search); }, 5000);
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

  // Dokumentliste: Suche nach Namensteilen, Gruppenfilter, Favoriten (Stern), zuletzt gewählte (wie im Fax).
  var dokSuche = document.getElementById('dok-suche'), dokFilter = '';
  var dokBasis = (location.pathname.match(/^\/[a-z0-9-]{1,31}/) || ['/signatur'])[0];
  function dokFiltern() {
    var q = dokSuche ? dokSuche.value.trim().toLowerCase() : '', n = 0;
    document.querySelectorAll('.us-dokzeile').forEach(function (z) {
      var ja = (!q || (z.getAttribute('data-name') || '').indexOf(q) >= 0)
        && (!dokFilter || (dokFilter === 'fav' ? z.getAttribute('data-fav') === '1' : ('|' + (z.getAttribute('data-gruppen') || '') + '|').indexOf('|' + dokFilter.slice(2) + '|') >= 0));
      z.hidden = !ja; if (ja) n++;
    });
    var leer = document.getElementById('dok-leer'); if (leer) leer.hidden = n > 0;
  }
  if (dokSuche) dokSuche.addEventListener('input', dokFiltern);
  document.querySelectorAll('.us-chip[data-filter]').forEach(function (c) {
    c.addEventListener('click', function () {
      dokFilter = c.getAttribute('data-filter');
      document.querySelectorAll('.us-chip[data-filter]').forEach(function (x) { x.classList.toggle('an', x === c); });
      dokFiltern();
    });
  });
  document.querySelectorAll('.us-chip[data-waehle]').forEach(function (c) {
    c.addEventListener('click', function () {
      var r = document.querySelector('input[name="vorlage_id"][value="' + c.getAttribute('data-waehle') + '"]');
      if (!r) return;
      if (dokSuche) dokSuche.value = '';
      dokFilter = ''; document.querySelectorAll('.us-chip[data-filter]').forEach(function (x) { x.classList.toggle('an', x.getAttribute('data-filter') === ''); });
      dokFiltern(); r.checked = true; r.focus();
    });
  });
  document.querySelectorAll('.us-stern[data-favorit]').forEach(function (s) {
    s.addEventListener('click', function () {
      var an = !s.classList.contains('an'), meta = document.querySelector('meta[name="kienzle-csrf"]');
      fetch(dokBasis + '/favorit', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Kienzle-CSRF': meta ? meta.content : '' },
        body: JSON.stringify({ id: parseInt(s.getAttribute('data-favorit'), 10), an: an }) })
        .then(function (r) { if (!r.ok) throw new Error(); s.classList.toggle('an', an); s.setAttribute('aria-pressed', an ? 'true' : 'false');
          s.title = an ? 'Favorit entfernen' : 'Als Favorit oben anzeigen';
          var z = s.closest('.us-dokzeile'); if (an) z.setAttribute('data-fav', '1'); else z.removeAttribute('data-fav'); dokFiltern(); })
        .catch(function () { s.title = 'Speichern nicht möglich. Bitte die Seite neu laden.'; });
    });
  });
})();
