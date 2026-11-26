//go:build !windows

package main

import "path/filepath"

// Andere Systeme: Der Agent ist zurzeit nur für Windows gedacht. Hier gibt es die Schnittstelle ohne Systemeingriffe
// (Tests und spätere macOS-/Linux-Fassung).
type anderePlattform struct{ ordner string }

func neuePlattform(ordner string) Plattform { return &anderePlattform{ordner: ordner} }

func datenOrdner() string { return configDir() }

func (p *anderePlattform) Schemata(map[string]string, string) error { return nil }
func (p *anderePlattform) Zertifizierungsstelle([]byte) error       { return nil }
func (p *anderePlattform) Drucker([]Drucker) (string, error)        { return "", nil }
func (p *anderePlattform) GDTOrdner() string                        { return filepath.Join(p.ordner, "gdt") }
