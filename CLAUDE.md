# SpamAnvil - AI Anti-Spam Plugin for WordPress

## Project Overview

WordPress plugin that blocks comment spam using AI/LLM services. 100% free, GPLv2+, no premium tier.
Author: Alexandre Amato. Website: https://software.amato.com.br/spamanvil-antispam-plugin-for-wordpress/

## Directory Structure

```
spamanvil/                          ← Plugin root (this gets zipped for upload)
├── spamanvil.php                   # Bootstrap: header, constants, autoloader, activation hooks
├── uninstall.php                   # Conditional removal: only deletes data if user opted in
├── readme.txt                      # WordPress.org readme (SEO-optimized)
├── LICENSE.txt                     # GPLv2 full text
├── includes/
│   ├── class-spamanvil.php                    # Singleton orchestrator, wires all WP hooks
│   ├── class-spamanvil-activator.php          # DB tables (dbDelta), default options, cron scheduling
│   ├── class-spamanvil-deactivator.php        # Clear cron hooks
│   ├── class-spamanvil-encryptor.php          # AES-256-CBC for API keys (AUTH_SALT-derived key)
│   ├── class-spamanvil-heuristics.php         # Regex pre-analysis: URLs, spam words, prompt injection
│   ├── class-spamanvil-ip-manager.php         # IP blocking with SHA-256 hashing + escalation
│   ├── class-spamanvil-stats.php              # Atomic upsert counters + evaluation logs
│   ├── class-spamanvil-queue.php              # Async processing: batch, retry, backoff, prompt building
│   ├── class-spamanvil-comment-processor.php  # WP comment hooks (preprocess, pre_approved, comment_post)
│   ├── class-spamanvil-provider-factory.php   # Factory: resolves key/model, creates provider + fallback
│   └── providers/
│       ├── class-spamanvil-provider.php           # Abstract base: request/response/validate cycle
│       ├── class-spamanvil-openai-compatible.php  # OpenAI, OpenRouter, Featherless, Generic
│       ├── class-spamanvil-anthropic.php          # Claude (unique auth + format)
│       └── class-spamanvil-gemini.php             # Gemini (unique format)
├── admin/
│   ├── class-spamanvil-admin.php   # 6-tab settings page, AJAX handlers, form save logic, notices
│   ├── css/admin.css               # WP-consistent styling (.spamanvil- prefix)
│   ├── js/admin.js                 # Range sliders, Test Connection, Unblock IP, notice dismiss
│   └── views/
│       ├── settings-general.php    # Enable, mode, threshold, batch size, delete data, privacy
│       ├── settings-providers.php  # API keys, models, test connection per provider
│       ├── settings-prompt.php     # Editable system/user prompts + spam words
│       ├── settings-ip.php         # Block settings + blocked IP list
│       ├── settings-stats.php      # Hero banner (all-time spam blocked) + 30-day stats + tips
│       └── settings-logs.php       # Evaluation logs with scores, reasons, timing
└── languages/
    └── spamanvil.pot               # Translation template
```

## Naming Conventions

| Element         | Pattern                    | Example                          |
|-----------------|----------------------------|----------------------------------|
| Classes         | `SpamAnvil_*`              | `SpamAnvil_Queue`                |
| Options (DB)    | `spamanvil_*`              | `spamanvil_threshold`            |
| Constants       | `SPAMANVIL_*`              | `SPAMANVIL_VERSION`              |
| DB tables       | `{prefix}spamanvil_*`     | `wp_spamanvil_queue`             |
| CSS classes     | `.spamanvil-*`             | `.spamanvil-card`                |
| Hooks/actions   | `spamanvil_*`              | `spamanvil_before_analysis`      |
| Cron events     | `spamanvil_*`              | `spamanvil_process_queue`        |
| AJAX actions    | `spamanvil_*`              | `spamanvil_test_connection`      |
| Text domain     | `spamanvil`                |                                  |
| wp-config keys  | `SPAMANVIL_*_API_KEY`      | `SPAMANVIL_OPENAI_API_KEY`       |

## Database Tables (4)

1. **spamanvil_queue** — Comment processing queue (status, score, reason, provider, attempts, retry_at)
2. **spamanvil_blocked_ips** — Blocked IPs as SHA-256 hashes (escalation_level, blocked_until)
3. **spamanvil_stats** — Daily counters with UNIQUE(stat_date, stat_key), atomic upserts
4. **spamanvil_logs** — Per-comment evaluation logs (score, provider, model, reason, heuristic_details, processing_time_ms)

## Comment Processing Flow

```
Comment submitted
  → preprocess_comment: Check if IP is blocked → wp_die(403) if yes
  → pre_comment_approved: Hold as pending (async mode)
  → comment_post: Run heuristics
      → If heuristic_score >= 95: Auto-spam (no API call)
      → Else: Enqueue for async LLM analysis (or process sync)

WP-Cron (every 5 min):
  → Claim batch from queue (transient lock prevents concurrent runs)
  → For each: Build prompt → Call LLM → Parse JSON → Apply threshold
  → score >= threshold(70): Mark spam + record IP attempt
  → score < threshold: Auto-approve
  → On failure: Exponential backoff (60s, 300s, 900s), max 3 retries
```

