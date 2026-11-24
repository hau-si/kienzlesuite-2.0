"""seven.io-Guthaben (core/lib/kienzle/verbindungen.py): abfragen, merken, Fehlerfälle; es wird nichts verschickt."""
import sys
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'core' / 'lib'))
from kienzle import verbindungen as v  # noqa: E402

C = {'anbieter': 'seven', 'api_key': 'geheim'}


class SmsGuthaben(unittest.TestCase):
    def test_guthaben_wird_abgefragt_und_gemerkt(self):
        with mock.patch.object(v, '_sms_call', return_value={'wert': 3.5}) as call, mock.patch.object(v, '_balance_record') as rec:
            self.assertEqual(v.balance_sms(C), 'Guthaben bei seven.io: 3.50 €.')
        call.assert_called_once_with(C, 'balance', None)   # nur Abfrage, kein Versand
        rec.assert_called_once_with('sms', 3.5)

    def test_unlesbare_antwort_und_fehlende_einrichtung(self):
        with mock.patch.object(v, '_sms_call', return_value={'wert': 'viel'}), mock.patch.object(v, '_balance_record') as rec:
            self.assertEqual(v.balance_sms(C), 'seven.io hat kein Guthaben gemeldet.')
        rec.assert_called_once_with('sms', None)
        with self.assertRaises(v.Fehler):
            v.balance_sms(None)

    def test_schluesselpruefung_merkt_auch_das_guthaben(self):
        with mock.patch.object(v, '_sms_call', return_value={'wert': 12}), mock.patch.object(v, '_balance_record') as rec:
            self.assertIn('Guthaben: 12.00 €', v.test_sms(C))
        rec.assert_called_once_with('sms', 12.0)


if __name__ == '__main__':
    unittest.main()
