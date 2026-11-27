package main

import (
	"os"
	"path/filepath"
)

// ordnerWahl: Seit der Umbenennung in Praxisherz heißen die Ordner am PC „Praxisherz“ statt „Kienzle“. Ein PC, der vorher
// eingerichtet wurde, behält seinen Ordner, bis das Paket neu ausgeführt wird (das zieht die Dateien um). Entscheidend ist,
// wo die Datei „merkmal“ liegt; gibt es sie nirgends, gilt der neue Ordner.
//
// Liegt der Zugang des Agents (agent.json) nur im früheren Ordner, gilt dieser, auch wenn im neuen schon Einstellungen liegen
// (z. B. Paket abgebrochen, bevor der Agent neu angemeldet war): sonst stünde der Agent ohne Zugang da.
func ordnerWahl(neu, frueher, merkmal string) string {
	da := func(o, n string) bool { _, err := os.Stat(filepath.Join(o, n)); return err == nil }
	switch {
	case da(neu, "agent.json"):
		return neu
	case da(frueher, "agent.json"):
		return frueher
	case da(neu, merkmal):
		return neu
	case da(frueher, merkmal):
		return frueher
	}
	return neu
}

// gdtFrueher: Plattformen mit einem früheren GDT-Ordner (Windows: C:\Kienzle\GDT). Solange es ihn gibt, schreibt der Agent die
// Datei zusätzlich dorthin, damit „Akte öffnen“ weiter geht, bis in T2med der neue Ordner eingetragen ist.
type gdtFrueher interface {
	GDTOrdnerFrueher() string
}
