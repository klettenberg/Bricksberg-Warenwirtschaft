# Bricksberg Warenwirtschaft (WaWi)

[![WordPress Plugin Version](https://img.shields.io/badge/WordPress-6.2+-blue.svg)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-8.0+-8892BF.svg)](https://php.net/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-orange.svg)](https://www.gnu.org/licenses/gpl-2.0)

Professionelles Warenwirtschaftssystem für LEGO® Händler. Zentrale Verwaltungsplattform zur Organisation von Katalogdaten und präzisen Lagerbestandssynchronisation über mehrere Marktplätze.

## ✨ Features

- **API-Integrationen**: BrickLink, BrickOwl, Rebrickable, eBay
- **Intelligente Katalogverwaltung**: Automatische Datenanreicherung und Duplikatserkennung
- **Batch-Verarbeitung**: Asynchrone Jobs für große Datenmengen
- **KI-gestützte Preisanalyse**: Marktnachfrage-Analyse mit OpenAI/Gemini
- **Mehrsprachig**: Deutsche und englische Oberfläche
- **Multitenant-fähig**: Mandantenfähige Architektur

## 🚀 Installation

1. **Voraussetzungen**:
   - WordPress 6.2+
   - PHP 8.0+
   - WooCommerce (erforderlich)
   - MySQL/MariaDB

2. **Plugin installieren**:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/yourusername/bricksberg-warenwirtschaft.git
   ```

3. **Aktivieren**:
   - Gehe zu `WP Admin > Plugins`
   - Aktiviere "Bricksberg Warenwirtschaft"
   - Konfiguriere API-Schlüssel in den Einstellungen

## ⚙️ Konfiguration

### API-Schlüssel einrichten

1. **BrickLink**: [API-Registrierung](https://www.bricklink.com/v2/api/register_consumer.page)
2. **BrickOwl**: [API-Dokumentation](https://www.brickowl.com/api/docs)
3. **Rebrickable**: [API v3](https://rebrickable.com/api/v3/docs/)

### Grundlegende Einstellungen

```php
// wp-config.php oder Einstellungen
define('LWW_API_CACHE_TIMEOUT', 3600); // 1 Stunde Cache
define('LWW_MAX_IMPORT_SIZE', 1000);  // Max Items pro Import
```

## 📖 Verwendung

### Daten importieren

1. **Rebrickable Katalog**: Basisdaten für Teile, Sets und Minifiguren
2. **BrickLink Inventory**: Lagerbestand mit Preisen
3. **BrickOwl CSV**: Alternative Datenquelle

### Jobs verwalten

- **Dashboard**: Übersicht über aktive Jobs
- **Job-Queue**: Monitoring und Steuerung
- **Log-Analyse**: Detaillierte Fehlerdiagnose

## 🔧 Entwicklung

### Architektur

```
includes/
├── api/                    # API-Client-Klassen
├── import-handlers/       # Datenimport-Handler
├── lww-*.php             # Kernmodule
└── admin/                 # Admin-Interface
```

### Coding Standards

- **WordPress Coding Standards** (WPCS)
- **PSR-4 Autoloading** (empfohlen für Erweiterungen)
- **PHPDoc** für alle Klassen und Funktionen
- **Internationalisierung** mit `bricksberg-wawi` Textdomain

### Tests

```bash
# Unit Tests (empfohlen für Erweiterungen)
composer test

# API-Tests
wp eval "lww_test_api_connections();"
```

## 🔒 Sicherheit

- **Nonce-Verifikation** bei allen AJAX-Requests
- **Berechtigungsprüfungen** (`current_user_can()`)
- **Input-Sanitization** (`sanitize_text_field()`, `sanitize_textarea_field()`)
- **Prepared Statements** für Datenbank-Queries
- **API-Key-Verschlüsselung** (empfohlen)

## 📊 Performance

### Empfohlene Server-Konfiguration

```ini
# php.ini
memory_limit = 512M
max_execution_time = 300
upload_max_filesize = 50M

# MySQL
innodb_buffer_pool_size = 6G  # Bei 12GB RAM
query_cache_type = 1
query_cache_size = 256M
```

### Caching-Strategien

- **Transient API**: Für teure Statistiken (1 Stunde)
- **Object Cache**: Für Meta-Lookups (24 Stunden)
- **File Cache**: Für API-Chunks während Imports

## 🤝 Beitrag

1. Fork das Repository
2. Erstelle einen Feature-Branch (`git checkout -b feature/amazing-feature`)
3. Commit deine Änderungen (`git commit -m 'Add amazing feature'`)
4. Push zum Branch (`git push origin feature/amazing-feature`)
5. Öffne einen Pull Request

### Entwicklungsrichtlinien

- **PHP 8.0+** kompatibel
- **WordPress 6.2+** kompatibel
- **Backwards-Kompatibilität** beibehalten
- **Dokumentation** aktualisieren
- **Tests** für neue Features

## 📝 Changelog

### [6.50.0] - 2024-12-15
- ✅ Vollständige API-Sync-Implementierung
- ✅ Intelligente Katalog-Anreicherung
- ✅ Datenbank-Diagnose und -Bereinigung
- ✅ Performance-Optimierungen
- ✅ GitHub-ready Code-Qualität

### [0.48.0] - 2024-11-01
- Initiale Veröffentlichung
- Grundlegende Import-Funktionalität
- WooCommerce-Integration

## 📄 Lizenz

Dieses Plugin ist lizenziert unter der **GPL v2 or later**.

```
Copyright (C) 2024 Bricksberg

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
```

## 🆘 Support

- **Dokumentation**: [Handbuch](HANDBUCH.md)
- **Issues**: [GitHub Issues](https://github.com/yourusername/bricksberg-warenwirtschaft/issues)
- **Forum**: [WordPress.org](https://wordpress.org/support/plugin/bricksberg-warenwirtschaft/)

## 🙏 Credits

Entwickelt von [Bricksberg](https://bricksberg.eu/) für die LEGO® Community.

**API-Provider** (ohne Gewähr):
- BrickLink API
- BrickOwl API
- Rebrickable API
- eBay API