```markdown
# Bricksberg Warenwirtschaft (WaWi) - Benutzerhandbuch

**Version:** 0.57.0
**Autor:** Olaf Ziörjen (bricksberg.eu)

--- 

## Inhaltsverzeichnis

1.  [Einführung](#1-einführung)
    *   [Was ist Bricksberg WaWi?](#was-ist-bricksberg-wawi)
    *   [Für wen ist dieses Plugin?](#für-wen-ist-dieses-plugin)
    *   [Kernkonzept: Single Source of Truth](#kernkonzept-single-source-of-truth)
2.  [Installation & Voraussetzungen](#2-installation--voraussetzungen)
    *   [Systemvoraussetzungen](#systemvoraussetzungen)
    *   [Installation des Plugins](#installation-des-plugins)
    *   [Erste Aktivierung](#erste-aktivierung)
3.  [Konfiguration & Einstellungen](#3-konfiguration--einstellungen)
    *   [API-Schlüssel Konfiguration](#api-schlüssel-konfiguration)
    *   [KI-Konfiguration](#ki-konfiguration)
    *   [Import & Performance](#import--performance)
    *   [Job-Prioritäten](#job-prioritäten)
4.  [Der erste Datenimport (Schritt-für-Schritt)](#4-der-erste-datenimport-schritt-für-schritt)
    *   [Schritt 1: Katalog aufbauen (Rebrickable)](#schritt-1-katalog-aufbauen-rebrickable)
    *   [Schritt 2: Inventar importieren (BrickOwl / BrickLink)](#schritt-2-inventar-importieren-brickowl--bricklink)
    *   [Die Job-Warteschlange verstehen](#die-job-warteschlange-verstehen)
5.  [Die Benutzeroberfläche im Detail](#5-die-benutzeroberfläche-im-detail)
    *   [Dashboard](#dashboard)
    *   [Inventar Verwalten](#inventar-verwalten)
    *   [Lagerverwaltung](#lagerverwaltung)
    *   [Katalog-Stammdaten (Teile, Sets, etc.)](#katalog-stammdaten-teile-sets-etc)
    *   [Import](#import)
    *   [API Synchronisation](#api-synchronisation)
    *   [Job-Warteschlange](#job-warteschlange)
    *   [Analyse & KI](#analyse--ki)
    *   [Werkzeuge & Wartung](#werkzeuge--wartung)
    *   [Daten-Korrektur](#daten-korrektur)
    *   [API-Log](#api-log)
    *   [Einstellungen](#einstellungen)
6.  [Kernfunktionen im praktischen Einsatz](#6-kernfunktionen-im-praktischen-einsatz)
    *   [WooCommerce-Produkte erstellen & verwalten](#woocommerce-produkte-erstellen--verwalten)
    *   [Lagerorte zuweisen und nutzen](#lagerorte-zuweisen-und-nutzen)
    *   [Preise per API aktualisieren](#preise-per-api-aktualisieren)
    *   [Daten mit KI anreichern](#daten-mit-ki-anreichern)
7.  [Fehlerbehebung (Troubleshooting)](#7-fehlerbehebung-troubleshooting)

---

## 1. Einführung

### Was ist Bricksberg WaWi?

Bricksberg WaWi ist ein professionelles Warenwirtschaftssystem für WordPress, das speziell für LEGO®-Händler entwickelt wurde. Es dient als zentrale Verwaltungsplattform, um Katalogdaten zu organisieren und den Lagerbestand über mehrere Marktplätze wie WooCommerce, BrickOwl und BrickLink präzise zu synchronisieren.

### Für wen ist dieses Plugin?

Dieses Plugin richtet sich an alle, die professionell mit gebrauchten oder neuen LEGO®-Steinen, Sets oder Minifiguren handeln und dabei WordPress mit WooCommerce als Online-Shop nutzen oder nutzen möchten. Es ist ideal für:

*   BrickLink- und BrickOwl-Shop-Betreiber, die einen eigenen WooCommerce-Shop aufbauen wollen.
*   Händler, die ihren Bestand über mehrere Plattformen synchron halten müssen.
*   Verkäufer, die eine zentrale, selbst gehostete Lösung zur Verwaltung ihrer Daten suchen.

### Kernkonzept: Single Source of Truth

Das WaWi-System arbeitet nach dem Prinzip einer "einzigen Quelle der Wahrheit". Das bedeutet:

*   **Katalog:** Die Daten von **Rebrickable** bilden die Grundlage für alle Artikelinformationen (was ist ein Teil, ein Set etc.).
*   **Inventar:** Ihr **BrickOwl- oder BrickLink-Inventar** wird als Master für Ihren persönlichen Bestand behandelt (was besitze ich, in welchem Zustand und zu welchem Preis).
*   **WooCommerce & andere:** Ihr WooCommerce-Shop ist ein "Satellit", der seine Daten aus dem WaWi-System bezieht. Verkäufe werden zurückgemeldet, aber die primäre Datenpflege findet zentral im WaWi statt.


## 2. Installation & Voraussetzungen

### Systemvoraussetzungen

Stellen Sie sicher, dass Ihr Hosting-Umfeld die folgenden Anforderungen erfüllt:

*   WordPress 6.2 oder höher
*   PHP 8.0 oder höher (8.1+ empfohlen)
*   **WooCommerce Plugin (installiert und aktiviert) - Dies ist eine zwingende Voraussetzung!**
*   Ein funktionierender WP-Cron oder (besser) ein Server-seitiger Cronjob.
*   `memory_limit` von 256M oder höher.
*   `max_execution_time` von 300 Sekunden oder höher.
*   Eine SSL-verschlüsselte Website (HTTPS).

### Installation des Plugins

1.  Navigieren Sie in Ihrem WordPress-Adminbereich zu **Plugins &rarr; Installieren**.
2.  Klicken Sie auf **Plugin hochladen**.
3.  Wählen Sie die heruntergeladene `.zip`-Datei des Plugins aus und klicken Sie auf **Jetzt installieren**.

### Erste Aktivierung

1.  Aktivieren Sie das Plugin "Bricksberg Warenwirtschaft (WaWi)" in Ihrer Plugin-Liste.
2.  **Abhängigkeitsprüfung:** Das Plugin prüft nun, ob WooCommerce aktiv ist. Wenn nicht, sehen Sie eine Fehlermeldung und müssen WooCommerce zuerst aktivieren.
3.  **Einrichtung:** Bei erfolgreicher Aktivierung legt das Plugin im Hintergrund alle notwendigen Datenstrukturen an und plant einen wiederkehrenden Cron-Job, der für die Verarbeitung der Import- und Synchronisierungsaufgaben zuständig ist.


## 3. Konfiguration & Einstellungen

Nach der Aktivierung sollten Sie als Erstes die Einstellungen konfigurieren. Navigieren Sie dazu zu **Bricksberg WaWi &rarr; Einstellungen**.

### API-Schlüssel Konfiguration

Hier tragen Sie die Zugangsdaten für die verschiedenen Plattformen ein.

*   **BrickOwl API Key:** Notwendig für den Inventar-Import von BrickOwl und die API-Synchronisation.
*   **BrickLink API Keys:** Vier Schlüssel (Consumer Key/Secret, Token Value/Secret), notwendig für den Import und Sync mit BrickLink.
*   **Rebrickable API Key:** Wichtig für zukünftige Katalog-Updates und das automatische Laden von Bildern.
*   **eBay Keys:** Notwendig, wenn Sie das eBay-Modul nutzen.

Nutzen Sie nach dem Eintragen eines Schlüssels den **"Verbindung testen"**-Button, um sicherzustellen, dass die Zugangsdaten korrekt sind.

### KI-Konfiguration

Wählen Sie hier den KI-Anbieter (z.B. OpenAI oder Google Gemini), den Sie für Funktionen wie die Nachfrageanalyse oder die automatische Erstellung von Produktbeschreibungen verwenden möchten. Der entsprechende API-Schlüssel muss oben eingetragen sein.

### Import & Performance

*   **Cron-Job Intervall:** Legt fest, wie oft das System nach neuen Aufgaben sucht. "Jede Minute" ist empfohlen.
*   **Batch-Größe:** Bestimmt, wie viele Zeilen einer CSV-Datei pro Durchlauf verarbeitet werden. Kleinere Werte schonen den Server, verlängern aber die Gesamt-Importzeit.

### Job-Prioritäten

Legen Sie fest, welche Arten von Jobs bevorzugt behandelt werden sollen. Eine niedrigere Zahl bedeutet eine höhere Priorität (0 = höchste Priorität).


## 4. Der erste Datenimport (Schritt-für-Schritt)

### Schritt 1: Katalog aufbauen (Rebrickable)

1.  **Dateien herunterladen:** Besuchen Sie [rebrickable.com/downloads/](https://rebrickable.com/downloads/) und laden Sie die aktuellen CSV-Dateien (als `.csv.gz`) herunter. Die wichtigsten für den Start sind:
    *   `colors.csv.gz`
    *   `part_categories.csv.gz`
    *   `parts.csv.gz`
    *   Für Sets und Minifiguren zusätzlich: `themes.csv.gz`, `sets.csv.gz`, `minifigs.csv.gz`.

2.  **Import starten:**
    *   Gehen Sie zu **Bricksberg WaWi &rarr; Import**.
    *   Im Abschnitt **"Katalog-Import starten"** laden Sie Ihre heruntergeladenen `.gz`-Dateien in die passenden Felder hoch.
    *   Klicken Sie auf **"Neuen Katalog-Import-Job erstellen"**.

### Schritt 2: Inventar importieren (BrickOwl / BrickLink)

1.  **Voraussetzung:** Der Katalog-Import (mindestens für Teile und Farben) muss abgeschlossen sein.
2.  **Datei exportieren:** Exportieren Sie Ihr Inventar aus Ihrem BrickLink- oder BrickOwl-Shop.
3.  **Import starten:**
    *   Gehen Sie zu **Bricksberg WaWi &rarr; Import**.
    *   Wählen Sie den passenden Abschnitt (BrickLink oder BrickOwl) und laden Sie Ihre Export-Datei hoch.
    *   Klicken Sie auf den entsprechenden Button, um den Import-Job zu erstellen.

### Die Job-Warteschlange verstehen

Nachdem Sie einen Import gestartet haben, werden Sie zur **Job-Warteschlange** weitergeleitet. Dies ist das Herzstück der Hintergrundverarbeitung.

*   **Status:** Ein Job durchläuft die Status `Wartend` &rarr; `Laufend` &rarr; `Abgeschlossen` (oder `Fehlgeschlagen`).
*   **Fortschritt:** Bei laufenden Jobs sehen Sie einen Fortschrittsbalken und die letzte Log-Nachricht.
*   **Automatische Aktualisierung:** Die Seite aktualisiert sich standardmäßig automatisch, damit Sie den Fortschritt live verfolgen können.


## 5. Die Benutzeroberfläche im Detail

### Dashboard
Die Startseite des Plugins. Hier sehen Sie auf einen Blick wichtige Kennzahlen zu Ihrem Datenbestand, dem Status der Jobs und der Datenqualität.

### Inventar Verwalten
Dies ist Ihre zentrale Arbeitsansicht für Ihren importierten Bestand. Hier können Sie:

*   Ihr gesamtes Inventar durchsuchen und filtern (nach Typ, Zustand, Lagerort etc.).
*   Preise und Bestände einsehen.
*   Aktionen ausführen, wie z.B. WooCommerce-Produkte erstellen oder Preise per API abrufen.

### Lagerverwaltung
Verwalten Sie hier Ihre physischen Lagerorte. Die Seite bietet Statistiken zur Belegung und schlägt Lagerorte für noch nicht einsortierte Artikel vor.

### Katalog-Stammdaten (Teile, Sets, etc.)
Diese Menüpunkte führen zu den Standard-WordPress-Listenansichten für die importierten Katalogdaten. Hier können Sie einzelne Einträge bearbeiten, Bilder prüfen etc.

### Import
Die zentrale Anlaufstelle, um manuelle Import-Jobs für Katalog- und Inventardaten zu starten.

### API Synchronisation
Starten Sie hier manuelle Jobs, die Daten über eine API mit einem Marktplatz abgleichen, z.B. einen vollständigen Preisabgleich mit BrickOwl.

### Job-Warteschlange
Die Übersicht aller Hintergrund-Jobs. Hier können Sie den Fortschritt verfolgen, Jobs pausieren, fortsetzen oder abbrechen.

### Analyse & KI
Werkzeuge, die auf künstlicher Intelligenz basieren. Starten Sie hier Jobs zur:

*   **Nachfrageanalyse:** Bewertet die Marktnachfrage für Ihre Artikel.
*   **SEO-Beschreibungen:** Generiert automatisch Produkttexte.

### Werkzeuge & Wartung
Enthält Werkzeuge für einmalige Aktionen, z.B.:

*   **Lagerorte synchronisieren:** Liest die Lagerorte aus den Notizfeldern aller Artikel neu ein.
*   **Jobs zurücksetzen:** Setzt Jobs zurück, die im Status "Laufend" feststecken.
*   **Gefahrenzone:** Enthält irreversible Aktionen wie das vollständige Löschen aller Plugin-Daten.

### Daten-Korrektur
Wenn bei einem Import Referenzen nicht gefunden wurden (z.B. eine Teilenummer existierte nicht im Katalog), werden die Fehler hier gesammelt. Das System gibt Ihnen Vorschläge zur Korrektur.

### API-Log
Ein Protokoll aller ausgehenden API-Anfragen. Nützlich zur Fehlersuche und zur Überwachung der (simulierten) Kosten.

### Einstellungen
Die Konfigurationsseite des Plugins (siehe Abschnitt 3).


## 6. Kernfunktionen im praktischen Einsatz

### WooCommerce-Produkte erstellen & verwalten

1.  Gehen Sie zu **Inventar Verwalten**.
2.  Suchen Sie den Artikel (z.B. ein bestimmtes Teil), den Sie in Ihrem Shop verkaufen möchten.
3.  In der Spalte "Aktionen" klicken Sie auf den **"Erstellen"**-Button.
4.  Das System legt nun im Hintergrund ein variables WooCommerce-Produkt an und erstellt für jede gefundene Kombination aus Farbe und Zustand eine eigene Variante mit dem korrekten Preis und Lagerbestand.
5.  Wenn Sie den Button erneut klicken (**"Aktualisieren"**), werden Preis und Menge der Variante synchronisiert.

### Lagerorte zuweisen und nutzen

*   **Manuell:** Tragen Sie den Lagerort (z.B. "Schublade A3", "Kiste 12") in das Notizfeld (`remarks`) bei BrickOwl ein. Beim nächsten Inventar-Import oder bei einer manuellen Synchronisation unter **Werkzeuge & Wartung** wird dieser Ort automatisch der Lagerort-Taxonomie zugewiesen.
*   **Über die UI:** Nutzen Sie die Seite **Lagerverwaltung**, um Vorschläge für unsortierte Artikel zu erhalten und diese direkt zuzuweisen.

### Preise per API aktualisieren

1.  Gehen Sie zu **Inventar Verwalten**.
2.  Klicken Sie in der Zeile eines Artikels auf das kleine Download-Icon in der Spalte "Aktionen".
3.  Das System ruft den aktuellen Preis von BrickOwl ab und aktualisiert den Wert in der Tabelle und in der Datenbank.
4.  Um alle Preise auf einmal zu aktualisieren, starten Sie einen Job unter **API Synchronisation**.

### Daten mit KI anreichern

1.  Stellen Sie sicher, dass ein KI-Anbieter in den **Einstellungen** konfiguriert ist.
2.  Gehen Sie zu **Analyse & KI**.
3.  Starten Sie einen Job, z.B. **"Analyse für Artikel ohne Score starten"**.
4.  In der **Job-Warteschlange** können Sie den Fortschritt verfolgen.
5.  Nach Abschluss sehen Sie die Ergebnisse in der Spalte "Nachfrage (KI)" in der Inventar-Übersicht.


## 7. Fehlerbehebung (Troubleshooting)

**Problem: Import-Job bleibt im Status "Laufend" hängen**

*   **WP-Cron prüfen:** Installieren Sie das Plugin "WP Crontrol" und prüfen Sie, ob der Hook `lww_main_batch_hook` geplant ist und regelmäßig läuft.
*   **Server-Cron einrichten:** Für maximale Zuverlässigkeit sollten Sie den WordPress-eigenen Cron deaktivieren und einen echten Server-Cronjob einrichten.
*   **Job zurücksetzen:** Nutzen Sie das Werkzeug unter **Werkzeuge & Wartung**, um feststeckende Jobs manuell auf "Fehlgeschlagen" zu setzen und die globale Sperre aufzuheben.

**Problem: WooCommerce-Produkt wird nicht erstellt**

*   **WooCommerce aktiv?** Prüfen Sie, ob WooCommerce aktiv ist.
*   **Katalog-Verknüpfung:** Stellen Sie sicher, dass der Inventar-Eintrag korrekt mit einem Katalog-Teil verknüpft ist. In der Spalte "Name" sollte unter dem Titel ein Link "Katalog-Eintrag anzeigen" erscheinen.

**Problem: API-Verbindung schlägt fehl**

*   **Keys überprüfen:** Kopieren Sie Ihre API-Keys erneut, um Tippfehler auszuschließen.
*   **Firewall:** Deaktivieren Sie testweise Sicherheits-Plugins, um zu prüfen, ob diese ausgehende Anfragen blockieren.


--- 
*(Dieses Handbuch wird parallel zur Entwicklung des Plugins kontinuierlich aktualisiert.)*
```