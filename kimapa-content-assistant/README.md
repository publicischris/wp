# KiMaPa Content Assistant

WordPress-MVP-Plugin für das Familienportal KiMaPa. Das Plugin ergänzt den klassischen Beitragseditor um eine Meta Box, prüft Beiträge heuristisch, erzeugt einen kopierbaren KI-Prompt und speichert manuell eingetragene Instagram-Performance-Daten als Post Meta.

## Dateien und Architektur

```text
kimapa-content-assistant/
├── kimapa-content-assistant.php              # Plugin-Bootstrap und Konstanten
├── includes/
│   ├── class-plugin.php                      # Verdrahtung der Services
│   ├── class-admin.php                       # Meta Box, Assets, AJAX-Endpunkte, Nonces, Capabilities
│   ├── class-analyzer.php                    # WordPress- und Content-Konsistenz-Checks, Scoring
│   ├── class-config.php                      # JSON-Konfigurationsloader mit Defaults
│   ├── class-meta.php                        # Post-Meta-Lesen/Speichern und Sanitizing
│   └── class-prompt-builder.php              # Strukturierter KI-Prompt aus Beitrag + Config
├── assets/
│   ├── admin.css                             # Admin-Layout der Meta Box
│   └── admin.js                              # AJAX für Analyse und Speichern
└── config/
    └── kimapa-content-assistant.config.json  # Tonalität, Checks, Ausgabeformat, Scoring
```

Die Services sind bewusst getrennt, damit später ein eigener KI-Service oder ein Instagram/Meta-Service ergänzt werden kann, ohne die Meta-Box-Logik neu zu schreiben.

## Installation

1. Den Ordner `kimapa-content-assistant` nach `wp-content/plugins/` kopieren.
2. Im WordPress-Backend unter **Plugins** das Plugin **KiMaPa Content Assistant** aktivieren.
3. Einen klassischen WordPress-Beitrag öffnen.
4. In der Meta Box **KiMaPa Content Assistant** auf **Beitrag analysieren** klicken.
5. Den generierten Prompt kopieren und in ein KI-Tool einfügen.
6. Vorschläge und Instagram-Performance-Daten manuell eintragen und mit **Speichern** sichern.

## MVP-Funktionen

- Admin Meta Box im Post Editor für klassische Posts.
- AJAX-Analyse mit Nonce- und Capability-Prüfung.
- JSON-Konfigurationsdatei für Tonalität, Checks, Ausgabeformat und Score-Labels.
- Heuristische WordPress-Checks:
  - Featured Image vorhanden und Mindestgröße erreicht.
  - ALT-Text vorhanden.
  - Excerpt vorhanden.
  - Beitragslänge ausreichend.
  - Kategorien und Tags vorhanden.
  - Interne Links und Zwischenüberschriften vorhanden.
  - Aktualität innerhalb des konfigurierten Schwellenwerts.
  - Relative Zeitbegriffe gefunden.
- Heuristische Content-Konsistenz-Hinweise für Zielgruppe, Region, Kosten, Öffnungszeiten, Wetter, Anfahrt und konkrete Aktualitätsangaben.
- Score von 0 bis 100.
- Strukturierter JSON-Prompt für manuelle KI-Nutzung.
- Speicherung der geforderten Post-Meta-Felder.

## Gespeicherte Post Meta

- `_kimapa_content_score`
- `_kimapa_content_checks`
- `_kimapa_generated_prompt`
- `_kimapa_instagram_caption`
- `_kimapa_instagram_hashtags`
- `_kimapa_instagram_cta`
- `_kimapa_instagram_story_idea`
- `_kimapa_instagram_carousel_idea`
- `_kimapa_newsletter_teaser`
- `_kimapa_instagram_url`
- `_kimapa_instagram_likes`
- `_kimapa_instagram_comments`
- `_kimapa_instagram_shares`
- `_kimapa_instagram_saves`
- `_kimapa_instagram_reach`
- `_kimapa_instagram_impressions`
- `_kimapa_last_ai_analysis_at`

## JSON-Konfiguration erweitern

Die Konfiguration liegt unter `config/kimapa-content-assistant.config.json`.

Typische Erweiterungen:

- `tone`: zusätzliche Tonalitätsregeln für KiMaPa.
- `avoid_phrases`: Formulierungen, die im Prompt als zu vermeiden übergeben werden.
- `wordpress_checks`: Schwellenwerte wie Wortanzahl, Bildgröße oder Aktualitätsalter.
- `content_consistency_checks`: neue heuristische Checks mit `label`, `hint`, `keywords` und `weight`.
- `output_formats`: gewünschte Ausgabeformate für Instagram, Newsletter oder spätere Kanäle.
- `scoring.labels`: andere Score-Grenzen und Labels.

Neue Konsistenzchecks benötigen im MVP keinen PHP-Code, solange sie keywordbasiert funktionieren.

## Vorbereitung für spätere APIs

