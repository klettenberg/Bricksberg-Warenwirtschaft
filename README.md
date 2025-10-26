Projektplan "Bricksberg WaWi" (v4.2)
Datum: 26. Oktober 2025
Projektziele
 * Stabilität (Sofort): Behebung aller bestehenden PHP-Fatal-Errors.
 * Datenfundament (Phase 1): Erstellung einer vollständigen Offline-Katalog-Datenbank (100% Rebrickable-Import) und initialer Import des BrickOwl-Inventars.
 * Benutzerführung (Phase 1): Schaffung einer "smarten & schicken" UI mit Datenintegrität (Kategorien, Bilder) und "Readiness Check".
 * Live-Integration (Phase 2): Umwandlung zur Synchronisations-Plattform via BrickOwl- & BrickLink-APIs (Bestellungen, Preise).
 * Intelligenz (Phase 2): Anreicherung von Produktdaten mittels Google Gemini API.
 * KI-Bestandserfassung (Phase 3): Beschleunigung der physischen Bestandspflege durch Kamera-Erkennung.
 * Mandantenfähigkeit (Phase 4): Bereitstellung als kommerzieller SaaS-Dienst.
Abhängigkeiten & Voraussetzungen
 * [ ] WordPress: Neueste stabile Version (z.B. 6.6+). Prüfung bei Aktivierung implementieren.
 * [ ] WooCommerce: Aktive Installation erforderlich. Prüfung bei Aktivierung implementieren.
 * [x] PHP: Mindestens PHP 7.4 (empfohlen 8.0+).
 * [x] Server: WP-Cron muss funktionieren, ausreichende Ausführungszeiten für Skripte.
Versionierungsschema (bis v1.0)
Wir befinden uns im Entwicklungsstatus (Dev). Version 1.0 markiert die stabile Veröffentlichung mit allen Kernfunktionen der Phase 1 und 2.
 * 0.9.x (Aktuell): Basis-Implementierung, Bugfixing, Vervollständigung Phase 1.
   * 0.9.9: Abschluss aller Bugfixes. (Meilenstein 1)
 * 0.10.x: Abschluss Phase 1 (Stabiler Katalog- & Initial-Inventar-Import, UI-Grundlagen). (Meilenstein 2)
 * 0.11.x: Implementierung Phase 2 - Bestellungs-Import & Bestands-Sync (BrickOwl API). (Meilenstein 3)
 * 0.12.x: Implementierung Phase 2 - Marktpreis-Analyse (BrickOwl & BrickLink API).
 * 0.13.x: Implementierung Phase 2 - KI-Textgenerierung (Gemini API). (Meilenstein 4)
 * 0.14.x: Implementierung Phase 3 - KI-Bestandserfassung (Konzept & Prototyp).
 * 0.15.x: Implementierung Phase 4 - Mandantenfähigkeit (Architektur).
 * 1.0.0: Stabiles Release nach Abschluss und Test aller Kernfunktionen aus Phase 1 & 2. (Meilenstein 5)
Projektphasen & Aufgaben
(Legende: [x] = Grundlegend implementiert, [ ] = Offen/Zu tun, [!] = Kritischer Bug)
Phase 1: Statisches Fundament & Datenintegrität (Aktueller Fokus)
1.1: Kritische Bugfixes (Sofort)
 * [!] Bugfix 1: Cannot redeclare lww_activate_plugin()
   * Aktion: Ersetzen der bricksberg-warenwirtschaft.php durch korrigierte Version (mit if !function_exists).
 * [!] Bugfix 2: Cannot declare class LWW_Import_Handler_Base
   * Aktion: Entfernen des doppelten Codes in class-lww-import-handler-base.php.
 * [!] Bugfix 3: Dateiverwechslung inventory-parts-Handler
   * Aktion: Korrekte Umbenennung des BrickOwl-Handlers (ehem. class-lww-import-inventory-parts-handler.php).
 * Meilenstein 1 (Version 0.9.9): Plugin startet fehlerfrei.
   * ==(TEST-DEPLOYMENT EMPFOHLEN)== Nach Abschluss der Bugfixes sollte das Plugin im WordPress-Admin aktiviert werden können, ohne PHP-Fehler zu werfen. Grundlegende Menüs sollten sichtbar sein.
1.2: Datenvollständigkeit (Rebrickable Katalog-Import)
 * [x] Basis-Import: Handler für colors, part_categories, parts, minifigs, part_relationships, elements, inventories, inventory_sets, inventory_minifigs existieren.
 * [ ] (Neu) Handler für inventory_parts.csv: Erstellung des fehlenden Handlers.
 * [ ] (Priorität A) Import is_spare: Der neue inventory_parts-Handler muss is_spare importieren.
 * [ ] (Prüfung/Ergänzung) Themes-Hierarchie: Sicherstellen, dass der themes-Handler parent_id nutzt.
 * [ ] (Prüfung/Ergänzung) Sets-Metadaten: Sicherstellen, dass der sets-Handler year und num_parts importiert.
 * [ ] (Prüfung/Ergänzung) Vollständigkeit: Überprüfung aller Handler, ob alle Felder aus dem Diagramm [cite: downloads_schema_v3.png] als Meta-Felder gespeichert werden.
