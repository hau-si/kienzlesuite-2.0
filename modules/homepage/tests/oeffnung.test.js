// Prüft die Rechnung von oeffnung.js (Plugin): geöffnet, Mittagspause, geschlossen, Feiertag, Urlaub mit Vertretung,
// Bereitschaftsdienst (Woche, über Mitternacht, Feiertag, einzelner Tag). Nur erfundene Daten. Aufruf: node oeffnung.test.js
'use strict';
const assert = require('assert');
const oe = require('../wordpress/praxisherz-hinweise/oeffnung.js');

const A = {name: 'Praxis Dr. med. Erika Beispiel', telefon: '01234 56789'};
const B = {name: 'Hausarztpraxis Max Mustermann', telefon: '01234 98765'};
const C = {name: 'Bereitschaftspraxis am Klinikum', telefon: '116117'};
const daten = {
  woche: {mo: [['08:00', '12:00'], ['14:00', '18:00']], di: [['08:00', '12:00'], ['14:00', '18:00']], mi: [['08:00', '12:00']],
    do: [['08:00', '12:00'], ['14:00', '18:00']], fr: [['08:00', '13:00']], sa: [], so: []},
  tage: {
    '2026-12-25': {zeiten: [], grund: '1. Weihnachtstag', feiertag: true, vertretung: []},
    '2026-12-28': {zeiten: [], grund: 'Urlaub', feiertag: false, vertretung: [{name: 'Praxis Dr. med. Anna Muster', telefon: '01234 55555'}]},
    '2026-12-31': {zeiten: [['08:00', '10:00']], grund: 'Sonderöffnung', feiertag: false, vertretung: []},
  },
  dienste: {woche: [{tag: 'mi', von: '13:00', bis: '19:00', praxis: A}, {tag: 'fr', von: '18:00', bis: '08:00', praxis: B}],
    feiertag: [{von: '08:00', bis: '20:00', praxis: C}], tage: {'2026-12-24': [{von: '12:00', bis: '18:00', praxis: C}]}},
  notdienst: 'Ärztlicher Bereitschaftsdienst: 116 117',
};
// Monate in JS ab 0: 11 = Dezember. 21.12.2026 ist ein Montag.
const am = (t, h, m) => oe.status(daten, new Date(2026, 11, t, h, m));

let s = am(21, 9, 30);
assert.strictEqual(s.art, 'offen'); assert.strictEqual(s.zusatz, 'bis 12:00 Uhr'); assert.strictEqual(s.heute, 'mo'); assert.strictEqual(s.dienst, null);
s = am(21, 12, 40);
assert.strictEqual(s.art, 'pause'); assert.strictEqual(s.zusatz, 'wieder erreichbar ab 14:00 Uhr (in 1 Std. 20 Min.)');
s = am(21, 7, 15);
assert.strictEqual(s.art, 'zu'); assert.strictEqual(s.zusatz, 'öffnet heute um 08:00 Uhr (in 45 Min.)');
s = am(21, 19, 0);
assert.strictEqual(s.zusatz, 'öffnet morgen um 08:00 Uhr (in 13 Std.)');
// Mittwoch 23.12.: Dienst 13–19 Uhr
s = am(23, 15, 0);
assert.strictEqual(s.art, 'zu'); assert.deepStrictEqual(s.dienst, {jetzt: true, praxis: A, bis: '19:00'});
s = am(23, 12, 30);
assert.deepStrictEqual(s.dienst, {jetzt: false, praxis: A, ab: '13:00', bis: '19:00'});
// Donnerstag 24.12.: einzelner Tag ersetzt Regeln; Feiertag 25.12. (Fr): Feiertagsdienst statt Freitagsregel, Grund wird genannt
s = am(24, 13, 0);
assert.strictEqual(s.art, 'pause'); assert.strictEqual(s.dienst.praxis, C);
s = am(25, 10, 0);
assert.strictEqual(s.art, 'zu'); assert.strictEqual(s.grund, 'Heute: 1. Weihnachtstag'); assert.deepStrictEqual(s.dienst, {jetzt: true, praxis: C, bis: '20:00'});
assert.strictEqual(s.zusatz, 'öffnet am Dienstag, 29.12., um 08:00 Uhr');
// Freitag 18.12. 23 Uhr und Samstag 19.12. 7 Uhr: Dienst über Mitternacht
s = am(18, 23, 0);
assert.deepStrictEqual(s.dienst, {jetzt: true, praxis: B, bis: '08:00'});
s = am(19, 7, 0);
assert.deepStrictEqual(s.dienst, {jetzt: true, praxis: B, bis: '08:00'});
s = am(19, 9, 0);
assert.strictEqual(s.dienst, null); assert.strictEqual(s.zusatz, 'öffnet am Montag, 21.12., um 08:00 Uhr');
// Urlaub 28.12.: geschlossen mit Vertretung
s = am(28, 10, 0);
assert.strictEqual(s.art, 'zu'); assert.strictEqual(s.grund, 'Heute: Urlaub'); assert.strictEqual(s.vertretung[0].name, 'Praxis Dr. med. Anna Muster');
// Sonderöffnung 31.12. (Do) nur 8–10 Uhr
assert.strictEqual(am(31, 9, 0).zusatz, 'bis 10:00 Uhr');
assert.strictEqual(am(31, 11, 0).art, 'zu');
// Zusammenhängende Zeiten: 08–12 und 12–14 → bis 14 Uhr
assert.strictEqual(oe.status({woche: {mo: [['08:00', '12:00'], ['12:00', '14:00']]}}, new Date(2026, 11, 21, 9, 0)).zusatz, 'bis 14:00 Uhr');
// Ohne jede Sprechzeit: geschlossen ohne „öffnet“
assert.strictEqual(oe.status({woche: {}}, new Date(2026, 11, 21, 9, 0)).zusatz, '');
console.log('oeffnung.js: alle Tests bestanden.');
