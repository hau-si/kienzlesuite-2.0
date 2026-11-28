import asyncio, pathlib, sys
from playwright.async_api import async_playwright
# Bildschirmfotos aller Seiten mit erfundenen Daten (Aufruf durch scripts/bildschirmfotos.sh): python3 scripts/bildschirmfotos-seiten.py <Zielordner> <Ordner der Basis-Seiten> [Name …]
# Benötigt Playwright für Python und Chrome/Chromium. Kasse läuft auf Port 8099, Terminzettel, Signatur, QM, Fax, Telefon und Homepage auf 8098 (Vorschau-Server des Skripts).
import os
OUT = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else 'docs/screenshots')
B = 'file://' + (sys.argv[2] if len(sys.argv) > 2 else '/tmp/basis').rstrip('/') + '/'
K = 'http://127.0.0.1:8099'
M = 'http://127.0.0.1:8098'
shots = [
  ('anmelden', B+'anmelden.html', 1200, 640), ('start', B+'start.html', 1200, 760), ('einrichtung', B+'einrichtung.html', 1200, 1150),
  ('starter', B+'starter.html', 1200, 1000), ('verbindungen', B+'verbindungen.html', 1200, 1250), ('updates', B+'updates.html', 1200, 700),
  ('benutzer', B+'benutzer.html', 1200, 760), ('gruppen', B+'gruppen.html', 1200, 1250), ('konto', B+'konto.html', 1200, 1200),
  ('kasse-start', K+'/kasse/', 1280, 860), ('kasse-vorgaenge', K+'/kasse/vorgaenge', 1280, 760), ('kasse-einstellungen', K+'/kasse/einstellungen', 1280, 1100),
  ('kasse-ablage', K+'/kasse/einstellungen#praxis', 1100, 900),
  ('kasse-igel', K+'/kasse/', 1280, 1000), ('kasse-goae', K+'/kasse/', 1280, 1000), ('weg-der-rechnung', None, 1200, 790),
  ('terminzettel', M+'/terminzettel/', 1280, 1720), ('signatur-vorgaenge', M+'/signatur/', 1280, 900), ('signatur-pads', M+'/signatur/einstellungen', 1280, 1250), ('signatur-software', M+'/signatur/einstellungen', 1280, 1150),
  ('signatur-editor', M+'/signatur/editor', 1440, 1000),
  ('qm-lesemappe', M+'/qm/?als=carla', 1280, 820), ('qm-handbuch', M+'/qm/handbuch?d=2', 1440, 1000), ('qm-dokument', M+'/qm/dokument/2?als=carla', 1280, 900),
  ('qm-editor', M+'/qm/dokument/2?bearbeiten=1', 1280, 1100), ('qm-import', M+'/qm/import', 1280, 860),
  ('fax-senden', M+'/fax/', 1280, 980), ('fax-eingang', M+'/fax/eingang', 1280, 560),
  ('telefon-vorgaenge', M+'/telefon/vorgaenge', 1280, 1000), ('telefon-statistik', M+'/telefon/statistik', 1280, 1130),
  ('telefon-anlage', M+'/telefon/', 1280, 860),
  ('homepage-mitteilungen', M+'/homepage/', 1440, 900), ('homepage-praxen', M+'/homepage/praxen', 1280, 640),
]

# Schaubild „Weg der Rechnung“: drei Wege (T2med-Verbindungsklick, Rechnung drucken, IGeL-Modul) → Kassieren → Netzwerkordner
FARBEN = {'p': ('#EEEDFE', '#534AB7', '#3C3489'), 't': ('#E1F5EE', '#0F6E56', '#085041'), 'g': ('#F1EFE8', '#5F5E5A', '#444441')}
def kasten(x, y, w, h, f, titel, zeilen):
    fill, stroke, text = FARBEN[f]
    s = f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="8" fill="{fill}" stroke="{stroke}" stroke-width="1"/>'
    mitte = y + h / 2 - (len(zeilen) * 8)
    s += f'<text x="{x + w / 2}" y="{mitte}" text-anchor="middle" dominant-baseline="central" font-weight="600" font-size="14" fill="{text}">{titel}</text>'
    for i, z in enumerate(zeilen):
        s += f'<text x="{x + w / 2}" y="{mitte + 20 + i * 16}" text-anchor="middle" dominant-baseline="central" font-size="12" fill="{stroke}">{z}</text>'
    return s
