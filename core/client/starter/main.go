// praxisherz-starter: gemeinsamer Arbeitsplatz-Starter von Praxisherz.
//
// T2med ruft ihn über ein URL-Schema auf, z. B. praxisherz-kasse://?kontextId=…&fhirBasisUrl=…&oAuthToken=… oder
// praxisherz-signatur://…. Der Starter sucht das Schema in client.json, schickt den Kontext per HTTPS an die dort
// eingetragene Adresse des Moduls (mit dem Starter-Schlüssel), öffnet den zurückgegebenen einmaligen Link im Browser
// und beendet sich. Es läuft kein Dienst. Das Serverzertifikat wird über den Fingerabdruck aus client.json geprüft.
package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
)

func main() {
	// Praxisherz-Setup.exe (Programm mit angehängtem Paket) per Doppelklick: PC einrichten.
	if len(os.Args) == 1 && setupStart() {
		return
	}
	if len(os.Args) == 2 {
		switch os.Args[1] {
		case "--version":
			fmt.Println("praxisherz-starter", version, assetName)
			return
		case "--anmelden":
			// Einmal bei der Einrichtung (als Administrator): Agent mit dem Starter-Schlüssel anmelden, agent.json schreiben.
			ac, err := Anmelden(datenOrdner(), nil)
			if err != nil {
				fmt.Fprintln(os.Stderr, "Fehler:", err)
				os.Exit(1)
			}
			fmt.Println("Angemeldet als Arbeitsplatz", ac.Name)
			return
		case "--tray":
			// Symbol in der Taskleiste mit Statusfenster (beim Anmelden gestartet, HKLM\…\Run).
			if err := tray(datenOrdner()); err != nil {
				fmt.Fprintln(os.Stderr, "Fehler:", err)
				os.Exit(1)
			}
			return
		case "--dienst":
			// Dauerbetrieb (geplante Aufgabe „Praxisherz-Agent“ mit Systemrechten). Nur ein Agent je PC: ein zweiter Start wartet
			// höchstens eine Minute, bis der erste endet (nach einer Aktualisierung), sonst beendet er sich.
			if !einzigerDienst() {
				return
			}
			exe, _ := os.Executable()
			_ = os.Remove(exe + ".alt")
			if reste, _ := filepath.Glob(exe + ".alt-*"); len(reste) > 0 { // Ausweichnamen früherer Aktualisierungen
				for _, r := range reste {
					_ = os.Remove(r)
				}
			}
			a := &Agent{Ordner: datenOrdner(), Programm: exe, Plattform: neuePlattform(datenOrdner())}
			if err := a.Dienst(nil); err != nil {
				// Neues Programm eingespielt: selbst neu starten. Die geplante Aufgabe startet bei einem Ende mit Fehlercode
				// nicht neu (das hielt Agenten nach der ersten Aktualisierung an); zur Sicherheit startet sie zusätzlich alle 5 Minuten.
				neuStarten(exe)
				os.Exit(3)
			}
			return
		}
	}
	dir := configDir()
	if _, err := os.Stat(filepath.Join(datenOrdner(), "client.json")); err == nil {
		dir = datenOrdner() // eingerichtet mit Agent: Einstellungen im gemeinsamen Datenordner
	}
	arg := urlArgument(os.Args[1:])
	if arg == "" {
		fmt.Fprintln(os.Stderr, "Aufruf: praxisherz-starter --url \"praxisherz-…://?kontextId=…&fhirBasisUrl=…&oAuthToken=…\"")
		os.Exit(2)
	}
	cfg, err := loadConfig(filepath.Join(dir, "client.json"))
	if err == nil {
		var link string
		link, err = run(cfg, arg, nil)
		if err == nil {
			err = openBrowser(link)
		}
	}
	if err != nil {
		logError(dir, err)
		fmt.Fprintln(os.Stderr, "Fehler:", err)
		os.Exit(1)
	}
}

// configDir ist der Ordner des Programms: dort liegen client.json und server.crt.
func configDir() string {
	exe, err := os.Executable()
	if err != nil {
		return "."
	}
	return filepath.Dir(exe)
}

func urlArgument(args []string) string {
	for i, a := range args {
		if a == "--url" && i+1 < len(args) {
			return args[i+1]
		}
	}
	if len(args) == 1 {
		return args[0]
	}
	return ""
}

func openBrowser(link string) error {
	var cmd *exec.Cmd
	switch runtime.GOOS {
	case "windows":
		cmd = exec.Command("rundll32", "url.dll,FileProtocolHandler", link)
	case "darwin":
		cmd = exec.Command("open", link)
	default:
		cmd = exec.Command("xdg-open", link)
	}
	return cmd.Start()
}

// logError schreibt den Fehler (ohne Zugangsdaten) in starter.log neben das Programm.
func logError(dir string, err error) {
	f, e := os.OpenFile(filepath.Join(dir, "starter.log"), os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600)
	if e != nil {
		return
	}
	defer f.Close()
	fmt.Fprintf(f, "%s %v\n", nowString(), err)
}
