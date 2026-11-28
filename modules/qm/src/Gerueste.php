<?php
declare(strict_types=1);
namespace KienzleQm;

/**
 * Mitgelieferte Gerüste für neue Dokumente: eigene, frei verwendbare Gliederungen mit Lücken in eckigen Klammern. Sie ersetzen keine
 * fachliche Prüfung; Inhalte (z. B. Hygiene- und Notfallmaßnahmen) legt die Praxis fest und vidiert sie.
 */
final class Gerueste {
    /** @return array<string, array{titel:string, ordner:string, tags:string, inhalt:string}> */
    public static function alle(): array {
        $kopf = '<p><strong>Geltungsbereich:</strong> [für wen und wo gilt das Dokument]</p><p><strong>Verantwortlich:</strong> [Name oder Funktion]</p>';
        return [
            'verfahren' => ['titel' => 'Verfahrensanweisung', 'ordner' => '6 Abläufe (SOP)', 'tags' => 'SOP',
                'inhalt' => '<h2>Ziel</h2><p>[Was soll mit diesem Ablauf erreicht werden?]</p>' . $kopf
                    . '<h2>Ablauf</h2><ol><li>[Schritt]</li><li>[Schritt]</li><li>[Schritt]</li></ol><h2>Mitgeltende Unterlagen</h2><ul><li>[z. B. Formular, Checkliste, Gerätebuch]</li></ul>'],
            'checkliste' => ['titel' => 'Checkliste', 'ordner' => '7 Formulare und Checklisten', 'tags' => 'Checkliste',
                'inhalt' => '<h2>Wofür</h2><p>[Anlass, z. B. Praxisöffnung am Morgen]</p><ul class="checkliste"><li>[Punkt]</li><li>[Punkt]</li><li>[Punkt]</li></ul>'
                    . '<p>Erledigt von: ______________ am: ______________</p>'],
            'verantwortung' => ['titel' => 'Aufgaben und Verantwortlichkeiten', 'ordner' => '1 Praxisführung und Organisation', 'tags' => 'Organisation',
                'inhalt' => '<h2>Übersicht</h2><table><tbody><tr><th>Aufgabe</th><th>Verantwortlich</th><th>Vertretung</th></tr><tr><td>Qualitätsmanagement</td><td>[Name]</td><td>[Name]</td></tr>'
                    . '<tr><td>Hygiene</td><td>[Name]</td><td>[Name]</td></tr><tr><td>Medizinprodukte und Gerätebuch</td><td>[Name]</td><td>[Name]</td></tr><tr><td>Notfallausstattung</td><td>[Name]</td><td>[Name]</td></tr>'
                    . '<tr><td>Datenschutz</td><td>[Name]</td><td>[Name]</td></tr><tr><td>Arbeitsschutz</td><td>[Name]</td><td>[Name]</td></tr></tbody></table>'],
            'notfall' => ['titel' => 'Notfallplan', 'ordner' => '3 Notfallmanagement', 'tags' => 'Notfall',
                'inhalt' => $kopf . '<h2>Wer macht was?</h2><table><tbody><tr><th>Rolle</th><th>Aufgabe</th></tr><tr><td>Erste Person am Patienten</td><td>[Hilfe rufen, Erstmaßnahmen]</td></tr>'
                    . '<tr><td>Empfang</td><td>[Notruf 112, Weg freimachen, Rettungsdienst empfangen]</td></tr><tr><td>Ärztin/Arzt</td><td>[Leitung der Versorgung]</td></tr></tbody></table>'
                    . '<h2>Wo liegt was?</h2><ul><li>Notfallkoffer: [Ort]</li><li>Defibrillator (AED): [Ort]</li><li>Sauerstoff: [Ort]</li></ul>'
                    . '<h2>Maßnahmen</h2><p>[Ablauf nach den aktuellen Leitlinien, von der Praxisleitung festgelegt]</p><h2>Übungen</h2><p>[Wie oft wird geübt, wer organisiert?]</p>'],
            'hygiene' => ['titel' => 'Hygieneplan: [Bereich]', 'ordner' => '2 Hygiene und Medizinprodukte', 'tags' => 'Hygiene',
                'inhalt' => $kopf . '<table><tbody><tr><th>Was</th><th>Wann</th><th>Womit</th><th>Wie</th><th>Wer</th></tr><tr><td>[Händedesinfektion]</td><td>[Anlass]</td><td>[Mittel]</td><td>[Einwirkzeit]</td><td>[alle]</td></tr>'
                    . '<tr><td>[Flächen]</td><td>[täglich]</td><td>[Mittel]</td><td>[Verfahren]</td><td>[Name]</td></tr></tbody></table><p>Grundlage: [z. B. Musterhygieneplan der KV, Empfehlungen der KRINKO]</p>'],
            'geraet' => ['titel' => 'Medizinprodukt: [Gerät]', 'ordner' => '2 Hygiene und Medizinprodukte', 'tags' => 'Medizinprodukte',
                'inhalt' => '<table><tbody><tr><th>Gerät</th><td>[Bezeichnung, Hersteller]</td></tr><tr><th>Seriennummer</th><td>[Nummer]</td></tr><tr><th>Standort</th><td>[Raum]</td></tr>'
                    . '<tr><th>Eingewiesene Personen</th><td>[Namen, Datum]</td></tr><tr><th>Prüfungen (STK/MTK)</th><td>[Fälligkeit]</td></tr></tbody></table><h2>Bedienung und Reinigung</h2><p>[Kurzanleitung]</p>'],
            'einarbeitung' => ['titel' => 'Einarbeitung neuer Mitarbeitender', 'ordner' => '8 Mitarbeitende und Einarbeitung', 'tags' => 'Einarbeitung',
                'inhalt' => '<h2>Erster Tag</h2><ul class="checkliste"><li>Begrüßung, Rundgang, Vorstellung im Team</li><li>Zugänge (Praxissoftware, Praxisherz) eingerichtet</li><li>Schweigepflicht und Datenschutz unterschrieben</li></ul>'
                    . '<h2>Erste Woche</h2><ul class="checkliste"><li>Hygieneplan gelesen</li><li>Notfallplan gelesen, Notfallausstattung gezeigt</li><li>Einweisung in Geräte</li></ul>'
                    . '<h2>Pate/Patin</h2><p>[Name]</p><p>Tipp: Alle Dokumente mit dem Tag „Einarbeitung“ lassen sich im Handbuch gesammelt vorlegen.</p>'],
            'beschwerde' => ['titel' => 'Umgang mit Rückmeldungen und Beschwerden', 'ordner' => '1 Praxisführung und Organisation', 'tags' => 'Patientensicherheit',
                'inhalt' => '<h2>Annahme</h2><p>[Wer nimmt Rückmeldungen entgegen, wie werden sie festgehalten?]</p><h2>Bearbeitung</h2><ol><li>[Bestätigung an die Person]</li><li>[Klärung im Team]</li><li>[Antwort und Maßnahme]</li></ol>'
                    . '<h2>Auswertung</h2><p>[z. B. Teambesprechung einmal im Quartal]</p>'],
            'datenschutz' => ['titel' => 'Datenschutz am Empfang', 'ordner' => '5 Datenschutz und Informationssicherheit', 'tags' => 'Datenschutz',
                'inhalt' => '<ul class="checkliste"><li>Bildschirme sind vom Wartebereich nicht einsehbar</li><li>Telefonate mit Patientendaten nicht im Wartebereich</li><li>Unterlagen nicht offen liegen lassen</li><li>PC beim Verlassen sperren</li></ul>'
                    . '<p>Ansprechperson Datenschutz: [Name]</p>'],
        ];
    }

    /** Bekannte Musterquellen (nur Verweise; Inhalte nicht im Modul). */
    public const QUELLEN = [
        ['titel' => 'QEP – Qualität und Entwicklung in Praxen (KBV)', 'url' => 'https://www.kbv.de/praxis/tools-und-services/qep',
         'hinweis' => 'Rund 200 Musterdokumente als Word-Dateien im Bereich „OnlinePlus“ (mit dem QEP-Manual). Nutzung nach den Bedingungen der KBV.'],
        ['titel' => 'Musterhygieneplan Arztpraxis (Kompetenzzentrum Hygiene und Medizinprodukte der KVen)', 'url' => 'https://www.kbv.de/media/sp/CoC_Mustervorlage_Hygieneplan_Arztpraxis_pdf.pdf',
         'hinweis' => 'Kostenlose Vorlage, als Word-Datei z. B. bei der KV Hessen. Für die eigene Praxis anpassen.'],
        ['titel' => 'Qualitätsmanagement bei der KBV', 'url' => 'https://www.kbv.de/praxis/qualitaet/qualitaetsmanagement',
         'hinweis' => 'Anforderungen der QM-Richtlinie des G-BA, Selbstbewertung, Checklisten.'],
    ];
}
