//go:build windows

package main

// Symbol in der Taskleiste unter Windows (Entwurf B): Klick öffnet ein kleines Statusfenster über der Uhr. Läuft als
// angemeldeter Benutzer (Start über HKLM\…\Run, „kienzle-starter --tray“), liest nur status.json des Agents und legt für
// „Drucker prüfen“ eine leere Anstoß-Datei ab. Ohne Zusatzpakete, direkt über user32/shell32/gdi32.

import (
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"time"
	"unsafe"
)

var (
	user32   = syscall.NewLazyDLL("user32.dll")
	shell32  = syscall.NewLazyDLL("shell32.dll")
	gdi32    = syscall.NewLazyDLL("gdi32.dll")
	kernel32 = syscall.NewLazyDLL("kernel32.dll")
	dwmapi   = syscall.NewLazyDLL("dwmapi.dll")

	pPostMessageW           = user32.NewProc("PostMessageW")
	pRegisterClassExW       = user32.NewProc("RegisterClassExW")
	pCreateWindowExW        = user32.NewProc("CreateWindowExW")
	pDefWindowProcW         = user32.NewProc("DefWindowProcW")
	pGetMessageW            = user32.NewProc("GetMessageW")
	pTranslateMessage       = user32.NewProc("TranslateMessage")
	pDispatchMessageW       = user32.NewProc("DispatchMessageW")
	pShowWindow             = user32.NewProc("ShowWindow")
	pSetWindowPos           = user32.NewProc("SetWindowPos")
	pSetForegroundWindow    = user32.NewProc("SetForegroundWindow")
	pSetTimer               = user32.NewProc("SetTimer")
	pInvalidateRect         = user32.NewProc("InvalidateRect")
	pBeginPaint             = user32.NewProc("BeginPaint")
	pEndPaint               = user32.NewProc("EndPaint")
	pFillRect               = user32.NewProc("FillRect")
	pDrawTextW              = user32.NewProc("DrawTextW")
	pSystemParametersInfoW  = user32.NewProc("SystemParametersInfoW")
	pGetSystemMetrics       = user32.NewProc("GetSystemMetrics")
	pCreateIconIndirect     = user32.NewProc("CreateIconIndirect")
	pDestroyIcon            = user32.NewProc("DestroyIcon")
	pLoadCursorW            = user32.NewProc("LoadCursorW")
	pIsWindowVisible        = user32.NewProc("IsWindowVisible")
	pSetProcessDPIAware     = user32.NewProc("SetProcessDPIAware")
	pRegisterWindowMessageW = user32.NewProc("RegisterWindowMessageW")
	pMessageBoxW            = user32.NewProc("MessageBoxW")
	pGetDC                  = user32.NewProc("GetDC")
	pReleaseDC              = user32.NewProc("ReleaseDC")
	pShellNotifyIconW       = shell32.NewProc("Shell_NotifyIconW")
	pCreateSolidBrush       = gdi32.NewProc("CreateSolidBrush")
	pCreatePen              = gdi32.NewProc("CreatePen")
	pDeleteObject           = gdi32.NewProc("DeleteObject")
	pSelectObject           = gdi32.NewProc("SelectObject")
	pSetTextColor           = gdi32.NewProc("SetTextColor")
	pSetBkMode              = gdi32.NewProc("SetBkMode")
	pCreateFontW            = gdi32.NewProc("CreateFontW")
	pEllipse                = gdi32.NewProc("Ellipse")
	pRoundRect              = gdi32.NewProc("RoundRect")
	pCreateDIBSection       = gdi32.NewProc("CreateDIBSection")
	pCreateBitmap           = gdi32.NewProc("CreateBitmap")
	pGetDeviceCaps          = gdi32.NewProc("GetDeviceCaps")
	pCreateMutexW           = kernel32.NewProc("CreateMutexW")
	pCloseHandle            = kernel32.NewProc("CloseHandle")
	pGetModuleHandleW       = kernel32.NewProc("GetModuleHandleW")
	pDwmSetWindowAttribute  = dwmapi.NewProc("DwmSetWindowAttribute")
)

