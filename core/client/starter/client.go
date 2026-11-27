package main

import (
	"bytes"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

// Config entspricht client.json. Contexts ordnet jedem URL-Schema die Adresse des Moduls zu (Pfad auf dem Server).
type Config struct {
	ServerURL   string            `json:"server_url"`
	LauncherKey string            `json:"launcher_key"`
	CertSHA256  string            `json:"certificate_sha256"`
	Contexts    map[string]string `json:"kontexte"`
	// CA ist die Zertifizierungsstelle der Suite (ca.crt neben client.json). Mit ihr bleibt die Verbindung auch nach einem neuen
	// Serverzertifikat (z. B. anderer Servername) gültig; der Fingerabdruck oben gilt weiter für ältere Pakete.
	CA *x509.Certificate `json:"-"`
}

var (
	schemeRe = regexp.MustCompile(`^(praxisherz|kienzle)-[a-z0-9-]{1,30}$`) // kienzle-… = frühere Adressen in T2med
	pathRe   = regexp.MustCompile(`^/[A-Za-z0-9._/-]{1,80}$`)
	keyRe    = regexp.MustCompile(`^[A-Za-z0-9._~+/=-]{16,200}$`)
)

func nowString() string { return time.Now().Format("2006-01-02 15:04:05") }

func loadConfig(file string) (*Config, error) {
	b, err := os.ReadFile(file)
	if err != nil {
		return nil, fmt.Errorf("client.json nicht lesbar: %w", err)
	}
	var c Config
	if err := json.Unmarshal(b, &c); err != nil {
		return nil, fmt.Errorf("client.json ungültig: %w", err)
	}
	if len(c.Contexts) == 0 {
		return nil, errors.New("client.json: keine Startkontexte (Paket neu laden)")
	}
	for s, p := range c.Contexts {
		if !schemeRe.MatchString(s) || !pathRe.MatchString(p) || strings.Contains(p, "..") {
			return nil, fmt.Errorf("client.json: ungültiger Eintrag %q", s)
		}
	}
	u, err := url.Parse(c.ServerURL)
	if err != nil || u.Scheme != "https" || u.Host == "" || u.User != nil || (u.Path != "" && u.Path != "/") {
		return nil, errors.New("client.json: server_url muss eine https-Adresse ohne Pfad sein")
	}
	if !keyRe.MatchString(c.LauncherKey) {
		return nil, errors.New("client.json: launcher_key fehlt oder ist ungültig")
	}
	if c.CertSHA256 != "" {
		if _, err := hex.DecodeString(c.CertSHA256); err != nil || len(c.CertSHA256) != 64 {
			return nil, errors.New("client.json: certificate_sha256 ist ungültig")
		}
	}
	c.CertSHA256 = strings.ToLower(c.CertSHA256)
	if pem, err := os.ReadFile(filepath.Join(filepath.Dir(file), "ca.crt")); err == nil {
		c.CA = parseCA(pem)
	}
	return &c, nil
}

// Launch ist der Inhalt des Aufrufs aus T2med.
type Launch struct {
	Scheme  string
	Context string
	FHIRURL string
	Token   string
	Extra   map[string]string
}

var contextRe = regexp.MustCompile(`^[A-Za-z0-9._-]{1,128}$`)

// parseLaunch zerlegt kienzle-…://?kontextId=…&fhirBasisUrl=…&oAuthToken=… (Schreibweise der Namen wie in T2med; die
// Namen context_id usw. gehen auch). Weitere einfache Parameter werden als Extra weitergegeben (höchstens 8).
func parseLaunch(raw string, cfg *Config) (*Launch, string, error) {
	u, err := url.Parse(raw)
	if err != nil || !schemeRe.MatchString(u.Scheme) {
		return nil, "", errors.New("unbekanntes Adressformat")
	}
	path, ok := cfg.Contexts[u.Scheme]
	if !ok {
		return nil, "", fmt.Errorf("das Schema %s ist an diesem Arbeitsplatz nicht eingerichtet (Paket erneut installieren)", u.Scheme)
	}
	q := u.Query()
	pick := func(names ...string) string {
		for _, n := range names {
			if v := q.Get(n); v != "" {
				return v
			}
		}
		return ""
	}
	l := &Launch{Scheme: u.Scheme, Context: pick("kontextId", "context_id"), FHIRURL: pick("fhirBasisUrl", "fhir_base_url"), Token: pick("oAuthToken", "oauth_token"), Extra: map[string]string{}}
	if !contextRe.MatchString(l.Context) {
		return nil, "", errors.New("die Kontext-Kennung fehlt oder ist ungültig")
	}
	known := map[string]bool{"kontextId": true, "context_id": true, "fhirBasisUrl": true, "fhir_base_url": true, "oAuthToken": true, "oauth_token": true}
	for k, v := range q {
		if known[k] || len(l.Extra) >= 8 || len(k) > 40 || len(v[0]) > 200 || !regexp.MustCompile(`^[A-Za-z0-9_]+$`).MatchString(k) {
			continue
		}
		l.Extra[k] = v[0]
	}
	return l, path, nil
}

// pinnedClient prüft das Serverzertifikat über den Fingerabdruck (und Gültigkeit und Name), nicht über Zertifizierungsstellen.
func pinnedClient(cfg *Config) *http.Client {
	host := hostOnly(cfg.ServerURL)
	tr := &http.Transport{
		TLSClientConfig: &tls.Config{
			MinVersion:         tls.VersionTLS12,
			InsecureSkipVerify: true, // ersetzt durch die Prüfung unten
			VerifyPeerCertificate: func(raw [][]byte, _ [][]*x509.Certificate) error {
				if len(raw) == 0 {
					return errors.New("der Server hat kein Zertifikat geschickt")
				}
				sum := sha256.Sum256(raw[0])
				cert, err := x509.ParseCertificate(raw[0])
				if err != nil {
					return err
				}
				if hex.EncodeToString(sum[:]) != cfg.CertSHA256 {
					// Sonst muss das Zertifikat von der Zertifizierungsstelle der Suite stammen (und nur von ihr).
					if cfg.CA == nil {
						return errors.New("das Zertifikat des Servers stimmt nicht mit dem eingerichteten überein")
					}
					pool := x509.NewCertPool()
					pool.AddCert(cfg.CA)
					if _, err := cert.Verify(x509.VerifyOptions{Roots: pool, DNSName: host, KeyUsages: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}}); err != nil {
						return errors.New("das Zertifikat des Servers stammt nicht von der Zertifizierungsstelle der Suite")
					}
				}
				now := time.Now()
				if now.Before(cert.NotBefore) || now.After(cert.NotAfter) {
					return errors.New("das Zertifikat des Servers ist abgelaufen oder noch nicht gültig")
				}
				if err := cert.VerifyHostname(host); err != nil {
					return errors.New("das Zertifikat passt nicht zum Servernamen")
				}
				return nil
			},
		},
		DisableKeepAlives: true,
	}
	return &http.Client{Transport: tr, Timeout: 20 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
}

// parseCA liest eine PEM-Datei mit genau dem Zertifikat einer Zertifizierungsstelle (sonst nil).
func parseCA(data []byte) *x509.Certificate {
	b, _ := pem.Decode(data)
	if b == nil || b.Type != "CERTIFICATE" {
		return nil
	}
	c, err := x509.ParseCertificate(b.Bytes)
	if err != nil || !c.IsCA {
		return nil
	}
	return c
}

func hostOnly(serverURL string) string {
	u, _ := url.Parse(serverURL)
	h := u.Host
	if hh, _, err := net.SplitHostPort(h); err == nil {
		return hh
	}
	return h
}

// run schickt den Aufruf an das Modul und liefert den einmaligen Browserlink. client darf in Tests ersetzt werden.
func run(cfg *Config, raw string, client *http.Client) (string, error) {
	l, path, err := parseLaunch(raw, cfg)
	if err != nil {
		return "", err
	}
	if client == nil {
		client = pinnedClient(cfg)
	}
	body := map[string]any{"action": "launch", "context_id": l.Context, "fhir_base_url": l.FHIRURL, "oauth_token": l.Token}
	if len(l.Extra) > 0 {
		body["extra"] = l.Extra
	}
	payload, _ := json.Marshal(body)
	req, err := http.NewRequest(http.MethodPost, strings.TrimRight(cfg.ServerURL, "/")+path, bytes.NewReader(payload))
	if err != nil {
		return "", err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+cfg.LauncherKey)
	resp, err := client.Do(req)
	if err != nil {
		return "", fmt.Errorf("der Server ist nicht erreichbar: %w", err)
	}
	defer resp.Body.Close()
	data, _ := io.ReadAll(io.LimitReader(resp.Body, 65536))
	var out struct {
		URL   string `json:"url"`
		Error string `json:"error"`
	}
	_ = json.Unmarshal(data, &out)
	if resp.StatusCode != http.StatusOK || out.URL == "" {
		msg := out.Error
		if msg == "" {
			msg = fmt.Sprintf("Antwort %d", resp.StatusCode)
		}
		return "", fmt.Errorf("der Server lehnt den Start ab: %s", msg)
	}
	// Nur ein Link auf denselben Server wird geöffnet.
	lu, err := url.Parse(out.URL)
	su, _ := url.Parse(cfg.ServerURL)
	if err != nil || lu.Scheme != "https" || lu.Host != su.Host {
		return "", errors.New("der Server hat einen unerwarteten Link geschickt")
	}
	return out.URL, nil
}
