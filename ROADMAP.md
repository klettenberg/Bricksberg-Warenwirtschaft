# Roadmap für Bricksberg WaWi

Diese Roadmap skizziert den Entwicklungsstand und die geplanten Funktionen für das Bricksberg Warenwirtschaftssystem.

**Aktuelle Version:** 6.50.0 (RC2)

---

## 🚀 Aktueller Fokus: Release

Das System ist nun Feature-Complete für den Release Candidate 2. Alle Hauptmodule (Logistik, Reporting, Mobile, Sync) sind implementiert.

---

## ✅ Abgeschlossene Meilensteine

### Meilenstein 7: Mobile & Reporting (v6.50+)
- [x] **Mobile App / PWA:** Dedizierte mobile Ansicht ohne Admin-Bar für fokussiertes Arbeiten im Lager.
- [x] **Reporting Dashboard:** Grafische Auswertung von Umsätzen und Kategorien-Performance.
- [x] **Cross-Site Sync:** Unterstützung für externe WooCommerce-Shops.

### Meilenstein 6: Logistik & Lager (v6.40+)
- [x] **Lagerkarte (Warehouse Map):** Visuelle Darstellung von Regalen und Fächern.
- [x] **Laufweg-Optimierung:** Pick-Listen werden intelligent nach Lagerort sortiert.
- [x] **Inventur-Scanner:** Tablet-optimierte UI für schnelle Bestandskorrekturen.
- [x] **Smart-Merge:** Automatische Vorschläge zum Zusammenführen ähnlicher Lagerorte.

### Meilenstein 5: KI & Pricing (v6.0 - v6.30)
- [x] **Pricing Engine:** Regelbasierte Preisgestaltung (z.B. "BrickLink Avg + 10%").
- [x] **KI-Integration:** Anbindung an OpenAI/Gemini für Preisanalyse und Texte.
- [x] **Automatisierte Übersetzung:** DeepL & KI-gestützte Übersetzung von Artikelnamen.
- [x] **Bild-Erkennung:** Fallback auf KI-generierte Bilder bei fehlenden Fotos.

### Meilenstein 4: Marktplatz-Integration (v5.x)
- [x] **BrickLink Sync:** Vollständiger OAuth-Support für Inventar und Bestellungen.
- [x] **BrickOwl Sync:** API-Anbindung für Bestand und Katalogdaten.
- [x] **eBay Listing Tool:** Erstellung von Angeboten für Sets/Minifigs direkt aus der WaWi.
- [x] **WooCommerce:** Nahtlose Integration als lokaler Shop.

### Meilenstein 1-3: Fundament (v1.0 - v4.x)
- [x] **Datenstruktur:** Custom Post Types für Teile, Sets, Minifiguren, Farben.
- [x] **Katalog-Import:** Performanter CSV-Import für Rebrickable-Daten.
- [x] **Batch-Processor:** Robustes Job-System mit Time-Boxing gegen Timeouts.
- [x] **Bundles:** Erstellung von virtuellen Paketen aus Einzelteilen.

---

## 🔮 Vision / Backlog (Zukunft)

- **Multi-User Kommissionierung:** Mehrere Picker arbeiten gleichzeitig an einer Bestellung (Live-Update).
- **POS-Integration:** Direkte Anbindung an Kassensysteme.
