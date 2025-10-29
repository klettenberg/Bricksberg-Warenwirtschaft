Bricksberg Warenwirtschaft (WaWi) für WordPress
Version: 0.9.7
Autor: Olaf Ziörjen (bricksberg.eu)
Beschreibung: Dieses Plugin dient als zentrale Verwaltung für LEGO Katalogdaten und synchronisiert WooCommerce mit BrickOwl (als Master-Bestandsquelle) sowie potenziell BrickLink. Der Datenimport erfolgt primär über die CSV-Downloads von Rebrickable.
Funktionsweise (Kurzfassung)
 * Katalog-Aufbau: Importiert die umfangreichen Katalogdaten (Teile, Farben, Sets, Minifigs, Themen, Kategorien, Beziehungen) aus den Rebrickable CSV-Downloads in eine eigene, relationale Datenstruktur innerhalb von WordPress (Custom Post Types & Taxonomien).
 * Inventar-Import: Importiert deinen persönlichen BrickOwl-Bestand (inkl. Farben, Zustand, Menge, Preis) in eine separate Liste innerhalb von WordPress.
 * WooCommerce-Anbindung (Selektiv): Ermöglicht dir, gezielt aus der importierten Inventarliste heraus WooCommerce-Produkte (als variable Produkte mit Farb-/Zustandsvarianten) zu erstellen oder zu aktualisieren.
 * Synchronisierung (Zukunft): Hält die Bestände zwischen BrickOwl (Master), WooCommerce und ggf. BrickLink synchron.
 * Frontend-Darstellung (Zukunft): Bietet Möglichkeiten (z.B. Shortcodes), um Katalog- oder Inventardaten auf deiner Website anzuzeigen.
Installation
 * Download: Lade das Plugin als ZIP-Datei herunter (oder klone das Git-Repository).
 * Upload: Gehe in deinem WordPress-Adminbereich zu Plugins -> Installieren -> Plugin hochladen und wähle die ZIP-Datei aus.
 * Aktivieren: Aktiviere das Plugin "Bricksberg Warenwirtschaft (WaWi)" in der Plugin-Liste.
   * Wichtig: Beim ersten Aktivieren werden die notwendigen Datenstrukturen (CPTs, Taxonomien, Job-Status) angelegt und der Hintergrund-Cronjob geplant.
 * Konfiguration:
   * Gehe zu LEGO WaWi -> Einstellungen.
   * Trage hier (mindestens für den Anfang) deinen Rebrickable API Key ein (wird später für Updates und ggf. Bild-Downloads benötigt).
   * Trage deine BrickOwl API Key ein (wird für den Inventar-Import und die spätere Synchronisierung benötigt).
   * (BrickLink, eBay, OpenAI Keys sind für spätere Phasen).
   * Klicke auf Einstellungen speichern.
Benutzung (Aktueller Stand - Katalog & Inventar Import)
Phase 1: Katalog aufbauen (Rebrickable CSVs)
Ziel: Deine WordPress-Datenbank lernt alle LEGO-Teile, Farben etc. kennen.
 * Downloads: Lade die aktuellen CSV-Downloads (als .zip oder .gz) von rebrickable.com/downloads/ herunter. Du benötigst mindestens:
   * colors.csv
   * themes.csv
   * part_categories.csv
   * parts.csv
   * (Später auch: sets.csv, minifigs.csv, elements.csv, part_relationships.csv, inventories.csv, inventory_parts.csv etc.)
 * Import starten:
   * Gehe in WordPress zu LEGO WaWi -> Katalog-Import.
   * Lade die heruntergeladenen Dateien in die entsprechenden Felder hoch (z.B. colors.csv.gz in das "colors.csv"-Feld). Du kannst auch mehrere Dateien gleichzeitig hochladen.
   * Klicke auf Neuen Katalog-Import-Job erstellen.
 * Job beobachten:
   * Du wirst zur Job-Warteschlange weitergeleitet. Dein neuer Job erscheint mit Status "Wartend".
   * Nach ca. 1 Minute sollte der Status auf "Laufend" wechseln.
   * Der Import läuft im Hintergrund (Batch-Verarbeitung). Du kannst die Seite verlassen.
   * Lade die Job-Liste gelegentlich neu, um den Fortschritt zu sehen (z.B. "Aufgabe 'parts': Batch beendet, 10201 Zeilen verarbeitet.").
   * Wenn alle Dateien verarbeitet sind, wechselt der Status auf "Abgeschlossen".
 * Ergebnis prüfen:
   * Gehe zum Dashboard-Tab. Die Kacheln sollten jetzt die Anzahl der importierten Farben, Teile etc. anzeigen.
   * Klicke auf die Untermenüpunkte unter LEGO WaWi (z.B. Farben, Teile, Themen), um die importierten Daten zu sehen. In der Farben-Liste siehst du die Farbvorschau.
