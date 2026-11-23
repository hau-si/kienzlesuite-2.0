#!/usr/bin/env bash
# Alle Tests der Kienzle-Suite. Muss vor jedem Commit grün sein (AGENTS.md).
#   bash tests/run-all.sh
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
# Zwischendateien von Python nicht im Repository ablegen.
PYTHONPYCACHEPREFIX=$(mktemp -d); export PYTHONPYCACHEPREFIX; trap 'rm -rf -- "$PYTHONPYCACHEPREFIX"' EXIT

step() { printf '\n== %s\n' "$*"; }

step 'Versionsangaben'
grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z]+(\.[0-9A-Za-z]+)*)?$' core/VERSION || { echo 'core/VERSION ungültig'; exit 1; }
grep -Eq "^## $(cat core/VERSION)\$" docs/RELEASE.md || { echo "docs/RELEASE.md: Abschnitt für $(cat core/VERSION) fehlt"; exit 1; }
for m in modules/*/; do
  v=$(sed -nE 's/^version = "([^"]+)"$/\1/p' "$m/module.toml" | head -1)
  grep -Eq "^## ${v//./\\.}\$" "$m/RELEASE.md" || { echo "$m: RELEASE.md ohne Abschnitt für $v"; exit 1; }
done

step 'Python: Syntax und Stil'
python_bins=(core/bin/kienzle-module core/bin/kienzle-modul-pruefen core/bin/kienzle-zertifikat core/bin/kienzle-web core/bin/kienzle-firewall core/bin/kienzle-update core/bin/kienzle-verbindungen core/bin/kienzle-ablage core/bin/kienzle-sicherung core/bin/kienzle-ueberblick core/bin/kienzle-drucker core/bin/kienzle-servername scripts/release-bauen scripts/release-signieren)
python3 -B -m py_compile core/lib/kienzle/*.py "${python_bins[@]}" tests/core/*.py tests/integration/*.py
if command -v ruff >/dev/null; then
  ruff check --no-cache --select E,F,W,B --line-length 180 core tests "${python_bins[@]}"
else
  echo 'ruff nicht installiert – Stilprüfung übersprungen.'
fi

step 'PHP: Syntax'
for f in core/php/*.php core/php/modul/*.php core/public/*.php core/bin/kienzle-admin core/bin/kienzle-tresor tests/php/*.php \
         modules/*/src/*.php modules/*/seiten/*.php modules/*/public/*.php modules/*/tests/*.php; do
  [ -f "$f" ] || continue
  php -l "$f" >/dev/null || { php -l "$f"; exit 1; }
done
# Haken und Drucker der Module: je nach Kopfzeile PHP oder Shell.
for f in modules/*/bin/* modules/*/printer/*; do
  case "$(head -c 40 "$f")" in
    '#!/usr/bin/php'*) php -l "$f" >/dev/null || { php -l "$f"; exit 1; } ;;
    '#!/bin/sh'*|'#!/usr/bin/env bash'*|'#!/bin/bash'*) sh -n "$f" ;;
  esac
done
echo 'in Ordnung'

step 'Shell: Syntax'
bash -n tests/run-all.sh installer/install.sh tests/integration/nginx.sh scripts/schluessel-erzeugen
if command -v shellcheck >/dev/null; then shellcheck -S warning tests/run-all.sh installer/install.sh tests/integration/nginx.sh; else echo 'shellcheck nicht installiert – übersprungen.'; fi

step 'Python: Prüfprogramm, Zertifikate, nginx-Vorlage (tests/core)'
python3 -B -m unittest discover -s tests/core

if command -v node >/dev/null; then
  step 'JavaScript: Syntax'
  for f in core/public/assets/core.js modules/*/public/assets/*.js; do node --check "$f"; done
  echo 'in Ordnung'
fi

step 'PHP: Basis (tests/php)'
for t in tests/php/core.php tests/php/passkeys.php tests/php/web.php tests/php/cli.php tests/php/firewall.php tests/php/updates.php tests/php/verbindungen.php tests/php/sicherung.php tests/php/einrichtung.php tests/php/drucker.php tests/php/oidc.php tests/php/server.php tests/php/uhrzeit.php tests/php/module.php; do echo "-- $t"; php "$t"; done

step 'Modul Kasse (modules/sumup/tests)'
for t in critical reader-cancel invoice t2med-invoice letterxpress auto-print settings tax goae-t2med cash print-format mail-mime suite fhir-error; do
  echo "-- $t"; php "modules/sumup/tests/$t.php"
done
grep -qx "$(sed -nE 's/^version = "([^"]+)"$/\1/p' modules/sumup/module.toml | head -1)" modules/sumup/VERSION || { echo 'modules/sumup/VERSION passt nicht zu module.toml'; exit 1; }

step 'Modul Terminzettel (modules/terminzettel/tests)'
php modules/terminzettel/tests/seite.php
(cd modules/terminzettel/tests && python3 -B -m unittest discover -s . -p 'test_*.py')
grep -qx "$(sed -nE 's/^version = "([^"]+)"$/\1/p' modules/terminzettel/module.toml | head -1)" modules/terminzettel/VERSION || { echo 'modules/terminzettel/VERSION passt nicht zu module.toml'; exit 1; }
sh -n modules/terminzettel/printer/kienzle-terminzettel && for h in modules/terminzettel/bin/*; do sh -n "$h"; done

step 'module.toml: alle Module im Repository'
core/bin/kienzle-modul-pruefen --alle modules

printf '\nAlle Tests grün.\n'
