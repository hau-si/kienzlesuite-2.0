package main

import (
	"os"
	"path/filepath"
)

// ordnerWahl: Seit der Umbenennung in Praxisherz heißen die Ordner am PC „Praxisherz“ statt „Kienzle“. Ein PC, der vorher
// eingerichtet wurde, behält seinen Ordner, bis das Paket neu ausgeführt wird (das zieht die Dateien um). Entscheidend ist,
// wo die Datei „merkmal“ liegt; gibt es sie nirgends, gilt der neue Ordner.
func ordnerWahl(neu, frueher, merkmal string) string {
	if _, err := os.Stat(filepath.Join(neu, merkmal)); err == nil {
		return neu
	}
	if _, err := os.Stat(filepath.Join(frueher, merkmal)); err == nil {
		return frueher
	}
	return neu
}

// gdtFrueher: Plattformen mit einem früheren GDT-Ordner (Windows: C:\Kienzle\GDT). Solange es ihn gibt, schreibt der Agent die
// Datei zusätzlich dorthin, damit „Akte öffnen“ weiter geht, bis in T2med der neue Ordner eingetragen ist.
type gdtFrueher interface {
	GDTOrdnerFrueher() string
}
