# Content Assistant

WordPress-Plugin für redaktionelle Content-Analyse, KI-Prompt-Erstellung und manuelle Social-Media-Performance-Dokumentation. Das Plugin bleibt bewusst Copy/Paste-basiert: Es ruft keine externe KI-API auf, veröffentlicht nichts automatisch und verändert keinen Beitragstext.

## Architektur

```text
kimapa-content-assistant/
├── kimapa-content-assistant.php
├── includes/
│   ├── class-plugin.php
│   ├── class-admin.php
│   ├── class-analyzer.php
│   ├── class-config.php
│   ├── class-content-extractor.php
│   ├── class-meta.php
│   ├── class-prompt-builder.php
│   └── class-publications.php
├── assets/
│   ├── admin.css
│   └── admin.js
└── config/
    ├── default-config.json
    └── kimapa-content-assistant.config.json
```

Die interne Namespace-/Meta-Key-Struktur enthält aus Kompatibilitätsgründen weiterhin `kimapa`-Präfixe. Sichtbare Plugin-Texte, Prompt-Texte und Defaults sind neutral als **Content Assistant** formuliert. Ein späterer, separater Rename kann Namespace, Konstanten, Textdomain und Meta Keys migrieren, ohne Live-Daten zu gefährden.

## Installation

1. Ordner `kimapa-content-assistant` nach `wp-content/plugins/` kopieren.
2. Im WordPress-Backend das Plugin **Content Assistant** aktivieren.
3. Unter **Content Assistant → Einstellungen** Projektname, Marke, Sprache, Kanäle und Content-Profil prüfen.
4. Beitrag öffnen, Meta Box **Content Assistant** verwenden und **Beitrag analysieren** klicken.

## Aktive Konfiguration

Beim Aktivieren wird `config/default-config.json` in `wp_options` unter `kimapa_content_assistant_config` gespeichert, sofern die Option noch nicht existiert. Bestehende Live-Konfigurationen werden nicht überschrieben. Die aktive Konfiguration wird zur Laufzeit mit Datei-Defaults gemergt.

## Prompt-Sprache vs. Admin-Sprache

`general.language` steuert nur die Prompt- und Ausgabe-Sprache der KI. Unterstützt sind mindestens:

- `de`: deutsche Prompt-Instruktionen
- `en`: englische Prompt-Instruktionen

Die WordPress-Admin-Oberfläche nutzt weiterhin WordPress-i18n und richtet sich nach WordPress-/Benutzersprache, nicht nach `general.language`.

## Generische Default-Konfiguration

Die Defaults sind kundenunabhängig:

- `plugin_name`: `Content Assistant`
- `brand_name`: `Your Brand`
- `portal_description`: `Editorial website or content platform.`
- Instagram aktiviert, Newsletter standardmäßig deaktiviert, redaktionelle Prüfung aktiviert, Debug deaktiviert.
- Tonalität: klar, sorgfältig, hilfreich, konkret und markenkonform.
- Required Output enthält u. a. Social-Copy-Felder, `suggested_excerpt` und `editorial_improvement_notes`.

## Content-Profile / Presets

Unter **Content Assistant → Einstellungen** kann ein Content-Profil gewählt und explizit per **Preset laden** angewendet werden. Das überschreibt bestehende Konfiguration nur nach Sicherheitsabfrage.

Vorbereitete Profile:

- **Generic Editorial**: allgemeine redaktionelle Inhalte.
- **Local / Family / Events**: lokale Inhalte, Ausflüge, Veranstaltungen, Familien-/Freizeitportale.
- **B2B Corporate**: Thought Leadership, Insights, Case Studies, Lead-Generierung.
- **Tech / IT / SaaS**: technische Inhalte, Cloud, Security, Managed Services, Use Cases.

Die Profile liefern vor allem Brand Guidance, Editorial Rules, Content Consistency Checks, Required Output und Format Settings. Kundenspezifische Tonalität, z. B. für ein Familienportal, kann über das passende Preset oder manuell gepflegt werden.

## Checks und Fakten

Der Analyzer prüft u. a.:

- Featured Image, Bildgröße und ALT-Text.
- Excerpt, Beitragslänge, Kategorien, Tags, interne Links und Überschriften.
- relative Zeitbegriffe, Datumsangaben ohne Jahr, potenziell veraltete Hinweise.
- interne Notizen / unfertige Kommentarstellen.
- einfache Tippfehler-Hinweise aus konfigurierbarer Liste.
- strukturierte Custom Fields für Adresse, Koordinaten, externe Links und Bildquelle, sofern gemappt.

`extracted_facts` enthält u. a. Standortdaten, strukturierte Felder, Quellenangaben, interne Notiz-Funde, Tippfehler-Hinweise und `facts_source`.

## Beispiel: deutscher Prompt-Ausschnitt

```json
{
  "role": "Du bist ein redaktioneller Content Assistant für Your Brand.",
  "project_context": {
    "plugin_name": "Content Assistant",
    "brand_name": "Your Brand",
    "language": "de",
    "content_profile": "generic_editorial",
    "active_channels": ["instagram", "editorial_review"]
  },
  "task": "Analysiere den Beitrag und erstelle strukturierte Vorschläge für die aktivierten Kanäle. Veröffentliche nichts automatisch.",
  "output_language_instruction": "Schreibe alle generierten Ausgaben und redaktionellen Hinweise in de.",
  "active_consistency_checks": []
}
```

## Example: English prompt excerpt

```json
{
  "role": "You are an editorial content assistant for Your Brand.",
  "project_context": {
    "plugin_name": "Content Assistant",
    "brand_name": "Your Brand",
    "language": "en",
    "content_profile": "b2b_corporate",
    "active_channels": ["instagram", "editorial_review"]
  },
  "task": "Analyze the content and create structured suggestions for the active channels. Do not publish anything automatically.",
  "output_language_instruction": "Write all generated output and editorial guidance in en."
}
```

## Strukturierte Felder / Custom Field Mapping

Wenn eine Website strukturierte Custom Fields für Adressen, Koordinaten oder Links nutzt, können die technischen Meta Keys in den Einstellungen hinterlegt werden. Diese Werte haben Vorrang vor unsicherem Content Parsing; wenn Felder leer sind und der Fallback aktiv ist, wird weiter heuristisch aus dem Beitragstext extrahiert.

## Publication History

Die Tabelle `{$wpdb->prefix}content_assistant_publications` wird bei Aktivierung per `dbDelta` vorbereitet. Snapshots werden nur per explizitem Button **Als Publication-History speichern** erzeugt; normales Speichern legt keine Historien-Duplikate an.

## Sicheres Live-Update

- Keine externen API-Calls.
- Keine automatische Veröffentlichung.
- Keine Cronjobs oder E-Mails.
- Keine Änderung am Beitragstext, Kategorien, Tags oder Featured Images.
- Bestehende `_kimapa_*` Post Meta bleiben erhalten.
- Deaktivierung löscht keine Daten.

## Spätere Erweiterungen

- KI-API kann hinter dem `Prompt_Builder` ergänzt werden.
- Social-/Meta-API kann später auf Basis der Publication-History-Tabelle synchronisieren.
- Technische Prefixes können in einer separaten Migration neutralisiert werden.
