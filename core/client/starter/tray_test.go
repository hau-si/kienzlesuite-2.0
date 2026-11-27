package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestAnsichtAgentLaeuftNicht(t *testing.T) {
	jetzt := time.Unix(1_800_000_000, 0)
	v := AnsichtBauen(Status{}, false, jetzt)
	if v.Farbe != Rot || v.Kopf != "Agent läuft nicht" {
		t.Fatalf("ohne status.json: %+v", v)
	}
	v = AnsichtBauen(Status{Zeit: jetzt.Unix() - 400, Verbunden: true, DruckerOk: true, Drucker: []string{"vKasse"}}, true, jetzt)
	if v.Farbe != Rot || v.Kopf != "Agent läuft nicht" {
		t.Fatalf("veralteter Zustand muss rot sein: %+v", v)
	}
}

func TestAnsichtFarben(t *testing.T) {
	jetzt := time.Unix(1_800_000_000, 0)
	gut := Status{Zeit: jetzt.Unix(), Verbunden: true, Name: "Empfang", Server: "kienzle.example.org", Version: "2.1.0",
		Drucker: []string{"vKasse", "vTermine"}, DruckerOk: true, Aufrufe: 3, Zertifikat: true}
	v := AnsichtBauen(gut, true, jetzt)
	if v.Farbe != Gruen || v.Kopf != "verbunden" || len(v.Zeilen) != 4 || !strings.Contains(v.Unter, "Empfang") || !strings.Contains(v.Tooltip, "Empfang") {
		t.Fatalf("alles gut: %+v", v)
	}
	halb := gut
	halb.DruckerOk, halb.DruckerTxt = false, "Drucker: 1 von 2 eingerichtet; fehlen: vTermine"
	if v := AnsichtBauen(halb, true, jetzt); v.Farbe != Gelb || v.Zeilen[0].Farbe != Gelb || !strings.Contains(v.Zeilen[0].Wert, "vTermine") {
		t.Fatalf("fehlende Drucker gelb: %+v", v)
	}
	weg := gut
	weg.Verbunden, weg.Fehler = false, "Server nicht erreichbar"
	if v := AnsichtBauen(weg, true, jetzt); v.Farbe != Rot || v.Kopf != "keine Verbindung" || v.Zeilen[0].Wert != "Server nicht erreichbar" {
		t.Fatalf("keine Verbindung rot: %+v", v)
	}
	keine := gut
	keine.Drucker = nil
	if v := AnsichtBauen(keine, true, jetzt); v.Farbe != Gruen || v.Zeilen[0].Farbe != Grau {
		t.Fatalf("keine Drucker grau, zählt nicht: %+v", v)
	}
}

func TestSymbolBild(t *testing.T) {
	for _, n := range []int{16, 20, 24, 32} {
		px := SymbolBild(n, Rot)
		if len(px) != n*n || px[0]>>24 != 0 || px[n/2*n+n/4] == 0 {
			t.Fatalf("Größe %d: Ecke muss durchsichtig, Fläche gefüllt sein", n)
		}
		if px[(n*25/32)*n+n*25/32] != 0xFFD4473A {
			t.Fatalf("Größe %d: Ampelpunkt fehlt", n)
		}
	}
}

func TestStatusUndAnstoss(t *testing.T) {
	dir := t.TempDir()
	a := &Agent{Ordner: dir}
	a.status.Name, a.status.Drucker, a.status.DruckerOk = "Empfang", []string{"vKasse"}, true
	a.statusSchreiben(nil)
	s, ok := StatusLesen(dir)
	if !ok || !s.Verbunden || s.Name != "Empfang" || s.Version != version || time.Now().Unix()-s.Zeit > 5 {
		t.Fatalf("status.json: %v %+v", ok, s)
	}
	a.statusSchreiben(os.ErrDeadlineExceeded)
	if s, _ := StatusLesen(dir); s.Verbunden || s.Fehler == "" {
		t.Fatalf("Fehler nicht übernommen: %+v", s)
	}
	a.druckerH, a.druckerFehlerH, a.stand = "h", "f", "s"
	a.anstoss()
	if a.druckerH != "h" {
		t.Fatal("ohne Anstoß-Datei nichts zurücksetzen")
	}
	os.MkdirAll(filepath.Join(dir, "anstoss"), 0o755)
	os.WriteFile(filepath.Join(dir, "anstoss", "drucker-pruefen"), nil, 0o644)
	a.anstoss()
	if a.druckerH != "" || a.druckerFehlerH != "" || a.stand != "" {
		t.Fatal("Anstoß muss die Druckerprüfung erzwingen")
	}
	if _, err := os.Stat(filepath.Join(dir, "anstoss", "drucker-pruefen")); err == nil {
		t.Fatal("Anstoß-Datei muss danach weg sein")
	}
}

