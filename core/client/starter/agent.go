package main

// Kienzle-Agent: derselbe Starter läuft mit --dienst dauerhaft (Windows: geplante Aufgabe mit Systemrechten beim Start des PCs).
// Er fragt die Suite etwa alle zwei Sekunden nach Aufträgen und hält den PC auf dem Sollzustand der Suite:
//   - Starterprogramm (Selbstaktualisierung, Prüfsumme aus der Suite),
//   - Aufrufe aus T2med (URL-Schemata) und client.json für den Startermodus,
//   - Zertifizierungsstelle der Suite (vertrauenswürdig für alle Benutzer),
//   - virtuelle Drucker der Module.
// Aufträge gibt es nur in festen Arten; zurzeit „gdt“ (Akte in T2med öffnen: Datei in den festen GDT-Ordner schreiben).
// Der Agent startet keine Befehle aus der Suite und schreibt nur in seine eigenen Ordner.

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"time"
)

// DruckerPause: Abstand zwischen zwei Versuchen, fehlende Drucker einzurichten.
var DruckerPause = 10 * time.Minute

// Version des Programms (scripts/starter-bauen setzt sie nicht; der Server vergleicht die Prüfsumme der Datei).
const version = "2.0.1"

// AgentConfig steht in agent.json (nur für SYSTEM und Administratoren lesbar).
type AgentConfig struct {
	ID    string `json:"id"`
	Token string `json:"token"`
	Name  string `json:"name"`
}

// Soll ist der Sollzustand aus der Suite.
type Soll struct {
	ServerURL   string            `json:"server_url"`
	LauncherKey string            `json:"launcher_key"`
	Programme   map[string]string `json:"programme"`
	Kontexte    map[string]string `json:"kontexte"`
	Drucker     []Drucker         `json:"drucker"`
	CA          string            `json:"ca"`
	GDT         struct {
		Ordner string `json:"ordner"`
	} `json:"gdt"`
}

// Drucker ist ein virtueller Drucker der Module (IPP-Adresse auf dem Server).
type Drucker struct {
	Name   string `json:"name"`
	URL    string `json:"url"`
	Papier string `json:"papier"`
}

// Auftrag aus der Suite.
type Auftrag struct {
	ID    string          `json:"id"`
	Typ   string          `json:"typ"`
	Daten json.RawMessage `json:"daten"`
}

// Plattform kapselt alles, was vom Betriebssystem abhängt (Windows: plattform_windows.go; Tests: Ersatz).
type Plattform interface {
	Schemata(kontexte map[string]string, programm string) error
	Drucker(liste []Drucker) (string, error)
	Zertifizierungsstelle(pem []byte) error
	GDTOrdner() string
}

// Agent hält Zustand zwischen den Abfragen.
type Agent struct {
	Ordner    string // Datenordner (client.json, agent.json, ca.crt)
	Programm  string // Pfad des laufenden Programms
	Client    *http.Client
	Plattform Plattform
	cfg       *Config
	ac        AgentConfig
	stand     string
	zustand   string
	druckerH  string
	// Nach einem Fehlschlag die Drucker höchstens alle 10 Minuten erneut versuchen (nicht bei jeder Abfrage).
	druckerFehlerH string
	druckerVersuch time.Time
	druckerText    string
	caH            string
	schemaH        string
}

var (
	idRe        = regexp.MustCompile(`^[0-9a-f]{16}$`)
	tokenRe     = regexp.MustCompile(`^[0-9a-f]{64}$`)
	gdtDatei    = regexp.MustCompile(`^[A-Za-z0-9_-]{1,40}\.gdt$`)
	druckerRe   = regexp.MustCompile(`^[A-Za-z0-9_-]{1,40}$`)
	assetName   = programmName(runtime.GOOS, runtime.GOARCH)
	errNeustart = errors.New("neues Programm eingespielt")
)

// programmName wie in scripts/starter-bauen (kienzle-starter-windows-amd64.exe …).
func programmName(goos, goarch string) string {
	if goos == "windows" {
		return "kienzle-starter-windows-" + goarch + ".exe"
	}
	return "kienzle-starter-" + goos + "-" + goarch
}

