package main

import (
	"archive/zip"
	"bytes"
	"os"
	"path/filepath"
	"testing"
)

func paketDatei(t *testing.T, programm []byte, dateien map[string]string) string {
	t.Helper()
	var b bytes.Buffer
	w := zip.NewWriter(&b)
	for n, inhalt := range dateien {
		f, _ := w.Create(n)
		f.Write([]byte(inhalt))
	}
	w.Close()
	p := filepath.Join(t.TempDir(), "Praxisherz-Setup.exe")
	os.WriteFile(p, append(append([]byte{}, programm...), b.Bytes()...), 0o700)
	return p
}

// Setup = Programm + angehängtes Paket: entpacken nur flacher Dateien; gewöhnliches Programm ohne Paket bleibt Starter.
func TestSetupPaket(t *testing.T) {
	exe := paketDatei(t, bytes.Repeat([]byte("MZ"), 4000), map[string]string{"install-windows.ps1": "Write-Host 1", "client.json": "{}"})
	zr, f, err := setupPaket(exe)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	ziel := t.TempDir()
	if err := paketEntpacken(zr, ziel); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(filepath.Join(ziel, "install-windows.ps1")); string(b) != "Write-Host 1" {
		t.Fatalf("Inhalt: %q", b)
	}
	ohne := filepath.Join(t.TempDir(), "praxisherz-starter.exe")
	os.WriteFile(ohne, []byte("MZ nur Programm"), 0o700)
	if _, _, err := setupPaket(ohne); err == nil {
		t.Fatal("Programm ohne Paket als Setup erkannt")
	}
	fremd := paketDatei(t, []byte("MZ"), map[string]string{"liesmich.txt": "x"})
	if _, _, err := setupPaket(fremd); err == nil {
		t.Fatal("Paket ohne install-windows.ps1 angenommen")
	}
	boese := paketDatei(t, []byte("MZ"), map[string]string{"install-windows.ps1": "x", "../../boese.ps1": "x"})
	zr2, f2, err := setupPaket(boese)
	if err != nil {
		t.Fatal(err)
	}
	defer f2.Close()
	if err := paketEntpacken(zr2, t.TempDir()); err == nil {
		t.Fatal("Pfad im Paket angenommen")
	}
}
