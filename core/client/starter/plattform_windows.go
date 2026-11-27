//go:build windows

package main

import (
	_ "embed"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
)

//go:embed drucker-agent.ps1
var druckerSkript string

// windowsPlattform: Aufrufe in HKLM (für alle Benutzer), Zertifizierungsstelle im Speicher des Computers, Drucker per PowerShell.
// Läuft als SYSTEM (geplante Aufgabe „Kienzle-Agent“).
type windowsPlattform struct{ ordner string }

func neuePlattform(ordner string) Plattform { return &windowsPlattform{ordner: ordner} }

// datenOrdner: C:\ProgramData\Kienzle (client.json, ca.crt, agent.json).
func datenOrdner() string {
	if p := os.Getenv("ProgramData"); p != "" {
		return filepath.Join(p, "Kienzle")
	}
	return `C:\ProgramData\Kienzle`
}

func versteckt(cmd *exec.Cmd) *exec.Cmd {
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
	return cmd
}

func reg(args ...string) error {
	out, err := versteckt(exec.Command("reg.exe", args...)).CombinedOutput()
	if err != nil {
		return errors.New(strings.TrimSpace(string(out)))
	}
	return nil
}

func (w *windowsPlattform) Schemata(kontexte map[string]string, programm string) error {
	befehl := `"` + programm + `" --url "%1"`
	for s := range kontexte {
		if !schemeRe.MatchString(s) {
			continue
		}
		k := `HKLM\Software\Classes\` + s
		if err := reg("add", k, "/ve", "/d", "URL:"+s, "/f"); err != nil {
			return err
		}
		if err := reg("add", k, "/v", "URL Protocol", "/d", "", "/f"); err != nil {
			return err
		}
		if err := reg("add", k+`\shell\open\command`, "/ve", "/d", befehl, "/f"); err != nil {
			return err
		}
	}
	return nil
}

func (w *windowsPlattform) Zertifizierungsstelle(pem []byte) error {
	datei := filepath.Join(w.ordner, "ca.crt")
	out, err := versteckt(exec.Command("certutil.exe", "-addstore", "-f", "Root", datei)).CombinedOutput()
	if err != nil {
		return errors.New(strings.TrimSpace(string(out)))
	}
	return nil
}

func (w *windowsPlattform) Drucker(liste []Drucker) (string, error) {
	if len(liste) == 0 {
		return "", nil
	}
	data, _ := json.Marshal(liste)
	datei := filepath.Join(w.ordner, "drucker.json")
	if err := os.WriteFile(datei, data, 0o644); err != nil {
		return "", err
	}
	skript := filepath.Join(w.ordner, "drucker-agent.ps1")
	if err := os.WriteFile(skript, []byte(druckerSkript), 0o644); err != nil {
		return "", err
	}
	out, err := versteckt(exec.Command("powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", skript, "-Liste", datei)).CombinedOutput()
	text := strings.TrimSpace(string(out))
	if i := strings.LastIndex(text, "\n"); i >= 0 {
		text = strings.TrimSpace(text[i+1:])
	}
	if err != nil {
		return "", errors.New(text)
	}
	return text, nil
}

// GDTOrdner: fester Ordner C:\Kienzle\GDT; Benutzer dürfen darin ändern (T2med liest und löscht die Datei).
func (w *windowsPlattform) GDTOrdner() string {
	ordner := `C:\Kienzle\GDT`
	if _, err := os.Stat(ordner); err != nil {
		_ = os.MkdirAll(ordner, 0o775)
		_ = versteckt(exec.Command("icacls.exe", ordner, "/grant", "*S-1-5-32-545:(OI)(CI)M")).Run()
	}
	return ordner
}

// Symbol: Symbol in der Taskleiste beim Anmelden jedes Benutzers starten (HKLM\…\Run) und den Ordner „anstoss“ anlegen, in den
// Benutzer für „Drucker prüfen“ eine leere Datei legen dürfen (sonst ist der Datenordner für Benutzer nur lesbar).
func (w *windowsPlattform) Symbol(programm string) error {
	ordner := filepath.Join(w.ordner, "anstoss")
	if _, err := os.Stat(ordner); err != nil {
		if err := os.MkdirAll(ordner, 0o775); err != nil {
			return err
		}
		if out, err := versteckt(exec.Command("icacls.exe", ordner, "/grant", "*S-1-5-32-545:(OI)(CI)M")).CombinedOutput(); err != nil {
			return fmt.Errorf("Rechte für %s: %v %s", ordner, err, strings.TrimSpace(string(out)))
		}
	}
	return reg("add", `HKLM\Software\Microsoft\Windows\CurrentVersion\Run`, "/v", "KienzleSuite", "/t", "REG_SZ", "/d", `"`+programm+`" --tray`, "/f")
}