func systemName() string {
	if runtime.GOOS == "darwin" {
		return "macos"
	}
	return runtime.GOOS
}

// Anmelden: einmal bei der Einrichtung (mit dem Starter-Schlüssel aus dem Paket). Schreibt agent.json.
func Anmelden(ordner string, client *http.Client) (AgentConfig, error) {
	cfg, err := loadConfig(filepath.Join(ordner, "client.json"))
	if err != nil {
		return AgentConfig{}, err
	}
	if client == nil {
		client = pinnedClient(cfg)
	}
	rechner, _ := os.Hostname()
	body, _ := json.Marshal(map[string]string{"rechner": rechner, "system": systemName()})
	var ac AgentConfig
	if err := anfrage(client, http.MethodPost, cfg.ServerURL+"/agent/anmelden", "Bearer "+cfg.LauncherKey, body, &ac); err != nil {
		return AgentConfig{}, err
	}
	if !idRe.MatchString(ac.ID) || !tokenRe.MatchString(ac.Token) {
		return AgentConfig{}, errors.New("unerwartete Antwort beim Anmelden")
	}
	data, _ := json.MarshalIndent(ac, "", "  ")
	if err := schreibeAtomar(filepath.Join(ordner, "agent.json"), data, 0o600); err != nil {
		return AgentConfig{}, err
	}
	return ac, nil
}

