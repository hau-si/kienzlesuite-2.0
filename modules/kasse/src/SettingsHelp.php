<?php
declare(strict_types=1);
namespace KienzleKasse;

/**
 * Hilfetexte für die Einstellungsseite: Erklärung (tip), Beispielwert (example) und
 * eine Erklärung je Auswahlmöglichkeit (options). Nur Beispieldaten, keine echten Praxiswerte.
 */
final class SettingsHelp {
    public const FIELDS = [
        'sumup.mode' => ['tip' => 'Im Testmodus wird keine Karte belastet; der Ablauf lässt sich gefahrlos ausprobieren.',
            'options' => ['mock' => 'Für die Einrichtung und Schulung. Zahlungen werden nur simuliert, kein Solo nötig.',
                'live' => 'Echter Betrieb: Der Betrag geht an das SumUp Solo und wird von der Karte abgebucht. Alle SumUp-Felder müssen ausgefüllt sein.']],
        'sumup.api_key' => ['tip' => 'Geheimer Schlüssel für die SumUp-Cloud-API (Solo). Im SumUp-Händlerkonto unter „Für Entwickler → API Keys“ einen Schlüssel anlegen und sofort hierher kopieren; SumUp zeigt ihn meist nur einmal. Hier wird er nach dem Speichern nie wieder angezeigt.', 'example' => 'sup_sk_…'],
        'sumup.affiliate_key' => ['tip' => 'Kennzeichnet diese Anbindung bei SumUp. Im Händlerkonto unter „Für Entwickler → Affiliate Keys“ zur App-ID anlegen.', 'example' => 'sup_afk_…'],
        'sumup.app_id' => ['tip' => 'Die App-ID, die beim Anlegen des Affiliate-Keys eingetragen wurde. Beide müssen zusammenpassen.', 'example' => 'de.kienzle.sumup'],
        'sumup.merchant_code' => ['tip' => 'Händlercode der Praxis bei SumUp (beginnt mit M, z. B. MK01A8C2). Steht im Händlerkonto unter „Profil“ bzw. in den Kontodaten; derselbe Code gehört zu dem API-Key oben.', 'example' => 'MK01A8C2'],
        'sumup.reader_id' => ['tip' => 'Kennung des gekoppelten SumUp Solo, beginnt mit „rdr_“. So kommst du daran: (1) Am Solo den Kopplungscode (Pairing-Code) anzeigen lassen, der Weg dorthin steht in der SumUp-Anleitung „Solo mit der Cloud-API verbinden“. (2) Mit dem Code das Gerät bei SumUp anlegen: POST /v0.1/merchants/<Händlercode>/readers mit {"pairing_code": "…", "name": "Kasse"}; die Antwort enthält die ID im Feld „id“. (3) Schon gekoppelt? GET /v0.1/merchants/<Händlercode>/readers listet alle Geräte mit ihrer ID. Beide Aufrufe mit dem API-Key oben als „Authorization: Bearer …“. Danach hier eintragen und „Verbindung zu SumUp prüfen“ drücken.', 'example' => 'rdr_1JHCGHNM3095NBKJP2CMDWJTDC'],
        'app.max_amount_cents' => ['tip' => 'Schutz vor Tippfehlern: höhere Beträge werden nicht an das Terminal geschickt. Ganze Euro.', 'example' => '1000'],
        'fhir.mode' => ['tip' => 'Wie die Kartenzahlungsseite mit T2med spricht.',
            'options' => ['mock' => 'Testpatient „Max Mustermann“, es wird nichts in eine Akte geschrieben.',
                'live' => 'Patient aus dem T2med-Aufruf, Zahlungen werden in der Akte dokumentiert. Nur zusammen mit SumUp „Live“.']],
        'fhir.entry_code' => ['tip' => 'Kürzel, unter dem die Zahlung in der Patientenakte erscheint.', 'example' => 'ZAHLUNG'],
        'bank.purpose' => ['tip' => 'Vorlage für den Verwendungszweck. {nummer} wird durch die Rechnungsnummer (bei T2med-Rechnungen die T2med-Nummer) ersetzt, {betrag} durch den Betrag. Der Verwendungszweck steckt auch im QR-Code.', 'example' => 'Rechnung {nummer}'],
        'practice.append_sumup_receipt' => ['tip' => 'Statt der eigenen Zahlungsbestätigung wird der originale SumUp-Beleg an die Rechnung angehängt.'],
        'tax.rate' => ['tip' => 'Gilt für alle Leistungen, die im Leistungskatalog „mit MwSt.“ markiert sind. Preise im Katalog sind Endpreise; die enthaltene MwSt. wird auf der Rechnung ausgewiesen. Bereits bezahlte Rechnungen behalten ihren Satz.', 'example' => '19'],
        'tax.manual_vat' => ['tip' => 'Ob ein frei eingegebener „Sonstiger Betrag“ MwSt. enthält.'],
        'tax.exempt_note' => ['tip' => 'Erscheint unter der Summe, wenn die Rechnung Positionen ohne MwSt. enthält. Den genauen Wortlaut mit der Steuerberatung abstimmen.', 'example' => 'Umsatzsteuerfreie Heilbehandlung nach § 4 Nr. 14 UStG.'],
        'tax.small_business_note' => ['tip' => 'Erscheint unter der Summe, wenn die Kleinunternehmerregelung eingeschaltet ist.', 'example' => 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.'],
        'mail.enabled' => ['tip' => 'Patientinnen und Patienten können sich den Beleg per E-Mail schicken lassen.'],
        'mail.from_name' => ['tip' => 'Name, der beim Empfänger als Absender erscheint.', 'example' => 'Praxis Dr. Musterfrau'],
        'printing.enabled' => ['tip' => 'Zeigt nach der Zahlung „Beleg drucken“. Welcher Bondrucker das ist, legt Praxisherz fest (Praxis → Drucker → Wofür welcher Drucker?).'],
        'letterxpress.enabled' => ['tip' => 'Zeigt bei offenen T2med-Rechnungen „Per Post senden“. Außerdem verschickt der virtuelle Drucker „vBriefversand“ jede darauf gedruckte T2med-Rechnung sofort per Post (auf den Windows-PCs richtet ihn der Praxisherz-Agent ein, Praxis → Arbeitsplätze); sie erscheint unter Vorgänge als „per Letterxpress verschickt“ mit Zahlungsart Überweisung. Dabei geht die Rechnung mit Patientendaten an Letterxpress (A&O Fischer GmbH & Co. KG). Dafür in der Regel vorher eine Vereinbarung zur Auftragsverarbeitung abschließen und mit dem Datenschutzbeauftragten abstimmen. Die Empfängeradresse liest Letterxpress aus dem Adressfenster der Rechnung; die T2med-Vorlage muss die Anschrift dort drucken.'],
        'letterxpress.color' => ['tip' => 'Gilt für alle Briefe. Schwarz-Weiß ist günstiger.'],
        'letterxpress.duplex' => ['tip' => 'Druckt auf Vorder- und Rückseite, spart Porto bei mehrseitigen Rechnungen.'],
        'letterxpress.shipping' => ['tip' => 'Empfehlung: „Nur Inland“ wählen. Bei „Automatisch“ liest Letterxpress die Anschrift aus dem Adressfenster des PDFs und scheitert dabei öfter („Inland/Ausland nicht bestimmbar“, HTTP 400). Nur bei Briefen ins Ausland „Ausland“ oder „Automatisch“ verwenden.'],
        'letterxpress.registered' => ['tip' => 'Nur für Inlandsbriefe möglich.'],
        'printing.cash_receipt_auto' => ['tip' => 'Druckt bei „Bar bezahlt“ (T2med-Rechnung) sofort eine Quittung auf dem Bondrucker: Betrag, Name, Datum, Rechnungsnummer und „bar erhalten“. Der Bondrucker muss oben eingeschaltet sein. Die Quittung lässt sich auch nachträglich mit „Quittung drucken“ erzeugen.'],
        'printing.cut' => ['tip' => 'Schneidet den Bon nach dem Druck ab (wenn der Drucker einen Abschneider hat).'],
        'office_printing.enabled' => ['tip' => 'Rechnungen auf einem normalen A4-Drucker ausgeben. Welcher Drucker das ist, legt Praxisherz fest (Praxis → Drucker → Wofür welcher Drucker?).'],
        'office_printing.media' => ['tip' => 'Papierformat im Drucker.', 'options' => ['A4' => 'Standard in Deutschland.', 'Letter' => 'US-Format, nur bei entsprechendem Papier.']],
        'office_printing.invoice_auto' => ['tip' => 'Ist das eingeschaltet, druckt die App die Original-Rechnung aus T2med immer auf dem Praxisdrucker: nach der Zahlung mit Karte oder bar und bei „Später (Überweisung)“. Beim Versand per Post (Letterxpress) wird nicht gedruckt. Ausschalten, wenn Rechnungen nicht automatisch gedruckt werden sollen.'],
        'office_printing.duplex' => ['tip' => 'Mehrseitige Rechnungen beidseitig drucken, wenn der Drucker es kann.'],
        'invoice_inbox.enabled' => ['tip' => 'Den Drucker „vKasse“ legt Praxisherz auf diesem Server an (Praxis → Drucker), auf den Windows-PCs richtet ihn der Praxisherz-Agent ein (Praxis → Arbeitsplätze). In T2med die Rechnung darauf drucken; sie erscheint dann in der Kasse zum Kassieren. Ausgeschaltet nimmt die Kasse keine Rechnungen von diesem Drucker an.'],
        'invoice_inbox.label_number' => ['tip' => 'Nur nötig, wenn deine T2med-Rechnungsvorlage andere Bezeichnungen verwendet. Der Server kennt „Re.-Nr.:“, „Rechnungsnummer:“ und ähnliche; hier trägst du zusätzlich den Text ein, der unmittelbar vor der Nummer steht. Unter „Rechnungseingang“ siehst du bei abgelehnten Drucken, welche Beschriftungen gefunden wurden.', 'example' => 'Beleg-Nr.'],
        'invoice_inbox.number_prefix' => ['tip' => 'Beginnen deine Rechnungsnummern mit einem festen Kürzel (z. B. „IG2026-309“), trägst du es hier ein, bei mehreren Rechnungsgruppen jedes in eine eigene Zeile. Hilft bei Rechnungen, die T2med als Bild druckt: Die Texterkennung liest das Kürzel oft falsch („1IG“, „16G“); die Kasse ordnet es dann dem passenden eingetragenen Kürzel zu, damit dieselbe Rechnung immer dieselbe Nummer bekommt.', 'example' => 'IG'],
        'invoice_inbox.label_date' => ['tip' => 'Text unmittelbar vor dem Rechnungsdatum (TT.MM.JJJJ).', 'example' => 'Ausgestellt am'],
        'invoice_inbox.label_total' => ['tip' => 'Text unmittelbar vor dem Gesamtbetrag, z. B. „Summe“. Der Betrag muss wie „12,34 €“ geschrieben sein.', 'example' => 'Endsumme'],
        'invoice_inbox.label_transfer' => ['tip' => 'Text vor dem Betrag im Überweisungssatz. Er wird mit der Gesamtsumme verglichen; weicht er ab, wird die Rechnung abgelehnt.', 'example' => 'Zahlbetrag'],
        'invoice_inbox.label_patient' => ['tip' => 'Text vor „Name , Geburtsdatum“ (auch „Name (geb. TT.MM.JJJJ)“ wird gelesen).', 'example' => 'Behandelte Person'],
        'invoice_inbox.max_age_days' => ['tip' => 'Wie lange eine gedruckte, noch unbezahlte Rechnung angeboten wird.', 'example' => '14'],
        'invoice_export.enabled' => ['tip' => 'Legt die Rechnungs-PDF zusätzlich in einem Netzwerkordner ab, z. B. für die Steuerberatung.'],
        'invoice_export.directory' => ['tip' => 'Ordner innerhalb der Freigabe. {jahr} und {monat} werden automatisch ersetzt; leer = direkt in die Freigabe.', 'example' => 'Rechnungen/{jahr}/{monat}'],
        'invoice_export.app_invoices' => ['tip' => 'Auch die direkt auf der Kartenzahlungsseite erstellten Rechnungen ablegen.'],
        'invoice_export.letter' => ['tip' => 'Rechnungen, die über den virtuellen Drucker „vBriefversand“ (oder „Per Post senden“ an der Kasse) verschickt werden, landen zusätzlich in der Ablage, z. B. für die Steuerberatung. Der Dateiname endet auf _Post. Unabhängig von der Einstellung darunter.'],
        'invoice_export.when' => ['tip' => 'Welche T2med-Rechnungen abgelegt werden.',
            'options' => ['paid' => 'Erst nach erfolgreicher Kartenzahlung.', 'received' => 'Sofort beim Drucken auf „vKasse“, auch ohne Zahlung.']],
    ];

    public static function for(string $section, string $key): array {
        return self::FIELDS[$section . '.' . $key] ?? [];
    }
}
