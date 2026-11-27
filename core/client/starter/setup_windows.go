//go:build windows

package main

import (
	"fmt"
	"os"
	"os/exec"
	"syscall"
	"unsafe"
)

// shell32 und user32 kommen aus tray_windows.go.
var (
	pIsUserAnAdmin    = shell32.NewProc("IsUserAnAdmin")
	pShellExecuteSetW = shell32.NewProc("ShellExecuteW")
	pMessageBoxSetupW = user32.NewProc("MessageBoxW")
)

func setupMeldung(text string) {
	t, _ := syscall.UTF16PtrFromString(text)
	k, _ := syscall.UTF16PtrFromString("Praxisherz-Setup")
	pMessageBoxSetupW.Call(0, uintptr(unsafe.Pointer(t)), uintptr(unsafe.Pointer(k)), 0x10)
}

// setupStart: Läuft das Programm als Praxisherz-Setup.exe (Paket angehängt, ohne Argumente gestartet), richtet es den PC ein:
// erst mit Administratorrechten neu starten (Windows fragt), dann Paket entpacken und install-windows.ps1 im eigenen Fenster
// ausführen. Liefert false für das gewöhnliche Starterprogramm.
func setupStart() bool {
	exe, err := os.Executable()
	if err != nil {
		return false
	}
	zr, f, err := setupPaket(exe)
	if err != nil {
		return false
	}
	defer f.Close()
	if r, _, _ := pIsUserAnAdmin.Call(); r == 0 {
		verb, _ := syscall.UTF16PtrFromString("runas")
		datei, _ := syscall.UTF16PtrFromString(exe)
		if r, _, _ := pShellExecuteSetW.Call(0, uintptr(unsafe.Pointer(verb)), uintptr(unsafe.Pointer(datei)), 0, 0, 1); r <= 32 {
			setupMeldung("Die Einrichtung braucht Administratorrechte.")
		}
		return true
	}
	dir, err := os.MkdirTemp("", "praxisherz-setup-")
	if err != nil {
		setupMeldung("Temp-Ordner nicht anlegbar: " + err.Error())
		return true
	}
	defer os.RemoveAll(dir)
	if err := paketEntpacken(zr, dir); err != nil {
		setupMeldung("Paket nicht lesbar: " + err.Error())
		return true
	}
	cmd := exec.Command("powershell.exe", "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", dir+`\install-windows.ps1`)
	cmd.Dir = dir
	// Eigenes Konsolenfenster (das Programm selbst hat keins): CREATE_NEW_CONSOLE.
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x00000010}
	if err := cmd.Run(); err != nil {
		setupMeldung(fmt.Sprintf("Die Einrichtung ist mit einem Fehler beendet (%v). Bitte die Meldungen im Fenster prüfen oder das Paket erneut laden.", err))
	}
	return true
}
