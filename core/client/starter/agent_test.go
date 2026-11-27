package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"io"
	"math/big"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

// Ersatzplattform: merkt sich die Aufrufe.
type testPlattform struct {
	mu       sync.Mutex
	ordner   string
	schemata int
	drucker  [][]Drucker
	ca       int
	fehler   error
}

func (p *testPlattform) Schemata(map[string]string, string) error {
	p.mu.Lock()
	p.schemata++
	p.mu.Unlock()
	return nil
}
func (p *testPlattform) Drucker(l []Drucker) (string, error) {
	p.mu.Lock()
	p.drucker = append(p.drucker, l)
	p.mu.Unlock()
	if p.fehler != nil {
		return "", p.fehler
	}
	return "Drucker: eingerichtet", nil
}
func (p *testPlattform) Zertifizierungsstelle([]byte) error { p.ca++; return nil }
func (p *testPlattform) GDTOrdner() string                  { return filepath.Join(p.ordner, "gdt") }

// testCA erzeugt eine Zertifizierungsstelle und ein Serverzertifikat für 127.0.0.1.
func testCA(t *testing.T) (caPEM []byte, server tls.Certificate) {
	t.Helper()
	caKey, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	ca := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "Kienzle-Suite Test-CA"}, NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour),
		IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign}
	caDER, _ := x509.CreateCertificate(rand.Reader, ca, ca, &caKey.PublicKey, caKey)
	caCert, _ := x509.ParseCertificate(caDER)
	key, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	leaf := &x509.Certificate{SerialNumber: big.NewInt(2), Subject: pkix.Name{CommonName: "127.0.0.1"}, NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour),
		IPAddresses: []net.IP{net.ParseIP("127.0.0.1")}, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}, KeyUsage: x509.KeyUsageDigitalSignature}
	leafDER, _ := x509.CreateCertificate(rand.Reader, leaf, caCert, &key.PublicKey, caKey)
	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: caDER}), tls.Certificate{Certificate: [][]byte{leafDER}, PrivateKey: key}
}

