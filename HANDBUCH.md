# 📘 Bricksberg Warenwirtschaft (WaWi) - Handbuch

**Version:** 6.50.0

## 1. Installation & Konfiguration

### API Dokumentation
Für den Betrieb werden folgende API-Schlüssel benötigt:
- **BrickLink:** [API Docs & Key Gen](https://www.bricklink.com/v2/api/register_consumer.page)
- **BrickOwl:** [API Docs](https://www.brickowl.com/api/docs)
- **Rebrickable:** [API v3 Docs](https://rebrickable.com/api/v3/docs/)

### Server-Anforderungen
Für den Import großer Inventare (10k+ Teile) empfehlen wir:
- PHP `memory_limit`: mind. 512M
- `max_execution_time`: 300s+
- MySQL `innodb_buffer_pool_size`: Passend zum RAM (z.B. 6GB bei 12GB RAM)
- Deaktivieren Sie Object-Caching (Redis/Memcached) während des initialen Imports.

## 2. Erste Schritte

1.  **Mandanten:** Falls Sie mehrere Shops betreiben, wählen Sie oben in der Admin-Leiste den Mandanten aus.
2.  **Import:** Laden Sie unter *Import & Sync* Ihre BrickLink XML oder BrickOwl CSV hoch. Nutzen Sie den "Smart Match", um Teile auch anhand ihres Namens zuzuordnen.
3.  **Reset:** Falls etwas schiefgeht, finden Sie unter *Werkzeuge* die "Gefahrenzone" zum Löschen aller Daten.

## 3. Funktionen

*   **KI-Preise:** Analysieren Sie Preise unter *Marktanalyse*. Die KI prüft BrickLink und BrickOwl.
*   **Scraping:** Experimentell können Daten von LEGO.com geladen werden (auf eigene Verantwortung).
*   **Lager:** Nutzen Sie den Scanner-Modus auf dem Tablet für die Inventur.

---

## 4. Externe Shops anbinden (Multi-Store)

Um das Inventar mit einer anderen WooCommerce-Installation zu synchronisieren:

1.  **Im externen Shop:**
    *   Gehen Sie zu **WooCommerce > Einstellungen > Erweitert > REST API**.
    *   Klicken Sie auf **Schlüssel hinzufügen**.
    *   **Beschreibung:** z.B. "WaWi Sync".
    *   **Benutzer:** Wählen Sie einen Administrator.
    *   **Berechtigungen:** Wählen Sie **Lesen/Schreiben**.
    *   Klicken Sie auf **API-Schlüssel erstellen**.
    *   Kopieren Sie den **Consumer Key (ck_...)** und das **Consumer Secret (cs_...)**.

2.  **In der WaWi (hier):**
    *   Gehen Sie zu **Einstellungen > Externe Shops (Multi-Woo)**.
    *   Geben Sie einen Namen (z.B. "Mein Zweitshop") und die URL ein (z.B. `https://mein-shop.de`).
    *   Fügen Sie die kopierten CK und CS Schlüssel ein.
    *   Klicken Sie auf **Shop hinzufügen**.