Projektplan "Bricksberg WaWi" (v4.1)
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
   * 0.9.9: Abschluss aller Bugfixes.
 * 0.10.x: Abschluss Phase 1 (Stabiler Katalog- & Initial-Inventar-Import, UI-Grundlagen).
 * 0.11.x: Implementierung Phase 2 - Bestellungs-Import & Bestands-Sync (BrickOwl API).
 * 0.12.x: Implementierung Phase 2 - Marktpreis-Analyse (BrickOwl & BrickLink API).
 * 0.13.x: Implementierung Phase 2 - KI-Textgenerierung (Gemini API).
 * 0.14.x: Implementierung Phase 3 - KI-Bestandserfassung (Konzept & Prototyp).
 * 0.15.x: Implementierung Phase 4 - Mandantenfähigkeit (Architektur).
 * 1.0.0: Stabiles Release nach Abschluss und Test aller Kernfunktionen aus Phase 1 & 2.
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
1.2: Datenvollständigkeit (Rebrickable Katalog-Import)
 * [x] Basis-Import: Handler für colors, part_categories, parts, minifigs, part_relationships, elements, inventories, inventory_sets, inventory_minifigs existieren.
 * [ ] (Neu) Handler für inventory_parts.csv: Erstellung des fehlenden Handlers.
 * [ ] (Priorität A) Import is_spare: Der neue inventory_parts-Handler muss is_spare importieren.
 * [ ] (Prüfung/Ergänzung) Themes-Hierarchie: Sicherstellen, dass der themes-Handler parent_id nutzt.
 * [ ] (Prüfung/Ergänzung) Sets-Metadaten: Sicherstellen, dass der sets-Handler year und num_parts importiert.
 * [ ] (Prüfung/Ergänzung) Vollständigkeit: Überprüfung aller Handler, ob alle Felder aus dem Diagramm [cite: downloads_schema_v3.png] als Meta-Felder gespeichert werden.
1.3: Datenintegrität & Benutzerführung (UI)
 * [x] Kategorie-Basis: Taxonomie lww_part_category existiert.
 * [ ] Kategorie-Hierarchie: Setzen von hierarchical => true für lww_part_category.
 * [ ] Kategorie-Fallback: Anpassung des parts-Handlers, um Teile ohne Kategorie einer Standard-Kategorie zuzuweisen.
 * [x] Bild-Import (Basis): Handler nutzen sideload_image_to_post für Rebrickable-Bild-URLs.
 * [x] Bild-Anzeige (Admin): lww-admin-columns.php zeigt Thumbnails oder Platzhalter.
 * [ ] Bild-Anzeige (Inventar-UI): Implementierung der Bild-Spalte in der lww-inventory-ui.php.
 * [ ] Bilder-Galerie: Erweiterung der CPTs zur Unterstützung mehrerer Bilder pro Artikel.
 * [x] Readiness Check (Basis): Dashboard & Inventar-Upload prüfen rudimentär Kerndaten.
 * [ ] Readiness Check (Erweitert): Implementierung der visuellen Checkliste auf dem Dashboard.
 * [ ] Abhängigkeits-Check (WooCommerce): Prüfung bei Plugin-Aktivierung implementieren.
 * [ ] Abhängigkeits-Check (WP-Version): Prüfung bei Plugin-Aktivierung implementieren.
1.4: "Smart & Schick" UI (Inventar & Jobs)
 * [x] Tabellen-Basis: WP_List_Table für Jobs ist implementiert.
 * [ ] Tabellen-UI (Inventar): Erstellung der WP_List_Table für lww_inventory_item.
 * [ ] Accordion-Details: Implementierung der aufklappbaren Detailansicht für Jobs und Inventar-Items.
 * [ ] Galerie-Ansicht: Implementierung des Ansicht-Umschalters und der Galerie-Karten für Katalog-CPTs und Inventar.
1.5: Metriken & Dashboard
 * [x] Basis-Metriken: Dashboard zeigt Job- und Katalog-Anzahlen.
 * [ ] Import-Metriken: Erweiterung des Dashboards um Dateigrößen, Gesamtzeilen etc.
 * [x] Job-Fortschritt (Basis): Anzeige von Status und letzter Log-Nachricht.
 * [ ] Job-Fortschritt (Erweitert): Anzeige von "Zeile X / Y" in der Job-Liste.
1.6: BrickOwl Backup-Import (Initial)
 * [x] Handler-Basis: Der (umbenannte) Handler für BrickOwl-CSV existiert.
 * [ ] Aktivierung: Sicherstellen, dass der Upload und die Job-Erstellung für die BrickOwl-CSV funktionieren (nach Bugfix 3).
Phase 2: Live-API-Integration & Automatisierung
 * [ ] Bestell-Import (BrickOwl -> WC): Entwicklung des Cron-Moduls und der Logik.
 * [ ] Marktpreis-Analyse: Entwicklung des Cron-Jobs für BrickOwl- & BrickLink-Preis-APIs.
 * [ ] KI-Textgenerierung: Implementierung des Gemini API Calls und des UI-Buttons.
 * [ ] Bestands-Sync: Automatische Bestandsreduzierung bei Bestell-Import.
Phase 3: KI-Bestandserfassung
 * [ ] Modul-Entwicklung: Konzeption und Bau des Kamera-Interface/App.
 * [ ] KI-Anbindung: Integration eines Bilderkennungsmodells.
 * [ ] Workflow: Implementierung des Erfassungsprozesses.
Phase 4: Mandantenfähigkeit & Kommerzialisierung
 * [ ] Architektur-Umbau: Trennung von globalem Katalog und mandantenspezifischen Daten.
 * [ ] Lizenzsystem: Implementierung eines Abo-/Lizenzmodells.
 * [ ] Add-on-Pakete: Entwicklung der kostenpflichtigen Zusatzfunktionen (KI-Preis, KI-Content, KI-Scanner).
Dieser Plan sollte als gute Grundlage für die weitere Entwicklung dienen.