- **KI-API:** `includes/class-prompt-builder.php` liefert bereits eine strukturierte JSON-Payload. Später kann ein Service wie `class-ai-service.php` diese Payload an eine externe API senden und die Antwort auf die bestehenden Meta-Felder verteilen.
- **Instagram/Meta-API:** `includes/class-meta.php` kapselt die Social- und Performance-Felder. Ein späterer Meta-Service kann hier gespeicherte URLs und Metriken synchronisieren.
- **Weitere Post Types:** `includes/class-admin.php` registriert die Meta Box aktuell nur für `post`. Die Registrierung kann später um Custom Post Types erweitert oder aus der JSON-Konfiguration gelesen werden.

## Sicherheit

- AJAX-Endpunkte verwenden WordPress-Nonces.
- Berechtigungen werden mit `current_user_can( 'edit_post', $post_id )` geprüft.
- Eingaben werden sanitisiert (`sanitize_textarea_field`, `esc_url_raw`, `absint`).
- Ausgaben in der Meta Box werden escaped.
- Es sind keine API-Keys enthalten oder erforderlich.
- Das MVP veröffentlicht nichts automatisch auf Instagram und ruft keine externe KI-API auf.

## Content-Extraktion für Avada/Fusion Builder

Die Content-Extraktion ist in `includes/class-content-extractor.php` zentralisiert. Sie liest den originalen `post_content` per `get_post_field( 'post_content', $post_id, 'raw' )`, entfernt technische Blöcke wie Scripts, Styles und Kommentare und bereinigt Avada/Fusion-Builder-Shortcodes so, dass redaktioneller Text in Containern, Rows, Columns, `fusion_text` und `fusion_title` erhalten bleibt.

Layout- und Designelemente wie Separatoren, Imageframes, Galerien oder Menü-Anker werden entfernt, damit der Prompt keinen technischen Ballast enthält. Verbleibende Shortcode-Klammern, HTML-Tags und HTML-Entities werden normalisiert; Whitespace und Leerzeilen werden reduziert.

Wenn kein manueller Excerpt vorhanden ist, erzeugt das Plugin automatisch einen sauberen Auszug aus dem bereinigten Beitragstext. Der Prompt enthält zusätzlich `content_word_count`, `content_extraction_status` und `content_extraction_warnings`. Bei leerem oder sehr kurzem Inhalt wird eine Safety-Anweisung ergänzt, damit die KI keine Details erfindet.

Administratoren sehen in der Meta Box eine einklappbare Debug-Ausgabe mit Rohinhalt-Status, bereinigter Inhaltslänge, Wortanzahl, Excerpt-Quelle und Extraktionsstatus.

### Beispiel für relevante Prompt-Felder

```json
{
  "input": {
    "title": "Ausflug mit Kindern in München",
    "permalink": "https://example.test/ausflug-muenchen/",
    "excerpt": "Ein familienfreundlicher Ausflugstipp mit Spielplatz, Café und praktischen Hinweisen zur Anfahrt…",
    "content": "Ein Ausflug mit Kindern gelingt besonders entspannt, wenn Spielmöglichkeiten, Pausen und Anfahrt gut zusammenpassen. In diesem Beitrag findet ihr konkrete Tipps...",
    "content_word_count": 842,
    "content_extraction_status": "success",
    "content_extraction_warnings": [],
    "categories": ["Ausflüge"],
    "tags": ["München", "Familien", "Wochenende"],
    "checks": []
  }
}
```

### Lokal testen

1. Plugin aktivieren und einen Beitrag mit klassischem Inhalt öffnen.
2. Einen Testbeitrag mit Avada/Fusion-Builder-Shortcodes wie `[fusion_builder_container]`, `[fusion_builder_row]`, `[fusion_builder_column]`, `[fusion_text]Text[/fusion_text]` anlegen.
3. In der Meta Box **Beitrag analysieren** klicken.
4. Prüfen, ob der kopierbare JSON-Prompt `excerpt`, `content`, `content_word_count`, `content_extraction_status` und `content_extraction_warnings` enthält.
5. Als Administrator die Debug-Ausgabe **Content-Extraktion Debug** öffnen und Rohinhalt, Länge, Wortanzahl und Excerpt-Quelle kontrollieren.

## Erweiterter Copy/Paste-Workflow

Die Meta Box ist in kompakte, einklappbare Bereiche gegliedert:

- **Analyse**: Score und redaktionelle Checkliste.
- **KI-Prompt**: kopierbarer JSON-Prompt.
- **KI-Ergebnis einfügen**: Textarea für die Antwort aus dem KI-Tool und Button **KI-Ergebnis übernehmen**.
- **Social Copy**: Caption, Caption-Varianten, Hook, CTA, Hashtags, Story, Carousel, Newsletter und redaktionelle Hinweise.
- **Instagram Performance**: Referenzlink und manuell pflegbare Kennzahlen.
- **Debug**: nur für Admins sichtbar.

Für Prompt, Caption, Hashtags, CTA, Story-Idee, Carousel-Idee, Newsletter-Teaser und Instagram-Link stehen Copy-Buttons bereit. Das JavaScript nutzt die moderne Clipboard API und fällt bei älteren Browsern auf `document.execCommand( 'copy' )` zurück.

