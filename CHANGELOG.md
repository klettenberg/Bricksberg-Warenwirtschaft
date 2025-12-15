# Changelog

## [0.48.0] - 2025-12-07
### Hinzugefügt\n- Implementierung der fehlenden Funktionen für den Grid-Editor und das Auto-Mapping in includes/lww-warehouse-map.php.\n### Behoben\n- Ein Syntaxfehler in includes/lww-warehouse-map.php wurde behoben.

## [0.47.0] - 2025-12-07
### Hinzugefügt\n- BrickLink Inventory API Import implementiert, inklusive Chunking-System für große Datenmengen.\n### Behoben\n- Fatale Fehler behoben durch Wiederherstellung aller Import-Handler-Funktionen.

## [0.46.0] - 2025-12-07
### Hinzugefügt
- Detaillierte Fortschrittsanzeigen (Prozent/Status-Text) im Batch-Prozessor und der Job-Liste.
### Geändert
- Die Logik für den 'Marktdaten abrufen'-Button wurde zur ODER-Verknüpfung der API-Keys geändert.

## [0.45.0] - 2025-12-07
### Hinzugefügt
- Eine grafische Gesamtkarte (Raumplan) für das Lager wurde implementiert.
- Eine "Auto-Map"-Funktion hinzugefügt, die Lagerort-Namen aus Importen (z.B. BrickOwl Notizen wie 'A-05-01') automatisch den entsprechenden Regalfächern zuordnet.

## [0.44.0] - 2025-12-07
### Geändert- Deutlich schnellere CSV-Importe durch optimiertes Parsing.- Verbesserte Leistung bei Datenbankabfragen durch Einführung von Object-Caching.- Reduzierung der Systemlast bei der Berechnung von Statistiken.

## [0.43.0] - 2025-12-07
### Hinzugefügt\n- Das Reporting-Modul wurde massiv erweitert.\n- Neue KPIs und Umsatz nach Plattform zur detaillierteren Analyse hinzugefügt.\n- Erkennung von Preisausreißern zur Korrektur des zu hohen Lagerwerts implementiert.\n- Analyse potenzieller Dubletten zur Verbesserung der Datenqualität eingeführt.

## [0.42.0] - 2025-12-07
### Hinzugefügt- Robuster Batch-Prozessor mit Absturz-Watchdog.- Funktion zur Umwandlung von Import-Bestellungen in WooCommerce-Bestellungen.### Geändert- Import-Benutzeroberfläche als geführter Assistent für verbesserte Datenreihenfolge neu gestaltet.### Behoben- Admin-Menü-Link repariert.

## [0.41.2] - 2025-12-07
### Behoben\n- Taxonomie-Labels für Jahreszahlen präzisiert.\n- Admin-Stylesheet repariert und optimiert.

## [0.41.1] - 2025-12-07
### Behoben
- Fehlerbehebung für den Job-Typ 'delete_stubs' im Batch-Prozessor durch Hinzufügen des fehlenden Cases und Bereinigung des Job-Typ-Strings.

## [0.41.0] - 2025-12-07
### Hinzugefügt\n- Vollständige Implementierung der BrickOwl-Inventarsynchronisation mit direkter API-Anbindung.\n- Erweiterung des Import-Handlers zur Speicherung von BrickOwl Lot-IDs.

## [0.40.0] - 2025-12-07
### Hinzugefügt
- Implementierung aller fehlenden Batch-Prozessoren für Hintergrund-Jobs (Analysen, Syncs, Importe).
- Erweiterung der BrickLink API um den Abruf von Bestellungen.

## [0.39.0] - 2025-12-07
### Hinzugefügt- Implementierung fehlender BrickLink-API-Methoden für Preis-Guide und Inventar.- Integration des Brickset-Imports im Batch-Prozessor zur Speicherung von UVPs.

## [0.38.0] - 2025-12-07
### Hinzugefügt\n- Funktion zum Abrufen der Lagerorte im Regal implementiert.\n### Geändert\n- Logik zum Zuweisen und Löschen von Lagerplätzen im Raster verbessert.

## [0.37.0] - 2025-12-06
### Hinzugefügt\n- Implementierung des 'Mobile App'-Modus für eine ablenkungsfreie Inventur.\n- Einführung eines grafischen Reporting-Moduls zur Analyse von Umsatz und Lagerwerten.

## [0.50.0] - 2025-12-07
### Hinzugefügt
- **Mobile App Modus:** Neuer "Distraction-Free" Fullscreen-Modus für die Inventur auf Mobilgeräten. Startbar über das Dashboard.
- **Reporting Modul:** Neues Menü "Berichte" mit grafischen Auswertungen (HTML5 Canvas) zu Umsatzentwicklung und Lagerwertverteilung.

## [0.36.0] - 2025-12-06
### Hinzugefügt
- Implementierung der Rebrickable API-Synchronisierung im Batch-Prozessor.
- Erweiterung der Shop-Funktionalitäten im Frontend-Katalog, inklusive Warenkorb-Button.
- Ausbau der Marketing-Seite mit einer Roadmap.
- Das BrickOwl-Logging wurde erweitert.

## [0.35.0] - 2025-12-06
### Hinzugefügt
- Visuelle Lade-Indikatoren (Spinner und Opazitätsänderung) in der Job-Liste sowie globale CSS-Klassen für Ladevorgänge integriert, um das Nutzerfeedback bei Datenaktualisierungen zu verbessern.

## [0.34.2] - 2025-12-05
### Behoben
- Entfernung der redundanten und veralteten Datei 'lww-batch-processor.php' aus dem Hauptverzeichnis, um die korrekte Dateistruktur zu gewährleisten.

## [0.34.1] - 2025-12-05
### Behoben
- Das Problem 'Kein Handler gefunden' im Batch-Prozessor wurde durch explizites Nachladen der Import-Handler behoben.

### Geändert
- Das Handbuch wurde um eine Anleitung zur Erstellung von WooCommerce API-Keys erweitert.
