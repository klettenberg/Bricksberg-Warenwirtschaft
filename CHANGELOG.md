# Changelog

## [0.47.6] - 2025-11-04
### Behoben
- Die KI-Hintergrundverarbeitung wurde robuster gemacht, indem vor dem Start geprüft wird, ob ein API-Schlüssel konfiguriert ist, um Fehlversuche zu vermeiden.

## [0.47.5] - 2025-11-04
### Behoben
- Ein kritischer Fehler bei der Spaltenerkennung während des CSV-Imports von Inventardaten wurde korrigiert.
- Buttons auf den Analyse-Seiten repariert, die das Starten von Hintergrund-Jobs (z.B. Nachfrageanalyse, Beschreibungserstellung) verhinderten.

## [0.47.4] - 2025-11-04
### Behoben
- Ein Fehler im Batch-Prozessor wurde behoben, der dazu führte, dass die globale Job-Sperre zu früh aufgehoben und das zuverlässige Starten neuer Jobs verhindert wurde.

## [0.47.3] - 2025-11-04
### Behoben
- Ein Fehler wurde behoben, der zum Absturz von "Daten-Validierung"-Jobs im Batch-Prozessor führen konnte.

## [0.47.2] - 2025-11-04
### Behoben
- Ein Fehler in der Job-Warteschlange wurde behoben, der bei der automatischen Aktualisierung zu einer leeren Ansicht führen konnte, wenn kein Filter aktiv war.

## [0.47.1] - 2025-11-04
### Behoben
- Ein Fehler wurde behoben, bei dem die Job-Liste nach einer automatischen Aktualisierung leer angezeigt wurde.
- Buttons auf den Werkzeug- und Analyse-Seiten wurden durch stabilere Weiterleitungen korrigiert und funktionieren nun wie erwartet.

## [0.47.0] - 2025-11-04
### Hinzugefügt
- In der Inventarübersicht werden nun Marktplatz-Badges angezeigt, um auf einen Blick zu erkennen, auf welchen Plattformen (BrickOwl, WooCommerce, eBay) ein Artikel gelistet ist.

### Behoben
- Ein Fehler im eBay-Import wurde korrigiert, der verhinderte, dass Varianten eines Angebots korrekt mit dem zentralen Produktkatalog verknüpft wurden.

## [0.46.0] - 2025-11-04
### Geändert
- Umfassendes Refactoring der Codebasis zur Verbesserung der Leistung und zukünftigen Wartbarkeit.
- Einführung einer neuen zentralen Basisklasse für alle Import-Prozesse zur Reduzierung von redundantem Code und zur Verringerung der Dateigröße.

## [0.45.1] - 2025-11-04
### Behoben
- Ein Fehler bei der Zählung der Teile-Beziehungen im Dashboard wurde korrigiert.
- Die Teile-Zuordnung beim Import von Beziehungen wurde verbessert, um Fehler zu vermeiden.
- Die Codebasis wurde umfassend refaktorisiert, um Duplizierung in den Import-Handlern zu beseitigen. Dies reduziert die Gesamtgröße des Plugins und verbessert die Wartbarkeit, ohne die Funktionalität zu beeinträchtigen.

## [0.45.0] - 2025-11-04
### Hinzugefügt
- Eine neue Seite zur Datenkorrektur wurde hinzugefügt.
- Der eBay-Import unterstützt jetzt Angebots-Varianten.

### Geändert
- Die Benutzeroberfläche wurde umfassend modernisiert, inklusive eines neuen hierarchischen Menüs und verbessertem Branding.
- Die Job-Warteschlange zeigt nun standardmäßig aktive Jobs an.