const (
	wmDestroy     = 0x0002
	wmActivate    = 0x0006
	wmPaint       = 0x000F
	wmTimer       = 0x0113
	wmLButtonUp   = 0x0202
	wmRButtonUp   = 0x0205
	wmApp         = 0x8000
	wmTray        = wmApp + 1
	// wmKnopfFertig: eigene Nachricht, mit der ein Hintergrund-Thread (Datei schreiben, Programm starten) dem Fenster
	// das Ergebnis meldet, ohne selbst Win32-Aufrufe zu machen (die sind nur vom Fenster-Thread aus sicher).
	wmKnopfFertig = wmApp + 2
	wsPopup       = 0x80000000
	wsExToolwin   = 0x00000080
	wsExTopmost   = 0x00000008
	swHide        = 0
	swShow        = 5
	nimAdd        = 0
	nimModify     = 1
	nimDelete     = 2
	nifMessage    = 0x1
	nifIcon       = 0x2
	nifTip        = 0x4
	dtLeft        = 0x0
	dtRight       = 0x2
	dtVCenter     = 0x4
	dtSingleLine  = 0x20
	dtWordbreak   = 0x10
	dtEndEllipsis = 0x8000
	dtNoPrefix    = 0x800
)

type wndClassEx struct {
	Size, Style                        uint32
	WndProc                            uintptr
	ClsExtra, WndExtra                 int32
	Instance, Icon, Cursor, Background uintptr
	MenuName, ClassName                *uint16
	IconSm                             uintptr
}

type msg struct {
	Hwnd           uintptr
	Message        uint32
	WParam, LParam uintptr
	Time           uint32
	PtX, PtY       int32
	Private        uint32
}

type rect struct{ L, T, R, B int32 }

type paintStruct struct {
	Hdc       uintptr
	Erase     int32
	Rc        rect
	Restore   int32
	IncUpdate int32
	Reserved  [32]byte
}

type notifyIconData struct {
	Size             uint32
	Wnd              uintptr
	ID               uint32
	Flags            uint32
	CallbackMessage  uint32
	Icon             uintptr
	Tip              [128]uint16
	State, StateMask uint32
	Info             [256]uint16
	Version          uint32
	InfoTitle        [64]uint16
	InfoFlags        uint32
	GUID             [16]byte
	BalloonIcon      uintptr
}

type iconInfo struct {
	FIcon       int32
	XHot, YHot  uint32
	Mask, Color uintptr
}

type bitmapInfoHeader struct {
	Size                   uint32
	Width, Height          int32
	Planes, BitCount       uint16
	Compression, SizeImage uint32
	XPels, YPels           int32
	ClrUsed, ClrImportant  uint32
}

// trayZustand: alles, was das Fenster zum Zeichnen braucht.
type trayZustand struct {
	ordner  string
	hwnd    uintptr
	mutex   uintptr
	nid     notifyIconData
	ansicht Ansicht
	farbe   int
	icon    uintptr
	skala   float64
	knopf   []rect // Suite öffnen, Drucker prüfen, Protokoll, (Alte Drucker entfernen)
	meldung string
	frage   bool // Rückfrage offen: Fenster beim Wechsel zur Rückfrage nicht schließen
}

var (
	tz            *trayZustand
	taskleisteNeu uintptr // „TaskbarCreated“: Explorer neu gestartet, Symbol neu eintragen
)

func w16(s string) *uint16 { p, _ := syscall.UTF16PtrFromString(s); return p }

func rgb(r, g, b uint32) uintptr { return uintptr(r | g<<8 | b<<16) }

func (t *trayZustand) px(v float64) int32 { return int32(v*t.skala + 0.5) }