// anfrage schickt JSON und liest JSON (höchstens 1 MB); Fehlermeldungen des Servers werden weitergegeben.
func anfrage(client *http.Client, method, adresse, auth string, body []byte, ziel any) error {
	req, err := http.NewRequest(method, adresse, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Authorization", auth)
	resp, err := client.Do(req)
	if err != nil {
		return fmt.Errorf("der Server ist nicht erreichbar: %w", err)
	}
	defer resp.Body.Close()
	data, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode != http.StatusOK {
		var e struct {
			Error string `json:"error"`
		}
		_ = json.Unmarshal(data, &e)
		if e.Error == "" {
			e.Error = fmt.Sprintf("Antwort %d", resp.StatusCode)
		}
		return fmt.Errorf("Server: %s", e.Error)
	}
	if ziel != nil {
		return json.Unmarshal(data, ziel)
	}
	return nil
}

// Laden liest client.json und agent.json.
func (a *Agent) Laden() error {
	cfg, err := loadConfig(filepath.Join(a.Ordner, "client.json"))
	if err != nil {
		return err
	}
	data, err := os.ReadFile(filepath.Join(a.Ordner, "agent.json"))
	if err != nil {
		return fmt.Errorf("agent.json fehlt (Paket erneut ausführen): %w", err)
	}
	var ac AgentConfig
	if json.Unmarshal(data, &ac) != nil || !idRe.MatchString(ac.ID) || !tokenRe.MatchString(ac.Token) {
		return errors.New("agent.json ungültig (Paket erneut ausführen)")
	}
	a.cfg, a.ac = cfg, ac
	if a.Client == nil {
		a.Client = pinnedClient(cfg)
	}
	return nil
}

// Schritt: eine Abfrage mit allen Folgen. errNeustart, wenn ein neues Programm eingespielt wurde.
func (a *Agent) Schritt() error {
	body, _ := json.Marshal(map[string]string{"version": version, "stand": a.stand, "zustand": a.zustand})
	var r struct {
		Auftraege []Auftrag `json:"auftraege"`
		Stand     string    `json:"stand"`
		Soll      *Soll     `json:"soll"`
	}
	if err := anfrage(a.Client, http.MethodPost, a.cfg.ServerURL+"/agent/abholen", "Bearer "+a.ac.Token, body, &r); err != nil {
		return err
	}
	for _, auf := range r.Auftraege {
		ok, text := a.ausfuehren(auf)
		erg, _ := json.Marshal(map[string]any{"auftrag": auf.ID, "ok": ok, "text": text})
		_ = anfrage(a.Client, http.MethodPost, a.cfg.ServerURL+"/agent/ergebnis", "Bearer "+a.ac.Token, erg, nil)
	}
	if r.Soll != nil {
		err := a.angleichen(r.Soll)
		if errors.Is(err, errNeustart) {
			return err
		}
		if err == nil {
			a.stand = r.Stand
		}
	}
	return nil
}

// ausfuehren führt einen Auftrag aus (nur bekannte Arten).
func (a *Agent) ausfuehren(auf Auftrag) (bool, string) {
	switch auf.Typ {
	case "gdt":
		var d struct {
			Datei  string `json:"datei"`
			Inhalt string `json:"inhalt"`
		}
		if json.Unmarshal(auf.Daten, &d) != nil || !gdtDatei.MatchString(d.Datei) || len(d.Inhalt) == 0 || len(d.Inhalt) > 65536 || !istASCII(d.Inhalt) {
			return false, "GDT-Auftrag ungültig"
		}
		ordner := a.Plattform.GDTOrdner()
		if err := os.MkdirAll(ordner, 0o775); err != nil {
			return false, "GDT-Ordner nicht anlegbar"
		}
		if err := schreibeAtomar(filepath.Join(ordner, d.Datei), []byte(d.Inhalt), 0o664); err != nil {
			return false, "GDT-Datei nicht geschrieben"
		}
		return true, "geschrieben"
	}
	return false, "unbekannte Auftragsart"
}

func istASCII(s string) bool {
	for i := 0; i < len(s); i++ {
		if s[i] > 126 || (s[i] < 32 && s[i] != '\r' && s[i] != '\n') {
			return false
		}
	}
	return true
}

func hashOf(v any) string {
	b, _ := json.Marshal(v)
	s := sha256.Sum256(b)
	return hex.EncodeToString(s[:])
}

// angleichen bringt den PC auf den Sollzustand. Fehler einzelner Teile landen im Zustand (Seite „Arbeitsplätze“).
func (a *Agent) angleichen(s *Soll) error {
	var meldungen []string
	fehler := false
	// 1. Programm: neue Fassung laden, Prüfsumme prüfen, austauschen; danach neu starten (die geplante Aufgabe startet neu).
	if soll, ok := s.Programme[assetName]; ok && len(soll) == 64 {
		if ist, err := dateiHash(a.Programm); err == nil && ist != soll {
			if err := a.aktualisieren(soll); err != nil {
				meldungen = append(meldungen, "Aktualisierung: "+err.Error())
				fehler = true
			} else {
				return errNeustart
			}
		}
	}
	// 2. client.json (Startermodus) und Zertifizierungsstelle.
	if pemCA := []byte(s.CA); len(pemCA) > 0 && parseCA(pemCA) != nil {
		if h := hashOf(s.CA); h != a.caH {
			if err := schreibeAtomar(filepath.Join(a.Ordner, "ca.crt"), pemCA, 0o644); err != nil {
				meldungen = append(meldungen, "Zertifikat: nicht gespeichert")
				fehler = true
			} else if err := a.Plattform.Zertifizierungsstelle(pemCA); err != nil {
				meldungen = append(meldungen, "Zertifikat: "+err.Error())
				fehler = true
			} else {
				a.caH = h
			}
		}
	}
	if len(s.Kontexte) > 0 {
		neu := map[string]any{"server_url": s.ServerURL, "launcher_key": s.LauncherKey, "certificate_sha256": a.cfg.CertSHA256, "kontexte": s.Kontexte}
		if s.LauncherKey == "" {
			neu["launcher_key"] = a.cfg.LauncherKey
		}
		if s.ServerURL == "" {
			neu["server_url"] = a.cfg.ServerURL
		}
		data, _ := json.MarshalIndent(neu, "", "  ")
		if alt, _ := os.ReadFile(filepath.Join(a.Ordner, "client.json")); !bytes.Equal(alt, data) {
			if err := schreibeAtomar(filepath.Join(a.Ordner, "client.json"), data, 0o644); err != nil {
				meldungen = append(meldungen, "client.json nicht geschrieben")
				fehler = true
			}
		}
		if h := hashOf(s.Kontexte); h != a.schemaH {
			if err := a.Plattform.Schemata(s.Kontexte, a.Programm); err != nil {
				meldungen = append(meldungen, "Aufrufe: "+err.Error())
				fehler = true
			} else {
				a.schemaH = h
			}
		}
	}
	// 3. Drucker (nur gültige Namen und Adressen auf dem eigenen Server).
	var drucker []Drucker
	for _, d := range s.Drucker {
		if druckerRe.MatchString(d.Name) && strings.HasPrefix(d.URL, "http://") && strings.Contains(d.URL, ":631/printers/") {
			drucker = append(drucker, d)
		}
	}
	if h := hashOf(drucker); h != a.druckerH {
		if h != a.druckerFehlerH || time.Since(a.druckerVersuch) >= DruckerPause {
			a.druckerVersuch = time.Now()
			text, err := a.Plattform.Drucker(drucker)
			if err != nil {
				a.druckerText, a.druckerFehlerH = err.Error(), h
			} else {
				a.druckerText, a.druckerH, a.druckerFehlerH = text, h, ""
			}
		}
		if h != a.druckerH {
			fehler = true
		}
	}
	if a.druckerText != "" {
		meldungen = append(meldungen, a.druckerText)
	}
	a.zustand = strings.Join(meldungen, "; ")
	if len(a.zustand) > 280 {
		a.zustand = a.zustand[:280]
	}
	if fehler {
		return errors.New(a.zustand)
	}
	return nil
}

// aktualisieren lädt das neue Programm, prüft die Prüfsumme und tauscht die Datei aus (unter Windows lässt sich ein laufendes
// Programm umbenennen, aber nicht überschreiben).
func (a *Agent) aktualisieren(soll string) error {
	req, _ := http.NewRequest(http.MethodGet, a.cfg.ServerURL+"/agent/programm/"+assetName, nil)
	req.Header.Set("Authorization", "Bearer "+a.ac.Token)
	resp, err := a.Client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("Antwort %d", resp.StatusCode)
	}
	data, err := io.ReadAll(io.LimitReader(resp.Body, 64<<20))
	if err != nil {
		return err
	}
	sum := sha256.Sum256(data)
	if hex.EncodeToString(sum[:]) != soll {
		return errors.New("Prüfsumme stimmt nicht")
	}
	neu := a.Programm + ".neu"
	if err := os.WriteFile(neu, data, 0o755); err != nil {
		return err
	}
	alt := a.Programm + ".alt"
	_ = os.Remove(alt)
	if err := os.Rename(a.Programm, alt); err != nil {
		_ = os.Remove(neu)
		return err
	}
	if err := os.Rename(neu, a.Programm); err != nil {
		_ = os.Rename(alt, a.Programm)
		return err
	}
	return nil
}

