# Prüfliste für die Test-VM (Ubuntu Server 26.04.1)

Nur auf einer **Test-VM**, nie auf dem Praxisserver. Nur erfundene Namen und Konten verwenden.
Ergebnis je Punkt hier abhaken und in `docs/RELEASE.md` bei der Version vermerken.

## Vorbereitung

- VM: 2 Kerne, 4 GB RAM, 100 GB, Standard-ISO Ubuntu Server 26.04.1, OpenSSH-Server.
- Ein Name im Praxisnetz, der auf die VM zeigt (Router-DNS oder Hosts-Datei der Arbeitsplätze), z. B. `kienzle-test.praxis.internal`.
- Zwei Arbeitsplätze zum Testen: Windows 11 (Edge/Chrome) und macOS (Safari), ein Telefon mit Authenticator-App.

## Meilenstein 2 – Anmeldung, Benutzer, Rechte, OTP, Passkeys, Zertifikat (Basis 0.2.0)

1. [ ] `git clone https://github.com/hau-si/kienzlesuite-2.0 && cd kienzlesuite-2.0`
       `sudo bash installer/install.sh --name kienzle-test.praxis.internal --name <IP der VM> --praxis "Testpraxis"` läuft ohne Fehler;
       am Ende stehen Adresse, Fingerabdruck der CA und ein Einrichtungslink.
2. [ ] `systemctl status kienzle-core-php nginx` aktiv; `systemctl status php*-fpm` (Distribution) aus.
3. [ ] `https://<server>/ca.crt` auf Windows herunterladen, unter „Vertrauenswürdige Stammzertifizierungsstellen“ (Computer) einspielen;
       Fingerabdruck (SHA-256) mit der Ausgabe des Installers vergleichen. Danach keine Zertifikatswarnung, weder über den Namen noch über die IP.
4. [ ] Dasselbe auf macOS (Schlüsselbundverwaltung → System → „Immer vertrauen“).
5. [ ] **Namensbeschränkung:** Auf der VM `sudo openssl x509 -in /etc/kienzle/ca/ca.crt -noout -ext nameConstraints` zeigt nur den
       eigenen Namen und private Netze.
6. [ ] Einrichtungslink öffnen, ersten Admin anlegen, OTP-App per QR-Code einrichten, Wiederherstellungscodes drucken.
7. [ ] Abmelden, neu anmelden mit Passwort + Code; dann einmal mit einem Wiederherstellungscode (Zähler sinkt auf 9).
8. [ ] Passkey hinzufügen (Windows Hello bzw. Touch ID), abmelden, „Mit Passkey anmelden“ – ohne Passwort und Code.
       Über die IP-Adresse erscheint der Hinweis, dass Passkeys nur unter dem Namen funktionieren.
9. [ ] Benutzer „test-mitarbeiter“ anlegen, Einrichtungslink im anderen Browser öffnen, Passwort setzen; Anmeldung ohne OTP.
       Unter „Benutzer“ ist die Verwaltung für ihn nicht erreichbar (403).
10. [ ] 5 falsche Passwörter von einem Arbeitsplatz: Sperre; vom anderen Arbeitsplatz klappt die Anmeldung weiter.
        `sudo kienzle-admin entsperren` hebt die Sperre auf.
11. [ ] „Dieses Gerät merken“ an einem Arbeitsplatz; Browser schließen und öffnen: noch angemeldet. Unter „Mein Konto“ abmelden.
12. [ ] Admin-Zugang verloren: `sudo kienzle-admin zuruecksetzen <name>` gibt einen Link aus; damit Passwort und OTP neu einrichten.
13. [ ] `sudo bash installer/install.sh` ein zweites Mal: Fingerabdruck der CA unverändert, Anmeldung und Konten bleiben.
14. [ ] `sudo systemctl start kienzle-zertifikat.service` läuft ohne Fehler (nichts zu erneuern).
15. [ ] Neustart der VM: alles kommt von selbst wieder hoch.