// tray: Symbol und Fenster; kehrt erst beim Abmelden zurück. Nur eine Instanz je Benutzersitzung.
func tray(ordner string) error {
	pSetProcessDPIAware.Call()
	m, _, err := pCreateMutexW.Call(0, 0, uintptr(unsafe.Pointer(w16(`Local\KienzleAgentSymbol`))))
	if m != 0 && err == syscall.Errno(183) { // ERROR_ALREADY_EXISTS
		return nil
	}
	tz = &trayZustand{ordner: ordner, farbe: -1, skala: 1, mutex: m}
	if dc, _, _ := pGetDC.Call(0); dc != 0 {
		if dpi, _, _ := pGetDeviceCaps.Call(dc, 90); dpi > 0 { // LOGPIXELSY
			tz.skala = float64(dpi) / 96
		}
		pReleaseDC.Call(0, dc)
	}
	inst, _, _ := pGetModuleHandleW.Call(0)
	cursor, _, _ := pLoadCursorW.Call(0, 32512)
	wc := wndClassEx{WndProc: syscall.NewCallback(trayProc), Instance: inst, Cursor: cursor, ClassName: w16("KienzleAgentSymbol")}
	wc.Size = uint32(unsafe.Sizeof(wc))
	pRegisterClassExW.Call(uintptr(unsafe.Pointer(&wc)))
	tz.hwnd, _, _ = pCreateWindowExW.Call(wsExToolwin|wsExTopmost, uintptr(unsafe.Pointer(wc.ClassName)), uintptr(unsafe.Pointer(w16("Kienzle-Suite"))),
		wsPopup, 0, 0, uintptr(tz.px(360)), uintptr(tz.px(300)), 0, 0, inst, 0)
	ecke := uint32(2) // abgerundete Ecken (Windows 11)
	pDwmSetWindowAttribute.Call(tz.hwnd, 33, uintptr(unsafe.Pointer(&ecke)), 4)
	taskleisteNeu, _, _ = pRegisterWindowMessageW.Call(uintptr(unsafe.Pointer(w16("TaskbarCreated"))))
	tz.nid = notifyIconData{Wnd: tz.hwnd, ID: 1, Flags: nifMessage | nifIcon | nifTip, CallbackMessage: wmTray}
	tz.nid.Size = uint32(unsafe.Sizeof(tz.nid))
	tz.aktualisieren()
	pShellNotifyIconW.Call(nimAdd, uintptr(unsafe.Pointer(&tz.nid)))
	pSetTimer.Call(tz.hwnd, 1, 3000, 0)
	var m2 msg
	for {
		r, _, _ := pGetMessageW.Call(uintptr(unsafe.Pointer(&m2)), 0, 0, 0)
		if int32(r) <= 0 {
			return nil
		}
		pTranslateMessage.Call(uintptr(unsafe.Pointer(&m2)))
		pDispatchMessageW.Call(uintptr(unsafe.Pointer(&m2)))
	}
}

// aktualisieren liest den Zustand, setzt Symbol und Hinweistext und zeichnet ein offenes Fenster neu. Hat sich der Agent
// (Dienst) inzwischen selbst aktualisiert, steht in status.json eine andere Programmversion als die dieses laufenden
// Symbol-Prozesses: Der startet dann (ohne jede Aktion des Benutzers) eine neue, frische Fassung von sich selbst und
// beendet sich – sonst bliebe das Symbol auf dem alten alten Stand stehen (rot, nicht mehr anklickbar).
func (t *trayZustand) aktualisieren() {
	s, ok := StatusLesen(t.ordner)
	if AgentAktualisiert(s, ok, version) {
		t.neustart()
		return
	}
	t.ansicht = AnsichtBauen(s, ok, time.Now())
	if t.ansicht.Farbe != t.farbe {
		alt := t.icon
		t.icon = symbolIcon(t.ansicht.Farbe)
		t.nid.Icon = t.icon
		t.farbe = t.ansicht.Farbe
		if alt != 0 {
			defer pDestroyIcon.Call(alt)
		}
	}
	tip, _ := syscall.UTF16FromString(kuerzen(t.ansicht.Tooltip, 120))
	t.nid.Tip = [128]uint16{}
	copy(t.nid.Tip[:127], tip)
	pShellNotifyIconW.Call(nimModify, uintptr(unsafe.Pointer(&t.nid)))
	if v, _, _ := pIsWindowVisible.Call(t.hwnd); v != 0 {
		t.groesse()
		pInvalidateRect.Call(t.hwnd, 0, 1)
	}
}

// neustart: Symbol entfernen, eigenen Mutex freigeben (sonst bekäme die neue Fassung ihn nicht), eine frische Fassung
// dieses Programms mit --tray starten und sich selbst beenden.
func (t *trayZustand) neustart() {
	pShellNotifyIconW.Call(nimDelete, uintptr(unsafe.Pointer(&t.nid)))
	if t.mutex != 0 {
		pCloseHandle.Call(t.mutex)
	}
	if exe, err := os.Executable(); err == nil {
		_ = exec.Command(exe, "--tray").Start()
	}
	os.Exit(0)
}

