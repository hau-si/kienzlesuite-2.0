=== Praxisherz-Hinweise ===
Requires at least: 5.3
Requires PHP: 7.4
Stable tag: 0.2.0
License: MIT

Zeigt Mitteilungen aus Praxisherz (Vertretung, Impfstoff lieferbar, Sonderöffnungszeiten, freier Text) auf der Homepage der Praxis an.

== Einrichtung ==

1. In WordPress: Plugins → Installieren → Plugin hochladen → diese ZIP-Datei wählen → Jetzt installieren → Aktivieren.
2. In WordPress: Einstellungen → Praxisherz-Hinweise → Schlüssel kopieren.
3. In Praxisherz: Homepage → Einrichtung → Adresse der Homepage und Schlüssel eintragen → Speichern und prüfen.

== Orte ==

* Leiste oben: auf allen Seiten ganz oben (Theme mit wp_body_open; sonst setzt ein kleines Skript die Leiste nach oben).
* Kasten Startseite: über dem Inhalt der Startseite.
* Fenster: öffnet sich einmal je Browser-Sitzung.
* Kurzcode: an der Stelle von [praxisherz_hinweise] in einer Seite oder einem Widget.

== Datenschutz ==

Das Plugin speichert nur die Texte der Mitteilungen. Es setzt keine Cookies und lädt nichts von fremden Servern. Ob ein Fenster
geschlossen wurde, merkt sich der Browser des Besuchers bis zum Schließen des Tabs (sessionStorage).
