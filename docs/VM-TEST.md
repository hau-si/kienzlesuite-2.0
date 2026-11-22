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

## Meilenstein 3 – Firewall (Basis 0.3.0)

Zweites Gerät bereithalten, das **nicht** im freigegebenen Netz liegt (z. B. Telefon im Gäste-WLAN), und eine Konsole an der VM
(Hypervisor), falls man sich doch aussperrt: `sudo nft delete table inet kienzle` hebt die Firewall sofort auf.

1. [ ] Verwaltung → Firewall: vorgeschlagen ist das Netz dieses Arbeitsplatzes, SSH an, „Alles andere gesperrt“.
2. [ ] Ein Netz wählen, in dem dieser Arbeitsplatz nicht liegt: Die Seite lehnt ab („wäre ausgesperrt“).
3. [ ] Laden, **nicht** bestätigen: Nach 2 Minuten ist der vorherige Zustand zurück (`sudo nft list ruleset`).
4. [ ] Laden und bestätigen: Zustand „aktiv“. Vom Gerät außerhalb ist weder 443 noch SSH erreichbar, vom Arbeitsplatz beides.
5. [ ] `sudo nft list table inet kienzle` zeigt nur 443 (+ 22) für das Praxisnetz; `ping` geht weiter.
6. [ ] Neustart der VM: Firewall ist sofort wieder aktiv (`systemctl status kienzle-firewall`).
7. [ ] Firewall ausschalten: Tabelle verschwindet, alles wieder erreichbar.

## Meilenstein 4 – Updater (Basis 0.4.0)

Vorbereitung: Test-Schlüssel erzeugen (`scripts/schluessel-erzeugen kienzle-test`), öffentlichen Teil auf der VM nach
`/etc/kienzle/release-keys/` legen, eine Basis 0.4.1 bauen, signieren und als Release in einem **Test-Fork** veröffentlichen;
auf der VM `KIENZLE_UPDATE_QUELLE=github:<fork>` in `kienzle-update-anwenden.service` und `kienzle-update-pruefen.service` setzen
(`systemctl edit`).

1. [ ] Verwaltung → Updates → „Jetzt nach Updates sehen“: 0.4.1 erscheint mit Notizen, im Menü steht eine 1.
2. [ ] Installieren: Seite ist kurz weg, danach „Aktualisiert auf 0.4.1“; `readlink /opt/kienzle/core/current` zeigt 0.4.1.
3. [ ] Eine 0.4.2 veröffentlichen, deren Signatur von einem fremden Schlüssel stammt: „Signatur ungültig“, nichts geändert.
4. [ ] Eine 0.4.3 mit absichtlich kaputtem Webinterface (z. B. Syntaxfehler in `index.php`): Rücksprung auf 0.4.1, Daten unverändert.
5. [ ] Während einer Installation die VM hart ausschalten; nach dem Start ist der vorherige Stand aktiv (`journalctl -u kienzle-update-start`).
6. [ ] „Zurück auf eine frühere Version“ 0.4.0: läuft, Hinweis auf Datenstand.
7. [ ] Beta-Kanal ohne Freigabe: unsignierte Vorabversion wird nicht angeboten; mit Freigabe angeboten und mit „nicht signiert“ markiert.

## Meilenstein 5 – Netzwerkspeicher und Verbindungen (Basis 0.5.0)

Test-NAS oder zweite VM mit Samba-Freigabe und SFTP; ein Test-SMTP-Konto; wenn möglich ein T2med-Testserver.

1. [ ] Verwaltung → Verbindungen → Ziel (SMB) anlegen, „Verbindung prüfen“: erfolgreich, auf dem NAS bleibt nichts liegen.
2. [ ] Falsches Passwort: verständliche Meldung (z. B. `NT_STATUS_LOGON_FAILURE`).
3. [ ] Ziel (SFTP): öffentlichen Schlüssel von der Seite beim NAS-Benutzer eintragen, „Schlüssel des Servers abrufen“,
       Fingerabdruck mit dem NAS vergleichen, übernehmen, prüfen.
4. [ ] Testauftrag als Modul: `sudo -u ks-beispiel` eine Datei nach `/var/spool/kienzle/ablage/beispiel/` legen (siehe
       `docs/MODUL-SCHNITTSTELLE.md`), nach höchstens 5 Minuten liegt sie auf dem NAS.
5. [ ] E-Mail eintragen, „Anmeldung prüfen“: erfolgreich, es wird keine Nachricht verschickt.
6. [ ] T2med eintragen (mit Zertifikat), „Verbindung prüfen“: CapabilityStatement.
7. [ ] `sudo -u ks-core cat /etc/kienzle/verbindungen/ablage.json` wird verweigert.
