/* Passwortstärke: Balken und Hinweise unter Feldern mit data-staerke. Die Rechnung entspricht Passwords::score in core/php/Passwords.php. */
(function () {
  var NAMEN = ['sehr schwach', 'schwach', 'mittel', 'gut', 'sehr gut'];
  var SCHLECHT = ['passwort', 'password', 'admin', 'kienzle', 'sumup', 'qwertz', 'qwert', 'asdf', 'letmein', 'welcome', 'willkommen',
    'praxis', 't2med', '1234567', '0123456', 'abcdefg', '1111111', '0000000'];
  function zeichen(s) { return Array.from(s); }
  function weak(p) {
    var plain = p.toLowerCase();
    if (new Set(zeichen(plain)).size < 5) return true;
    var squeezed = plain.replace(/ä/g, 'a').replace(/ö/g, 'o').replace(/ü/g, 'u').replace(/ß/g, 's').replace(/[^a-z0-9]/g, '');
    var rest = squeezed;
    SCHLECHT.forEach(function (b) { rest = rest.split(b).join(''); });
    return rest !== squeezed && rest.length < 6;
  }
  function klassen(p) {
    return (/\p{Ll}/u.test(p) ? 1 : 0) + (/\p{Lu}/u.test(p) ? 1 : 0) + (/\d/.test(p) ? 1 : 0) + (/[^\p{L}\d]/u.test(p) ? 1 : 0);
  }
  function score(p, login, pruefeSchwach) {
    var cs = zeichen(p);
    if (!cs.length) return 0;
    var pool = (/[a-z]/.test(p) ? 26 : 0) + (/[A-Z]/.test(p) ? 26 : 0) + (/\d/.test(p) ? 10 : 0) + (/[\x20-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e]/.test(p) ? 33 : 0) + (/[^\x20-\x7e]/.test(p) ? 30 : 0);
    var eff = 0, prev = null;
    cs.forEach(function (c) {
      var o = c.codePointAt(0);
      if (prev === null || (o !== prev && Math.abs(o - prev) !== 1)) eff++;
      prev = o;
    });
    var bits = pool > 1 ? eff * Math.log2(pool) : 0;
    if (new Set(cs).size < 5) bits = Math.min(bits, 15);
    if ((pruefeSchwach && weak(p)) || (login && p.toLowerCase().indexOf(login.toLowerCase()) !== -1)) bits = Math.min(bits, 20);
    return bits < 28 ? 0 : bits < 40 ? 1 : bits < 56 ? 2 : bits < 72 ? 3 : 4;
  }
  function binde(feld) {
    var min = parseInt(feld.dataset.min || '10', 10), kl = parseInt(feld.dataset.klassen || '1', 10), ms = parseInt(feld.dataset.minStaerke || '0', 10);
    var pruefeSchwach = feld.dataset.schwach !== '0', loginFest = feld.dataset.login || '', loginFeld = feld.dataset.loginFeld || '';
    var box = document.createElement('div'); box.className = 'staerke'; box.setAttribute('aria-live', 'polite');
    var bahn = document.createElement('div'); bahn.className = 'staerke-bahn';
    var balken = document.createElement('div'); balken.className = 'staerke-balken'; bahn.appendChild(balken);
    var text = document.createElement('div'); text.className = 'staerke-text';
    box.appendChild(bahn); box.appendChild(text);
    feld.parentNode.appendChild(box);
    function aktualisieren() {
      var p = feld.value, login = loginFest;
      if (loginFeld) { var l = feld.form && feld.form.elements[loginFeld]; if (l && l.value) login = l.value; }
      if (!p) { balken.style.width = '0%'; balken.className = 'staerke-balken'; text.textContent = 'Stärke: noch kein Passwort eingegeben'; return; }
      var s = score(p, login, pruefeSchwach), fehlt = [];
      balken.style.width = (s + 1) * 20 + '%'; balken.className = 'staerke-balken s' + s;
      if (zeichen(p).length < min) fehlt.push('mindestens ' + min + ' Zeichen');
      if (klassen(p) < kl) fehlt.push('mindestens ' + kl + ' Zeichenarten (Klein-, Großbuchstaben, Ziffern, Sonderzeichen)');
      if (pruefeSchwach && weak(p)) fehlt.push('kein häufiges Wort wie „passwort“ oder „qwertz“');
      if (login && p.toLowerCase().indexOf(login.toLowerCase()) !== -1) fehlt.push('ohne den Anmeldenamen');
      if (ms > 0 && s < ms) fehlt.push('Stärke mindestens „' + NAMEN[ms] + '“');
      text.textContent = 'Stärke: ' + NAMEN[s] + (fehlt.length ? ' · noch nötig: ' + fehlt.join(', ') : ' · erfüllt die Regeln');
    }
    feld.addEventListener('input', aktualisieren);
    if (feld.form && loginFeld && feld.form.elements[loginFeld]) feld.form.elements[loginFeld].addEventListener('input', aktualisieren);
    aktualisieren();
  }
  document.querySelectorAll('input[data-staerke]').forEach(binde);
})();