def pfeil(x1, y1, x2, y2): return f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="#5F5E5A" stroke-width="1.5" marker-end="url(#a)"/>'
def wege_html():
    z = [('Weg 1: Verbindungsklick', 52, [('p', 'T2med', ['Knopf anklicken']), ('t', 'Starter am PC', ['ein Schlüssel']), ('t', 'Kasse öffnet', ['mit Patient'])]),
         ('Weg 2: Rechnung drucken', 152, [('p', 'T2med', ['Rechnung drucken']), ('t', 'Drucker vKasse', ['PDF kommt an']), ('t', 'Kasse: Eingang', ['liest Betrag, Nr.'])]),
         ('Weg 3: IGeL-Modul', 252, [('t', 'IGeL-Modul', ['Schnellkasse']), ('t', 'Leistung', ['aus GOÄ-Liste']), ('t', 'Rechnung', ['Endpreis, Faktor'])])]
    svg = '<defs><marker id="a" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M2 1L8 5L2 9" fill="none" stroke="#5F5E5A" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></marker></defs>'
    for titel, y, boxen in z:
        svg += f'<text x="40" y="{y - 12}" font-size="12" fill="#5F5E5A">{titel}</text>'
        for i, (f, t, zeilen) in enumerate(boxen):
            svg += kasten(40 + i * 154, y, 130, 56, f, t, zeilen)
            if i < 2: svg += pfeil(40 + i * 154 + 132, y + 28, 40 + (i + 1) * 154 - 2, y + 28)
        svg += pfeil(480, y + 28, 500, y + 28)
    svg += kasten(502, 52, 130, 256, 't', 'Kassieren', ['Karte, bar oder', 'Überweisung'])
    svg += pfeil(567, 310, 567, 346) + kasten(502, 348, 130, 56, 'g', 'Netzwerkordner', ['Rechnungen/Jahr'])
    svg += '<text x="484" y="380" text-anchor="end" font-size="12" fill="#5F5E5A">PDF landet im Ordner</text>'
    return f'<html><body style="margin:0;background:#fff;font-family:Inter,sans-serif"><svg xmlns="http://www.w3.org/2000/svg" width="1200" viewBox="0 0 680 420" style="display:block">{svg}</svg></body></html>'
JS = {
  'terminzettel': "document.querySelectorAll('main p.fehler').forEach(e=>{if(/Bondrucker/.test(e.textContent))e.remove()});document.querySelectorAll('details.fold').forEach((d,i)=>{if(i<2)d.open=true})",
  'signatur-pads': "document.querySelectorAll('main details').forEach(d=>{d.open=/Unterschriftenpads/.test(d.querySelector('summary').textContent)})",
  'signatur-software': "document.querySelectorAll('main details').forEach(d=>{d.open=/Pad-Software/.test(d.querySelector('summary').textContent)})",
}
async def main():
    only = sys.argv[3:]
    OUT.mkdir(parents=True, exist_ok=True)
    async with async_playwright() as p:
        b = await p.chromium.launch(executable_path=next(c for c in ('/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser') if os.path.exists(c)), args=['--no-proxy-server', '--no-sandbox'])
        for name, url, w, h in shots:
            if only and name not in only: continue
            pg = await b.new_page(viewport={'width': w, 'height': h})
            if name == 'weg-der-rechnung':
                await pg.set_content(wege_html()); await pg.wait_for_timeout(500); await pg.screenshot(path=str(OUT / (name + '.png')), full_page=True); await pg.close(); print(name); continue
            await pg.goto(url, wait_until='networkidle'); await pg.wait_for_timeout(1500)
            if name in ('kasse-igel', 'kasse-goae'):
                await pg.click('#free-entry'); await pg.wait_for_timeout(1500)
                f = next(x for x in pg.frames if 'kassieren' in x.url)
                if name == 'kasse-igel':
                    boxen = pg.frame_locator('iframe').locator('#services input[type=checkbox]'); await boxen.nth(1).check(); await boxen.nth(2).check()
                else:
                    await f.evaluate("document.getElementById('manage-toggle').click()"); await pg.wait_for_timeout(1500)
                    await f.fill('#service-label', 'Ultraschall Schilddrüse'); await f.evaluate("document.getElementById('goae-fields').open=true")
                    await f.fill('#service-goae', '410'); await pg.wait_for_timeout(1200)
                    await f.get_by_text('Einfachsatz und Rahmen übernehmen').click(); await pg.wait_for_timeout(600)
                    await f.fill('#service-target', '30,00'); await f.dispatch_event('#service-target', 'input'); await pg.wait_for_timeout(900)
                    await f.evaluate("document.getElementById('service-base').scrollIntoView({block:'start'})")
                await pg.wait_for_timeout(700)
            if name in JS: await pg.evaluate(JS[name]); await pg.wait_for_timeout(1200)
            if name == 'kasse-ablage':
                await pg.evaluate("document.querySelectorAll('details').forEach(d=>{if(d.querySelector('#s-invoice_export')||d.id==='s-invoice_export')d.open=true})")
                await pg.evaluate("(()=>{const c=document.querySelector('#s-invoice_export input[type=checkbox]'); if(c&&!c.checked){c.click();}})()"); await pg.evaluate("(()=>{const s=document.querySelector('#s-invoice_export select[name=when]'); if(s){s.value='received'; s.dispatchEvent(new Event('change',{bubbles:true}));}})()"); await pg.wait_for_timeout(600)
                loc = pg.locator('#s-invoice_export'); await loc.scroll_into_view_if_needed(); await loc.screenshot(path=str(OUT / (name + '.png')))
            else:
                await pg.screenshot(path=str(OUT / (name + '.png')), full_page=False)
            await pg.close(); print(name)
        await b.close()
asyncio.run(main())
