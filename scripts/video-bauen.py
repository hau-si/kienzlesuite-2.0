#!/usr/bin/env python3
"""Erklärvideo Praxisherz aus den Bildschirmfotos (erfundene Daten) bauen.
Aufruf: python3 scripts/video-bauen.py [Bilderordner, Standard docs/screenshots] [Ausgabedatei, Standard Praxisherz-Ueberblick.mp4] [--probe]
Benötigt Pillow und ffmpeg. Jede Szene ist eine eigene Datei (gleiche Größe, Bildrate, Encoder), zusammengesetzt mit dem concat-Demuxer."""
import os, subprocess, sys, tempfile
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

W, H, FPS = 1920, 1080, 30
KOPF = 200            # Höhe der Beschriftung oben
RAND = 40
FLAECHE_W, FLAECHE_H = W - 2 * 180, H - KOPF - RAND - 50
BG, TITEL, TEXT, AKZENT = (238, 243, 241), (28, 62, 55), (84, 108, 102), (16, 107, 92)
FONT = '/usr/share/fonts/opentype/inter/'

# (Bild, Titel, Untertitel, Sekunden)
SZENEN = [
    ('@titel', 'Praxisherz', 'Ein Server für die Praxis: Kasse, Terminzettel, Unterschrift. Alle Bilder zeigen erfundene Beispieldaten.', 6),
    ('anmelden', 'Eine Anmeldung für alle Module', 'Passwort oder Passkey; Admins zusätzlich mit Code aus der Authenticator-App.', 6),
    ('start', 'Start: Einrichtung und Server-Zustand', 'Was noch zu tun ist, dazu Prozessor, Speicher, Festplatte und Laufzeit des Servers.', 7),
    ('einrichtung', 'Einrichtungs-Überblick', 'Jeder Schritt zeigt, wo er erledigt wird: im Webfenster, am Arbeitsplatz oder im Router.', 8),
    ('starter', 'Zentraler Arbeitsplatz-Starter', 'Ein Programm je PC und ein Schlüssel für alle Module. T2med öffnet Kasse und Signatur mit dem aktuellen Patienten.', 8),
    ('verbindungen', 'Verbindungen einmal eintragen', 'T2med, E-Mail, SMS, Letterxpress und der Netzwerkspeicher; Module bekommen nur, was sie anmelden.', 7),
    ('kasse-start', 'Kasse: Rechnungen aus T2med kassieren', 'In T2med auf „vKasse“ drucken, in der Kasse ein Klick: Karte, bar oder Überweisung.', 8),
    ('kasse-vorgaenge', 'Kasse: Vorgänge', 'Tag, Woche, Monat, Jahr mit Filtern, Abhaken in T2med und Excel-Liste für die Steuerberatung.', 8),
    ('weg-der-rechnung', 'Der Weg einer Rechnung', 'Verbindungsklick, Rechnung drucken oder IGeL-Modul: alle Wege enden im Kassieren und, wenn gewünscht, als PDF im Netzwerkordner.', 10),
    ('kasse-igel', 'Kasse: IGeL ohne T2med-Rechnung', 'Leistungen aus der Liste wählen, Name und Geburtsdatum sind optional; die GOÄ-Ziffern kommen aus dem amtlichen Verzeichnis.', 9),
    ('kasse-goae', 'IGeL: Endpreis eintragen, Faktor wird errechnet', 'Ziffer wählen, gewünschten Endbetrag angeben: Die Kasse rechnet den Faktor nach § 5 GOÄ und warnt über dem Schwellenwert.', 10),
    ('kasse-ablage', 'Kasse: PDF im Netzwerkordner', 'Jede gedruckte oder bezahlte Rechnung landet als PDF im Ordner, zum Beispiel Rechnungen/2026. Fehlende Ordner legt die Suite selbst an.', 8),
    ('kasse-einstellungen', 'Kasse: Einstellungen', 'Einrichtung als Ausklappzeilen, Rotes zuerst. Nur Admins sehen Schlüssel und Zugangsdaten.', 7),
    ('terminzettel', 'Terminzettel: groß, lesbar, mit QR-Code', 'Der Zettel aus T2med ist klein und ohne QR-Code. Hier steht er groß auf dem Bondrucker, mit Kalender-QR je Termin.', 10),
    ('signatur-vorgaenge', 'Signatur: Vorgang anlegen', 'IGeL-Vertrag, Aufklärung oder Datenschutz am Unterschriftenpad; der Patient wird aus T2med übernommen.', 8),
    ('signatur-pads', 'Signatur: Unterschriftenpads', 'Ein Pad je Arbeitsplatz als feste Zeile, Pad im Netzwerk suchen und Anleitung für das Servicemenü von Delta und Alpha.', 10),
    ('signatur-software', 'Signatur: Pad-Software von signotec', 'Die Bibliotheken liefern wir nicht mit: ZIP hochladen, danach steht „erledigt“.', 8),
    ('updates', 'Updates mit Sicherung und Rücksprung', 'Kanal stabil oder beta, Schnappschuss vor jedem Update und automatischer Rücksprung bei einem Fehler.', 7),
    ('benutzer', 'Benutzer, Gruppen und Rechte', 'Gruppen sind Rollen mit Rechten je Modul. Sensible Funktionen sehen nur Admins.', 7),
    ('@ende', 'Praxisherz', 'Open Source: Quelltext, Anleitungen und Releases auf GitHub (hau-si/kienzlesuite-2.0).', 6),
]

def schrift(name, px): return ImageFont.truetype(FONT + name, px)

def umbruch(d, text, font, breite):
    zeilen, zeile = [], ''
    for wort in text.split():
        probe = (zeile + ' ' + wort).strip()
        if d.textlength(probe, font=font) <= breite: zeile = probe
        else: zeilen.append(zeile); zeile = wort
    return zeilen + [zeile]