## [0.44.0] - 2025-11-04
### Hinzugefügt
- Eine neue Funktion zur Hintergrund-Synchronisation von BrickOwl-Preisen wurde über eine dedizierte "API Synchronisation"-Seite hinzugefügt.
- Felder für SEO-optimierte Beschreibungen zu Sets, Minifiguren und Teilen hinzugefügt, inklusive einer Meta-Box zur Bearbeitung.
- Branding durch einheitlichen Footer auf allen Plugin-Seiten verbessert.
### Geändert
- Die Admin-Liste für Sets zeigt nun zusätzlich Thema, Teileanzahl und Erscheinungsjahr an.
- Die Admin-Liste für Farben enthält jetzt eine visuelle Farbvorschau und den RGB-Hex-Wert.
### Behoben
- Die Filter in der Job-Warteschlange funktionieren nun korrekt und leiten nicht mehr zum Dashboard um.
- Die Job-Warteschlange zeigt standardmäßig nur noch aktive Jobs (wartend, laufend, pausiert) an.
- Die Anzeige der Einstellungsfelder wurde korrigiert.

## [0.43.0] - 2025-11-03
### Hinzugefügt
- Eine neue Seite "API Synchronisation" wurde hinzugefügt, um Hintergrund-Jobs zur Synchronisation von Marktplatz-Daten zu starten.
- Eine Funktion zur vollständigen Synchronisation aller BrickOwl-Preise wurde als Hintergrund-Job implementiert.

### Behoben
- Ein Fehler wurde behoben, der verhinderte, dass die Felder auf der Einstellungsseite korrekt angezeigt wurden.

## [0.42.0] - 2025-11-03
### Geändert
- Die Menüpunkte für die Verwaltung der Katalog-Stammdaten (Teile, Sets etc.) wurden wiederhergestellt.
- Das Design der Import-Seiten und der Farb-Vorschauen wurde verbessert.

## [0.41.0] - 2025-11-03
### Hinzugefügt
- Die Inventar-Übersicht wurde um neue Filter für den WooCommerce-Status und den Bild-Status erweitert.
- Die gewählten Filtereinstellungen werden nun pro Benutzer gespeichert.
- Die Produktbilder in der Übersicht wurden für eine bessere Sichtbarkeit vergrößert.

## [0.40.0] - 2025-11-04
### Hinzugefügt
- **Erweiterte Filterung:** Die Inventar-Übersicht verfügt nun über neue Filter für den WooCommerce-Status (Synchronisiert / Nicht synchronisiert) und den Bild-Status (Mit Bild / Ohne Bild).
- **Persistente Filter:** Die ausgewählten Filtereinstellungen in der Inventar-Übersicht werden jetzt pro Benutzer gespeichert, um die wiederholte Auswahl zu vereinfachen. Ein "Filter zurücksetzen"-Button wurde hinzugefügt.

### Geändert
- **Größere Vorschaubilder:** Die Größe der Vorschaubilder in allen Admin-Listen (Katalog, Inventar) wurde auf 80x80 Pixel erhöht, um eine bessere Erkennbarkeit zu gewährleisten.

## [0.39.0] - 2025-11-03
### Hinzugefügt
- Robuste Fallback-Lösung für fehlende Bilder in Listen implementiert.
- Dashboard-Statistiken vervollständigt für einen umfassenderen Überblick.

### Geändert
- Die Anzeige von Farbvorschaubildern wurde verbessert.

### Behoben
- Ein Problem mit der automatischen Aktualisierung der Job-Liste wurde korrigiert.

## [0.38.0] - 2025-11-02
### Hinzugefügt
- Neues System zur Daten-Korrektur nach Imports. Nicht auflösbare Referenzen werden nun in einem neuen Tab gesammelt und mit intelligenten Lösungsvorschlägen zur einfachen Behebung angezeigt, ohne den Importprozess zu unterbrechen.

## [0.37.0] - 2025-11-02
### Hinzugefügt
- Hilfetexte für API-Einstellungsfelder, die erklären, wie und wo die jeweiligen API-Schlüssel zu finden sind.

## [0.36.0] - 2025-11-02
### Hinzugefügt
- Ein umfassendes Caching-System zur deutlichen Verbesserung der Performance wurde implementiert.
- Das Dashboard nutzt nun Transients, um Datenbankabfragen zu reduzieren und Ladezeiten zu verkürzen.
- Import-Prozesse werden durch einen In-Memory-Cache beschleunigt.

## [0.35.1] - 2025-11-02
### Geändert
- Die minimal erforderliche PHP- und WordPress-Version wurde im Plugin-Header spezifiziert.
- Die Plugin-Beschreibung wurde aktualisiert, um den aktuellen Funktionsumfang besser widerzuspiegeln.

