//go:build windows

package main

import (
	"errors"
	"fmt"
	"os"
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
	// Über ShellExecuteEx starten, nicht über exec.Command: Go verbindet sonst Ein- und Ausgabe des Kindprozesses mit NUL,
	// das Fenster bliebe leer und „Enter drücken“ würde nicht warten.
	code, err := shellStartenUndWarten("powershell.exe", `-NoProfile -ExecutionPolicy Bypass -File "`+dir+`\install-windows.ps1"`, dir)
	if err != nil {
		setupMeldung("PowerShell ließ sich nicht starten: " + err.Error())
	} else if code != 0 {
		setupMeldung(fmt.Sprintf("Die Einrichtung ist mit Fehler %d beendet. Bitte die Meldungen im Fenster bzw. das Protokoll %%TEMP%%\\praxisherz-einrichtung.log prüfen.", code))
	}
	return true
}

// shellExecuteInfo entspricht SHELLEXECUTEINFOW (64 Bit).
type shellExecuteInfo struct {
	cbSize       uint32
	fMask        uint32
	hwnd         uintptr
	lpVerb       *uint16
	lpFile       *uint16
	lpParameters *uint16
	lpDirectory  *uint16
	nShow        int32
	hInstApp     uintptr
	lpIDList     uintptr
	lpClass      *uint16
	hkeyClass    uintptr
	dwHotKey     uint32
	hIcon        uintptr
	hProcess     uintptr
}

var (
	pShellExecuteExW     = shell32.NewProc("ShellExecuteExW")
	pWaitForSingleObject = kernel32.NewProc("WaitForSingleObject")
	pGetExitCodeProcess  = kernel32.NewProc("GetExitCodeProcess")
)

// shellStartenUndWarten startet ein Programm mit eigenem, sichtbarem Fenster und wartet auf sein Ende (Rückgabe: Exit-Code).
func shellStartenUndWarten(datei, parameter, ordner string) (uint32, error) {
	f, _ := syscall.UTF16PtrFromString(datei)
	p, _ := syscall.UTF16PtrFromString(parameter)
	d, _ := syscall.UTF16PtrFromString(ordner)
	info := shellExecuteInfo{fMask: 0x40 /* SEE_MASK_NOCLOSEPROCESS */, lpFile: f, lpParameters: p, lpDirectory: d, nShow: 1}
	info.cbSize = uint32(unsafe.Sizeof(info))
	if r, _, e := pShellExecuteExW.Call(uintptr(unsafe.Pointer(&info))); r == 0 {
		return 0, e
	}
	if info.hProcess == 0 {
		return 0, errors.New("kein Prozess")
	}
	defer pCloseHandle.Call(info.hProcess)
	pWaitForSingleObject.Call(info.hProcess, 0xFFFFFFFF)
	var code uint32
	pGetExitCodeProcess.Call(info.hProcess, uintptr(unsafe.Pointer(&code)))
	return code, nil
}