// symbolIcon: 32-Bit-Symbol aus SymbolBild in der Größe der kleinen Symbole.
func symbolIcon(farbe int) uintptr {
	n, _, _ := pGetSystemMetrics.Call(49) // SM_CXSMICON
	if n < 16 {
		n = 16
	}
	px := SymbolBild(int(n), farbe)
	bi := bitmapInfoHeader{Width: int32(n), Height: -int32(n), Planes: 1, BitCount: 32}
	bi.Size = uint32(unsafe.Sizeof(bi))
	var bits unsafe.Pointer
	color, _, _ := pCreateDIBSection.Call(0, uintptr(unsafe.Pointer(&bi)), 0, uintptr(unsafe.Pointer(&bits)), 0, 0)
	if color == 0 || bits == nil {
		return 0
	}
	copy(unsafe.Slice((*uint32)(bits), len(px)), px)
	maske := make([]byte, int(n)*int(n)/8+int(n)*2)
	mask, _, _ := pCreateBitmap.Call(n, n, 1, 1, uintptr(unsafe.Pointer(&maske[0])))
	ii := iconInfo{FIcon: 1, Mask: mask, Color: color}
	h, _, _ := pCreateIconIndirect.Call(uintptr(unsafe.Pointer(&ii)))
	pDeleteObject.Call(color)
	pDeleteObject.Call(mask)
	return h
}

// Höhe einer Zeile: grüne Zeilen einzeilig (Wert rechts), sonst Wert in zwei Zeilen darunter.
func (t *trayZustand) zeilenHoehe(z Zeile) float64 {
	if z.Farbe == Gruen || z.Farbe == Grau {
		return 30
	}
	return 62
}

// groesse setzt Größe und Lage über der Uhr (rechts unten im Arbeitsbereich).
func (t *trayZustand) groesse() (w, h int32) {
	hoehe := 16.0 + 46 + 8 + 26 + 16 + 34 + 16
	if len(t.ansicht.Entfernen) > 0 {
		hoehe += 40
	}
	for _, z := range t.ansicht.Zeilen {
		hoehe += t.zeilenHoehe(z)
	}
	w, h = t.px(360), t.px(hoehe)
	var a rect
	pSystemParametersInfoW.Call(0x30, 0, uintptr(unsafe.Pointer(&a)), 0)                                                      // SPI_GETWORKAREA
	pSetWindowPos.Call(t.hwnd, ^uintptr(0), uintptr(a.R-w-t.px(12)), uintptr(a.B-h-t.px(12)), uintptr(w), uintptr(h), 0x0010) // HWND_TOPMOST, SWP_NOACTIVATE
	return
}

func trayProc(hwnd, nachricht, wparam, lparam uintptr) uintptr {
	t := tz
	if nachricht == taskleisteNeu && taskleisteNeu != 0 {
		pShellNotifyIconW.Call(nimAdd, uintptr(unsafe.Pointer(&t.nid)))
		return 0
	}
	switch nachricht {
	case wmTray:
		if lparam == wmLButtonUp || lparam == wmRButtonUp {
			if v, _, _ := pIsWindowVisible.Call(hwnd); v != 0 {
				pShowWindow.Call(hwnd, swHide)
			} else {
				t.meldung = ""
				t.aktualisieren()
				t.groesse()
				pShowWindow.Call(hwnd, swShow)
				pSetForegroundWindow.Call(hwnd)
			}
		}
		return 0
	case wmActivate:
		if wparam&0xFFFF == 0 && !t.frage { // inaktiv: wie ein Windows-Flyout schließen
			pShowWindow.Call(hwnd, swHide)
		}
		return 0
	case wmTimer:
		t.aktualisieren()
		return 0
	case wmLButtonUp:
		x, y := int32(int16(lparam&0xFFFF)), int32(int16((lparam>>16)&0xFFFF))
		for i, k := range t.knopf {
			if x >= k.L && x <= k.R && y >= k.T && y <= k.B {
				t.knopfGedrueckt(i)
			}
		}
		return 0
	case wmPaint:
		t.zeichnen()
		return 0
	case wmKnopfFertig:
		if lparam == 1 && wparam == 0 { // Drucker prüfen: Anstoß ließ sich nicht schreiben
			t.meldung = "Anstoß nicht möglich (Paket erneut ausführen)"
		}
		if lparam == 3 && wparam == 0 { // Alte Drucker entfernen: Anstoß ließ sich nicht schreiben
			t.meldung = "Entfernen nicht möglich (Paket erneut ausführen)"
		}
		pInvalidateRect.Call(hwnd, 0, 1)
		return 0
	case wmDestroy:
		return 0
	}
	r, _, _ := pDefWindowProcW.Call(hwnd, nachricht, wparam, lparam)
	return r
}