## [0.35.0] - 2025-11-02
### Hinzugefügt
- Die Fortschrittsanzeige beim Import wurde um die Anzeige der Gesamtzeilenzahl erweitert.
- Fehlende Referenzen werden nun während des Imports für eine spätere Prüfung protokolliert.
- Die Artikelliste wurde durch neue Filter- und Suchfunktionen verbessert.

## [0.34.0] - 2025-11-02
### Hinzugefügt
- Die Plugin-Version wird nun zur schnellen Einsicht auf dem Dashboard angezeigt.
- Log-Nachrichten in der Job-Liste sind jetzt für einen direkten Zugriff auf Details klickbar.

### Geändert
- Das Backend-Design wurde durch die Verbesserung von Farben, Typografie und Animationen professionalisiert.

## [0.33.0] - 2025-11-02
### Geändert
- Die Leistung des Datenimports wurde durch die Implementierung einer Caching-Schicht, die Optimierung von Bild-Downloads und eine verbesserte Stapelverarbeitung erheblich gesteigert.

## [0.32.0] - 2025-11-02
### Hinzugefügt
- Das Dashboard wurde um einen Zähler für importierte 'Teile-Beziehungen' erweitert, um einen vollständigeren Überblick über die Katalogdaten zu geben.

## [0.31.0] - 2025-11-02
### Hinzugefügt
- Eine neue, umfassende Lagerortverwaltung wurde hinzugefügt, inklusive eines eigenen UI-Tabs, Statistiken und intelligenten Lagerort-Vorschlägen.
- Das Dashboard wurde um neue Aktionskacheln und Zähler für WooCommerce- und eBay-Artikel erweitert, um einen besseren Überblick zu ermöglichen.

## [0.30.0] - 2025-11-02
### Hinzugefügt
- Die letzte Log-Nachricht in der Job-Warteschlange ist nun klickbar, um den vollständigen Text anzuzeigen und das Lesen von abgeschnittenen Nachrichten zu erleichtern.

## [0.29.2] - 2025-11-02
### Verbessert
- Die letzte Log-Nachricht in der Job-Liste ist nun klickbar, um den vollständigen Text in einem Pop-up anzuzeigen, was die Lesbarkeit bei langen Nachrichten verbessert.

## [0.29.1] - 2025-11-02
### Behoben
- Ein unnötiger Aufruf von `flush_rewrite_rules()` bei der Plugin-Aktivierung und -Deaktivierung wurde entfernt, der auf bestimmten Serverkonfigurationen eine Warnung auslösen konnte.

## [0.29.0] - 2025-11-02
### Hinzugefügt
- Neue Daten-Werkzeuge zum Zurücksetzen von fehlgeschlagenen Jobs, zum Löschen temporärer Importdateien und zum vollständigen Zurücksetzen der Plugin-Daten.

## [0.28.1] - 2025-11-02
### Behoben
- Ein fataler Fehler (Division durch Null) bei der Fortschrittsanzeige von Katalog-Importen wurde behoben, der auftreten konnte, wenn eine CSV-Datei nur eine Kopfzeile enthielt.

## [0.28.0] - 2025-11-02
### Hinzugefügt
- Ein neues Feature zum Import von eBay-Angeboten für Sets und Minifiguren wurde implementiert.
- Ein neuer "eBay-Import"-Tab wurde zur Benutzeroberfläche hinzugefügt, um Importvorgänge zu verwalten.
- Das Hintergrund-Job-System wurde erweitert, um die importierten eBay-Daten asynchron zu verarbeiten.

## [0.27.0] - 2025-11-02
### Hinzugefügt
- AJAX-gesteuerte Synchronisation von Inventarartikeln als variable WooCommerce-Produkte.
- Preisabruf-API mit Historienfunktion zur Verfolgung von Preisänderungen.
- KI-gestützte Generierung von Produktbeschreibungen für synchronisierte Artikel.

