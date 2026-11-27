"""Feste Texte am Pad in der Sprache der Vorlage (Sprachfassungen aus dem Editor). Inhalte der Vorlage übersetzt DeepL;
diese wenigen Bedienwörter stehen hier fest. Unbekannte Sprache oder unbekannter Text: Deutsch."""
from __future__ import annotations

SPRACHE = 'de'

_T: dict[str, dict[str, str]] = {
    'Abbrechen': {'en': 'Cancel', 'tr': 'İptal', 'ru': 'Отмена', 'uk': 'Скасувати', 'pl': 'Anuluj', 'fr': 'Annuler', 'it': 'Annulla', 'es': 'Cancelar', 'ro': 'Anulare', 'el': 'Ακύρωση'},
    'Zurück': {'en': 'Back', 'tr': 'Geri', 'ru': 'Назад', 'uk': 'Назад', 'pl': 'Wstecz', 'fr': 'Retour', 'it': 'Indietro', 'es': 'Atrás', 'ro': 'Înapoi', 'el': 'Πίσω'},
    'Weiter': {'en': 'Next', 'tr': 'İleri', 'ru': 'Далее', 'uk': 'Далі', 'pl': 'Dalej', 'fr': 'Suivant', 'it': 'Avanti', 'es': 'Siguiente', 'ro': 'Înainte', 'el': 'Επόμενο'},
    'Weiter zur Unterschrift': {'en': 'Continue to sign', 'tr': 'İmzaya geç', 'ru': 'К подписи', 'uk': 'До підпису', 'pl': 'Do podpisu', 'fr': 'Vers la signature',
                                'it': 'Alla firma', 'es': 'Ir a firmar', 'ro': 'Spre semnătură', 'el': 'Στην υπογραφή'},
    'Löschen': {'en': 'Clear', 'tr': 'Sil', 'ru': 'Стереть', 'uk': 'Стерти', 'pl': 'Wyczyść', 'fr': 'Effacer', 'it': 'Cancella', 'es': 'Borrar', 'ro': 'Șterge', 'el': 'Διαγραφή'},
    'Unterschrift': {'en': 'Signature', 'tr': 'İmza', 'ru': 'Подпись', 'uk': 'Підпис', 'pl': 'Podpis', 'fr': 'Signature', 'it': 'Firma', 'es': 'Firma', 'ro': 'Semnătură', 'el': 'Υπογραφή'},
    'Bitte unterschreiben Sie im Feld und bestätigen Sie mit „OK“.': {
        'en': 'Please sign in the box and confirm with “OK”.', 'tr': 'Lütfen kutuya imzalayın ve „OK“ ile onaylayın.', 'ru': 'Пожалуйста, распишитесь в поле и нажмите «OK».',
        'uk': 'Будь ласка, підпишіться в полі та натисніть «OK».', 'pl': 'Proszę podpisać w polu i potwierdzić przyciskiem „OK”.',
        'fr': 'Veuillez signer dans le cadre et confirmer avec « OK ».', 'it': 'Firmi nel riquadro e confermi con «OK».', 'es': 'Firme en el recuadro y confirme con «OK».',
        'ro': 'Vă rugăm să semnați în câmp și să confirmați cu „OK”.', 'el': 'Παρακαλούμε υπογράψτε στο πλαίσιο και επιβεβαιώστε με «OK».'},
    'Seite {a} von {b}': {'en': 'Page {a} of {b}', 'tr': 'Sayfa {a} / {b}', 'ru': 'Страница {a} из {b}', 'uk': 'Сторінка {a} з {b}', 'pl': 'Strona {a} z {b}', 'fr': 'Page {a} sur {b}',
                          'it': 'Pagina {a} di {b}', 'es': 'Página {a} de {b}', 'ro': 'Pagina {a} din {b}', 'el': 'Σελίδα {a} από {b}'},
    'Vielen Dank': {'en': 'Thank you', 'tr': 'Teşekkürler', 'ru': 'Спасибо', 'uk': 'Дякуємо', 'pl': 'Dziękujemy', 'fr': 'Merci', 'it': 'Grazie', 'es': 'Gracias', 'ro': 'Mulțumim', 'el': 'Ευχαριστούμε'},
    'Ändern': {'en': 'Change', 'tr': 'Değiştir', 'ru': 'Изменить', 'uk': 'Змінити', 'pl': 'Zmień', 'fr': 'Modifier', 'it': 'Modifica', 'es': 'Cambiar', 'ro': 'Modifică', 'el': 'Αλλαγή'},
    'Leer': {'en': 'Space', 'tr': 'Boşluk', 'ru': 'Пробел', 'uk': 'Пробіл', 'pl': 'Spacja', 'fr': 'Espace', 'it': 'Spazio', 'es': 'Espacio', 'ro': 'Spațiu', 'el': 'Κενό'},
    'E-Mail': {'en': 'Email', 'tr': 'E-posta', 'ru': 'Эл. почта', 'uk': 'Ел. пошта', 'pl': 'E-mail', 'fr': 'E-mail', 'it': 'E-mail', 'es': 'Correo', 'ro': 'E-mail', 'el': 'E-mail'},
    'Telefon': {'en': 'Phone', 'tr': 'Telefon', 'ru': 'Телефон', 'uk': 'Телефон', 'pl': 'Telefon', 'fr': 'Téléphone', 'it': 'Telefono', 'es': 'Teléfono', 'ro': 'Telefon', 'el': 'Τηλέφωνο'},
    'Bitte die mit * markierten Angaben ausfüllen.': {
        'en': 'Please complete the fields marked with *.', 'tr': 'Lütfen * ile işaretli alanları doldurun.', 'ru': 'Пожалуйста, заполните поля, отмеченные *.',
        'uk': 'Будь ласка, заповніть поля, позначені *.', 'pl': 'Proszę wypełnić pola oznaczone *.', 'fr': 'Veuillez remplir les champs marqués *.',
        'it': 'Compili i campi contrassegnati con *.', 'es': 'Complete los campos marcados con *.', 'ro': 'Completați câmpurile marcate cu *.', 'el': 'Συμπληρώστε τα πεδία με *.'},
    'Bitte nur Ziffern, Leerzeichen und + / - verwenden.': {
        'en': 'Please use only digits, spaces and + / -.', 'tr': 'Yalnızca rakam, boşluk ve + / - kullanın.', 'ru': 'Используйте только цифры, пробелы и + / -.',
        'uk': 'Використовуйте лише цифри, пробіли та + / -.', 'pl': 'Proszę używać tylko cyfr, spacji i + / -.', 'fr': 'Uniquement des chiffres, espaces et + / -.',
        'it': 'Usi solo cifre, spazi e + / -.', 'es': 'Use solo cifras, espacios y + / -.', 'ro': 'Folosiți doar cifre, spații și + / -.', 'el': 'Μόνο ψηφία, κενά και + / -.'},
    'Bitte eine gültige E-Mail-Adresse eingeben.': {
        'en': 'Please enter a valid email address.', 'tr': 'Lütfen geçerli bir e-posta adresi girin.', 'ru': 'Введите действительный адрес эл. почты.',
        'uk': 'Введіть дійсну адресу ел. пошти.', 'pl': 'Proszę podać prawidłowy adres e-mail.', 'fr': 'Veuillez saisir une adresse e-mail valide.',
        'it': 'Inserisca un indirizzo e-mail valido.', 'es': 'Introduzca un correo electrónico válido.', 'ro': 'Introduceți o adresă de e-mail validă.', 'el': 'Εισαγάγετε έγκυρο e-mail.'},
}


def t(text: str, **werte) -> str:
    """Text in der eingestellten Sprache (SPRACHE); {a}/{b} werden eingesetzt."""
    s = _T.get(text, {}).get(SPRACHE, text) if SPRACHE != 'de' else text
    return s.format(**werte) if werte else s
