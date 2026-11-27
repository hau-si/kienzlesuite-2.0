package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func testConfig(t *testing.T, srv *httptest.Server) *Config {
	t.Helper()
	sum := sha256.Sum256(srv.Certificate().Raw)
	return &Config{ServerURL: srv.URL, LauncherKey: "0123456789abcdef0123456789abcdef", CertSHA256: hex.EncodeToString(sum[:]),
		Contexts: map[string]string{"kienzle-kasse": "/starter/kasse", "kienzle-signatur": "/starter/signatur", "praxisherz-signatur": "/starter/signatur"}}
}

func TestStartMitSchemaUndPfad(t *testing.T) {
	var gotPath, gotAuth string
	var gotBody map[string]any
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath, gotAuth = r.URL.Path, r.Header.Get("Authorization")
		b, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(b, &gotBody)
		w.Write([]byte(`{"url":"https://` + r.Host + `/#launch=` + strings.Repeat("a", 64) + `"}`))
	}))
	defer srv.Close()
	cfg := testConfig(t, srv)
	link, err := run(cfg, "kienzle-signatur://?kontextId=ctx-1&fhirBasisUrl=https%3A%2F%2Ft2.example.org%2Ffhir&oAuthToken=tok&dokument=igel", srv.Client())
	if err != nil {
		t.Fatal(err)
	}
	if gotPath != "/starter/signatur" || gotAuth != "Bearer "+cfg.LauncherKey {
		t.Fatalf("Pfad %q, Kopf %q", gotPath, gotAuth)
	}
	if gotBody["context_id"] != "ctx-1" || gotBody["oauth_token"] != "tok" || gotBody["action"] != "launch" || gotBody["fhir_base_url"] != "https://t2.example.org/fhir" {
		t.Fatalf("Inhalt: %v", gotBody)
	}
	if extra, _ := gotBody["extra"].(map[string]any); extra["dokument"] != "igel" {
		t.Fatalf("Extra fehlt: %v", gotBody)
	}
	if !strings.Contains(link, "#launch=") {
		t.Fatal(link)
	}
	// Kasse wie bisher
	if _, err := run(cfg, "kienzle-kasse://?kontextId=ctx-2&fhirBasisUrl=x&oAuthToken=y", srv.Client()); err != nil || gotPath != "/starter/kasse" {
		t.Fatalf("Kasse: %v %q", err, gotPath)
	}
}

func TestUnbekanntesSchemaUndFehler(t *testing.T) {
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(401)
		w.Write([]byte(`{"error":"Starter nicht autorisiert."}`))
	}))
	defer srv.Close()
	cfg := testConfig(t, srv)
	if _, err := run(cfg, "kienzle-fremd://?kontextId=a", srv.Client()); err == nil || !strings.Contains(err.Error(), "nicht eingerichtet") {
		t.Fatalf("unbekanntes Schema: %v", err)
	}
	if _, err := run(cfg, "kienzle-kasse://?fhirBasisUrl=x", srv.Client()); err == nil {
		t.Fatal("ohne Kontext muss es scheitern")
	}
	if _, err := run(cfg, "kienzle-kasse://?kontextId=a", srv.Client()); err == nil || !strings.Contains(err.Error(), "Starter nicht autorisiert") {
		t.Fatalf("Serverfehler: %v", err)
	}
}

func TestFremderLinkWirdNichtGeoeffnet(t *testing.T) {
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"url":"https://boese.example.org/#launch=` + strings.Repeat("a", 64) + `"}`))
	}))
	defer srv.Close()
	if _, err := run(testConfig(t, srv), "kienzle-kasse://?kontextId=a", srv.Client()); err == nil {
		t.Fatal("Link auf fremden Server muss abgelehnt werden")
	}
}

func TestZertifikatPruefung(t *testing.T) {
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte(`{}`)) }))
	defer srv.Close()
	cfg := testConfig(t, srv)
	cfg.CertSHA256 = strings.Repeat("0", 64)
	if _, err := run(cfg, "kienzle-kasse://?kontextId=a", nil); err == nil || !strings.Contains(err.Error(), "stimmt nicht") {
		t.Fatalf("falscher Fingerabdruck muss scheitern: %v", err)
	}
}

func TestConfigPruefung(t *testing.T) {
	dir := t.TempDir()
	write := func(s string) string {
		p := filepath.Join(dir, "c.json")
		os.WriteFile(p, []byte(s), 0o600)
		return p
	}
	key := strings.Repeat("a", 24)
	hash := strings.Repeat("b", 64)
	if _, err := loadConfig(write(`{"server_url":"https://s.example.org","launcher_key":"` + key + `","certificate_sha256":"` + hash + `"}`)); err == nil {
		t.Fatal("ohne Startkontexte darf die Konfiguration nicht gelten")
	}
	bad := []string{
		`{"server_url":"http://s.example.org","launcher_key":"` + key + `","certificate_sha256":"` + hash + `"}`,
		`{"server_url":"https://s.example.org/x","launcher_key":"` + key + `","certificate_sha256":"` + hash + `"}`,
		`{"server_url":"https://s.example.org","launcher_key":"kurz","certificate_sha256":"` + hash + `"}`,
		`{"server_url":"https://s.example.org","launcher_key":"` + key + `","certificate_sha256":"zz"}`,
		`{"server_url":"https://s.example.org","launcher_key":"` + key + `","certificate_sha256":"` + hash + `","kontexte":{"Fremd":"/a"}}`,
		`{"server_url":"https://s.example.org","launcher_key":"` + key + `","certificate_sha256":"` + hash + `","kontexte":{"kienzle-x":"/../a"}}`,
	}
	for i, b := range bad {
		if _, err := loadConfig(write(b)); err == nil {
			t.Errorf("ungültige Konfiguration %d wurde akzeptiert", i)
		}
	}
}

func TestURLArgument(t *testing.T) {
	if urlArgument([]string{"--url", "kienzle-kasse://?a=1"}) != "kienzle-kasse://?a=1" || urlArgument([]string{"kienzle-kasse://x"}) != "kienzle-kasse://x" || urlArgument(nil) != "" {
		t.Fatal("Argumente")
	}
}

func TestPinnedClientMitRichtigemFingerabdruck(t *testing.T) {
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"url":"https://` + r.Host + `/#launch=` + strings.Repeat("a", 64) + `"}`))
	}))
	defer srv.Close()
	if _, err := run(testConfig(t, srv), "kienzle-kasse://?kontextId=a", nil); err != nil {
		t.Fatalf("richtiger Fingerabdruck muss gehen: %v", err)
	}
}

// Neue Adresse praxisherz-… und frühere kienzle-… führen zum selben Modul; fremde Präfixe bleiben abgelehnt.
func TestNeueUndFruehereAdresse(t *testing.T) {
	var pfade []string
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		pfade = append(pfade, r.URL.Path)
		w.Write([]byte(`{"url":"https://` + r.Host + `/#launch=` + strings.Repeat("b", 64) + `"}`))
	}))
	defer srv.Close()
	cfg := testConfig(t, srv)
	for _, u := range []string{"praxisherz-signatur://?kontextId=a", "kienzle-signatur://?kontextId=a"} {
		if _, err := run(cfg, u, srv.Client()); err != nil {
			t.Fatalf("%s: %v", u, err)
		}
	}
	if len(pfade) != 2 || pfade[0] != "/starter/signatur" || pfade[1] != "/starter/signatur" {
		t.Fatalf("Pfade: %v", pfade)
	}
	if _, err := run(cfg, "fremd-signatur://?kontextId=a", srv.Client()); err == nil {
		t.Fatal("fremdes Schema angenommen")
	}
}
