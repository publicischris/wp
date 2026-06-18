=== Content Compass ===
Contributors: content-taxonomy-overview
Tags: taxonomy, content audit, admin, seo, ai-ready
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later

Rule-based WordPress admin overview for taxonomy and content-structure completeness of posts and pages with optional, controlled AI recommendations.

== Installation ==
1. Copy the content-taxonomy-overview folder into wp-content/plugins/.
2. Activate "Content Compass" in the WordPress admin Plugins screen.
3. Open Content Compass in the admin menu.

== Phase 1 ==
Rule-based analysis remains available without an API key. Use "Alle Inhalte neu analysieren" or "Neu analysieren" to refresh deterministic scores.

== Phase 2 ==
Enter the OpenAI API key under Content Compass > Einstellungen. The key is masked in the UI and is not logged. Use "API-Verbindung testen" to validate the saved key. If AI is enabled, use "Mit KI analysieren" for one row or "Alle Inhalte mit KI analysieren" for a limited batch of 10 items.

== Phase 3 ==
AI recommendations are shown in a collapsed detail panel below each analyzed row to keep the overview compact. Row actions and recommendation workflow actions use AJAX with visual feedback, so active filters remain in place. Recommendations can be accepted, ignored, or reset. Categories, tags, and existing custom-taxonomy terms are only applied after an explicit admin click. New terms are only created when the corresponding settings are enabled. Internal link recommendations are suggestions only and are never inserted automatically.

== Settings ==
* OpenAI API Key
* Modellname
* KI-Analyse aktivieren
* Maximal analysierte Zeichen pro Inhalt
* KI-Ergebnisse speichern
* KI-Analyse nur manuell starten
* KI-Analyse automatisch nach regelbasierter Analyse starten
* Inhaltstypen für Content Compass
* Prüfkriterien pro Inhaltstyp: prüfen oder nicht relevant
* Neue Kategorien/Tags aus KI-Empfehlungen erstellen erlauben
* Neue Custom-Taxonomy-Terms aus KI-Empfehlungen erstellen erlauben
* API Key entfernen

== Stored Meta Keys ==
* _cto_taxonomy_score
* _cto_structure_score
* _cto_total_score
* _cto_analysis_status
* _cto_analysis_data
* _cto_analyzed_at
* _cto_ai_enabled
* _cto_ai_analyzed_at
* _cto_ai_status
* _cto_ai_error
* _cto_ai_main_topic
* _cto_ai_content_cluster
* _cto_ai_search_intent
* _cto_ai_target_audience
* _cto_ai_recommended_categories
* _cto_ai_recommended_tags
* _cto_ai_recommended_custom_taxonomies
* _cto_ai_internal_link_suggestions
* _cto_ai_tone_assessment
* _cto_ai_summary
* _cto_ai_recommendations
* _cto_ai_raw_response
* _cto_ai_analysis_data
* _cto_ai_recommendation_status

== Hooks ==
* plugins_loaded
* save_post
* admin_menu
* admin_enqueue_scripts
* admin_post_cto_analyze_all
* admin_post_cto_analyze_single
* admin_post_cto_ai_analyze_single
* admin_post_cto_ai_analyze_all
* admin_post_cto_ai_recommendation_action
* admin_post_cto_save_settings
* admin_post_cto_test_api
* admin_post_cto_save_columns

== Files ==
Changed files: content-taxonomy-overview.php, includes/class-cto-plugin.php, includes/class-cto-analyzer.php, includes/class-cto-admin.php, includes/class-cto-settings.php, includes/class-cto-ai-service.php, includes/class-cto-utils.php, assets/admin.css, assets/admin.js, readme.txt.

== Safety ==
The plugin does not automatically modify content, insert links, assign terms, or create terms. Taxonomy changes require an explicit admin action and nonce/capability checks.

== Update: CPTs, Taxonomien und AJAX-Workflow ==
- Unterstützte Inhalte werden dynamisch aus allen im Admin sichtbaren Post Types ermittelt; Anhänge bleiben ausgeschlossen.
- KI-Prompts und gespeicherte KI-Ergebnisse werden auf die Taxonomien begrenzt, die für den jeweiligen Post Type registriert sind. Kategorien und Tags werden nur empfohlen, wenn `category` bzw. `post_tag` für den Inhalt verfügbar sind.
- Workflow-Aktionen laufen per AJAX ohne Seitenreload. Akzeptierte, geprüfte oder ignorierte Empfehlungen werden in der Übersicht ausgeblendet; eine erneute KI-Analyse erzeugt neue Vorschläge.
- Unter Einstellungen können die zu analysierenden Post Types explizit aktiviert werden. Die Übersicht, Scans und KI-Prompts verwenden danach nur diese Auswahl.
- Nach übernommenen Taxonomie-Empfehlungen wird die regelbasierte Analyse des Inhalts erneut ausgeführt; sichtbare Scores werden per AJAX aktualisiert.
- Die Admin-Übersicht enthält eine einklappbare Erklärung der Score-Regeln und pro Inhalt eine Score-Detailansicht mit erfüllten, nicht erfüllten und nicht relevanten Kriterien.
- `_cto_analysis_data` speichert nach erneuter Analyse zusätzlich `scoring.taxonomy.criteria`, `scoring.structure.criteria` und `scoring.total` mit Status, Punkten und Maximalpunkten pro Kriterium.
- Die Content-Analyse extrahiert sichtbaren Text aus Avada/Fusion-Shortcode-Strukturen, zählt Wörter per Unicode-RegEx, erkennt HTML- und Fusion-H2-Strukturen und speichert Diagnosewerte unter `content_extraction`.
- Die Avada/Fusion-Textextraktion nutzt mehrere Strategien: Shortcode-Tag-Cleanup, gerenderten Content als Fallback und ausgewählte Builder-Metafelder. KI-Empfehlungen werden vor der Anzeige gegen aktuelle Terms abgeglichen; bereits vorhandene Terms erscheinen nicht mehr als Übernehmen-Aufgabe.
- Aktive Inhaltstypen werden in `cto_enabled_post_types` gespeichert; Prüfkriterien pro Inhaltstyp in `cto_post_type_criteria_settings`.
- Nicht relevante Kriterien werden in den Score-Details als nicht relevant angezeigt und aus der Normalisierung ausgeschlossen.
- Suchintention nutzt den Meta Key `_cto_ai_search_intent`; Content Cluster nutzt `_cto_ai_content_cluster`.

== Changelog ==
= 1.2.0 =
* Post-Type-Auswahl für Content Compass ergänzt.
* Prüfkriterien pro Post Type konfigurierbar gemacht.
* Score-Normalisierung nach relevanten Kriterien eingeführt.
* Featured-Image-ALT-Text als neues Prüfkriterium ergänzt.
* Suchintention-Filter auf Dropdown umgestellt.
* Content-Cluster-Filter auf vorhandene Cluster umgestellt.

= 1.1.1 =
* Konsistente Score-Details nach Reanalyse.
* Synchronisierung von Tabellenwerten und Score-Kriterien.
* Korrektur für Tags, Custom Taxonomies, H2 und interne Links in der Detailbewertung.

= 1.1.0 =
* Verbesserte Aktualisierung von Kategorien, Tags und Custom Taxonomies nach manueller Reanalyse.
* Frischeres Laden von Term-Daten nach Änderungen im Editor.
* Konsistentere Admin-Tabellenanzeige nach Analyse und Taxonomie-Übernahme.
* Vorbereitung/stabilere UX für Content Compass.
