package main

// Symbol in der Taskleiste (Entwurf B): Statusfenster mit Ampel je Bereich. Hier steht alles, was nicht von Windows abhängt
// (Zustand lesen, Zeilen und Farben, Bild des Symbols); das Fenster selbst steht in tray_windows.go.

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// Farben der Ampel.
const (
	Gruen = iota
	Gelb
	Rot
	Grau
)

// Zeile im Statusfenster.
type Zeile struct {
	Titel string
	Wert  string
	Farbe int
}

// Ansicht ist der Inhalt des Statusfensters.
type Ansicht struct {
	Farbe   int    // Punkt am Symbol: schlechteste Ampel
	Kopf    string // „verbunden“ / „keine Verbindung“
	Unter   string // Arbeitsplatz · Server
	Zeilen  []Zeile
	Fuss    string // Version · zuletzt
	Tooltip string
	// Entfernen: alte Kienzle-Drucker; das Fenster bietet dann „Alte Drucker entfernen …“ mit Rückfrage an.
	Entfernen []string
}

// StatusLesen liest status.json; ok=false, wenn es (noch) keine gibt.
func StatusLesen(ordner string) (Status, bool) {
	var s Status
	data, err := os.ReadFile(filepath.Join(ordner, "status.json"))
	if err != nil || json.Unmarshal(data, &s) != nil {
		return Status{}, false
	}
	return s, true
}

// AnsichtBauen macht aus dem Zustand die Zeilen des Fensters. Ein Zustand älter als 3 Minuten heißt: Der Agent läuft nicht.
func AnsichtBauen(s Status, vorhanden bool, jetzt time.Time) Ansicht {
	v := Ansicht{Farbe: Gruen, Kopf: "verbunden"}
	alt := !vorhanden || jetzt.Unix()-s.Zeit > 180
	switch {
	case alt:
		v.Farbe, v.Kopf = Rot, "Agent läuft nicht"
		v.Zeilen = append(v.Zeilen, Zeile{"Kienzle-Agent", "keine Rückmeldung – PC neu starten oder Paket erneut ausführen", Rot})
	case !s.Verbunden:
		v.Farbe, v.Kopf = Rot, "keine Verbindung"
		v.Zeilen = append(v.Zeilen, Zeile{"Verbindung", kuerzen(s.Fehler, 90), Rot})
	}
	if vorhanden {
		switch {
		case len(s.Drucker) == 0:
			v.Zeilen = append(v.Zeilen, Zeile{"Drucker", "keine virtuellen Drucker in der Suite", Grau})
		case s.DruckerOk:
			v.Zeilen = append(v.Zeilen, Zeile{"Drucker", strings.Join(s.Drucker, ", "), Gruen})
		default:
			t := s.DruckerTxt
			if t == "" {
				t = "werden eingerichtet …"
			}
			v.Zeilen = append(v.Zeilen, Zeile{"Drucker", kuerzen(t, 110), Gelb})
		}
		if len(s.AltDrucker) > 0 {
			wert := strings.Join(s.AltDrucker, ", ") + " – gibt es in der Suite nicht mehr"
			if s.AltText != "" {
				wert += " (" + s.AltText + ")"
			}
			v.Zeilen = append(v.Zeilen, Zeile{"Alte Drucker", kuerzen(wert, 110), Gelb})
			v.Entfernen = append([]string(nil), s.AltDrucker...)
		}
		if s.Aufrufe > 0 {
			v.Zeilen = append(v.Zeilen, Zeile{"Aufrufe aus T2med", fmt.Sprintf("%d eingerichtet", s.Aufrufe), Gruen})
		} else {
			v.Zeilen = append(v.Zeilen, Zeile{"Aufrufe aus T2med", "noch nicht eingerichtet", Gelb})
		}
		if s.Zertifikat {
			v.Zeilen = append(v.Zeilen, Zeile{"Zertifikat", "vertrauenswürdig", Gruen})
		} else {
			v.Zeilen = append(v.Zeilen, Zeile{"Zertifikat", "noch nicht eingetragen", Gelb})
		}
		switch {
		case s.AkteZeit == 0:
			v.Zeilen = append(v.Zeilen, Zeile{"Akte öffnen", "bereit (C:\\Kienzle\\GDT)", Gruen})
		case s.AkteOk:
			v.Zeilen = append(v.Zeilen, Zeile{"Akte öffnen", "zuletzt " + time.Unix(s.AkteZeit, 0).Format("15:04"), Gruen})
		default:
			v.Zeilen = append(v.Zeilen, Zeile{"Akte öffnen", "letzter Aufruf fehlgeschlagen (" + time.Unix(s.AkteZeit, 0).Format("15:04") + ")", Gelb})
		}
		teile := []string{}
		if s.Name != "" {
			teile = append(teile, "Arbeitsplatz „"+s.Name+"“")
		}
		if s.Server != "" {
			teile = append(teile, s.Server)
		}
		v.Unter = strings.Join(teile, " · ")
		v.Fuss = "Version " + s.Version + " · zuletzt " + time.Unix(s.Zeit, 0).Format("15:04")
	}
	for _, z := range v.Zeilen {
		if z.Farbe != Grau && z.Farbe > v.Farbe {
			v.Farbe = z.Farbe
		}
	}
	v.Tooltip = kuerzen("Kienzle-Suite – "+v.Kopf+map[bool]string{true: " (" + s.Name + ")", false: ""}[s.Name != "" && !alt], 120)
	return v
}