### KI-Ergebnis-JSON übernehmen

Ein gültiges KI-Ergebnis kann z. B. so aussehen:

```json
{
  "instagram_caption_variant_1_emotional": "#Anzeige Familienzeit im Museum: Ein entspannter Ausflug für neugierige Kinder und Eltern...",
  "instagram_caption_variant_2_practical": "#Anzeige Praktischer Ausflugstipp: Adresse, Zeiten und Altersangabe vor dem Besuch prüfen...",
  "instagram_caption_variant_3_short": "#Anzeige Kurz gesagt: Ein schöner Indoor-Tipp für Familien.",
  "hook": "Schlechtwettertag? Dieser Indoor-Tipp passt für Familien.",
  "cta": "Speichert euch den Tipp für das nächste freie Wochenende.",
  "hashtags": ["#KiMaPa", "#Familienausflug", "#Indoor", "#Anzeige"],
  "story_idea": "Umfrage-Sticker: Museum oder Theater bei Regen?",
  "carousel_idea": "Slide 1 Hook, Slide 2 Für wen geeignet, Slide 3 praktische Hinweise, Slide 4 CTA.",
  "newsletter_teaser": "Ein kompakter Indoor-Tipp für Familien – mit Hinweis, welche Angaben vor dem Besuch geprüft werden sollten.",
  "editorial_improvement_notes": ["Datumsangaben ohne Jahr ergänzen.", "Platzhalter-Auszug redaktionell ersetzen."]
}
```

Beim Übernehmen wird vor dem Überschreiben vorhandener Felder eine Bestätigung angezeigt. Danach sollten die übernommenen Inhalte mit **Speichern** gesichert werden.

## Erweiterte redaktionelle Checks und Fakten

Der Analyzer erkennt zusätzlich:

- Platzhalter-Excerpts wie „Lorem ipsum“ oder „Hier steht der Auszug“.
- Potenziell veraltete Corona-/Hygiene-Hinweise.
- Werbekennzeichnungen wie `#Anzeige`, `Werbung`, `Advertorial`, `Sponsored` oder `Kooperation`.
- Relative Zeitbegriffe mit konkreter Liste der gefundenen Begriffe.
- Datumsangaben ohne Jahr, z. B. „27. Juli bis 30. September“.
- Indoor-/Outdoor-Hinweise aus Kategorien und Inhalt, damit Indoor-Beiträge beim Wettercheck nicht unnötig negativ bewertet werden.

Der Prompt enthält außerdem `extracted_facts`, z. B.:

```json
{
  "extracted_facts": {
    "location": "München",
    "address": "Beispielstraße 12, 80331 München",
    "age_recommendation": "ab 6 Jahren",
    "price_or_offer": "freier Eintritt",
    "opening_hours_or_dates": "27. Juli bis 30. September",
    "indoor_outdoor": "indoor",
    "advertising_disclosure_detected": true,
    "detected_relative_time_terms": ["aktuell", "in den Sommerferien"],
    "detected_outdated_terms": ["Corona"],
    "dates_without_year": ["27. Juli bis 30. September"],
    "placeholder_excerpt_detected": true
  }
}
```

Die Extraktion ist heuristisch und erfindet keine Fakten. Wenn ein Wert nicht sicher erkannt wird, bleibt er leer oder als leere Liste erhalten.

## Zusätzliche gespeicherte Post Meta

- `_kimapa_extracted_facts`
- `_kimapa_advertising_disclosure_detected`
- `_kimapa_detected_relative_time_terms`
- `_kimapa_detected_outdated_terms`
- `_kimapa_placeholder_excerpt_detected`
- `_kimapa_ai_result_raw`
- `_kimapa_editorial_improvement_notes`
- `_kimapa_instagram_caption_variant_1`
- `_kimapa_instagram_caption_variant_2`
- `_kimapa_instagram_caption_variant_3`
- `_kimapa_hook`
- `_kimapa_dates_without_year`

## Testhinweise für die Erweiterung

1. Beitrag mit Platzhalter-Auszug wie „Lorem ipsum“ analysieren und den Check `placeholder_excerpt` prüfen.
2. Beitrag mit „Corona“, „derzeit geschlossen“ oder „Schutzmaßnahmen“ analysieren und den Hinweis auf veraltete Inhalte prüfen.
3. Beitrag mit `#Anzeige` analysieren und prüfen, ob `advertising_disclosure_detected` im Prompt true ist und die Prompt-Regeln die Kennzeichnung erhalten.
4. Beitrag mit „aktuell“, „derzeit“ oder „in den Sommerferien“ analysieren und prüfen, ob die konkreten Begriffe im Check und Debug-Bereich erscheinen.
5. Beitrag mit „27. Juli bis 30. September“ ohne Jahr analysieren und den Check `dates_without_year` prüfen.
6. Ein gültiges KI-Ergebnis-JSON in **KI-Ergebnis JSON einfügen** einfügen, **KI-Ergebnis übernehmen** klicken und anschließend **Speichern**.