## [0.26.0] - 2025-11-05
### Hinzugefügt
- **WooCommerce-Produktsynchronisation:** Eine neue UI-Aktion auf der "Inventar Verwalten"-Seite ermöglicht das manuelle Erstellen und Aktualisieren von variablen WooCommerce-Produkten aus `lww_inventory_item`-Einträgen. Der Prozess legt automatisch globale Attribute (Farbe, Zustand) und die entsprechenden Produktvarianten mit Preis und Menge an.
- **KI-gestützte Beschreibungen:** Neue Funktion im Tab "Analyse & KI" zum automatischen Generieren von SEO-optimierten Beschreibungen für Sets und Minifiguren sowie Kurzbeschreibungen für Teile. Der Prozess läuft als neuer Hintergrund-Job-Typ `description_generation`.
- **Listenpreis (UVP) für Sets:** Für Sets kann nun der offizielle LEGO-Listenpreis (UVP) in einer neuen Meta-Box auf der Bearbeitungsseite erfasst werden. Das Feld wird beim Import initialisiert.
- **WooCommerce-Integration erweitert:** Beim Erstellen/Aktualisieren von Produkten aus Teilen werden nun die automatisch generierten Kurz- und Langbeschreibungen in das WooCommerce-Produkt übernommen.

## [0.25.0] - 2025-10-31
### Hinzugefügt
- Sortierbare Spalten (Vorschau, ID, Erscheinungsjahr) zu den Admin-Listen für Farben und Sets hinzugefügt.

### Behoben
- Ein Fehler wurde behoben, bei dem das Branding-Logo aufgrund eines falschen Pfades nicht korrekt angezeigt wurde.

## [0.24.0] - 2025-10-31
### Hinzugefügt
- Filterfunktion für Kataloglisten zur einfacheren Navigation.
- Externe Links können nun auf Detailseiten hinzugefügt werden.
- Fallback-Bilder für Einträge ohne eigenes Bild zur Verbesserung der visuellen Konsistenz.
- Neues, kompakteres Listenlayout mit Animationen für eine modernere Darstellung.

## [0.23.0] - 2025-10-31
### Hinzugefügt
- Eine AJAX-Funktion zum Testen von API-Verbindungen.
- Job-Status-Badges und Farb-Vorschauen in den Admin-Listen für eine bessere Übersicht.
- Neue Meta-Boxen zur Verwaltung von Stücklisten.
- Eine neue Benutzeroberfläche zur Anzeige von API-Aufrufen.

## [0.22.1] - 2025-10-31
### Behoben
- Ein Fehler wurde behoben, der unter bestimmten Umständen zu "Constant already defined"-Warnungen führen konnte, indem das Initialisierungssystem des Plugins verbessert wurde.

## [0.22.0] - 2025-10-31
### Hinzugefügt
- Button zum Abrufen von BrickOwl-Preisen per API, inklusive einer Preis-Historie, implementiert.
- Neue Meta-Boxen zur Anzeige der Stücklisten für Sets und Minifiguren direkt auf den jeweiligen Bearbeitungsseiten.

## [0.21.0] - 2025-10-31
### Hinzugefügt
- Google Gemini als neue KI-Engine integriert.
- Funktion zum Testen der API-Verbindung für eine einfachere Einrichtung.
- Neues Panel zur Überwachung von API-Aufrufen und -Kosten.
### Geändert
- Das Bricksberg-Branding im gesamten Modul wurde vereinheitlicht.

## [0.20.0] - 2025-11-04
### Hinzugefügt
- **Gemini AI Integration:** Google Gemini als alternative KI für die Nachfrageanalyse hinzugefügt. Nutzer können nun in den Einstellungen zwischen OpenAI und Gemini wählen.
- **API-Verbindungstests:** 'Test Verbindung'-Buttons in den Einstellungen hinzugefügt, um die Gültigkeit von API-Schlüsseln (BrickOwl, Rebrickable, OpenAI, Gemini) per AJAX zu überprüfen.
- **API-Nutzungs-Log:** Neuer Menüpunkt 'API-Log' zur Anzeige aller ausgehenden API-Anfragen, inklusive (simulierter) Kosten.
- **Dashboard API-Statistik:** Neue Kachel im Dashboard zur Anzeige der API-Aufrufe und -Kosten der letzten 24 Stunden.

