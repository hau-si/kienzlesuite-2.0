#!/usr/bin/env bash
# Alle Tests der Kienzle-Suite. Muss vor jedem Commit grün sein (AGENTS.md).
#   bash tests/run-all.sh
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
# Zwischendateien von Python nicht im Repository ablegen.
PYTHONPYCACHEPREFIX=$(mktemp -d); export PYTHONPYCACHEPREFIX; trap 'rm -rf -- "$PYTHONPYCACHEPREFIX"' EXIT

step() { printf '\n== %s\n' "$*"; }

step 'Versionsangaben'
grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$' core/VERSION || { echo 'core/VERSION ungültig'; exit 1; }
grep -Eq "^## $(cat core/VERSION)\$" docs/RELEASE.md || { echo "docs/RELEASE.md: Abschnitt für $(cat core/VERSION) fehlt"; exit 1; }
for m in modules/*/; do
  v=$(sed -nE 's/^version = "([^"]+)"$/\1/p' "$m/module.toml" | head -1)
  grep -Eq "^## ${v//./\\.}\$" "$m/RELEASE.md" || { echo "$m: RELEASE.md ohne Abschnitt für $v"; exit 1; }
done

step 'Python: Syntax und Stil'
python3 -B -m py_compile core/lib/kienzle/*.py core/bin/kienzle-modul-pruefen tests/core/*.py
if command -v ruff >/dev/null; then
  ruff check --no-cache --select E,F,W,B --line-length 130 core tests
  ruff check --no-cache --select E,F,W,B --line-length 130 core/bin/kienzle-modul-pruefen
else
  echo 'ruff nicht installiert – Stilprüfung übersprungen.'
fi

step 'Shell: Syntax'
bash -n tests/run-all.sh
for f in modules/*/bin/* modules/*/printer/*-backend; do sh -n "$f"; done
if command -v shellcheck >/dev/null; then shellcheck tests/run-all.sh; else echo 'shellcheck nicht installiert – übersprungen.'; fi

step 'module.toml: Prüfprogramm (tests/core)'
python3 -B -m unittest discover -s tests/core

step 'module.toml: alle Module im Repository'
core/bin/kienzle-modul-pruefen --alle modules

printf '\nAlle Tests grün.\n'