## Supported Providers

| Provider    | Class                        | Default Model                              |
|-------------|------------------------------|--------------------------------------------|
| OpenAI      | SpamAnvil_OpenAI_Compatible  | gpt-4o-mini                                |
| OpenRouter  | SpamAnvil_OpenAI_Compatible  | meta-llama/llama-3.3-70b-instruct:free     |
| Featherless | SpamAnvil_OpenAI_Compatible  | meta-llama/Meta-Llama-3.1-8B-Instruct      |
| Anthropic   | SpamAnvil_Anthropic          | claude-sonnet-4-5-20250929                 |
| Gemini      | SpamAnvil_Gemini             | gemini-2.0-flash                           |
| Generic     | SpamAnvil_OpenAI_Compatible  | (user-defined)                             |

## Security — Critical Requirements

- **Every PHP file** must start with `if ( ! defined( 'ABSPATH' ) ) { exit; }`
- **All SQL** must use `$wpdb->prepare()` for user-supplied values
- **All forms** must use `wp_nonce_field()` + `check_admin_referer()`
- **All AJAX** must use `check_ajax_referer('spamanvil_ajax', 'nonce')` + `current_user_can('manage_options')`
- **All output** must be escaped: `esc_html()`, `esc_attr()`, `esc_url()`, `esc_textarea()`
- **All input** must be sanitized: `sanitize_text_field()`, `absint()`, `wp_kses_post()`, `esc_url_raw()`
- **API keys** are AES-256-CBC encrypted in DB or defined via wp-config.php constants
- **IPs** are stored as SHA-256 hashes, displayed masked (last octet hidden)
- **HTTP requests** use `wp_safe_remote_post()` with 30s timeout

## Prompt Injection Defense (6 layers)

1. `<comment_data>` boundary tags isolate user input
2. System prompt explicitly forbids following comment instructions
3. Heuristic regex detects 14 injection patterns (raises spam score)
4. Strict JSON validation: only `{"score": 0-100, "reason": "..."}` accepted
5. Temperature = 0 for deterministic output
6. Content truncated at 5,000 characters

## WordPress.org Compliance Rules

- **NO affiliate/referral links** in readme.txt or admin UI (WordPress.org Guideline 12)
- **NO tracking/phoning home** without explicit consent (Guideline 7)
- **NO external scripts/CDN** — use WordPress bundled libraries (Guideline 13)
- **NO frontend output** (no "powered by" links, no public-facing HTML)
- **NO obfuscated code** (base64 only for encryption, with phpcs:ignore)
- **Third-party services must be disclosed** in readme.txt with Terms/Privacy links
- **All code must be human-readable** (no minification without source)
- **readme.txt tags**: max 5, no near-duplicates, no keyword stuffing
- **LICENSE.txt** must exist in plugin root with full GPLv2 text
- **POT file** must contain ALL translatable strings (regenerate with `wp i18n make-pot`)
- **Tested up to** must match latest WordPress version at submission time

## Building the Plugin ZIP

```bash
# From the project root (parent of spamanvil/):
cd spamanvil && zip -r ../spamanvil.zip . -x ".*" -x "__MACOSX/*"
```

After any JS/CSS changes, bump `SPAMANVIL_VERSION` in `spamanvil.php` to bust browser cache.

## Publishing to WordPress.org (SVN)

The plugin is hosted on WordPress.org via SVN. The local SVN working copy is at `svn-spamanvil/` (git-ignored). The SVN repo URL is `https://plugins.svn.wordpress.org/spamanvil`.

### SVN Directory Layout

```
svn-spamanvil/
├── assets/             # WordPress.org page assets (NOT shipped with plugin)
│   ├── icon-128x128.png
│   ├── icon-256x256.png
│   ├── banner-772x250.png
│   ├── banner-772x250.gif    # Animated banner
│   └── banner-1544x500.png   # Retina banner
├── trunk/              # Current development version (mirrors spamanvil/)
└── tags/
    └── X.Y.Z/          # Tagged releases (one directory per version)
```

### Pre-Flight Checklist

Before publishing, verify ALL of these:

1. **Version consistency** — The same version string must appear in all 3 places:
   - `spamanvil/spamanvil.php` → Plugin header `Version:` AND `SPAMANVIL_VERSION` constant
   - `spamanvil/readme.txt` → `Stable tag:` field
2. **Changelog** — `readme.txt` has a `= X.Y.Z =` entry under `== Changelog ==`
3. **Tested up to** — `readme.txt` `Tested up to:` matches the latest stable WordPress version
4. **POT file** — Regenerate if any translatable strings changed:
   ```bash
   wp i18n make-pot spamanvil/ spamanvil/languages/spamanvil.pot
   ```