### Geändert
- **Branding:** Das 'Bricksberg'-Branding wurde durch ein Logo im Header und einen benutzerdefinierten Footer auf allen Plugin-Seiten verstärkt.

## [0.19.0] - 2025-10-31
### Hinzugefügt
- Die Platzhalter-Funktion für die KI-gestützte Nachfrageanalyse wurde durch eine voll funktionsfähige Implementierung ersetzt, die Nachfrage-Scores für Inventarartikel berechnet und anzeigt.

## [0.18.0] - 2025-10-31
### Hinzugefügt
- Neue, sortierbare Spalte 'Nachfrage (KI)' zur Inventarübersicht hinzugefügt. Diese dient als Vorbereitung für eine zukünftige KI-gestützte Analyse der Artikelnachfrage.

## [0.18.0] - 2025-11-03
### Added
- **Demand Column Placeholder:** Added a new, sortable 'Nachfrage (KI)' column to the inventory management UIs (`lww_inventory_item` list and custom inventory page).
- This column currently displays a placeholder ('---') and serves as the foundation for a future AI-driven feature to score item demand and price development.

## [0.17.0] - 2025-10-31
### Added
- New 'Backup-Import' feature for BrickOwl inventory, allowing the entire shop inventory to be updated from a BrickOwl backup file.

## [0.16.0] - 2025-10-31
### Added
- Implemented dashboard statistics for BrickOwl inventory, showing total piece count and total inventory value.

### Fixed
- Corrected the price display in admin lists to show three decimal places to reflect the full precision imported from BrickOwl.

## [0.15.0] - 2025-10-31
### Added
- New 'Backup-Import' feature to allow importing inventory directly from a BrickOwl Backup file.

## [0.14.0] - 2025-10-31
### Changed
- Refactored the core plugin loading mechanism to a class-based singleton pattern to prevent potential 'Cannot redeclare function' fatal errors and improve stability.

## [0.13.0] - 2025-11-02
### Changed
- **Major Refactor:** Converted the entire plugin loading mechanism into a class-based singleton pattern (`LWW_Core`). This architectural improvement provides a robust solution against "Cannot redeclare function" fatal errors that could occur in certain server environments or with conflicting plugins.

## [0.12.4] - 2025-10-31
### Fixed
- Resolved a fatal error and multiple warnings by ensuring the main plugin file is loaded only once.

## [0.12.3] - 2025-10-31
### Fixed
- Resolved a fatal error caused by function redeclaration, improving stability in environments where plugin files might be loaded multiple times.

## [0.12.2] - 2025-10-31
### Changed
- Performed a comprehensive code review and refactored shared helper functions for improved maintainability.

### Fixed
- Resolved an issue where the Inventory UI JavaScript was not loading correctly.

All notable changes to this project will be documented in this file.

## [0.12.1] - 2025-11-01
### Changed
- Refactored various helper functions (logging, cron jobs, data counters) into a new central `includes/lww-functions.php` file for better code organization and to avoid dependency issues.

### Fixed
- The JavaScript file required for the AJAX functionality on the 'Inventar Verwalten' page (`lww-admin-inventory.js`) was not being loaded. This has been corrected.
- Updated all internal version numbers in PHP file docblocks to `v12.0` for consistency with the main plugin version.
- Corrected the main plugin version constant `LWW_PLUGIN_VERSION` to match the plugin header.

## [0.12.0] - 2025-10-31
### Added
- Implemented the import of BrickOwl inventory files.
- Introduced a new 'Storage Location' taxonomy for better inventory organization.
- Added 'Storage Location' columns and filters to the admin inventory management pages.

## [0.11.0] - 2025-10-31
### Added
- Enhanced admin list views for parts, sets, minifigs, and inventory items with new, sortable columns for key data.
- Added direct links from related items in list views to their respective edit pages for improved navigation.

## [0.10.0] - 2025-10-31
### Added
- New sortable admin columns for inventory items (ID, Part, Color, Quantity) for improved usability.
- Support for importing additional data fields, including color external IDs and part manufacturing years.

### Changed
- Standardized internal meta keys with a `_lww_` prefix for better WordPress integration and consistency.