def kopf(titel, untertitel, gross=False):
    """Beschriftung als RGBA-Bild in voller Größe (oben), unten eine feine Fußzeile."""
    im = Image.new('RGBA', (W, H), (0, 0, 0, 0)); d = ImageDraw.Draw(im)
    if gross:
        d.rectangle([0, 0, W, H], fill=BG + (255,))
        d.rounded_rectangle([W // 2 - 60, 250, W // 2 + 60, 370], radius=28, fill=AKZENT + (255,))
        d.text((W // 2, 310), 'K', font=schrift('Inter-Bold.otf', 84), fill=(255, 255, 255, 255), anchor='mm')
        d.text((W // 2, 470), titel, font=schrift('Inter-Bold.otf', 96), fill=TITEL + (255,), anchor='mm')
        f = schrift('Inter-Regular.otf', 38)
        for i, z in enumerate(umbruch(d, untertitel, f, 1300)): d.text((W // 2, 570 + i * 54), z, font=f, fill=TEXT + (255,), anchor='mm')
        return im
    d.rectangle([0, 0, W, KOPF], fill=BG + (255,))
    d.rectangle([0, H - 50, W, H], fill=BG + (255,))
    d.rounded_rectangle([180, 38, 180 + 8, 38 + 62], radius=4, fill=AKZENT + (255,))
    d.text((210, 36), titel, font=schrift('Inter-Bold.otf', 52), fill=TITEL + (255,))
    f = schrift('Inter-Regular.otf', 30)
    for i, z in enumerate(umbruch(d, untertitel, f, W - 420)[:2]): d.text((210, 102 + i * 38), z, font=f, fill=TEXT + (255,))
    d.text((W - 180, H - 25), 'Praxisherz · erfundene Beispieldaten', font=schrift('Inter-Regular.otf', 22), fill=TEXT + (255,), anchor='rm')
    return im

def seite(png):
    """Bildschirmfoto auf die Breite der Fläche skalieren (mit feinem Rahmen); Rückgabe: Bild."""
    im = Image.open(png).convert('RGB')
    s = FLAECHE_W / im.width
    if im.width * s > FLAECHE_W: s = FLAECHE_W / im.width
    # Schmale Bilder nicht über 1,35-fach aufblasen
    s = min(s, 1.35)
    return im.resize((round(im.width * s), round(im.height * s)), Image.LANCZOS)

def szene(i, bild, titel, untertitel, sek, ordner, tmp, ziel):
    over = tmp / f'k{i:02d}.png'
    if bild.startswith('@'):
        kopf(titel, untertitel, gross=True).save(over)
        cmd = ['ffmpeg', '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', f'color=c=0xeef3f1:s={W}x{H}:r={FPS}:d={sek}', '-i', str(over),
               '-filter_complex', f'[0][1]overlay=format=auto,fade=t=in:st=0:d=0.6,fade=t=out:st={sek - 0.6}:d=0.6,format=yuv420p']
    else:
        kopf(titel, untertitel).save(over)
        s = seite(ordner / f'{bild}.png'); sp = tmp / f's{i:02d}.png'; s.save(sp)
        sichtbar = min(s.height, FLAECHE_H)
        x = (W - s.width) // 2; y = KOPF + (FLAECHE_H - sichtbar) // 2 + 0
        if s.height > FLAECHE_H:
            # Hohe Seiten: langsam von oben nach unten schieben (erste und letzte Sekunde stehen)
            dy = s.height - FLAECHE_H
            pos = f"'if(lt(t,1),0,if(gt(t,{sek - 1}),{dy},{dy}*(t-1)/({sek - 2})))'"
            vor = f"[1]crop={s.width}:{FLAECHE_H}:0:{pos}[b]"
        else:
            vor = f"[1]null[b]"
        cmd = ['ffmpeg', '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', f'color=c=0xeef3f1:s={W}x{H}:r={FPS}:d={sek}', '-loop', '1', '-framerate', str(FPS), '-t', str(sek), '-i', str(sp), '-i', str(over),
               '-filter_complex', f'{vor};[0][b]overlay={x}:{y}[c];[c][2]overlay=format=auto,fade=t=in:st=0:d=0.4,fade=t=out:st={sek - 0.4}:d=0.4,format=yuv420p']
    tmpdatei = ziel / f'.szene{i:02d}.tmp.mp4'
    cmd += ['-r', str(FPS), '-c:v', 'libx264', '-crf', '20', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-t', str(sek), str(tmpdatei)]
    subprocess.run(cmd, check=True)
    fertig = ziel / f'szene{i:02d}.mp4'; tmpdatei.rename(fertig)
    return fertig

def main():
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    ordner = Path(args[0] if args else 'docs/screenshots'); ausgabe = Path(args[1] if len(args) > 1 else 'Praxisherz-Ueberblick.mp4')
    probe = '--probe' in sys.argv
    with tempfile.TemporaryDirectory() as t, tempfile.TemporaryDirectory() as z:
        tmp, ziel = Path(t), Path(z)
        szenen = SZENEN[:: max(1, len(SZENEN) // 4)] if probe else SZENEN
        dateien = [szene(i, *s, ordner, tmp, ziel) for i, s in enumerate(szenen)]
        liste = ziel / 'liste.txt'; liste.write_text(''.join(f"file '{p}'\n" for p in dateien))
        tmpaus = ausgabe.with_suffix('.tmp.mp4')
        subprocess.run(['ffmpeg', '-y', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', str(liste), '-c', 'copy', '-movflags', '+faststart', str(tmpaus)], check=True)
        tmpaus.rename(ausgabe)
    print(ausgabe, sum(s[3] for s in SZENEN), 'Sekunden')
main()
