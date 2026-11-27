package main

import (
	"os"
	"path/filepath"
	"testing"
)

// Neuer Ordner „Praxisherz“, früherer „Kienzle“ nur, solange dort die Einrichtung liegt und im neuen noch nicht.
func TestOrdnerWahl(t *testing.T) {
	d := t.TempDir()
	neu, alt := filepath.Join(d, "Praxisherz"), filepath.Join(d, "Kienzle")
	if ordnerWahl(neu, alt, "client.json") != neu {
		t.Fatal("ohne Einrichtung: neuer Ordner")
	}
	os.MkdirAll(alt, 0o755)
	os.WriteFile(filepath.Join(alt, "client.json"), []byte("{}"), 0o600)
	if ordnerWahl(neu, alt, "client.json") != alt {
		t.Fatal("PC von vor der Umbenennung: früherer Ordner")
	}
	os.MkdirAll(neu, 0o755)
	os.WriteFile(filepath.Join(neu, "client.json"), []byte("{}"), 0o600)
	if ordnerWahl(neu, alt, "client.json") != neu {
		t.Fatal("nach dem Umzug: neuer Ordner")
	}
	// Paket abgebrochen: Einstellungen schon im neuen Ordner, Zugang des Agents nur im früheren
	os.WriteFile(filepath.Join(alt, "agent.json"), []byte("{}"), 0o600)
	if ordnerWahl(neu, alt, "client.json") != alt {
		t.Fatal("Zugang nur im früheren Ordner: dort weiterarbeiten")
	}
	os.WriteFile(filepath.Join(neu, "agent.json"), []byte("{}"), 0o600)
	if ordnerWahl(neu, alt, "client.json") != neu {
		t.Fatal("neu angemeldet: neuer Ordner")
	}
}
