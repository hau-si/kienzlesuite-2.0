// kienzle-starter: gemeinsamer Arbeitsplatz-Starter der Kienzle-Suite.
//
// T2med ruft ihn über ein URL-Schema auf, z. B. kienzle-sumup://?kontextId=…&fhirBasisUrl=…&oAuthToken=… oder
// kienzle-signatur://…. Der Starter sucht das Schema in client.json, schickt den Kontext per HTTPS an die dort
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
	dir := configDir()
	arg := urlArgument(os.Args[1:])
	if arg == "" {
		fmt.Fprintln(os.Stderr, "Aufruf: kienzle-starter --url \"kienzle-…://?kontextId=…&fhirBasisUrl=…&oAuthToken=…\"")
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
