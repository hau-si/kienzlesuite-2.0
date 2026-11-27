# Gestaltung der Module

Gemeinsame Regeln für die Oberfläche aller Module Praxisherz. Sie gelten zusätzlich zu den Grundbausteinen der Basis
(`core/public/assets/core.css`: Karten, `details.fold`, `tag ok/warn/err`, `mark-icon`) und zur Modul-Schnittstelle
(`docs/MODUL-SCHNITTSTELLE.md`). Neue Gestaltungen zeigt man zuerst als drei Entwürfe; was der Nutzer wählt, kommt hierher.

## Arbeitsseiten mit Patient (Entwurf B3, gewählt am 27.11.2026)

Gilt für Module, die mit einem Patienten arbeiten (Kasse, Signatur und künftige):

- **Oben der Patient, links die Vorgänge.** Die linke Spalte zeigt die Vorgänge bzw. Rechnungen des Moduls. Rechts steht oben die
  Patientenkarte, darunter die Arbeitsfläche.
- **Patientenkarte** (Bauteil der Basis, Klassen in `core.css`):
  - Links ein Etikett. Bei Patienten aus T2med ist es rot mit Herz und „T2med“, sonst neutral mit Personensymbol (`.pk-marke.neutral`).
  - In der Mitte der Name groß, darunter das Geburtsdatum.
  - Rechts die Knöpfe:
    - **„Zum Patienten“** holt ihn zurück, wenn gerade etwas anderes offen ist.
    - **„Zur Kasse“** bzw. ein anderes Modul führt in das Modul, aus dem der Patient kam.
    - **Entfernen** als Mülltonnen-Symbol (Titel „Patient entfernen“) nimmt ihn heraus.
  - Ein Klick auf Name oder T2med-Etikett öffnet die Akte in T2med (`POST /arbeitsplatz/akte` mit der Patientennummer, GDT über den
    Praxisherz-Agent), wenn die Patientennummer bekannt ist.
  - Hat ein Modul zwei Arbeitsflächen für den Patienten (Kasse: offene T2med-Rechnung und IGeL-Liste), stehen kompakte Umschalter in der
    Karte („T2m-Rechnung“ mit Herz, „IGeL“ mit Igel). Die Mitte wechselt, das Bezahl- bzw. Abschlussfeld bleibt rechts an derselben Stelle.
  - Der Name hat Vorrang: Er wird nicht gekürzt, Knöpfe rücken notfalls in eine zweite Zeile.
  - Ohne Patient darf die Karte das Eingabefeld für den Namen tragen.
- **Der Patient bleibt erhalten,** bis er entfernt wird: beim Öffnen anderer Vorgänge, beim Wechsel auf andere Seiten des Moduls
  und beim Wechsel zwischen Modulen im selben Browser-Tab.
  - Gespeichert wird im `sessionStorage` des Tabs, nie auf dem Server und nie in `localStorage`. Mit dem Schließen des Tabs ist er weg.
  - Kasse: Schlüssel `ks-patient` (Besuch, Name, Geburtsdatum). Signatur: `sg-patient` (Übergabedaten).
  - Läuft der T2med-Zugang ab (4 Stunden), verschwindet die Karte von selbst.
- **Wechsel zwischen Modulen im selben Tab** (nicht in neuen Tabs), damit der Patient erhalten bleibt und der Rückweg stimmt:
  - Kasse → Signatur: „Zur Unterschrift“ öffnet Signatur im selben Tab mit `#uebergabe=…`. Darin stehen der Rückweg `zurueck`
    (Pfad der Kasse) und `t2med`.
  - Signatur → Kasse: Nach der Unterschrift eines Vorgangs aus der Kasse springt Signatur von selbst zurück zur Kasse, damit gleich
    bezahlt werden kann. Der Patient steht dort noch oben.
- **Seitenbreite:** Alle Seiten eines Moduls sind gleich breit, `main.modul { max-width: 1400px; }`, auch Einstellungen und Listen.
- **Symbole statt Emojis:** einfache Liniensymbole (24er-Raster, Strich 2, `stroke="currentColor"`), z. B. für Zahlungsarten Karte,
  Geldschein, Bank, Brief.

### Markup der Patientenkarte

```html
<div class="patient-karte">
  <div class="pk-marke">            <!-- neutral: class="pk-marke neutral" -->
    <svg class="pk-herz" …>…</svg> <svg class="pk-person" …>…</svg><span>T2med</span>
  </div>
  <div class="pk-text"><strong>Max Mustermann</strong><span>geb. 01.02.1970</span></div>
  <div class="pk-knoepfe">
    <button class="primary pk-knopf">Zum Patienten</button>
    <button class="pk-knopf pk-weg">Entfernen</button>
  </div>
</div>
```

Ohne `rahmen()` der Basis (z. B. eingebettete Ansichten in einem `iframe`) steht die Karte auf der umgebenden Seite, nicht doppelt
im eingebetteten Teil.