func TestAnsichtWirdAktualisiert(t *testing.T) {
	jetzt := time.Unix(1_800_000_000, 0)
	gut := Status{Zeit: jetzt.Unix(), Verbunden: true, Name: "Empfang", Version: "2.3.2", WirdAktualisiert: true,
		Drucker: []string{"vKasse"}, DruckerOk: true, Aufrufe: 1, Zertifikat: true}
	v := AnsichtBauen(gut, true, jetzt)
	if v.Farbe != Blau || v.Kopf != "wird aktualisiert …" || !strings.Contains(v.Zeilen[0].Wert, "bitte kurz warten") {
		t.Fatalf("wird aktualisiert muss blau sein, nicht rot: %+v", v)
	}
	// Auch mit zwei Minuten altem Zeitstempel (länger als die sonst übliche 3-Minuten-Frist) noch blau, nicht rot.
	spaeter := jetzt.Add(130 * time.Second)
	if v := AnsichtBauen(gut, true, spaeter); v.Farbe != Blau {
		t.Fatalf("wird aktualisiert bleibt blau, solange die Frist nicht um ist: %+v", v)
	}
	// Länger als aktualisierenFrist: kein Update findet mehr statt, muss wie gewohnt rot werden.
	vielSpaeter := jetzt.Add((aktualisierenFrist + 60) * time.Second)
	if v := AnsichtBauen(gut, true, vielSpaeter); v.Farbe != Rot || v.Kopf != "Agent läuft nicht" {
		t.Fatalf("nach Ablauf der Frist muss es wie ein hängender Agent aussehen: %+v", v)
	}
}

func TestAgentAktualisiert(t *testing.T) {
	if AgentAktualisiert(Status{}, false, "2.3.1") {
		t.Fatal("ohne status.json nicht neu starten")
	}
	if AgentAktualisiert(Status{Version: "2.3.1"}, true, "2.3.1") {
		t.Fatal("gleiche Version: nicht neu starten")
	}
	if !AgentAktualisiert(Status{Version: "2.3.2"}, true, "2.3.1") {
		t.Fatal("Dienst schon aktualisiert (neuere Version): Symbol muss sich neu starten")
	}
	if AgentAktualisiert(Status{Version: ""}, true, "2.3.1") {
		t.Fatal("leere Version (altes/unvollständiges status.json): nicht neu starten")
	}
}

func TestAnsichtAlteDrucker(t *testing.T) {
	jetzt := time.Unix(1_800_000_000, 0)
	s := Status{Zeit: jetzt.Unix(), Verbunden: true, Drucker: []string{"vTermine"}, DruckerOk: true, Aufrufe: 1, Zertifikat: true,
		AltDrucker: []string{"Terminzettel", "SumUp-Rechnung"}}
	v := AnsichtBauen(s, true, jetzt)
	if v.Farbe != Gelb || len(v.Entfernen) != 2 || v.Zeilen[1].Titel != "Alte Drucker" || !strings.Contains(v.Zeilen[1].Wert, "SumUp-Rechnung") {
		t.Fatalf("alte Drucker gelb mit Knopf: %+v", v)
	}
	s.AltDrucker = nil
	if v := AnsichtBauen(s, true, jetzt); v.Farbe != Gruen || len(v.Entfernen) != 0 {
		t.Fatalf("ohne alte Drucker kein Knopf: %+v", v)
	}
}
