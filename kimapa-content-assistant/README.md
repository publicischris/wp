# Content Assistant

WordPress plugin for editorial content analysis, AI prompt generation and manual social-media performance documentation. The plugin intentionally remains copy/paste based: it does not call external AI APIs, does not publish automatically and does not change post content.

## Language model

The plugin now separates two language concepts:

1. **WordPress admin UI language**
   - Controls meta box labels, buttons, checklist labels/messages, settings pages, help text and debug output.
   - Follows WordPress and the logged-in user's locale via WordPress i18n.
   - English strings are the default source strings in code.
   - No translation files are committed in this branch; English strings are the default source strings and translations can be generated later outside this branch.

2. **Prompt/output language**
   - Controls `role`, `task`, `editorial_rules`, `conditional_instructions` and the requested AI output language in the generated JSON prompt.
   - Can be configured globally under **Content Assistant → Settings**.
   - Can be overridden per post in the meta box via **Prompt language**.

## Prompt language resolution priority

When a prompt is generated, the final language is resolved in this order:

1. Post-specific prompt language, if it is not `auto`.
2. Multilingual plugin language, if detected via Polylang or WPML.
3. Global plugin prompt/output language, if it is not `auto`.
4. WordPress site locale, normalized from values such as `de_DE` or `en_US`.
5. Fallback language `de`.

The prompt never writes `auto` as the final language. It stores both:

```json
"project_context": {
  "language": "en",
  "language_source": "post_override"
}
```

## Example German prompt excerpt

```json
{
  "role": "Du bist ein redaktioneller Content Assistant für Your Brand.",
  "project_context": {
    "plugin_name": "Content Assistant",
    "brand_name": "Your Brand",
    "language": "de",
    "language_source": "multilingual_plugin",
    "content_profile": "generic_editorial",
    "active_channels": ["instagram", "editorial_review"]
  },
  "task": "Analysiere den Beitrag und erstelle strukturierte Vorschläge für die aktivierten Kanäle. Veröffentliche nichts automatisch.",
  "editorial_rules": [
    "Keine Fakten erfinden.",
    "Nur Informationen verwenden, die im Beitrag, in Kategorien, Tags, Checks oder extracted_facts vorhanden sind."
  ]
}
```

## Example English prompt excerpt

```json
{
  "role": "You are an editorial content assistant for Your Brand.",
  "project_context": {
    "plugin_name": "Content Assistant",
    "brand_name": "Your Brand",
    "language": "en",
    "language_source": "post_override",
    "content_profile": "b2b_corporate",
    "active_channels": ["instagram", "editorial_review"]
  },
  "task": "Analyze the content and create structured suggestions for the active channels. Do not publish anything automatically.",
  "editorial_rules": [
    "Do not invent facts.",
    "Only use information that is present in the article, categories, tags, checks or extracted_facts."
  ]
}
```

## Architecture

```text
kimapa-content-assistant/
├── kimapa-content-assistant.php
├── includes/
│   ├── class-plugin.php
│   ├── class-admin.php
│   ├── class-analyzer.php
│   ├── class-config.php
│   ├── class-content-extractor.php
│   ├── class-language-resolver.php
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

The internal namespace, textdomain and existing `_kimapa_*` post meta keys remain for compatibility with live data. The plugin keeps WordPress i18n calls and works without committed language files; the new per-post prompt language is stored as `_content_assistant_prompt_language`.

## Configuration

On activation, `config/default-config.json` is stored in `wp_options` under `kimapa_content_assistant_config` if the option does not exist yet. Existing live configuration is not overwritten. The active configuration is merged with file defaults at runtime.

The global prompt language setting is now a select with:

- `auto`
- `de`
- `en`

Existing saved values such as `de`, `en`, `de_DE`, `en_US`, `Deutsch` or `English` are normalized when settings are saved.

## Content profiles / presets

Prepared profiles:

- **Generic Editorial**
- **Local / Family / Events**
- **B2B Corporate**
- **Tech / IT / SaaS**

Presets are only applied when explicitly loaded from the settings page. Normal saving does not overwrite custom configuration with a preset.

## Safe live update notes

- No external API calls.
- No AI API integration.
- No Instagram/Meta API integration.
- No automatic publishing.
- No cronjobs or emails.
- No changes to post content, categories, tags or featured images.
- Deactivation does not delete data.