Phase 2: Inventar importieren (BrickOwl CSV)
Ziel: Deinen persönlichen Lagerbestand in die interne WordPress-Liste übertragen.
 * Voraussetzung: Stelle sicher, dass der Katalog-Import für colors.csv und parts.csv abgeschlossen ist (siehe Dashboard).
 * BrickOwl Export: Exportiere dein Inventar aus deinem BrickOwl-Shop als CSV-Datei.
 * Import starten:
   * Gehe zu LEGO WaWi -> Inventar-Import.
   * Lade deine BrickOwl-CSV-Datei hoch.
   * Klicke auf Neuen Inventar-Import-Job erstellen.
 * Job beobachten: Wie beim Katalog-Import in der Job-Warteschlange.
 * Ergebnis prüfen:
   * Gehe zum Dashboard. Die Kachel "Inventar-Positionen" sollte jetzt die Anzahl deiner importierten Bestandszeilen anzeigen.
   * Gehe zu LEGO WaWi -> BrickOwl Inventar. (Diese Seite ist noch ein Platzhalter!) Hier wirst du später dein importiertes Inventar sehen und verwalten können.
Phase 3: WooCommerce Produkte erstellen (Manuell/Selektiv - ZUKUNFT)
Ziel: Ausgewählte Artikel aus deinem importierten BrickOwl-Inventar als Produkte in WooCommerce anlegen.
 * Gehe zur (zukünftigen) Seite LEGO WaWi -> BrickOwl Inventar.
 * Finde den Artikel (z.B. "Brick 2x4").
 * Klicke auf einen Button "WooCommerce Produkt erstellen/aktualisieren".
 * Das Plugin erstellt automatisch:
   * Das variable Produkt "Brick 2x4".
   * Alle Farb-/Zustands-Variationen, die du auf Lager hast (z.B. "Rot / Gebraucht", "Blau / Neu"), mit der korrekten Menge und dem Preis aus deinem BrickOwl-Import.
   * (Optional versucht es, Bilder zuzuweisen).
Entwicklungs-Phasen (Plan)
 * Phase 1: Fundament ✅ (Grundstruktur, Admin-Seiten, CPTs, Job-System)
 * Phase 2: Katalog- & Inventar-Import ✅ (Import von Rebrickable CSVs in CPTs/Taxonomien, Import von BrickOwl CSV in interne lww_inventory_item Liste)
 * Phase 3: WooCommerce Produkt-Erstellung ⏳ (Implementierung der UI unter "BrickOwl Inventar" mit WP_List_Table und AJAX-Funktion zum Erstellen/Aktualisieren variabler WooCommerce-Produkte)
 * Phase 4: Synchronisierung (Master -> Satellit) ⏳ (BrickOwl-Bestandsänderungen erkennen und an WooCommerce übertragen)
 * Phase 5: Synchronisierung (Satellit -> Master) ⏳ (WooCommerce-Verkäufe an BrickOwl melden)
 * Phase 6: Frontend-Integration ⏳ (Shortcodes/Blöcke/Widgets zur Anzeige von Katalog-Listen oder Detailseiten auf der Website)
 * Phase 7: Erweiterungen ⏳ (BrickLink-Sync, eBay-Sync, API-Updates, Bild-Import, KI-Features)
Fehlerbehebung (Troubleshooting)
 * Job hängt bei "Laufend":
   * Prüfe wp-content/debug.log auf PHP-Fehler (aktiviere WP_DEBUG_LOG in wp-config.php).
   * Prüfe mit Plugin "WP Crontrol" (Werkzeuge -> Cron-Ereignisse), ob der Hook lww_main_batch_hook geplant ist und läuft.
   * Erhöhe testweise max_execution_time und memory_limit in den PHP-Einstellungen (Plesk).
   * Erwäge die Einrichtung eines Server-Cronjobs (siehe Plesk-Anleitung), um WP-Cron zuverlässiger zu machen.
 * Keine Daten werden importiert:
   * Prüfe die debug.log auf Fehler innerhalb der Import-Funktionen (z.B. "Undefined index", Fehler bei wp_insert_post).
   * Prüfe das Job-Log in der Job-Warteschlange auf spezifische Fehlermeldungen.
   * Stimmen die Spaltennamen in deiner CSV exakt mit denen überein, die der Code erwartet (siehe Header-Map-Logik in lww-batch-processor.php)?
(Dieses Handbuch wird mit dem Plugin weiterentwickelt)