5. **No secrets** — Grep for API keys, passwords, debug flags left in code
6. **ABSPATH check** — Every PHP file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }`

### Deployment Commands

```bash
# All commands from project root:
PROJECT_ROOT="/Users/alexandreamato/Amato Dropbox/Alexandre Amato/Projects/Informatica/Software/llm_anti_spam"
SVN_DIR="$PROJECT_ROOT/svn-spamanvil"
PLUGIN_DIR="$PROJECT_ROOT/spamanvil"
VERSION="1.1.2"  # ← Update this each release

# 1. Update SVN working copy
cd "$SVN_DIR" && svn up

# 2. Sync plugin files to trunk (delete old, copy new)
rm -rf "$SVN_DIR/trunk/"*
cp -R "$PLUGIN_DIR/"* "$SVN_DIR/trunk/"

# 3. Create version tag
svn cp "$SVN_DIR/trunk" "$SVN_DIR/tags/$VERSION"

# 4. Stage all changes (adds new files, removes deleted ones)
cd "$SVN_DIR" && svn add --force trunk/ tags/$VERSION/ 2>/dev/null
svn status | grep '^!' | awk '{print $2}' | xargs -I {} svn rm {}

# 5. Review and commit
svn status
svn commit -m "Release $VERSION"
```

**SVN credentials**: WordPress.org username + application password. SVN will prompt on first commit; macOS Keychain caches it.

### Updating Only Assets (No New Release)

Assets (icons, banners, screenshots) live in `assets/` and are deployed independently from plugin code:

```bash
cd "$SVN_DIR"
# (regenerate assets if needed)
python3 "$PROJECT_ROOT/create_assets.py"
svn add assets/* 2>/dev/null
svn commit -m "Update assets" assets/
```

Screenshot naming: `screenshot-1.png`, `screenshot-2.png`, etc. Must match descriptions in `readme.txt` `== Screenshots ==` section.

### Asset Generator

`create_assets.py` (in project root) generates all WordPress.org visual assets using Pillow:
- Icons: 128x128 and 256x256 PNG (dark background, anvil + sparks + "SA")
- Banner: 772x250 static PNG + 1544x500 retina PNG
- Banner: 772x250 animated GIF (3-scene: drop, features, CTA)

```bash
pip install Pillow  # if needed
python3 create_assets.py
```

### Important SVN Notes

- SVN `trunk/` IS the published version (WordPress.org reads from it immediately)
- `Stable tag:` in readme.txt tells WordPress.org which `tags/` directory to serve as download
- If `Stable tag: trunk`, users download trunk directly (use for beta testing only)
- Assets in `assets/` are NOT included in the plugin ZIP — they only appear on the WordPress.org page
- WordPress.org caches aggressively; asset changes can take up to 24h to appear
- Never commit `.svn/`, `.git/`, `.DS_Store`, or IDE files to SVN

## Key Options

| Option | Default | Purpose |
|--------|---------|---------|
| `spamanvil_enabled` | `'1'` | Plugin on/off |
| `spamanvil_mode` | `'async'` | `async` (WP-Cron) or `sync` |
| `spamanvil_threshold` | `70` | Spam score cutoff (0-100) |
| `spamanvil_heuristic_auto_spam` | `95` | Heuristic auto-block threshold |
| `spamanvil_batch_size` | `5` | Comments per cron batch |
| `spamanvil_primary_provider` | `''` | Primary LLM slug |
| `spamanvil_fallback_provider` | `''` | Fallback LLM slug |
| `spamanvil_log_retention` | `30` | Days to keep logs |
| `spamanvil_ip_block_threshold` | `3` | Spam attempts before IP block |
| `spamanvil_delete_data` | `'0'` | Delete all data on uninstall (off by default) |

## Extensibility Hooks

**Filters:** `spamanvil_prompt`, `spamanvil_threshold`, `spamanvil_heuristic_score`
**Actions:** `spamanvil_before_analysis`, `spamanvil_after_analysis`, `spamanvil_spam_detected`

## Common Tasks

- **Add a new provider**: Create class in `providers/`, add config to `$provider_configs` in `class-spamanvil-provider-factory.php`, add to `get_available_providers()`, add default model to `settings-providers.php`
- **Add a heuristic signal**: Add detection in `class-spamanvil-heuristics.php` `analyze()` method, add weight to `$weights` array
- **Add a new admin tab**: Add tab slug/label in `render_settings_page()`, create view file `admin/views/settings-{slug}.php`, add save handler in `handle_save_settings()`
- **Add a new stat counter**: Call `$this->stats->increment('new_key')` where needed, display in `settings-stats.php`

## Language

The plugin author is Brazilian. Code, comments, and readme are in English. User communication may be in Portuguese (pt-BR). Privacy notice references LGPD (Brazilian data protection law) alongside GDPR.