func kuerzen(s string, n int) string {
	r := []rune(strings.TrimSpace(s))
	if len(r) <= n {
		return string(r)
	}
	return string(r[:n-1]) + "…"
}

// SymbolBild: n×n Bildpunkte (0xAARRGGBB): grünes Quadrat mit abgerundeten Ecken, weißes „K“, Punkt unten rechts in der Ampelfarbe.
func SymbolBild(n int, farbe int) []uint32 {
	px := make([]uint32, n*n)
	f := float64(n) / 32
	teal := uint32(0xFF12695D)
	punkt := map[int]uint32{Gruen: 0xFF2FB36B, Gelb: 0xFFE0A400, Rot: 0xFFD4473A, Grau: 0xFF9AA5A2}[farbe]
	r := 6 * f
	for y := 0; y < n; y++ {
		for x := 0; x < n; x++ {
			fx, fy := float64(x)+0.5, float64(y)+0.5
			// abgerundetes Quadrat (Ecken mit Radius r)
			cx, cy := klemmen(fx, r, float64(n)-r), klemmen(fy, r, float64(n)-r)
			if (fx-cx)*(fx-cx)+(fy-cy)*(fy-cy) <= r*r {
				px[y*n+x] = teal
			}
			// „K“: senkrechter Strich und zwei schräge Striche
			u, w := fx/f, fy/f
			if u >= 8 && u <= 12 && w >= 7 && w <= 25 || naheLinie(u, w, 12, 16, 22, 7, 2.2) || naheLinie(u, w, 13, 15, 22, 25, 2.2) {
				px[y*n+x] = 0xFFFFFFFF
			}
			// Punkt unten rechts mit dunklem Rand
			dx, dy := u-25, w-25
			if dx*dx+dy*dy <= 49 {
				px[y*n+x] = 0xFF1F2A28
			}
			if dx*dx+dy*dy <= 25 {
				px[y*n+x] = punkt
			}
		}
	}
	return px
}

func klemmen(v, lo, hi float64) float64 {
	if v < lo {
		return lo
	}
	if v > hi {
		return hi
	}
	return v
}

// naheLinie: Abstand des Punkts (u,w) zur Strecke (x1,y1)–(x2,y2) höchstens d.
func naheLinie(u, w, x1, y1, x2, y2, d float64) bool {
	vx, vy := x2-x1, y2-y1
	t := klemmen(((u-x1)*vx+(w-y1)*vy)/(vx*vx+vy*vy), 0, 1)
	px, py := x1+t*vx-u, y1+t*vy-w
	return px*px+py*py <= d*d
}