// knopfGedrueckt reagiert auf einen Klick. Jede Aktion, die eine Datei schreibt oder ein Programm startet, macht das in
// einem eigenen Hintergrund-Thread (go func): CreateProcess/WriteFile können kurz nach dem Hochfahren spürbar hängen
// (z. B. durch den Virenscanner), und das darf nie die Nachrichtenschleife des Fensters blockieren – sonst wirkt das
// ganze Fenster „komplett aufgehängt“, bis der Scan fertig ist. Win32-Aufrufe macht nur der Fenster-Thread selbst;
// der Hintergrund-Thread meldet sein Ergebnis per PostMessage (wmKnopfFertig) zurück.
func (t *trayZustand) knopfGedrueckt(i int) {
	switch i {
	case 0: // Suite öffnen
		hwnd, ordner := t.hwnd, t.ordner
		go func() {
			if cfg, err := loadConfig(filepath.Join(ordner, "client.json")); err == nil {
				_ = openBrowser(cfg.ServerURL + "/")
			}
		}()
		pShowWindow.Call(hwnd, swHide)
	case 1: // Drucker prüfen: leere Anstoß-Datei, der Agent prüft bei der nächsten Abfrage
		t.meldung = "Drucker werden geprüft …"
		pInvalidateRect.Call(t.hwnd, 0, 1)
		hwnd, ordner := t.hwnd, t.ordner
		go func() {
			_ = os.MkdirAll(filepath.Join(ordner, "anstoss"), 0o775)
			err := os.WriteFile(filepath.Join(ordner, "anstoss", "drucker-pruefen"), nil, 0o664)
			pPostMessageW.Call(hwnd, wmKnopfFertig, boolUintptr(err == nil), 1)
		}()
	case 2: // Protokoll
		hwnd, ordner := t.hwnd, t.ordner
		go func() { _ = exec.Command("notepad.exe", filepath.Join(ordner, "starter.log")).Start() }()
		pShowWindow.Call(hwnd, swHide)
	case 3: // Alte Drucker entfernen – nur nach Rückfrage; der Agent prüft vor dem Löschen erneut, ob sie alt sind.
		// Die Rückfrage selbst (MessageBox) ist bewusst modal: Das soll hier auf eine Antwort warten.
		namen := t.ansicht.Entfernen
		if len(namen) == 0 {
			return
		}
		frage := "Diese Drucker zeigen auf die Kienzle-Suite, dort gibt es sie aber nicht mehr:\n\n• " + strings.Join(namen, "\n• ") +
			"\n\nAusdrucke darauf kommen nirgends an. Jetzt von diesem PC entfernen?\n\nDie aktuellen Drucker der Suite bleiben unverändert."
		t.frage = true
		antwort, _, _ := pMessageBoxW.Call(t.hwnd, uintptr(unsafe.Pointer(w16(frage))), uintptr(unsafe.Pointer(w16("Alte Drucker entfernen"))),
			0x4|0x20|0x100|0x10000|0x40000) // MB_YESNO, MB_ICONQUESTION, MB_DEFBUTTON2, MB_SETFOREGROUND, MB_TOPMOST
		t.frage = false
		if antwort != 6 { // IDYES
			return
		}
		t.meldung = "Alte Drucker werden entfernt …"
		pInvalidateRect.Call(t.hwnd, 0, 1)
		hwnd, ordner := t.hwnd, t.ordner
		go func() {
			_ = os.MkdirAll(filepath.Join(ordner, "anstoss"), 0o775)
			err := os.WriteFile(filepath.Join(ordner, "anstoss", "drucker-entfernen"), []byte(strings.Join(namen, "\n")), 0o664)
			pPostMessageW.Call(hwnd, wmKnopfFertig, boolUintptr(err == nil), 3)
		}()
	}
}

// boolUintptr: true→1, false→0 (Ergebnis einer Hintergrundaktion als Win32-Nachrichtenparameter).
func boolUintptr(ok bool) uintptr {
	if ok {
		return 1
	}
	return 0
}