1.3: Datenintegrität & Benutzerführung (UI) - Teil 1
 * [ ] Abhängigkeits-Check (WooCommerce): Prüfung bei Plugin-Aktivierung implementieren.
 * [ ] Abhängigkeits-Check (WP-Version): Prüfung bei Plugin-Aktivierung implementieren.
 * [ ] Kategorie-Hierarchie: Setzen von hierarchical => true für lww_part_category.
 * [ ] Kategorie-Fallback: Anpassung des parts-Handlers für Standard-Kategorie.
 * [ ] Bild-Import (Robustheit): Sicherstellen, dass der Import der Rebrickable-Bild-URLs stabil läuft.
 * [x] Bild-Anzeige (Admin): lww-admin-columns.php zeigt Thumbnails/Platzhalter.
1.6: BrickOwl Backup-Import (Initial)
 * [x] Handler-Basis: Der (umbenannte) Handler für BrickOwl-CSV existiert.
 * [ ] Aktivierung: Sicherstellen, dass der Upload und die Job-Erstellung für die BrickOwl-CSV funktionieren (nach Bugfix 3).
 * Meilenstein 1.5: Kerndaten-Import möglich.
   * ==(TEST-DEPLOYMENT EMPFOHLEN)== Wenn der Rebrickable-Import der Kerndaten (colors, part_categories, parts) UND der BrickOwl Backup-Import funktionieren, kann ein erster Test mit Echtdaten erfolgen. Ziel: Sind die Basis-Katalogdaten und das Inventar nach dem Import im WP-Admin sichtbar?
1.4: "Smart & Schick" UI (Inventar & Jobs) - Teil 1
 * [x] Tabellen-Basis: WP_List_Table für Jobs ist implementiert.
 * [ ] Tabellen-UI (Inventar): Erstellung der WP_List_Table für lww_inventory_item.
 * [ ] Bild-Anzeige (Inventar-UI): Implementierung der Bild-Spalte in der lww-inventory-ui.php.
 * [ ] Accordion-Details (Konzept): Grundstruktur für aufklappbare Details implementieren.
 * [ ] Galerie-Ansicht (Konzept): Basis-Umschalter und Grid-Layout implementieren.
1.5: Metriken & Dashboard
 * [x] Basis-Metriken: Dashboard zeigt Job- und Katalog-Anzahlen.
 * [ ] Readiness Check (Erweitert): Implementierung der visuellen Checkliste auf dem Dashboard.
 * [ ] Job-Fortschritt (Erweitert): Anzeige von "Zeile X / Y" in der Job-Liste.
 * Meilenstein 2 (Version 0.10.x): Phase 1 abgeschlossen.
   * ==(TEST-DEPLOYMENT EMPFOHLEN)== Wenn der vollständige Rebrickable-Import (inkl. is_spare, Hierarchien), der BrickOwl-Backup-Import UND die grundlegende Inventar-UI (Tabelle mit Bildern, Readiness Check) funktionieren. Ziel: Kann der Nutzer seinen gesamten Katalog importieren, sein Inventar importieren und beides in einer brauchbaren UI sehen?
Phase 2: Live-API-Integration & Automatisierung
 * [ ] Bestell-Import (BrickOwl -> WC): Entwicklung des Cron-Moduls und der Logik.
   * Meilenstein 3 (Version 0.11.x): Bestellungen werden synchronisiert.
     * ==(TEST-DEPLOYMENT EMPFOHLEN)== Nach Implementierung des Bestell-Imports. Ziel: Werden neue BrickOwl-Bestellungen korrekt als WooCommerce-Bestellungen angelegt? Funktioniert die Bestandsreduzierung?
 * [ ] Marktpreis-Analyse: Entwicklung des Cron-Jobs für BrickOwl- & BrickLink-Preis-APIs und Anzeige in Accordion-Details.
 * [ ] KI-Textgenerierung: Implementierung des Gemini API Calls und des UI-Buttons.
   * Meilenstein 4 (Version 0.13.x): API-Funktionen nutzbar.
     * ==(TEST-DEPLOYMENT EMPFOHLEN)== Nach Implementierung der Preis-Analyse und KI-Generierung. Ziel: Werden Marktpreise angezeigt? Funktioniert die Textgenerierung?
 * [ ] Bestands-Sync (Erweitert): Ggf. weitere Synchronisierungslogik (z.B. Preis-Updates von BrickOwl).
Phase 3: KI-Bestandserfassung
 * [ ] Modul-Entwicklung: Konzeption und Bau des Kamera-Interface/App.
 * [ ] KI-Anbindung: Integration eines Bilderkennungsmodells.
 * [ ] Workflow: Implementierung des Erfassungsprozesses.
Phase 4: Mandantenfähigkeit & Kommerzialisierung
 * [ ] Architektur-Umbau: Trennung von globalem Katalog und mandantenspezifischen Daten.
 * [ ] Lizenzsystem: Implementierung eines Abo-/Lizenzmodells.
 * [ ] Add-on-Pakete: Entwicklung der kostenpflichtigen Zusatzfunktionen.
 * Meilenstein 5 (Version 1.0.0): Stabiles Release.
   * ==(TEST-DEPLOYMENT EMPFOHLEN)== Umfangreicher Test aller Funktionen aus Phase 1 & 2 vor der offiziellen Veröffentlichung.
