package main

import (
	"archive/zip"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
)

// Praxisherz-Setup.exe ist das Starterprogramm mit dem angehängten Arbeitsplatz-Paket (ZIP). Doppelklick ohne Argumente:
// Paket in einen Temp-Ordner entpacken und install-windows.ps1 ausführen (setup_windows.go).

var paketName = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,80}$`)

const paketGrenze = 200 << 20 // höchstens 200 MB entpackt

// setupPaket liest das an das Programm angehängte ZIP. Ohne Paket (gewöhnliches Starterprogramm) gibt es einen Fehler.
func setupPaket(exe string) (*zip.Reader, *os.File, error) {
	f, err := os.Open(exe)
	if err != nil {
		return nil, nil, err
	}
	st, err := f.Stat()
	if err != nil {
		f.Close()
		return nil, nil, err
	}
	zr, err := zip.NewReader(f, st.Size())
	if err != nil {
		f.Close()
		return nil, nil, errors.New("kein Paket angehängt")
	}
	for _, d := range zr.File {
		if d.Name == "install-windows.ps1" {
			return zr, f, nil
		}
	}
	f.Close()
	return nil, nil, errors.New("Paket ohne install-windows.ps1")
}

// paketEntpacken: nur flache Dateinamen (keine Ordner, keine Pfade), Größe begrenzt.
func paketEntpacken(zr *zip.Reader, ziel string) error {
	var summe int64
	for _, d := range zr.File {
		if !paketName.MatchString(d.Name) || d.FileInfo().IsDir() {
			return fmt.Errorf("unerwarteter Eintrag im Paket: %q", d.Name)
		}
		summe += int64(d.UncompressedSize64)
		if summe > paketGrenze {
			return errors.New("Paket zu groß")
		}
		rc, err := d.Open()
		if err != nil {
			return err
		}
		out, err := os.OpenFile(filepath.Join(ziel, d.Name), os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o600)
		if err != nil {
			rc.Close()
			return err
		}
		_, err = io.Copy(out, io.LimitReader(rc, paketGrenze))
		rc.Close()
		if cerr := out.Close(); err == nil {
			err = cerr
		}
		if err != nil {
			return err
		}
	}
	return nil
}
