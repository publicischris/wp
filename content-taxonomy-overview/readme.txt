=== Content Taxonomy Overview ===
Contributors: content-taxonomy-overview
Tags: taxonomy, content audit, admin, seo, ai-ready
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Rule-based WordPress admin overview for taxonomy and content-structure completeness of posts and pages. Optional OpenAI settings are prepared for a later AI analysis phase.

== Installation ==
1. Copy the content-taxonomy-overview folder into wp-content/plugins/.
2. Activate "Content Taxonomy Overview" in the WordPress admin Plugins screen.
3. Open Content Taxonomy in the admin menu.

== Usage ==
Use "Alle Inhalte neu analysieren" for a full refresh or "Neu analysieren" in a table row for one post/page. The plugin only analyzes and stores scores in post meta; it does not modify content or taxonomies.

== Stored Meta Keys ==
* _cto_taxonomy_score
* _cto_structure_score
* _cto_total_score
* _cto_analysis_status
* _cto_analysis_data
* _cto_analyzed_at

== Hooks ==
* plugins_loaded
* save_post
* admin_menu
* admin_enqueue_scripts
* admin_post_cto_analyze_all
* admin_post_cto_analyze_single
* admin_post_cto_save_settings
* admin_post_cto_test_api

== Phase 2 Prepared ==
The plugin includes CTO_AI_Service, secure settings storage, masked API-key display, a connection test, and a placeholder analyze_with_ai($post_id). No AI categorization or automatic content/taxonomy changes are performed.