func TestVerbindungUeberZertifizierungsstelle(t *testing.T) {
	caPEM, cert := testCA(t)
	srv := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"url":"https://` + r.Host + `/#launch=` + strings.Repeat("a", 64) + `"}`))
	}))
	srv.TLS = &tls.Config{Certificates: []tls.Certificate{cert}}
	srv.StartTLS()
	defer srv.Close()
	cfg := &Config{ServerURL: srv.URL, LauncherKey: "0123456789abcdef0123456789abcdef", CertSHA256: strings.Repeat("0", 64), Contexts: map[string]string{"kienzle-kasse": "/starter/kasse"}}
	if _, err := run(cfg, "kienzle-kasse://?kontextId=a", nil); err == nil {
		t.Fatal("ohne passende CA darf keine Verbindung entstehen")
	}
	cfg.CA = parseCA(caPEM)
	if cfg.CA == nil {
		t.Fatal("CA nicht gelesen")
	}
	if _, err := run(cfg, "kienzle-kasse://?kontextId=a", nil); err != nil {
		t.Fatalf("mit der CA der Suite muss die Verbindung gehen: %v", err)
	}
	fremd, _ := testCA(t)
	cfg.CA = parseCA(fremd)
	if _, err := run(cfg, "kienzle-kasse://?kontextId=a", nil); err == nil {
		t.Fatal("fremde CA darf nicht reichen")
	}
}

// testServer bildet die Agent-Schnittstelle der Suite nach.
type testServer struct {
	mu        sync.Mutex
	srv       *httptest.Server
	programm  []byte
	auftraege []map[string]any
	ergebnis  []map[string]any
	abfragen  int
}

func neuerTestServer(t *testing.T, programm []byte) *testServer {
	ts := &testServer{programm: programm}
	ts.srv = httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		ts.mu.Lock()
		defer ts.mu.Unlock()
		body, _ := io.ReadAll(r.Body)
		var in map[string]any
		_ = json.Unmarshal(body, &in)
		switch r.URL.Path {
		case "/agent/anmelden":
			if r.Header.Get("Authorization") != "Bearer 0123456789abcdef0123456789abcdef" {
				w.WriteHeader(401)
				w.Write([]byte(`{"error":"Starter-Schlüssel ungültig."}`))
				return
			}
			w.Write([]byte(`{"id":"0123456789abcdef","token":"` + strings.Repeat("ab", 32) + `","name":"Empfang"}`))
		case "/agent/abholen":
			if r.Header.Get("Authorization") != "Bearer "+strings.Repeat("ab", 32) {
				w.WriteHeader(401)
				return
			}
			ts.abfragen++
			sum := sha256.Sum256(ts.programm)
			soll := map[string]any{"server_url": ts.srv.URL, "launcher_key": "neuer-schluessel-0123456789abcdef", "programme": map[string]string{assetName: hex.EncodeToString(sum[:])},
				"kontexte": map[string]string{"kienzle-kasse": "/starter/kasse", "kienzle-telefon": "/starter/telefon"},
				"drucker":  []map[string]string{{"name": "vFax", "url": "http://kienzle.example.org:631/printers/vFax"}, {"name": "bös artig", "url": "http://x:631/printers/x"}, {"name": "vKasse", "url": "file:///C:/x"}},
				"ca":       ""}
			out := map[string]any{"auftraege": ts.auftraege, "stand": "s1"}
			if in["stand"] != "s1" {
				out["soll"] = soll
			}
			ts.auftraege = nil
			json.NewEncoder(w).Encode(out)
		case "/agent/ergebnis":
			ts.ergebnis = append(ts.ergebnis, in)
			w.Write([]byte(`{"ok":true}`))
		case "/agent/programm/" + assetName:
			w.Write(ts.programm)
		default:
			w.WriteHeader(404)
		}
	}))
	t.Cleanup(ts.srv.Close)
	return ts
}

func agentOrdner(t *testing.T, ts *testServer) string {
	t.Helper()
	dir := t.TempDir()
	sum := sha256.Sum256(ts.srv.Certificate().Raw)
	cfg := map[string]any{"server_url": ts.srv.URL, "launcher_key": "0123456789abcdef0123456789abcdef", "certificate_sha256": hex.EncodeToString(sum[:]), "kontexte": map[string]string{"kienzle-kasse": "/starter/kasse"}}
	data, _ := json.Marshal(cfg)
	os.WriteFile(filepath.Join(dir, "client.json"), data, 0o600)
	return dir
}

func TestAnmeldenUndAngleichen(t *testing.T) {
	ts := neuerTestServer(t, []byte("neues Programm"))
	dir := agentOrdner(t, ts)
	ac, err := Anmelden(dir, nil)
	if err != nil || ac.Name != "Empfang" {
		t.Fatalf("Anmelden: %v %+v", err, ac)
	}
	if st, _ := os.Stat(filepath.Join(dir, "agent.json")); st == nil || st.Mode().Perm() != 0o600 {
		t.Fatal("agent.json fehlt oder ist für andere lesbar")
	}
	exe := filepath.Join(dir, "kienzle-starter")
	os.WriteFile(exe, []byte("neues Programm"), 0o755) // gleiche Prüfsumme: keine Aktualisierung
	p := &testPlattform{ordner: dir}
	a := &Agent{Ordner: dir, Programm: exe, Plattform: p}
	if err := a.Laden(); err != nil {
		t.Fatal(err)
	}
	if err := a.Schritt(); err != nil {
		t.Fatalf("Schritt: %v", err)
	}
	if p.schemata != 1 || len(p.drucker) != 1 || len(p.drucker[0]) != 1 || p.drucker[0][0].Name != "vFax" {
		t.Fatalf("Aufrufe/Drucker nicht angeglichen (ungültige Drucker müssen fehlen): %+v", p)
	}
	neu, _ := loadConfig(filepath.Join(dir, "client.json"))
	if neu == nil || neu.LauncherKey != "neuer-schluessel-0123456789abcdef" || neu.Contexts["kienzle-telefon"] != "/starter/telefon" {
		t.Fatalf("client.json nicht nachgeführt: %+v", neu)
	}
	if err := a.Schritt(); err != nil || p.schemata != 1 || len(p.drucker) != 1 || ts.abfragen != 2 {
		t.Fatalf("zweite Abfrage ohne Sollzustand darf nichts neu einrichten: %v %+v", err, p)
	}
	if a.zustand != "Drucker: eingerichtet" {
		t.Fatalf("Zustand: %q", a.zustand)
	}
}

func TestGDTAuftrag(t *testing.T) {
	ts := neuerTestServer(t, []byte("x"))
	dir := agentOrdner(t, ts)
	if _, err := Anmelden(dir, nil); err != nil {
		t.Fatal(err)
	}
	exe := filepath.Join(dir, "kienzle-starter")
	os.WriteFile(exe, []byte("x"), 0o755)
	p := &testPlattform{ordner: dir}
	a := &Agent{Ordner: dir, Programm: exe, Plattform: p}
	a.Laden()
	gdt := "01380006301\r\n014300012345\r\n"
	ts.auftraege = []map[string]any{
		{"id": "a1", "typ": "gdt", "daten": map[string]string{"datei": "AUFRUFi.gdt", "inhalt": gdt}},
		{"id": "a2", "typ": "gdt", "daten": map[string]string{"datei": `..\..\Windows\x.gdt`, "inhalt": gdt}},
		{"id": "a3", "typ": "befehl", "daten": map[string]string{"cmd": "calc"}},
		{"id": "a4", "typ": "gdt", "daten": map[string]string{"datei": "B.gdt", "inhalt": "ümlaut"}},
	}
	if err := a.Schritt(); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(filepath.Join(dir, "gdt", "AUFRUFi.gdt")); string(b) != gdt {
		t.Fatalf("GDT-Datei: %q", b)
	}
	if len(ts.ergebnis) != 4 || ts.ergebnis[0]["ok"] != true || ts.ergebnis[1]["ok"] != false || ts.ergebnis[2]["ok"] != false || ts.ergebnis[3]["ok"] != false {
		t.Fatalf("Ergebnisse: %+v", ts.ergebnis)
	}
	if _, err := os.Stat(filepath.Join(dir, "gdt", "B.gdt")); err == nil {
		t.Fatal("Datei mit Nicht-ASCII-Inhalt darf nicht entstehen")
	}
}

func TestSelbstaktualisierung(t *testing.T) {
	ts := neuerTestServer(t, []byte("Programm Fassung 2"))
	dir := agentOrdner(t, ts)
	Anmelden(dir, nil)
	exe := filepath.Join(dir, "kienzle-starter")
	os.WriteFile(exe, []byte("Programm Fassung 1"), 0o755)
	a := &Agent{Ordner: dir, Programm: exe, Plattform: &testPlattform{ordner: dir}}
	a.Laden()
	if err := a.Schritt(); err != errNeustart {
		t.Fatalf("erwartet Neustart, bekam %v", err)
	}
	if b, _ := os.ReadFile(exe); string(b) != "Programm Fassung 2" {
		t.Fatalf("Programm nicht ersetzt: %q", b)
	}
	if b, _ := os.ReadFile(exe + ".alt"); string(b) != "Programm Fassung 1" {
		t.Fatal("alte Fassung fehlt")
	}
	// Falsche Prüfsumme: nichts wird ersetzt
	ts.mu.Lock()
	ts.programm = []byte("Programm Fassung 3")
	ts.mu.Unlock()
	a2 := &Agent{Ordner: dir, Programm: exe, Plattform: &testPlattform{ordner: dir}}
	a2.Laden()
	a2.aktualisieren(strings.Repeat("0", 64))
	if b, _ := os.ReadFile(exe); string(b) != "Programm Fassung 2" {
		t.Fatal("Programm trotz falscher Prüfsumme ersetzt")
	}
}

func TestAnmeldenMitFalschemSchluessel(t *testing.T) {
	ts := neuerTestServer(t, nil)
	dir := agentOrdner(t, ts)
	data, _ := os.ReadFile(filepath.Join(dir, "client.json"))
	os.WriteFile(filepath.Join(dir, "client.json"), []byte(strings.Replace(string(data), "0123456789abcdef0123456789abcdef", "ffffffffffffffffffffffffffffffff", 1)), 0o600)
	if _, err := Anmelden(dir, nil); err == nil || !strings.Contains(err.Error(), "Starter-Schlüssel") {
		t.Fatalf("falscher Schlüssel: %v", err)
	}
}

func TestProgrammName(t *testing.T) {
	if programmName("windows", "amd64") != "kienzle-starter-windows-amd64.exe" || programmName("darwin", "arm64") != "kienzle-starter-darwin-arm64" {
		t.Fatal("Programmname passt nicht zu scripts/starter-bauen")
	}
}

func TestDruckerSkriptLoeschtNie(t *testing.T) {
	if strings.Contains(druckerSkriptText(), "Remove-Printer") {
		t.Fatal("drucker-agent.ps1 darf vorhandene Drucker nie löschen")
	}
	if !strings.Contains(druckerSkriptText(), "Set-Printer -Name $d.name -PortName $d.url") || !strings.Contains(druckerSkriptText(), "Restart-Service Spooler") {
		t.Fatal("vorhandene Drucker werden umgestellt, nicht neu angelegt")
	}
}

func druckerSkriptText() string { b, _ := os.ReadFile("drucker-agent.ps1"); return string(b) }

func TestDruckerFehlerNichtBeiJederAbfrage(t *testing.T) {
	ts := neuerTestServer(t, []byte("x"))
	dir := agentOrdner(t, ts)
	Anmelden(dir, nil)
	exe := filepath.Join(dir, "kienzle-starter")
	os.WriteFile(exe, []byte("x"), 0o755)
	p := &testPlattform{ordner: dir, fehler: errors.New("Drucker: 0 von 1 eingerichtet; fehlen: vFax. Windows meldet: Zugriff verweigert")}
	a := &Agent{Ordner: dir, Programm: exe, Plattform: p}
	a.Laden()
	for i := 0; i < 3; i++ {
		a.Schritt()
	}
	if len(p.drucker) != 1 {
		t.Fatalf("nach einem Fehlschlag darf der Agent nicht bei jeder Abfrage neu versuchen: %d Versuche", len(p.drucker))
	}
	if !strings.Contains(a.zustand, "Windows meldet: Zugriff verweigert") || strings.HasPrefix(a.zustand, "Drucker: Drucker") {
		t.Fatalf("Rückmeldung: %q", a.zustand)
	}
	alt := DruckerPause
	DruckerPause = 0
	defer func() { DruckerPause = alt }()
	p.fehler = nil
	a.Schritt()
	if len(p.drucker) != 2 || a.zustand != "Drucker: eingerichtet" {
		t.Fatalf("nach der Pause neuer Versuch: %d %q", len(p.drucker), a.zustand)
	}
}
