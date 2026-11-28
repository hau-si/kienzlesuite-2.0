/*
 * Praxisherz-Hinweise: Öffnungszeiten-Karte. Rechnet im Browser aus, ob die Praxis gerade geöffnet hat (auch Mittagspause,
 * „öffnet in …“), welcher ärztliche Bereitschaftsdienst gerade zuständig ist und hebt den heutigen Tag hervor. Alle Minute neu.
 * Daten: data-oeffnung der Karte (woche, tage, dienste, notdienst). Ohne Skript bleibt die Wochentabelle stehen.
 */
(function () {
  'use strict';
  var TAGE = ['so', 'mo', 'di', 'mi', 'do', 'fr', 'sa'];
  var NAMEN = {mo: 'Montag', di: 'Dienstag', mi: 'Mittwoch', do: 'Donnerstag', fr: 'Freitag', sa: 'Samstag', so: 'Sonntag'};

  function zwei(n) { return (n < 10 ? '0' : '') + n; }
  function iso(d) { return d.getFullYear() + '-' + zwei(d.getMonth() + 1) + '-' + zwei(d.getDate()); }
  function plus(d, n) { return new Date(d.getFullYear(), d.getMonth(), d.getDate() + n); }
  function minuten(hm) { var t = String(hm).split(':'); return Number(t[0]) * 60 + Number(t[1]); }
  function uhr(m) { m = ((m % 1440) + 1440) % 1440; return zwei(Math.floor(m / 60)) + ':' + zwei(m % 60); }

  /** Sprechzeiten eines Tages: Abweichung (Schließung, Feiertag, Sonderöffnung) oder die reguläre Woche. */
  function tag(daten, d) {
    var t = daten.tage && daten.tage[iso(d)];
    if (t) return {zeiten: t.zeiten || [], grund: t.grund || '', feiertag: !!t.feiertag, vertretung: t.vertretung || [], abweichend: true};
    return {zeiten: (daten.woche && daten.woche[TAGE[d.getDay()]]) || [], grund: '', feiertag: false, vertretung: [], abweichend: false};
  }

  /** Bereitschaftsdienste eines Tages in Minuten ab Mitternacht (Ende über Mitternacht > 1440). */
  function dienste(daten, d) {
    var dt = daten.dienste || {}, k = iso(d), l;
    if (dt.tage && dt.tage[k]) l = dt.tage[k];
    else {
      var t = tag(daten, d);
      l = t.feiertag && dt.feiertag && dt.feiertag.length ? dt.feiertag : (dt.woche || []).filter(function (r) { return r.tag === TAGE[d.getDay()]; });
    }
    return l.map(function (r) {
      var a = minuten(r.von), b = minuten(r.bis);
      if (b <= a) b += 1440;
      return {a: a, b: b, praxis: r.praxis};
    });
  }

  function dauer(m) {
    if (m < 60) return 'in ' + Math.max(1, m) + ' Min.';
    var h = Math.floor(m / 60), r = m % 60;
    if (h >= 10 || r === 0) return 'in ' + (r >= 30 && h >= 10 ? h + 1 : h) + ' Std.';
    return 'in ' + h + ' Std. ' + r + ' Min.';
  }

  /** Zustand zum Zeitpunkt jetzt: art offen|pause|zu, text, zusatz, grund, vertretung, dienst. */
  function status(daten, jetzt) {
    var m = jetzt.getHours() * 60 + jetzt.getMinutes(), heute = tag(daten, jetzt), i, f;
    var out = {art: 'zu', text: 'Gerade geschlossen', zusatz: '', grund: '', vertretung: [], dienst: null, heute: TAGE[jetzt.getDay()]};
    var z = heute.zeiten.map(function (x) { return [minuten(x[0]), minuten(x[1])]; });
    for (i = 0; i < z.length; i++) {
      if (m >= z[i][0] && m < z[i][1]) {
        var ende = z[i][1];
        for (var j = i + 1; j < z.length && z[j][0] <= ende; j++) ende = Math.max(ende, z[j][1]);
        out.art = 'offen'; out.text = 'Jetzt geöffnet'; out.zusatz = 'bis ' + uhr(ende) + ' Uhr';
        return out;
      }
    }
    var vorher = z.some(function (x) { return x[1] <= m; }), spaeter = z.filter(function (x) { return x[0] > m; });
    if (vorher && spaeter.length) {
      out.art = 'pause'; out.text = 'Mittagspause';
      out.zusatz = 'wieder erreichbar ab ' + uhr(spaeter[0][0]) + ' Uhr (' + dauer(spaeter[0][0] - m) + ')';
    } else {
      for (i = 0; i <= 21 && !out.zusatz; i++) {
        var d = plus(jetzt, i), t = tag(daten, d);
        for (f = 0; f < t.zeiten.length; f++) {
          var beginn = minuten(t.zeiten[f][0]), abstand = i * 1440 + beginn - m;
          if (abstand <= 0) continue;
          if (i === 0) out.zusatz = 'öffnet heute um ' + t.zeiten[f][0] + ' Uhr (' + dauer(abstand) + ')';
          else if (i === 1) out.zusatz = 'öffnet morgen um ' + t.zeiten[f][0] + ' Uhr (' + dauer(abstand) + ')';
          else out.zusatz = 'öffnet am ' + NAMEN[TAGE[d.getDay()]] + ', ' + zwei(d.getDate()) + '.' + zwei(d.getMonth() + 1) + '., um ' + t.zeiten[f][0] + ' Uhr';
          break;
        }
      }
      if (heute.abweichend && heute.grund) out.grund = 'Heute: ' + heute.grund;
      out.vertretung = heute.vertretung;
    }
    // Bereitschaftsdienst: Dienste von gestern über Mitternacht und von heute
    var gestern = dienste(daten, plus(jetzt, -1)).map(function (x) { return {a: x.a - 1440, b: x.b - 1440, praxis: x.praxis}; });
    var alle = gestern.concat(dienste(daten, jetzt));
    for (i = 0; i < alle.length; i++) {
      if (m >= alle[i].a && m < alle[i].b) { out.dienst = {jetzt: true, praxis: alle[i].praxis, bis: uhr(alle[i].b)}; break; }
    }
    if (!out.dienst) {
      var naechster = alle.filter(function (x) { return x.a > m && x.a < 1440; }).sort(function (a, b) { return a.a - b.a; })[0];
      if (naechster) out.dienst = {jetzt: false, praxis: naechster.praxis, ab: uhr(naechster.a), bis: uhr(naechster.b)};
    }
    return out;
  }

  // ---- Anzeige (nur Text-Knoten, keine HTML-Zeichenketten aus den Daten) ----
  function el(name, klasse, text) {
    var e = document.createElement(name);
    if (klasse) e.className = klasse;
    if (text) e.textContent = text;
    return e;
  }
  function praxisZeilen(p) {
    var f = document.createDocumentFragment();
    f.appendChild(el('span', 'ph-oe-name', p.name || ''));
    var rest = [[p.strasse, p.ort].filter(Boolean).join(', '), p.telefon ? 'Tel. ' + p.telefon : '', p.zusatz || ''].filter(Boolean).join(' · ');
    if (rest) f.appendChild(el('span', 'ph-oe-anschrift', rest));
    return f;
  }
  function zeigen(karte, daten, jetzt) {
    var s = status(daten, jetzt);
    var box = karte.querySelector('.ph-oe-status');
    if (box) {
      box.className = 'ph-oe-status ph-oe-' + s.art;
      box.textContent = '';
      var zeile = el('div', 'ph-oe-zeile');
      zeile.appendChild(el('span', 'ph-oe-punkt'));
      zeile.appendChild(el('strong', '', s.text));
      box.appendChild(zeile);
      if (s.zusatz) box.appendChild(el('div', 'ph-oe-zusatz', s.zusatz));
      if (s.grund) box.appendChild(el('div', 'ph-oe-grund', s.grund));
      box.hidden = false;
    }
    var d = karte.querySelector('.ph-oe-dienst');
    if (d) {
      d.textContent = '';
      if (s.art !== 'offen' && s.vertretung.length) {
        d.appendChild(el('strong', 'ph-oe-kopf', 'Vertretung'));
        s.vertretung.forEach(function (p) { var b = el('div', 'ph-oe-praxis'); b.appendChild(praxisZeilen(p)); d.appendChild(b); });
      }
      if (s.art !== 'offen' && s.dienst) {
        d.appendChild(el('strong', 'ph-oe-kopf', s.dienst.jetzt ? 'Ärztlicher Bereitschaftsdienst jetzt (bis ' + s.dienst.bis + ' Uhr)'
          : 'Ärztlicher Bereitschaftsdienst heute ab ' + s.dienst.ab + ' Uhr'));
        var b = el('div', 'ph-oe-praxis');
        b.appendChild(praxisZeilen(s.dienst.praxis || {}));
        d.appendChild(b);
      }
      d.hidden = !d.firstChild;
    }
    var zeilen = karte.querySelectorAll('tr[data-tage]');
    for (var i = 0; i < zeilen.length; i++) {
      var heute = (' ' + zeilen[i].getAttribute('data-tage') + ' ').indexOf(' ' + s.heute + ' ') >= 0;
      zeilen[i].classList.toggle('ph-oe-heute', heute);
    }
  }
  function start() {
    var karten = document.querySelectorAll('.ph-oeffnung[data-oeffnung]');
    Array.prototype.forEach.call(karten, function (k) {
      var daten;
      try { daten = JSON.parse(k.getAttribute('data-oeffnung')); } catch (e) { return; }
      var lauf = function () { zeigen(k, daten, new Date()); };
      lauf();
      setInterval(lauf, 60000);
    });
  }

  if (typeof module === 'object' && module.exports) module.exports = {status: status, tag: tag, dienste: dienste};
  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
  }
})();