func (t *trayZustand) font(groesse float64, fett bool) uintptr {
	gewicht := uintptr(400)
	if fett {
		gewicht = 600
	}
	f, _, _ := pCreateFontW.Call(uintptr(-t.px(groesse)), 0, 0, 0, gewicht, 0, 0, 0, 1, 0, 0, 5, 0, uintptr(unsafe.Pointer(w16("Segoe UI"))))
	return f
}

func (t *trayZustand) text(dc uintptr, s string, r rect, farbe uintptr, flags uintptr) {
	pSetTextColor.Call(dc, farbe)
	p, _ := syscall.UTF16FromString(s)
	pDrawTextW.Call(dc, uintptr(unsafe.Pointer(&p[0])), uintptr(len(p)-1), uintptr(unsafe.Pointer(&r)), flags|dtNoPrefix)
}

func ampel(f int) uintptr {
	switch f {
	case Gelb:
		return rgb(0xE0, 0xA4, 0x00)
	case Rot:
		return rgb(0xD4, 0x47, 0x3A)
	case Grau:
		return rgb(0x9A, 0xA5, 0xA2)
	case Blau:
		return rgb(0x2E, 0x7D, 0xD1)
	}
	return rgb(0x2F, 0xB3, 0x6B)
}

// zeichnen: Kopf (Symbol, „Kienzle-Suite“, Marke), Unterzeile, Zeilen mit Ampel, Fuß, drei Knöpfe – im Stil der Suite.
func (t *trayZustand) zeichnen() {
	var ps paintStruct
	dc, _, _ := pBeginPaint.Call(t.hwnd, uintptr(unsafe.Pointer(&ps)))
	defer pEndPaint.Call(t.hwnd, uintptr(unsafe.Pointer(&ps)))
	w, _ := t.groesse()
	weiss, _, _ := pCreateSolidBrush.Call(rgb(255, 255, 255))
	all := rect{0, 0, w, t.px(2000)}
	pFillRect.Call(dc, uintptr(unsafe.Pointer(&all)), weiss)
	pDeleteObject.Call(weiss)
	pSetBkMode.Call(dc, 1) // TRANSPARENT
	dunkel, grau, teal := rgb(0x1D, 0x3B, 0x36), rgb(0x5B, 0x6B, 0x67), rgb(0x12, 0x69, 0x5D)
	fett, normal, klein := t.font(15, true), t.font(13, false), t.font(11.5, false)
	defer pDeleteObject.Call(fett)
	defer pDeleteObject.Call(normal)
	defer pDeleteObject.Call(klein)
	stift, _, _ := pCreatePen.Call(5, 0, 0) // PS_NULL
	altStift, _, _ := pSelectObject.Call(dc, stift)
	defer func() { pSelectObject.Call(dc, altStift); pDeleteObject.Call(stift) }()
	kreis := func(x, y, r float64, farbe uintptr) {
		b, _, _ := pCreateSolidBrush.Call(farbe)
		alt, _, _ := pSelectObject.Call(dc, b)
		pEllipse.Call(dc, uintptr(t.px(x-r)), uintptr(t.px(y-r)), uintptr(t.px(x+r)), uintptr(t.px(y+r)))
		pSelectObject.Call(dc, alt)
		pDeleteObject.Call(b)
	}
	kasten := func(x1, y1, x2, y2, r float64, farbe uintptr) {
		b, _, _ := pCreateSolidBrush.Call(farbe)
		alt, _, _ := pSelectObject.Call(dc, b)
		pRoundRect.Call(dc, uintptr(t.px(x1)), uintptr(t.px(y1)), uintptr(t.px(x2)), uintptr(t.px(y2)), uintptr(t.px(r)), uintptr(t.px(r)))
		pSelectObject.Call(dc, alt)
		pDeleteObject.Call(b)
	}
	breite := float64(w) / t.skala
	v := t.ansicht
	// Kopf
	kasten(16, 16, 40, 40, 8, teal)
	pSelectObject.Call(dc, fett)
	t.text(dc, "K", rect{t.px(16), t.px(16), t.px(40), t.px(40)}, rgb(255, 255, 255), 1|dtVCenter|dtSingleLine) // DT_CENTER
	kreis(38, 38, 5, ampel(v.Farbe))
	t.text(dc, "Kienzle-Suite", rect{t.px(50), t.px(14), t.px(breite - 110), t.px(34)}, dunkel, dtLeft|dtSingleLine|dtVCenter)
	markeFarbe, markeText := rgb(0xE3, 0xF1, 0xEE), teal
	switch v.Farbe {
	case Rot:
		markeFarbe, markeText = rgb(0xFB, 0xEE, 0xEA), rgb(0x99, 0x3D, 0x2B)
	case Blau:
		markeFarbe, markeText = rgb(0xE4, 0xEE, 0xFA), rgb(0x1F, 0x51, 0x8C)
	}
	pSelectObject.Call(dc, klein)
	kasten(breite-16-110, 16, breite-16, 36, 8, markeFarbe)
	t.text(dc, v.Kopf, rect{t.px(breite - 16 - 110), t.px(16), t.px(breite - 16), t.px(36)}, markeText, 1|dtVCenter|dtSingleLine|dtEndEllipsis)
	t.text(dc, v.Unter, rect{t.px(50), t.px(36), t.px(breite - 16), t.px(56)}, grau, dtLeft|dtSingleLine|dtEndEllipsis)
	// Zeilen
	y := 70.0
	for _, z := range v.Zeilen {
		hz := t.zeilenHoehe(z)
		kreis(21, y+15, 4.5, ampel(z.Farbe))
		pSelectObject.Call(dc, normal)
		t.text(dc, z.Titel, rect{t.px(32), t.px(y), t.px(170), t.px(y + 30)}, dunkel, dtLeft|dtSingleLine|dtVCenter)
		if hz == 30 {
			t.text(dc, z.Wert, rect{t.px(150), t.px(y), t.px(breite - 16), t.px(y + 30)}, dunkel, dtRight|dtSingleLine|dtVCenter|dtEndEllipsis)
		} else {
			pSelectObject.Call(dc, klein)
			t.text(dc, z.Wert, rect{t.px(32), t.px(y + 26), t.px(breite - 16), t.px(y + hz - 2)}, rgb(0x7A, 0x5A, 0x00), dtLeft|dtWordbreak|dtEndEllipsis)
		}
		linie, _, _ := pCreateSolidBrush.Call(rgb(0xE7, 0xEE, 0xEB))
		l := rect{t.px(16), t.px(y + hz - 1), t.px(breite - 16), t.px(y + hz)}
		pFillRect.Call(dc, uintptr(unsafe.Pointer(&l)), linie)
		pDeleteObject.Call(linie)
		y += hz
	}
	// Fuß
	pSelectObject.Call(dc, klein)
	fuss := v.Fuss
	if t.meldung != "" {
		fuss = t.meldung
	}
	t.text(dc, fuss, rect{t.px(16), t.px(y + 4), t.px(breite - 16), t.px(y + 26)}, grau, dtLeft|dtSingleLine|dtVCenter|dtEndEllipsis)
	y += 26 + 8
	// Knöpfe
	t.knopf = t.knopf[:0]
	x := 16.0
	for i, k := range []struct {
		text  string
		breit float64
	}{{"Suite öffnen", 112}, {"Drucker prüfen", 118}, {"Protokoll", 84}} {
		farbe, schrift := rgb(0xE3, 0xEB, 0xE8), dunkel
		if i == 0 {
			farbe, schrift = teal, rgb(255, 255, 255)
		}
		kasten(x, y, x+k.breit, y+32, 8, farbe)
		pSelectObject.Call(dc, normal)
		r := rect{t.px(x), t.px(y), t.px(x + k.breit), t.px(y + 32)}
		t.text(dc, k.text, r, schrift, 1|dtVCenter|dtSingleLine)
		t.knopf = append(t.knopf, r)
		x += k.breit + 8
	}
	if len(v.Entfernen) > 0 {
		y += 40
		kasten(16, y, breite-16, y+32, 8, rgb(0xFB, 0xF3, 0xDC))
		r := rect{t.px(16), t.px(y), t.px(breite - 16), t.px(y + 32)}
		t.text(dc, "Alte Drucker entfernen …", r, rgb(0x7A, 0x5A, 0x00), 1|dtVCenter|dtSingleLine)
		t.knopf = append(t.knopf, r)
	}
}
