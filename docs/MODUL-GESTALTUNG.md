# Gestaltung der Module

Gemeinsame Regeln für die Oberfläche aller Module Praxisherz. Sie gelten zusätzlich zu den Grundbausteinen der Basis
(`core/public/assets/core.css`: Karten, `details.fold`, `tag ok/warn/err`, `mark-icon`) und zur Modul-Schnittstelle
(`docs/MODUL-SCHNITTSTELLE.md`). Neue Gestaltungen zeigt man zuerst als drei Entwürfe; was der Nutzer wählt, kommt hierher.

## Für alle Seiten (Stand 28.11.2026, Vorbild Kasse, Signatur, Fax und Telefon)

### Seitenbreite

- `main.modul { max-width: 1400px; margin: 0; padding: 32px 40px 48px; }` in der Stildatei des Moduls, auf **allen** Seiten gleich
  (Start, Listen, Einstellungen). Unter 900 px: `padding: 20px 16px 40px`.
- Schmale Formulare begrenzt man innen (z. B. `max-width: 620px` am Formular), nicht über `main.modul`.

### Seitenkopf

```html
<header class="seitenkopf">
  <div><p class="eyebrow">Telefon</p><h1>Vorgänge</h1></div>
  <nav class="start-tools" aria-label="Telefon">
    <a class="button secondary mit-icon" href="/telefon/"><svg …/>Übersicht</a>
    <a class="button primary mit-icon" href="/telefon/vorgaenge" aria-current="page"><svg …/>Vorgänge<span class="zaehler">3</span></a>
    <a class="button secondary mit-icon" href="/telefon/einstellungen"><svg …/>Einstellungen</a>
  </nav>
</header>
```

- **Links** die Zeile `eyebrow` mit dem Modulnamen (in Großbuchstaben durch CSS), darunter `<h1>` mit dem Namen der aktuellen Seite.
- **Rechts** die Knopfleiste mit allen Unterseiten des Moduls, die der Benutzer sehen darf:
  - **Auf jeder Seite dieselben Knöpfe in derselben Reihenfolge.** Kein „← Zurück“/„← Übersicht“ statt der Leiste.
  - Jeder Knopf hat **Symbol links und Text**: `class="button secondary mit-icon"`; die aktuelle Seite `primary` mit
    `aria-current="page"`.
  - Die Startseite des Moduls heißt im Knopf „Start“ oder „Übersicht“ und hat das Haus-Symbol.
  - **„Einstellungen“ steht immer zuletzt** (Zahnrad + Text); nur zeigen, wenn der Benutzer das Recht dafür hat.
  - Zähler (offene Vorgänge, ungelesene Faxe, rote Einrichtungspunkte) als `<span class="zaehler">N</span>` im Knopf, rot; kein
    „(N)“ im Text. Bei 0 kein Zähler.
  - Knöpfe für Unterseiten, die der Benutzer nicht darf oder die ausgeschaltet sind, fehlen ganz (nicht ausgegraut).
- Ein Suchfeld des Moduls (z. B. Patientensuche im Telefon) steht rechts hinter der Knopfleiste.
- Weitere Angaben (Tageswerte der Kasse, Testmodus) stehen vor den Knöpfen in derselben Leiste.
- Eine Funktion `kopfzeile(string $route)` je Modul baut den Kopf aus **einer** Tabelle der Seiten (Pfad → Titel), damit Titel, Knöpfe
  und Rechte nicht auseinanderlaufen (Beispiele: `Ansicht::SEITEN` in Fax und Telefon, `Kopf::knoepfe` in der Kasse).

- **Protokolle** stehen nicht unten auf der Seite, sondern in einem eigenen Reiter „Protokoll“ rechts oben (`?ansicht=protokoll`).
- **Aktualisieren/Nach Updates sehen** ist ein runder Drehpfeil ganz rechts in der Knopfleiste (`button.dreh`, Titel und `aria-label`
  sagen, was er tut), kein Textknopf am Seitenende.
- **Basis-Seiten** bekommen denselben Kopf über `V::page($titel, $inhalt, $nav, $praxis, $gruppe, ['werkzeuge' => …])` mit
  `V::reiter($href, $text, $symbol, $aktuell, $zaehler)` und `V::drehpfeil($action, $aktion, $titel, $csrf)`; die Seite wird dann
  1400 px breit (`main.breit`). Vorbild: Betrieb → Module und Updates (Basis 0.22.0-beta.58).

CSS (Basis: in `core.css`; Module tragen es bis auf Weiteres in ihrer eigenen Stildatei):

```css
.seitenkopf { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; margin-bottom: 8px; }
.seitenkopf h1 { margin: 6px 0 0; }
.start-tools { display: flex; gap: 8px; flex-wrap: wrap; }
.button.mit-icon { display: inline-flex; align-items: center; gap: 8px; }
.zaehler { display: inline-block; min-width: 18px; padding: 0 6px; border-radius: 9px; background: #c0392b; color: #fff; font-size: 12px; text-align: center; }
```

### Symbole

- Liniensymbole im 24er-Raster, 18 × 18 px, `fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"`,
  `aria-hidden="true"`. Keine Emojis, keine Bilddateien.
- Gleiche Bedeutung, gleiches Symbol in allen Modulen:

| Bedeutung | Symbol | Pfad |
|---|---|---|
| Start / Übersicht | Haus | `M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10` |
| Vorgänge, Listen, Protokolle | Liste | `M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01` |
| Einstellungen | Zahnrad | `circle 12/12/3` + `M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1` |
| Statistik | Balken | `M18 20V10M12 20V4M6 20v-6` |
| Bearbeiten | Stift | `M4 20h4L19 9l-4-4L4 16z` + `M13.5 6.5l4 4` |
| Telefon/Anruf | Hörer | wie in `modules/telefon/src/Ansicht.php` |

- Reine Symbolknöpfe (ohne Text, z. B. Löschen in Tabellenzeilen) brauchen `title` und `aria-label`.

### Inhalt

- Inhalte in Karten (`section.card` mit `<h2>`), Einstellungen als Ausklappzeilen (`details.fold`), Rotes (Handlungsbedarf) zuerst.
- Zustände als `tag ok|warn|err`. Meldungen nach einer Aktion: `Web::flash` bzw. `<p class="ok" role="status">` /
  `<p class="fehler" role="alert">` direkt unter dem Seitenkopf.
- Keine Inline-Styles (die CSP der Module verbietet sie); Stufen und Farben als CSS-Klassen.
- Nach Änderungen an der Stildatei die Versionsangabe der Datei erneuern (`?v=` aus der Prüfsumme, wie in `Ansicht::kopf`).

### Prüfen vor dem Push

- Bildschirmfoto mit `scripts/modul-vorschau` (Port 8098) und neben ein anderes Modul legen: gleiche Breite, gleicher Kopf.
- Tests prüfen die Knopfleiste (Symbol vorhanden, aktuelle Seite `aria-current`, fehlende Rechte = kein Knopf).

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