func dateiHash(pfad string) (string, error) {
	f, err := os.Open(pfad)
	if err != nil {
		return "", err
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}

// schreibeAtomar schreibt erst in eine Hilfsdatei und benennt sie dann um (Leser sehen nie eine halbe Datei).
func schreibeAtomar(pfad string, data []byte, mode os.FileMode) error {
	tmp := pfad + ".tmp"
	if err := os.WriteFile(tmp, data, mode); err != nil {
		return err
	}
	if err := os.Rename(tmp, pfad); err != nil {
		_ = os.Remove(tmp)
		return err
	}
	return nil
}

// Dienst: Endlosschleife. Kehrt nur zurück, wenn ein neues Programm eingespielt wurde (dann startet die Aufgabe neu).
func (a *Agent) Dienst(stop <-chan struct{}) error {
	pause := 2 * time.Second
	for {
		if a.cfg == nil {
			if err := a.Laden(); err != nil {
				logError(a.Ordner, err)
				pause = 60 * time.Second
			}
		}
		if a.cfg != nil {
			err := a.Schritt()
			if errors.Is(err, errNeustart) {
				return err
			}
			if err != nil {
				logError(a.Ordner, err)
				if pause < 60*time.Second {
					pause *= 2
				}
			} else {
				pause = 2 * time.Second
			}
		}
		select {
		case <-stop:
			return nil
		case <-time.After(pause):
		}
	}
}
