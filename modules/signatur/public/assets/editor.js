// Signatur: Editor für Vorlagen (Entwurf C). Links Bausteine und Vorlagen, Mitte das Dokument als Zeilen, rechts die
// Eigenschaften der gewählten Zeile und die Vorschau am Pad. Daten aus <script type="application/json" id="ed-daten">,
// Speichern per fetch (JSON) an /editor/speichern, Bilder roh an /editor/bild. Keine Inline-Stile (CSP), alles maskiert.
(function () {
  'use strict';
  var quelle = document.getElementById('ed-daten');
  if (!quelle) return;
  var D = JSON.parse(quelle.textContent);
  var csrf = (document.querySelector('meta[name="kienzle-csrf"]') || {}).content || '';
  var bloecke = D.bloecke || [];
  var vorlage = D.vorlage;
  var gewaehlt = -1, geaendert = false, zaehler = 0;
  var $ = function (id) { return document.getElementById(id); };
  var BEISPIEL = { patient: 'Max Mustermann', vorname: 'Max', nachname: 'Mustermann', anrede: 'Herr', briefanrede: 'Sehr geehrter Herr Mustermann',
    datum: new Date().toLocaleDateString('de-DE'), praxis: 'Praxis Dr. med. Erika Beispiel', leistung: 'Ultraschall', leistungen: '– Ultraschall – 45,00 €',
    betrag: '45,00', geburtsdatum: '01.02.1970', telefon: '030 1234567', email: 'max@example.org', adresse: 'Musterstraße 1, 12345 Musterstadt' };

  function el(tag, klasse, text) { var e = document.createElement(tag); if (klasse) e.className = klasse; if (text !== undefined) e.textContent = text; return e; }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function einsetzen(s) { return String(s).replace(/\{\{\s*([^{}]*?)\s*\}\}/g, function (m, n) { return BEISPIEL[n] !== undefined ? BEISPIEL[n] : m; }); }
  // Auszeichnung wie Bausteine::html (PHP): erst maskieren, dann **fett**, __unterstrichen__, *kursiv*, Listen, Überschrift.
  function inline(s) {
    s = esc(s);
    s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/__(.+?)__/g, '<u>$1</u>');
    return s.replace(/(^|[^*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/g, '$1<em>$2</em>');
  }
  function html(text) {
    var out = '', liste = '';
    String(text).split('\n').forEach(function (z) {
      var m = /^- (.*)$/.exec(z), art = m ? 'ul' : '';
      if (!m) { m = /^\d{1,2}\. (.*)$/.exec(z); art = m ? 'ol' : ''; }
      if (liste && art !== liste) { out += '</' + liste + '>'; liste = ''; }
      if (art) { if (!liste) { out += '<' + art + '>'; liste = art; } out += '<li>' + inline(m[1]) + '</li>'; }
      else if (/^# /.test(z)) out += '<h3>' + inline(z.slice(2)) + '</h3>';
      else if (!z.trim()) out += '<br>';
      else out += '<p>' + inline(z) + '</p>';
    });
    return out + (liste ? '</' + liste + '>' : '');
  }
  function neueId() { zaehler++; return ('n' + Date.now().toString(36) + zaehler).slice(-16); }
  function vorgabe(typ) {
    var b = { id: neueId(), typ: typ };
    if (typ === 'text') b.text = 'Neuer Text';
    if (typ === 'patientendaten') { b.felder = ['email', 'telefon']; b.aenderbar = true; }
    if (typ === 'ankreuz') { b.text = 'Ich stimme zu.'; b.pflicht = false; }
    if (typ === 'einzel' || typ === 'mehrfach') { b.frage = 'Ihre Wahl'; b.optionen = ['Ja', 'Nein']; b.pflicht = typ === 'einzel'; }
    if (typ === 'liste') { b.eintraege = ['Erster Punkt', 'Zweiter Punkt']; b.nummeriert = false; }
    if (typ === 'bild') { b.datei = ''; b.breite = 100; b.text = ''; }
    return b;
  }
  function aendern() { geaendert = true; $('ed-speichern').classList.add('ed-ungespeichert'); }
  function meldung(text, fehler) { var m = $('ed-meldung'); m.textContent = text; m.hidden = !text; m.className = 'ed-meldung ' + (fehler ? 'fehler' : 'ok'); }

  // ---- Dokument (Mitte) ----
  function kurz(b) {
    if (b.typ === 'text') return html(einsetzen(b.text));
    if (b.typ === 'patientendaten') return b.felder.map(function (f) { return '<div class="ed-feld">' + esc(D.felder[f]) + ': {{' + esc(f) + '}}' + (b.aenderbar ? ' <em>am Pad änderbar</em>' : '') + '</div>'; }).join('');
    if (b.typ === 'ankreuz') return '<span class="ed-kasten"></span>' + inline(b.text) + (b.pflicht ? ' <span class="ed-chip">Pflicht</span>' : '');
    if (b.typ === 'einzel' || b.typ === 'mehrfach') return '<strong>' + inline(b.frage) + '</strong>' + (b.pflicht ? ' <span class="ed-chip">Pflicht</span>' : '') + '<div>'
      + b.optionen.map(function (o) { return '<span class="' + (b.typ === 'einzel' ? 'ed-kreis' : 'ed-kasten') + '"></span>' + esc(o); }).join(' &nbsp; ') + '</div>';
    if (b.typ === 'liste') return '<' + (b.nummeriert ? 'ol' : 'ul') + '>' + b.eintraege.map(function (e) { return '<li>' + inline(e) + '</li>'; }).join('') + '</' + (b.nummeriert ? 'ol' : 'ul') + '>';
    if (b.typ === 'bild') return b.datei ? '<img class="ed-bild b' + Math.round((b.breite || 100) / 10) * 10 + '" alt="" src="' + esc(D.basis + '/bild/' + b.datei) + '">' + (b.text ? '<div class="muted">' + esc(b.text) + '</div>' : '') : '<span class="muted">Noch kein Bild gewählt.</span>';
    if (b.typ === 'trenner') return '<hr>';
    if (b.typ === 'seite') return '<span class="muted">— neue Seite am Pad —</span>';
    return '';
  }
  var gezogen = null; // Index beim Verschieben einer Zeile, oder Typ aus der Palette ("neu:<typ>")
  function dokument() {
    var d = $('ed-dokument');
    d.textContent = '';
    bloecke.forEach(function (b, i) {
      var z = el('div', 'ed-zeile' + (i === gewaehlt ? ' an' : ''));
      z.tabIndex = 0;
      z.setAttribute('role', 'button');
      z.setAttribute('aria-label', (D.typen[b.typ] || b.typ) + ', Zeile ' + (i + 1));
      var griff = el('span', 'ed-griff', '⋮⋮'); griff.draggable = true; griff.title = 'Ziehen zum Verschieben';
      griff.addEventListener('dragstart', function (e) { gezogen = i; e.dataTransfer.setData('text/plain', 'zeile'); });
      var typ = el('span', 'ed-typ', D.typen[b.typ] || b.typ);
      var inhalt = el('div', 'ed-inhalt'); inhalt.innerHTML = kurz(b); // kurz() maskiert alle Eingaben
      var knoepfe = el('span', 'ed-zknoepfe');
      [['↑', 'Nach oben', -1], ['↓', 'Nach unten', 1]].forEach(function (k) {
        var bt = el('button', 'ed-mini', k[0]); bt.type = 'button'; bt.title = k[1]; bt.setAttribute('aria-label', k[1]);
        bt.addEventListener('click', function (e) { e.stopPropagation(); verschieben(i, i + k[2]); });
        knoepfe.appendChild(bt);
      });
      var kopie = el('button', 'ed-mini', '⧉'); kopie.type = 'button'; kopie.title = 'Kopieren'; kopie.setAttribute('aria-label', 'Zeile kopieren');
      kopie.addEventListener('click', function (e) { e.stopPropagation(); var c = JSON.parse(JSON.stringify(b)); c.id = neueId(); bloecke.splice(i + 1, 0, c); gewaehlt = i + 1; aendern(); alles(); });
      var weg = el('button', 'ed-mini rot', '✕'); weg.type = 'button'; weg.title = 'Zeile entfernen'; weg.setAttribute('aria-label', 'Zeile entfernen');
      weg.addEventListener('click', function (e) { e.stopPropagation(); if (bloecke.length < 2) { meldung('Die Vorlage braucht mindestens eine Zeile.', true); return; } bloecke.splice(i, 1); gewaehlt = Math.min(gewaehlt, bloecke.length - 1); aendern(); alles(); });
      knoepfe.appendChild(kopie); knoepfe.appendChild(weg);
      z.appendChild(griff); z.appendChild(typ); z.appendChild(inhalt); z.appendChild(knoepfe);
      z.addEventListener('click', function () { gewaehlt = i; alles(); });
      z.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target === z) { gewaehlt = i; alles(); } });
      z.addEventListener('dragover', function (e) { e.preventDefault(); z.classList.add('ziel'); });
      z.addEventListener('dragleave', function () { z.classList.remove('ziel'); });
      z.addEventListener('drop', function (e) { e.preventDefault(); z.classList.remove('ziel'); ablegen(i); });
      d.appendChild(z);
    });
    var ende = el('div', 'ed-ende', 'Baustein hierher ziehen oder links anklicken');
    ende.addEventListener('dragover', function (e) { e.preventDefault(); ende.classList.add('ziel'); });
    ende.addEventListener('dragleave', function () { ende.classList.remove('ziel'); });
    ende.addEventListener('drop', function (e) { e.preventDefault(); ende.classList.remove('ziel'); ablegen(bloecke.length); });
    d.appendChild(ende);
  }
  function verschieben(von, nach) {
    if (nach < 0 || nach >= bloecke.length || von === nach) return;
    var b = bloecke.splice(von, 1)[0];
    bloecke.splice(nach, 0, b); gewaehlt = nach; aendern(); alles();
  }
  function ablegen(ziel) {
    if (typeof gezogen === 'string' && gezogen.indexOf('neu:') === 0) { einfuegen(gezogen.slice(4), ziel); }
    else if (typeof gezogen === 'number') { verschieben(gezogen, gezogen < ziel ? ziel - 1 : ziel); }
    gezogen = null;
  }
  function einfuegen(typ, an) {
    if (bloecke.length >= 100) { meldung('Höchstens 100 Bausteine je Vorlage.', true); return; }
    bloecke.splice(an, 0, vorgabe(typ)); gewaehlt = an; aendern(); alles();
  }

  // ---- Eigenschaften (rechts) ----
  function feld(label, eingabe) { var l = el('label', 'ed-label', label); l.appendChild(eingabe); return l; }
  function haken(label, wert, setzen) {
    var l = el('label', 'check'), c = el('input'); c.type = 'checkbox'; c.checked = !!wert;
    c.addEventListener('change', function () { setzen(c.checked); aendern(); dokument(); vorschau(); });
    l.appendChild(c); l.appendChild(document.createTextNode(' ' + label)); return l;
  }
  function eingabe(wert, setzen, mehrzeilig, max) {
    var e = el(mehrzeilig ? 'textarea' : 'input'); e.value = wert || ''; if (max) e.maxLength = max; if (mehrzeilig) e.rows = mehrzeilig;
    e.addEventListener('input', function () { setzen(e.value); aendern(); dokument(); vorschau(); });
    return e;
  }
  function zeilenListe(arr) { return arr.join('\n'); }
  function ausZeilen(s) { return s.split('\n').map(function (z) { return z.trim(); }).filter(function (z) { return z; }); }
  function platzhalterWahl(ziel) {
    var s = el('select', 'ed-platzhalter'); s.setAttribute('aria-label', 'Platzhalter einfügen');
    s.appendChild(new Option('{{ Platzhalter einfügen }}', ''));
    Object.keys(D.platzhalter).forEach(function (k) { s.appendChild(new Option('{{' + k + '}} – ' + D.platzhalter[k], k)); });
    s.addEventListener('change', function () { if (!s.value) return; umschliessen(ziel, '{{' + s.value + '}}', ''); s.value = ''; });
    return s;
  }
  // Auszeichnung um die Auswahl im Textfeld setzen (Werkzeugleiste).
  function umschliessen(t, vor, nach) {
    var a = t.selectionStart, b = t.selectionEnd, v = t.value;
    t.value = v.slice(0, a) + vor + v.slice(a, b) + nach + v.slice(b);
    t.selectionStart = a + vor.length; t.selectionEnd = b + vor.length; t.focus();
    t.dispatchEvent(new Event('input'));
  }
  function zeilenAnfang(t, vor) {
    var a = t.value.lastIndexOf('\n', t.selectionStart - 1) + 1;
    t.value = t.value.slice(0, a) + vor + t.value.slice(a); t.focus(); t.dispatchEvent(new Event('input'));
  }
  function eigenschaften() {
    var p = $('ed-eigenschaften');
    p.textContent = '';
    var b = bloecke[gewaehlt];
    if (!b) { p.appendChild(el('p', 'muted', 'Eine Zeile im Dokument anklicken.')); return; }
    p.appendChild(el('p', 'ed-typkopf', D.typen[b.typ] || b.typ));
    if (b.typ === 'text') {
      var t = eingabe(b.text, function (v) { b.text = v; }, 10, 20000);
      var leiste = el('div', 'ed-leiste');
      [['F', 'Fett', '**', '**'], ['K', 'Kursiv', '*', '*'], ['U', 'Unterstrichen', '__', '__']].forEach(function (k) {
        var bt = el('button', 'ed-mini', k[0]); bt.type = 'button'; bt.title = k[1]; bt.setAttribute('aria-label', k[1]);
        bt.addEventListener('click', function () { umschliessen(t, k[2], k[3]); }); leiste.appendChild(bt);
      });
      [['• Liste', 'Aufzählung', '- '], ['1. Liste', 'Nummerierte Liste', '1. '], ['Überschrift', 'Überschrift', '# ']].forEach(function (k) {
        var bt = el('button', 'ed-mini breit', k[0]); bt.type = 'button'; bt.title = k[1];
        bt.addEventListener('click', function () { zeilenAnfang(t, k[2]); }); leiste.appendChild(bt);
      });
      leiste.appendChild(platzhalterWahl(t));
      p.appendChild(leiste); p.appendChild(t);
      p.appendChild(el('p', 'muted klein-text', '**fett**, *kursiv*, __unterstrichen__; Zeilen mit „- “ oder „1. “ werden Listen, „# “ eine Überschrift.'));
    }
    if (b.typ === 'patientendaten') {
      Object.keys(D.felder).forEach(function (f) {
        p.appendChild(haken(D.felder[f], b.felder.indexOf(f) >= 0, function (an) {
          b.felder = Object.keys(D.felder).filter(function (x) { return x === f ? an : b.felder.indexOf(x) >= 0; });
        }));
      });
      p.appendChild(haken('Am Pad vom Patienten änderbar (geht nach der Unterschrift an T2med)', b.aenderbar, function (an) { b.aenderbar = an; }));
    }
    if (b.typ === 'ankreuz') {
      var tt = eingabe(b.text, function (v) { b.text = v; }, 3, 500);
      p.appendChild(feld('Text neben dem Kästchen', tt)); p.appendChild(platzhalterWahl(tt));
      p.appendChild(haken('Pflichtfeld (muss angekreuzt werden)', b.pflicht, function (an) { b.pflicht = an; }));
    }
    if (b.typ === 'einzel' || b.typ === 'mehrfach') {
      p.appendChild(feld('Frage', eingabe(b.frage, function (v) { b.frage = v; }, 0, 300)));
      p.appendChild(feld('Möglichkeiten (eine je Zeile, 2 bis 12)', eingabe(zeilenListe(b.optionen), function (v) { b.optionen = ausZeilen(v); }, 5)));
      p.appendChild(haken(b.typ === 'einzel' ? 'Pflichtfeld (eine Möglichkeit muss gewählt werden)' : 'Pflichtfeld (mindestens eine Möglichkeit)', b.pflicht, function (an) { b.pflicht = an; }));
    }
    if (b.typ === 'liste') {
      p.appendChild(feld('Einträge (einer je Zeile)', eingabe(zeilenListe(b.eintraege), function (v) { b.eintraege = ausZeilen(v); }, 6)));
      p.appendChild(haken('Nummeriert', b.nummeriert, function (an) { b.nummeriert = an; }));
    }
    if (b.typ === 'bild') {
      var datei = el('input'); datei.type = 'file'; datei.accept = 'image/png,image/jpeg';
      var stand = el('p', 'muted klein-text', b.datei ? 'Bild gespeichert.' : 'PNG oder JPEG, höchstens 3 MB.');
      datei.addEventListener('change', function () {
        var f = datei.files[0]; if (!f) return;
        stand.textContent = 'Wird hochgeladen …';
        fetch(D.basis + '/editor/bild', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/octet-stream', 'X-Kienzle-CSRF': csrf }, body: f })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (r) { if (!r.ok) throw new Error(r.j.error || 'Hochladen nicht möglich.'); b.datei = r.j.datei; stand.textContent = 'Bild gespeichert.'; aendern(); dokument(); vorschau(); })
          .catch(function (e) { stand.textContent = e.message; });
      });
      p.appendChild(feld('Bild wählen', datei)); p.appendChild(stand);
      var breite = el('input'); breite.type = 'range'; breite.min = 20; breite.max = 100; breite.step = 10; breite.value = b.breite || 100;
      breite.addEventListener('input', function () { b.breite = parseInt(breite.value, 10); aendern(); dokument(); vorschau(); });
      p.appendChild(feld('Breite', breite));
      p.appendChild(feld('Bildunterschrift (optional)', eingabe(b.text, function (v) { b.text = v; }, 0, 200)));
    }
    if (b.typ === 'trenner' || b.typ === 'seite') p.appendChild(el('p', 'muted', b.typ === 'seite' ? 'Am Pad beginnt hier eine neue Seite.' : 'Eine waagerechte Linie.'));
    else {
      // Eintrag in die T2med-Akte: eigener, meist kürzerer Text; {{antwort}} = Antwort am Pad.
      var box = el('details', 'ed-akte'); if (b.akte) box.open = true;
      box.appendChild(el('summary', '', 'Eintrag in die Akte' + (b.akte ? ' ✓' : '')));
      var at = eingabe(b.akte || '', function (v) { if (v.trim()) b.akte = v; else delete b.akte; }, 2, 300);
      at.placeholder = b.typ === 'text' || b.typ === 'liste' || b.typ === 'bild' ? 'z. B. Aufklärung über Risiken erfolgt' : 'z. B. Einwilligung Fotos: {{antwort}}';
      box.appendChild(at);
      if (['ankreuz', 'einzel', 'mehrfach', 'patientendaten'].indexOf(b.typ) >= 0) {
        var ab = el('button', 'ed-mini breit', '{{antwort}}'); ab.type = 'button'; ab.title = 'Antwort des Patienten einsetzen (ja/nein, gewählte Möglichkeit, Werte)';
        ab.addEventListener('click', function () { umschliessen(at, '{{antwort}}', ''); }); box.appendChild(ab);
      }
      box.appendChild(el('p', 'muted klein-text', 'Leer: dieser Baustein erscheint nicht im Akteneintrag. Der Eintrag geht nach der Unterschrift mit dem Kürzel der Vorlage an T2med, das PDF immer.'));
      p.appendChild(box);
    }
  }

  // ---- Vorschau am Pad (rechts unten) ----
  function vorschau() {
    var v = $('ed-vorschau'), o = '<div class="ed-pt">' + esc($('ed-titel').value || 'Titel') + '</div>';
    bloecke.forEach(function (b) {
      if (b.typ === 'text') o += html(einsetzen(b.text));
      else if (b.typ === 'patientendaten') o += b.felder.map(function (f) { return '<div class="ed-feld">' + esc(D.felder[f]) + ': ' + esc(BEISPIEL[f]) + (b.aenderbar ? ' <em>Ändern</em>' : '') + '</div>'; }).join('');
      else if (b.typ === 'ankreuz') o += '<p><span class="ed-kasten"></span>' + inline(einsetzen(b.text)) + (b.pflicht ? ' *' : '') + '</p>';
      else if (b.typ === 'einzel' || b.typ === 'mehrfach') o += '<p><strong>' + inline(einsetzen(b.frage)) + (b.pflicht ? ' *' : '') + '</strong><br>' + b.optionen.map(function (x) { return '<span class="' + (b.typ === 'einzel' ? 'ed-kreis' : 'ed-kasten') + '"></span>' + esc(einsetzen(x)); }).join('<br>') + '</p>';
      else if (b.typ === 'seite') o += '<div class="ed-seitenwechsel">Seite weiter</div>';
      else o += kurz(b);
    });
    v.innerHTML = o + '<div class="ed-pknoepfe"><span>Zurück</span><span class="h">Weiter</span></div>';
  }

  function alles() { dokument(); eigenschaften(); vorschau(); }

  // ---- Kopf: Titel, Art, aktiv, Fassung, Speichern ----
  var art = $('ed-art');
  Object.keys(D.arten).forEach(function (k) { art.appendChild(new Option(D.arten[k], k)); });
  $('ed-titel').value = vorlage.titel; art.value = vorlage.art; $('ed-aktiv').checked = vorlage.aktiv; $('ed-kuerzel').value = vorlage.kuerzel || ''; $('ed-gruppen').value = vorlage.gruppen || '';
  var vs = $('ed-vsuche');
  if (vs) vs.addEventListener('input', function () {
    var q = vs.value.trim().toLowerCase();
    document.querySelectorAll('.ed-vgruppe').forEach(function (g) {
      var sichtbar = 0;
      g.querySelectorAll('.ed-vorlage').forEach(function (a) { var ja = !q || (a.getAttribute('data-name') || '').indexOf(q) >= 0; a.hidden = !ja; if (ja) sichtbar++; });
      g.hidden = !sichtbar;
    });
  });
  if (vorlage.fest) { art.disabled = true; $('ed-aktiv').disabled = true; art.title = 'Der IGeL-Vertrag der Kasse bleibt ein IGeL-Vertrag und immer aktiv.'; }
  function fassung() { $('ed-fassung').textContent = vorlage.version ? 'Fassung ' + vorlage.version + (vorlage.geaendert ? ' vom ' + vorlage.geaendert : '') : 'neu'; }
  fassung();
  [$('ed-titel'), art, $('ed-aktiv'), $('ed-kuerzel'), $('ed-gruppen')].forEach(function (e) { e.addEventListener('input', function () { aendern(); vorschau(); }); e.addEventListener('change', aendern); });
  $('ed-speichern').addEventListener('click', function () {
    var knopf = $('ed-speichern'); knopf.disabled = true; meldung('Wird gespeichert …');
    fetch(D.basis + '/editor/speichern', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Kienzle-CSRF': csrf },
      body: JSON.stringify({ id: vorlage.id, titel: $('ed-titel').value, art: art.value, aktiv: $('ed-aktiv').checked, kuerzel: $('ed-kuerzel').value.trim(), gruppen: $('ed-gruppen').value, bloecke: bloecke }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (r) {
        knopf.disabled = false;
        if (!r.ok) { meldung(r.j.error || 'Speichern nicht möglich.', true); return; }
        var neu = !vorlage.id;
        vorlage.id = r.j.id; vorlage.version = r.j.version; vorlage.geaendert = new Date().toLocaleDateString('de-DE');
        geaendert = false; knopf.classList.remove('ed-ungespeichert'); fassung(); meldung('Gespeichert.');
        if (neu) location.href = D.basis + '/editor?vorlage=' + r.j.id;
      })
      .catch(function () { knopf.disabled = false; meldung('Keine Verbindung. Bitte erneut speichern.', true); });
  });
  window.addEventListener('beforeunload', function (e) { if (geaendert) { e.preventDefault(); e.returnValue = ''; } });

  // ---- Palette: anklicken (nach der gewählten Zeile) oder ziehen ----
  Array.prototype.forEach.call(document.querySelectorAll('.ed-baustein'), function (bt) {
    bt.addEventListener('click', function () { einfuegen(bt.getAttribute('data-typ'), gewaehlt >= 0 ? gewaehlt + 1 : bloecke.length); });
    bt.addEventListener('dragstart', function (e) { gezogen = 'neu:' + bt.getAttribute('data-typ'); e.dataTransfer.setData('text/plain', 'neu'); });
  });
  gewaehlt = bloecke.length ? 0 : -1;
  alles();
})();
