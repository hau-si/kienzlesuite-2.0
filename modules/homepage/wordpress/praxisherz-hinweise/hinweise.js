/* Praxisherz-Hinweise: Zeitraum im Browser erneut prüfen (Seitencache), Leiste nach oben setzen, Fenster einmal je Sitzung. */
(function () {
  'use strict';
  function heute() {
    var d = new Date(), z = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate());
  }
  function merken(schluessel, wert) { try { if (wert === undefined) return sessionStorage.getItem(schluessel); sessionStorage.setItem(schluessel, wert); } catch (e) { return null; } return null; }
  var tag = heute();
  var alle = document.querySelectorAll('.ph-hinweis');
  for (var i = 0; i < alle.length; i++) {
    var h = alle[i], von = h.getAttribute('data-von') || '', bis = h.getAttribute('data-bis') || '';
    var sichtbar = (von === '' || von <= tag) && (bis === '' || bis >= tag);
    if (h.classList.contains('ph-fenster') && merken('ph-zu-' + h.getAttribute('data-id') + '-' + h.getAttribute('data-stand'))) sichtbar = false;
    h.hidden = !sichtbar;
  }
  var nach = document.querySelector('.ph-nachreichen');
  if (nach && document.body.firstChild !== nach) document.body.insertBefore(nach, document.body.firstChild);
  // Kasten, den das Theme nicht über den Seiteninhalt ausgibt: an die eingestellte Stelle oder automatisch setzen.
  var kasten = document.querySelector('.ph-nachreichen-kasten');
  if (kasten) {
    var ziel = null, lage = kasten.getAttribute('data-lage') || 'anfang', sel = kasten.getAttribute('data-ziel') || '';
    if (sel) { try { ziel = document.querySelector(sel); } catch (e) { ziel = null; } }
    if (!ziel) {
      ziel = document.querySelector('main, [role="main"], #main, .site-main, #content');
      lage = 'anfang';
      if (!ziel) {
        // Erster Abschnitt nach Kopf und Leisten (z. B. der große Willkommensbereich), sonst der Kopf der Seite
        ziel = document.querySelector('body > section, body > div > section, header');
        lage = 'nach';
      }
    }
    if (ziel) {
      if (lage === 'vor') ziel.parentNode.insertBefore(kasten, ziel);
      else if (lage === 'nach') ziel.parentNode.insertBefore(kasten, ziel.nextSibling);
      else if (lage === 'ende') ziel.appendChild(kasten);
      else ziel.insertBefore(kasten, ziel.firstChild);
      kasten.hidden = false;
    }
  }
  // Fenster: nur das erste sichtbare zeigen, die weiteren nach dem Schließen
  var fenster = Array.prototype.filter.call(document.querySelectorAll('.ph-fenster'), function (f) { return !f.hidden; });
  function zeigen(n) {
    for (var j = 0; j < fenster.length; j++) fenster[j].hidden = j !== n;
    if (fenster[n]) { var b = fenster[n].querySelector('.ph-zu'); if (b) b.focus(); }
  }
  function schliessen(n) {
    var f = fenster[n];
    merken('ph-zu-' + f.getAttribute('data-id') + '-' + f.getAttribute('data-stand'), '1');
    f.hidden = true;
    if (n + 1 < fenster.length) zeigen(n + 1);
  }
  fenster.forEach(function (f, n) {
    f.querySelector('.ph-zu').addEventListener('click', function () { schliessen(n); });
    f.addEventListener('click', function (e) { if (e.target === f) schliessen(n); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    for (var j = 0; j < fenster.length; j++) if (!fenster[j].hidden) { schliessen(j); return; }
  });
  if (fenster.length) zeigen(0);
})();
