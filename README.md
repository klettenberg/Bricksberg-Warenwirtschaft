```markdown
# Bricksberg Warenwirtschaft (WaWi) für WordPress

**Version:** 0.57.0
**Autor:** Olaf Ziörjen (bricksberg.eu)

---

## Beschreibung

Bricksberg WaWi ist ein professionelles Warenwirtschaftssystem für WordPress, das speziell für LEGO®-Händler entwickelt wurde. Es dient als zentrale Verwaltungsplattform ("Single Source of Truth"), um Katalogdaten zu organisieren und den Lagerbestand über mehrere Marktplätze wie **WooCommerce**, **BrickOwl** und **BrickLink** präzise zu synchronisieren.

Das System nutzt die umfassenden Katalogdaten von **Rebrickable** als Fundament und behandelt **BrickOwl** als primäre Quelle (Master) für Ihren persönlichen Lagerbestand, um eine konsistente und verlässliche Datenhaltung zu gewährleisten.

## Hauptfunktionen

*   **Zentraler LEGO® Katalog:** Importiert und verwaltet den gesamten Rebrickable-Katalog (Teile, Sets, Minifigs, Farben etc.) in einer optimierten, relationalen Datenstruktur innerhalb von WordPress.
*   **Multi-Plattform Inventar-Management:** Synchronisiert Ihren Lagerbestand zwischen Ihrem BrickOwl-Shop (als Master-Quelle), Ihrem WooCommerce-Shop und Ihrem BrickLink-Shop und verhindert so Überverkäufe.
*   **Automatisierte WooCommerce-Anbindung:** Erstellt und aktualisiert automatisch variable Produkte in WooCommerce basierend auf Ihrem importierten Inventar, inklusive aller Farb- und Zustandsvarianten mit korrekten Preisen, Mengen und Bildern.
*   **Nahtlose BrickLink-Anbindung:** Importiert Ihr BrickLink-Inventar und ermöglicht eine Zwei-Wege-Synchronisation von Preisen, Mengen und Zuständen mit intelligentem Mapping.
*   **Frontend-Darstellung:** Bietet eine Sammlung von WordPress-Blöcken (z.B. für Set-Inventare) und Shortcodes, um Katalogdaten dynamisch auf Ihrer Website anzuzeigen und Kunden-Tools wie "Gesucht"-Listen zu integrieren.
*   **Asynchrone Hintergrundverarbeitung:** Alle ressourcenintensiven Import- und Synchronisierungsaufgaben laufen über ein robustes Job-System (Action Scheduler), um Timeouts zu vermeiden und einen reibungslosen Betrieb auch bei großen Datenmengen zu gewährleisten.
*   **Modulare & Erweiterbare Architektur:** Das Kernsystem konzentriert sich auf Stabilität und Leistung. Zukünftige Funktionen wie die Anbindung an eBay oder KI-Preisgestaltung werden als optionale Module entwickelt.
*   **Entwicklerfreundlich:** Ein umfassendes Set an WordPress-Hooks (Actions und Filter) ermöglicht die individuelle Anpassung und Erweiterung der Kernfunktionalität durch Entwickler.

## Architektur & Erweiterbarkeit

Bricksberg WaWi wurde von Grund auf modular konzipiert. Der Plugin-Kern liefert die stabile Basis für die Datenverwaltung und die essenziellen Synchronisierungs-Engines. Erweiterte Funktionalitäten werden als eigenständige Module oder Premium-Add-ons angeboten. Dies stellt sicher, dass Ihr System schlank bleibt und Sie nur für die Funktionen bezahlen, die Sie wirklich benötigen.

**Verfügbare & Geplante Erweiterungsmodule:**

*   **Frontend-Tools (Im Kern enthalten):** Eine Sammlung von WordPress-Blöcken und Shortcodes zur dynamischen Darstellung von Katalogdaten. Ideal für Set-Inventare, MOC-Teilelisten oder eine "Gesucht"-Listen-Funktion für Kunden.
*   **eBay-Integration (Verfügbar als Premium-Modul):** Ein Modul zur vollständigen Anbindung an die eBay-API. Synchronisieren Sie Ihre Angebote und Lagerbestände, importieren Sie Bestellungen und verwalten Sie Ihre eBay-Verkäufe direkt aus dem WaWi-System.
*   **Erweiterte Preisgestaltung (Pricing AI) (In Entwicklung):** Ein KI-gestütztes Modul, das Preisvorschläge basierend auf den aktuellen Marktdaten von BrickLink, BrickOwl und anderen Quellen generiert. Analysieren Sie Nachfragetrends und optimieren Sie Ihre Preisstrategie.
*   **MOC-Management (Geplant):** Tools zur Verwaltung von "My Own Creations". Erstellen Sie Teilelisten, kalkulieren Sie die Kosten für ein Set basierend auf Ihrem aktuellen Inventar und erstellen Sie WooCommerce-Produkte für komplette MOC-Kits.
*   **GoBrickLink-Integration (Geplant):** Anbindung an das beliebte Tool GoBrickLink zur Optimierung von Versand- und Verpackungsprozessen.

## Funktionsweise im Überblick

1.  **Katalog-Aufbau:** Die Datenbasis wird durch den Import der Rebrickable CSV-Downloads geschaffen. Das Plugin erstellt dedizierte Custom Post Types und Taxonomien für Teile, Farben, Sets etc. und verknüpft diese intelligent.
2.  **Inventar-Import:** Ihr persönlicher BrickOwl-Bestand wird importiert und mit den Katalogdaten verknüpft. Jede Zeile Ihres Inventars (z.B. "2x4 Brick, Red, Used, 100 Stück, 0.10€") wird zu einem internen Datensatz.
3.  **WooCommerce-Produkterstellung:** Sie können selektiv oder gesammelt aus Ihrem internen Inventar heraus WooCommerce-Produkte generieren lassen. Das Plugin fasst dabei alle Varianten eines Teils (z.B. alle Farben und Zustände eines "2x4 Brick") zu einem einzigen variablen Produkt zusammen.
4.  **Synchronisierung:** Einmal eingerichtet, arbeitet das System autonom im Hintergrund, um Ihre Bestände synchron zu halten.
    *   **Master &rarr; Satelliten:** Änderungen am Lagerbestand in BrickOwl (z.B. durch manuelle Anpassung oder einen Verkauf auf BrickOwl) werden erkannt und an WooCommerce und BrickLink übertragen.
    *   **Satelliten &rarr; Master:** Ein Verkauf in WooCommerce oder BrickLink reduziert den Lagerbestand und meldet diese Änderung zurück an BrickOwl, um den Bestand auf allen Plattformen konsistent zu halten und Überverkäufe zu vermeiden.

## Voraussetzungen

*   WordPress 6.2 oder höher
*   PHP 8.0 oder höher (8.1+ empfohlen)
*   **WooCommerce Plugin (aktiviert) - Obligatorisch**
*   Ein funktionierender WP-Cron oder ein Server-seitiger Cronjob für eine zuverlässige Hintergrundverarbeitung.
*   Ausreichende Server-Ressourcen (`memory_limit` von 256M oder höher empfohlen, `max_execution_time` von 300 oder höher).
*   Eine SSL-verschlüsselte Website (HTTPS) wird für die sichere API-Kommunikation dringend empfohlen.

---

## Installation & Konfiguration

**1. Plugin herunterladen**
Laden Sie das Plugin als `.zip`-Datei herunter oder klonen Sie das Git-Repository.

**2. Plugin installieren**
Navigieren Sie in Ihrem WordPress-Adminbereich zu **Plugins &rarr; Installieren** und klicken Sie auf **Plugin hochladen**. Wählen Sie die heruntergeladene `.zip`-Datei aus und installieren Sie sie.

**3. Plugin aktivieren**
Aktivieren Sie das Plugin "Bricksberg Warenwirtschaft (WaWi)" in der Plugin-Liste.
*   **Wichtig:** Stellen Sie sicher, dass WooCommerce installiert und aktiviert ist. Andernfalls wird das Plugin nicht geladen. Bei der ersten Aktivierung werden alle notwendigen Datenstrukturen (Custom Post Types, Taxonomien) angelegt und die Jobs für die Hintergrundverarbeitung werden initial geplant.

**4. Plugin konfigurieren**
*   Navigieren Sie zu **LEGO WaWi &rarr; Einstellungen**.
*   Tragen Sie Ihre API-Keys ein:
    *   **Rebrickable API Key:** Erforderlich für zukünftige Katalog-Updates und den Import von Teilebildern.
    *   **BrickOwl API Key:** Erforderlich für den Inventar-Import und die Bestandssynchronisierung.
    *   **BrickLink API Keys:** Erforderlich für den Inventar-Import und die Bestandssynchronisierung (Consumer Key, Consumer Secret, Token, Token Secret).
*   **API-Verbindungen testen:** Nutzen Sie nach dem Speichern die "Verbindung testen"-Buttons, um sicherzustellen, dass die Keys korrekt sind und die Kommunikation mit den Plattformen funktioniert.
*   **Weitere Integrationen:** Felder für eBay, OpenAI etc. sind für entsprechende Erweiterungsmodule reserviert.

## Erste Schritte: Datenimport

### Schritt 1: Katalog aufbauen (Rebrickable CSVs)

**Ziel:** Ihre WordPress-Datenbank mit dem globalen LEGO®-Katalog füllen.

1.  **Dateien herunterladen:** Besuchen Sie [rebrickable.com/downloads/](https://rebrickable.com/downloads/) und laden Sie die aktuellen CSV-Dateien herunter (als `.csv.gz` oder `.zip`). Für den Start benötigen Sie mindestens:
    *   `colors.csv.gz`
    *   `themes.csv.gz`
    *   `part_categories.csv.gz`
    *   `parts.csv.gz`
    *   *Optional für mehr Details:* `sets.csv.gz`, `minifigs.csv.gz`, `elements.csv.gz`, `part_relationships.csv.gz`, etc.

2.  **Import starten:**
    *   Navigieren Sie in WordPress zu **LEGO WaWi &rarr; Katalog-Import**.
    *   Laden Sie die heruntergeladenen Dateien in die entsprechenden Felder hoch. Sie können mehrere Dateien gleichzeitig auswählen und hochladen.
    *   Klicken Sie auf **Neuen Katalog-Import-Job erstellen**.

3.  **Job-Verlauf beobachten:**
    *   Sie werden zur Job-Warteschlange weitergeleitet. Ihr neuer Job hat den Status "Wartend".
    *   Nach kurzer Zeit (ca. 1 Minute, je nach Cron-Intervall) wechselt der Status zu "Laufend".
    *   Der Import läuft vollständig im Hintergrund. Sie können die Seite verlassen und weiterarbeiten.
    *   Aktualisieren Sie die Job-Liste gelegentlich, um den Fortschritt zu sehen. Wenn der Job abgeschlossen ist, wechselt der Status auf "Abgeschlossen".

4.  **Ergebnis prüfen:**
    *   Navigieren Sie zu **LEGO WaWi &rarr; Dashboard**. Die Kacheln sollten nun die Anzahl der importierten Elemente (Farben, Teile etc.) anzeigen.
    *   Erkunden Sie die Untermenüs wie **Teile** oder **Farben**, um die importierten Daten zu sichten.

### Schritt 2: Inventar importieren (BrickOwl CSV)

**Ziel:** Ihren persönlichen Lagerbestand in das WaWi-System laden.

1.  **Voraussetzung:** Stellen Sie sicher, dass der Katalog-Import für `colors.csv` und `parts.csv` erfolgreich abgeschlossen wurde.
2.  **BrickOwl-Inventar exportieren:** Exportieren Sie Ihr Inventar aus Ihrem BrickOwl-Shop als CSV-Datei.
3.  **Import starten:**
    *   Navigieren Sie zu **LEGO WaWi &rarr; Inventar-Import**.
    *   Laden Sie Ihre exportierte BrickOwl-CSV-Datei hoch.
    *   Klicken Sie auf **Neuen Inventar-Import-Job erstellen**.
4.  **Job-Verlauf beobachten:** Der Prozess ist identisch mit dem Katalog-Import.
5.  **Ergebnis prüfen:**
    *   Die Kachel "Inventar-Positionen" auf dem Dashboard sollte nun die Anzahl der importierten Zeilen anzeigen.
    *   Navigieren Sie zu **LEGO WaWi &rarr; BrickOwl Inventar**, um die importierten Daten zu verwalten und für WooCommerce vorzubereiten.

### Schritt 3: WooCommerce Produkte erstellen

**Ziel:** Ausgewählte Artikel aus dem importierten Inventar als Produkte in WooCommerce anlegen.

*   Auf der Seite **LEGO WaWi &rarr; BrickOwl Inventar** können Sie einzelne oder mehrere Teile auswählen.
*   Mit der Aktion "WooCommerce Produkt erstellen/aktualisieren" wird das Plugin automatisch:
    *   Ein variables Produkt für das Teil anlegen (z.B. "Brick 2x4").
    *   Alle im Inventar vorhandenen Farb- und Zustands-Kombinationen als Produktvarianten erstellen (z.B. "Rot / Gebraucht", "Blau / Neu").
    *   Preis, Lagermenge und Artikelnummer (SKU) für jede Variante korrekt aus den Inventardaten übernehmen.
    *   Bilder aus dem Katalog zuweisen.

## Marktplatz-Synchronisierung einrichten

Nachdem Ihr Katalog und Ihr Master-Inventar importiert sind, können Sie die Synchronisierung mit Ihren Ziel-Plattformen aktivieren.

1.  Navigieren Sie zu **LEGO WaWi &rarr; Synchronisierung**.
2.  **WooCommerce:** Aktivieren Sie den Schalter, um die automatische Bestandsaktualisierung zu starten. Das System überwacht nun Verkäufe in WooCommerce und passt den Bestand in BrickOwl (Master) entsprechend an. Ebenso werden Bestandsänderungen aus BrickOwl an WooCommerce übertragen.
3.  **BrickLink:** Stellen Sie sicher, dass Ihre BrickLink API-Daten in den Einstellungen korrekt hinterlegt und verifiziert sind. Aktivieren Sie die Synchronisierung. Das System wird nun Bestände zwischen BrickOwl und BrickLink abgleichen. In den erweiterten Einstellungen können Sie detailliert konfigurieren, ob und in welche Richtung Preise ebenfalls synchronisiert werden sollen.

## Technische Details

*   **Hintergrundverarbeitung:** Das Plugin nutzt die etablierte `Action Scheduler`-Bibliothek, die auch von WooCommerce Core verwendet wird. Dies garantiert eine robuste und skalierbare Abwicklung von asynchronen Aufgaben.
*   **Datenstruktur:** Alle Katalog- und Inventardaten werden in dedizierten Custom Post Types (CPTs) und Custom Taxonomies gespeichert. Dies sorgt für eine saubere Trennung von anderen WordPress-Inhalten und eine hohe Performance.
*   **Datenbank:** Es werden benutzerdefinierte, indizierte Datenbanktabellen für performance-kritische Daten wie die Inventar-Logs und die Job-Queue verwendet, um die `wp_posts`-Tabelle zu entlasten.
*   **REST API:** Das Plugin stellt interne REST-API-Endpunkte für die Kommunikation zwischen Admin-Interface (AJAX) und Backend bereit. Zukünftige Erweiterungen können diese Endpunkte ebenfalls nutzen.

---

## Roadmap & Zukünftige Entwicklungen

*   **Phase 1: Fundament & Kernarchitektur** ✅
    *(Grundstruktur, Admin-Seiten, CPTs, Job-System)*
*   **Phase 2: Manueller Datenimport (Core-Funktion)** ✅
    *(Import von Rebrickable CSVs und BrickOwl CSV in die interne Datenstruktur)*
*   **Phase 3: WooCommerce-Produkterstellung** ✅
    *(UI unter "BrickOwl Inventar" mit `WP_List_Table` und AJAX-Funktionen zum Erstellen/Aktualisieren variabler WooCommerce-Produkte)*
*   **Phase 4: Zweiweg-Synchronisierung (BrickOwl &harr; WC)** ✅
    *(Automatischer Abgleich von Beständen zwischen der Master-Quelle und WooCommerce)*
*   **Phase 5: BrickLink-Anbindung (Zweiweg-Sync)** ✅
    *(Synchronisierung von Preisen, Mengen und Status mit der BrickLink API)*
*   **Phase 6: Frontend-Darstellung & Tools** ✅
    *(Modul mit WordPress-Blöcken (Set-Inventar) und Shortcodes zur dynamischen Anzeige von Katalogdaten in den Kern integriert.)*
*   **Phase 7: Erweiterte Marktplatz-Integration (eBay)** ✅
    *(Das Premium-Modul zur Anbindung an die eBay-API ist verfügbar. Synchronisierung von Angeboten, Beständen und Bestellungen ist implementiert.)*
*   **Phase 8: KI-gestützte Funktionen** 🟡
    *(Aktive Entwicklung des Pricing-AI-Moduls. Anbindung an OpenAI für erste Prototypen zur Analyse von Marktdaten und Generierung von Preisvorschlägen.)*
*   **Phase 9: Buchhaltungs- & POS-Integration** ⏳
    *(Planung von Modulen zur Anbindung an Buchhaltungssoftware (z.B. Lexoffice) und Point-of-Sale-Systeme für den stationären Handel.)*

## Fehlerbehebung (Troubleshooting)

**Problem: Import-Job bleibt im Status "Laufend" hängen**

*   **WP-Cron prüfen:** Installieren Sie das Plugin "WP Crontrol", navigieren Sie zu **Werkzeuge &rarr; Cron-Ereignisse** und prüfen Sie, ob der Hook `lww_main_batch_hook` geplant ist und regelmäßig ausgeführt wird.
*   **Server-Cron einrichten:** Für maximale Zuverlässigkeit sollten Sie den WordPress-eigenen Cron deaktivieren und einen echten Server-Cronjob einrichten.
*   **PHP-Fehler prüfen:** Aktivieren Sie `WP_DEBUG_LOG` in Ihrer `wp-config.php` und prüfen Sie die Datei `wp-content/debug.log` auf fatale Fehler.
*   **Server-Limits erhöhen:** Erhöhen Sie testweise die Werte für `max_execution_time` und `memory_limit` in Ihrer PHP-Konfiguration.

**Problem: Import abgeschlossen, aber keine Daten sichtbar**

*   **Log-Dateien prüfen:** Prüfen Sie die `debug.log` auf Warnungen oder Fehler während des Imports (z.B. "Undefined index", Fehler bei `wp_insert_post`).
*   **Job-Details prüfen:** In der Job-Warteschlange können Sie auf den Job klicken, um detailliertere Log-Meldungen zu sehen, die auf spezifische Probleme hinweisen.
*   **CSV-Format prüfen:** Stellen Sie sicher, dass die Spaltenüberschriften in Ihrer CSV-Datei exakt mit denen übereinstimmen, die das Plugin erwartet (Groß-/Kleinschreibung, keine Leerzeichen).

**Problem: API-Verbindung schlägt fehl**

*   **Keys überprüfen:** Kopieren Sie Ihre API-Keys erneut und fügen Sie sie ein, um Tippfehler oder unsichtbare Leerzeichen auszuschließen.
*   **Firewall/Sicherheit-Plugins:** Deaktivieren Sie vorübergehend Sicherheits-Plugins (z.B. Wordfence, Sucuri), um zu prüfen, ob diese ausgehende API-Anfragen blockieren.
*   **Server-Konfiguration (cURL):** Stellen Sie sicher, dass die PHP-Erweiterung `cURL` auf Ihrem Server installiert und aktuell ist. Kontaktieren Sie Ihren Hoster, falls Sie unsicher sind.
*   **SSL-Zertifikat:** Prüfen Sie, ob Ihre Website ein gültiges SSL-Zertifikat hat (HTTPS). Viele APIs lehnen Verbindungen von unsicheren Seiten ab.

## Beitragende & Support

*   **Fehler melden:** Fehler oder unerwartetes Verhalten können als "Issue" im offiziellen GitHub-Repository des Projekts gemeldet werden.
*   **Feature-Vorschläge:** Ideen für neue Funktionen sind willkommen und können ebenfalls über die GitHub-Issues eingereicht werden.
*   **Support:** Für Premium-Module wird dedizierter E-Mail-Support angeboten. Für das kostenlose Kern-Plugin wird Community-Support über das WordPress.org-Supportforum (sobald veröffentlicht) oder GitHub bereitgestellt.

*(Dieses Handbuch wird parallel zur Entwicklung des Plugins kontinuierlich aktualisiert.)*
```
```