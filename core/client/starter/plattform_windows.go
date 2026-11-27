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
	"runtime"
	"strings"
	"syscall"
	"unsafe"
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
	return w.druckerSkript(liste)
}

func (w *windowsPlattform) AlteDrucker(liste []Drucker) ([]string, error) {
	text, err := w.druckerSkript(liste, "-Modus", "alt")
	if err != nil {
		return nil, err
	}
	var namen []string
	if err := json.Unmarshal([]byte(text), &namen); err != nil {
		return nil, fmt.Errorf("alte Drucker: unerwartete Antwort")
	}
	return namen, nil
}

func (w *windowsPlattform) DruckerEntfernen(liste []Drucker, namen []string) (string, error) {
	data, _ := json.Marshal(namen)
	datei := filepath.Join(w.ordner, "drucker-entfernen.json")
	if err := os.WriteFile(datei, data, 0o644); err != nil {
		return "", err
	}
	defer os.Remove(datei)
	return w.druckerSkript(liste, "-Modus", "entfernen", "-Entfernen", datei)
}

// druckerSkript schreibt Liste und Skript in den Datenordner (nur für System und Administratoren beschreibbar) und gibt die letzte
// Zeile der Ausgabe zurück.
func (w *windowsPlattform) druckerSkript(liste []Drucker, extra ...string) (string, error) {
	data, _ := json.Marshal(liste)
	datei := filepath.Join(w.ordner, "drucker.json")
	if err := os.WriteFile(datei, data, 0o644); err != nil {
		return "", err
	}
	skript := filepath.Join(w.ordner, "drucker-agent.ps1")
	if err := os.WriteFile(skript, []byte(druckerSkript), 0o644); err != nil {
		return "", err
	}
	args := append([]string{"-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", skript, "-Liste", datei}, extra...)
	out, err := versteckt(exec.Command("powershell.exe", args...)).CombinedOutput()
	text := strings.TrimSpace(strings.ToValidUTF8(string(out), "?"))
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
	if err := reg("add", `HKLM\Software\Microsoft\Windows\CurrentVersion\Run`, "/v", "KienzleSuite", "/t", "REG_SZ", "/d", `"`+programm+`" --tray`, "/f"); err != nil {
		return err
	}
	return aufgabeSichern()
}

// einzigerDienst: benannter Mutex für alle Sitzungen. Läuft schon ein Agent (z. B. der alte während einer Aktualisierung oder
// ein zweiter Start der Aufgabe), höchstens eine Minute warten; der Mutex wird frei, wenn der andere endet.
func einzigerDienst() bool {
	runtime.LockOSThread() // der Mutex gehört dem Thread, der ihn hält; der Hauptthread bleibt bis zum Ende bestehen
	h, _, _ := pCreateMutexW.Call(0, 0, uintptr(unsafe.Pointer(w16(`Global\KienzleAgentDienst`))))
	if h == 0 {
		return true
	}
	r, _, _ := kernel32.NewProc("WaitForSingleObject").Call(h, 60_000)
	return r == 0 || r == 0x80 // WAIT_OBJECT_0, WAIT_ABANDONED
}

// neuStarten startet das (neue) Programm als Dienst, losgelöst von diesem Prozess; es wartet in einzigerDienst, bis dieser endet.
func neuStarten(exe string) {
	for _, flags := range []uint32{0x01000000 | 0x00000200 | 0x00000008, 0x00000200 | 0x00000008} { // BREAKAWAY_FROM_JOB, NEW_PROCESS_GROUP, DETACHED_PROCESS
		cmd := exec.Command(exe, "--dienst")
		cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: flags}
		if cmd.Start() == nil {
			_ = cmd.Process.Release()
			return
		}
	}
}

// aufgabeSichern: Die geplante Aufgabe startet zusätzlich alle 5 Minuten (läuft der Agent, beendet sich der zweite Start sofort).
// Ältere Einrichtungen hatten nur „beim Systemstart“; dort blieb der Agent nach der ersten Aktualisierung stehen.
const aufgabeSkript = `$t = Get-ScheduledTask -TaskName 'Kienzle-Agent' -ErrorAction Stop
if (!(@($t.Triggers) | Where-Object { $_.Repetition -and $_.Repetition.Interval })) {
    $neu = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 5)
    Set-ScheduledTask -TaskName 'Kienzle-Agent' -Trigger (@($t.Triggers) + $neu) -ErrorAction Stop | Out-Null
    Write-Output 'ergänzt'
}`

func aufgabeSichern() error {
	out, err := versteckt(exec.Command("powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", aufgabeSkript)).CombinedOutput()
	if err != nil {
		return fmt.Errorf("geplante Aufgabe: %v %s", err, strings.TrimSpace(strings.ToValidUTF8(string(out), "?")))
	}
	return nil
}
