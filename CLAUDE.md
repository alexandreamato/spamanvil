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
│   ├── class-spamanvil-admin.php   # 6-tab settings page, AJAX handlers, form save logic, notices, dashboard widget
│   ├── css/admin.css               # WP-consistent styling (.spamanvil- prefix)
│   ├── js/admin.js                 # Range sliders, Test Connection, Unblock IP, notice dismiss
│   └── views/
│       ├── settings-general.php    # Enable, mode, threshold, batch size, delete data, privacy
│       ├── settings-providers.php  # API keys, models, test connection per provider
│       ├── settings-prompt.php     # Editable system/user prompts + spam words
│       ├── settings-ip.php         # Block settings + blocked IP list
│       ├── settings-stats.php      # Hero banner (all-time spam blocked) + 30-day stats + tips
│       # Note: Hero banner also appears on settings-general.php
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

**Timestamp convention (critical):** The `spamanvil_queue` columns `created_at`, `updated_at`, and `retry_at` are stored in **UTC** — always write them with `current_time( 'mysql', true )` (or `gmdate()`), never `current_time( 'mysql' )`. `claim_items()`/`handle_failure()` compare these against `gmdate()`-based cutoffs via naive SQL string comparison, so a single local-time write silently breaks retry/backoff and stale-reclaim on any non-UTC site (fixed in 1.2.8). These queue columns are internal-only and never displayed; the local-time timestamps shown in the Logs and IP tabs live in other tables and are intentionally left in site-local time.

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
  → Loop: claim batch_size items → process each → repeat until queue empty or 50s elapsed
  → For each: Build prompt → Call LLM → Parse JSON → Apply threshold
  → score >= threshold(70): Mark spam + record IP attempt
  → score < threshold: Auto-approve
  → On failure: Exponential backoff (60s, 300s, 900s), max 3 retries
  → spawn_cron() called after Scan Pending to trigger immediate processing
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

## Response Parsing & Failure Visibility (1.3.0)

- **Robust JSON extraction** — `SpamAnvil_Provider::validate_response()` strips `<think>…</think>` reasoning blocks and markdown fences, then extracts the first *balanced* `{…}` object (via `extract_json_object()`) from surrounding prose, with a regex fallback for a bare `"score": N`. Reasoning/chatty models (Qwen3, DeepSeek-R1, Llama prose) previously failed 100%. Covered by `tests/unit/ResponseParsingTest.php`.
- **`max_tokens` = 400** in all providers — reasoning models need room to "think" before emitting JSON.
- **Real Test Connection** — `test_connection()` runs an actual `analyze()` through `validate_response()`, so a model that returns unparseable output fails the test instead of a false green.
- **Failure visibility** — provider-creation failures (missing/undecryptable key) are now logged via `log_evaluation()` in `try_provider_chain()`/`try_anvil_mode()`; a decryption failure returns a distinct `spamanvil_key_decrypt_failed` error (not "no API key"); and `SpamAnvil_Admin::maybe_show_health_notice()` (hooked to `admin_notices`, 5-min transient cache) warns when no provider is set or items pile up in failed/max_retries.

## Model Picker (1.4.0)

- `SpamAnvil_OpenAI_Compatible::list_models()` GETs the provider's `/models` endpoint (openai / openrouter / featherless / generic-derived) and returns a normalized list via `parse_models_response()` (pure — unit-tested in `tests/unit/ModelListTest.php`). OpenRouter entries carry `context` and a `free` flag (pricing = 0); the list sorts free-first.
- AJAX action `spamanvil_list_models` (`ajax_list_models`, nonce + `manage_options`) creates the provider with the typed-or-stored key (masked `****` ignored) and returns the list.
- UI: `settings-providers.php` renders a "Browse models" button + search/free-only panel per OpenAI-compatible provider; `admin/js/admin.js` `initModelPicker()` fetches, filters, and fills the model field (model-supplied strings rendered via `.text()`).

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

## Release & Publishing

There are **3 distribution channels** for SpamAnvil. A full release publishes to all 3.

| Channel | What it does | When to use |
|---------|-------------|-------------|
| **Git (GitHub)** | Source code history + collaboration | Every change |
| **WordPress.org (SVN)** | Plugin directory — users download from here | Every version bump |
| **Plugin ZIP** | Manual install file for users without WP.org access | On request / for testing |

### Complete Release Workflow

When the user says "publicar" or "release", follow ALL steps below in order:

#### Step 1 — Version Bump (in code)

Update the version string in **all 4 places** (must match):

| File | Location |
|------|----------|
| `spamanvil/spamanvil.php` | Plugin header `Version:` line |
| `spamanvil/spamanvil.php` | `SPAMANVIL_VERSION` constant |
| `spamanvil/readme.txt` | `Stable tag:` field |
| `spamanvil/languages/spamanvil-pt_BR.po` | `Project-Id-Version:` header |

Also add a `= X.Y.Z =` changelog entry in `readme.txt` under `== Changelog ==`.

After any JS/CSS changes, the version bump in `SPAMANVIL_VERSION` also busts browser cache.

#### Step 2 — Translations (if translatable strings changed)

```bash
# Add new msgid/msgstr entries to the .po file
# Then compile to .mo:
msgfmt -o spamanvil/languages/spamanvil-pt_BR.mo spamanvil/languages/spamanvil-pt_BR.po

# Regenerate POT if wp-cli is available:
wp i18n make-pot spamanvil/ spamanvil/languages/spamanvil.pot
```

#### Step 3 — Git Commit & Push

```bash
git add <changed files>
git commit -m "SpamAnvil vX.Y.Z: Short description"
git push
```

Commit message convention: `SpamAnvil vX.Y.Z: Short description of changes`

#### Step 4 — Publish to WordPress.org (SVN)

The plugin is hosted on WordPress.org via SVN. Local SVN working copy: `svn-spamanvil/` (git-ignored). SVN repo: `https://plugins.svn.wordpress.org/spamanvil`.

```bash
PROJECT_ROOT="/Users/alexandreamato/Amato Dropbox/Alexandre Amato/Projects/Informatica/Software/spamanvil"
SVN_DIR="$PROJECT_ROOT/svn-spamanvil"
PLUGIN_DIR="$PROJECT_ROOT/spamanvil"
VERSION="X.Y.Z"  # ← current release version

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

# 5. Review changes, then commit
svn status
svn commit -m "Release $VERSION"
```

**SVN credentials**: WordPress.org username + application password. macOS Keychain caches after first use.

#### Step 5 — Plugin ZIP (optional, on request)

```bash
cd spamanvil && zip -r ../spamanvil.zip . -x ".*" -x "__MACOSX/*"
```

### Pre-Flight Checklist

Before ANY release, verify:

1. **Version consistency** — Same version in all 4 places (see Step 1)
2. **Changelog** — `readme.txt` has `= X.Y.Z =` entry
3. **Tested up to** — `readme.txt` `Tested up to:` matches latest stable WordPress
4. **Translations** — New translatable strings have entries in `.po` and compiled `.mo`
5. **No secrets** — No API keys, passwords, or debug flags left in code
6. **ABSPATH check** — Every PHP file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }`

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

### Updating Only Assets (No New Release)

Assets (icons, banners, screenshots) live in `assets/` and can be deployed independently:

```bash
cd "$SVN_DIR"
python3 "$PROJECT_ROOT/create_assets.py"  # regenerate if needed
svn add assets/* 2>/dev/null
svn commit -m "Update assets" assets/
```

Screenshot naming: `screenshot-1.png`, `screenshot-2.png`, etc. Must match `== Screenshots ==` in `readme.txt`.

### Asset Generator

`create_assets.py` (in project root) generates all WordPress.org visual assets using Pillow:
- Icons: 128x128 and 256x256 PNG (dark background, anvil + sparks + "SA")
- Banners: 772x250 static PNG + 1544x500 retina PNG + 772x250 animated GIF

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

## Testing & Continuous Integration

Dev tooling lives at the **repo root** (never shipped in the plugin ZIP — the plugin is only the `spamanvil/` subfolder): `composer.json`, `phpunit.xml.dist` (integration), `phpunit-unit.xml.dist` (unit), `.phpcs.xml.dist`, `bin/`, `tests/`, `.github/workflows/`. Setup: `composer install` (PHP 7.4+).

**Two suites:**
- **Unit** (`tests/unit/`) — no WordPress/DB; `tests/unit/bootstrap.php` stubs the few WP functions used. Covers `SpamAnvil_Encryptor` and `SpamAnvil_Heuristics`. Run: `composer test:unit`. Fast, runs anywhere.
- **Integration** (`tests/integration/`) — real WordPress + MySQL. Covers the `SpamAnvil_Queue` state machine and the **UTC timestamp invariant** (retry eligibility, stale/max_retries reclaim — the 1.2.8 regression). Locally: `bin/install-wp-tests.sh <db> <user> <pass> [host] [wp-version]` then `composer test:integration`. Runs in CI automatically (no local MySQL needed).

**Coding standards:** `composer lint` (WPCS + PHPCompatibility). **Advisory** in CI for now — the shipped code predates WPCS (~300 mostly auto-fixable findings); clean incrementally with `composer lint:fix`, don't gate merges on it yet.

**Version gate:** `php bin/check-version.php [X.Y.Z]` asserts the version matches across all 4 places + has a changelog entry. Runs in CI and (with the tag) during deploy.

**CI** (`.github/workflows/ci.yml`, on push/PR to `main`): unit + version check (PHP 7.4/8.3), advisory phpcs, integration matrix (PHP 7.4–8.3, WP latest, MariaDB).

### Automated release (preferred over the manual SVN steps above)

`.github/workflows/deploy.yml` deploys to WordPress.org when a version tag is pushed:

1. Bump version in the 4 places (Step 1) + changelog + commit + push to `main`.
2. `git tag X.Y.Z && git push origin X.Y.Z`

It re-checks version consistency, then deploys trunk + tag to WordPress.org and builds a ZIP artifact. Requires repo secrets `WPORG_SVN_USERNAME` / `WPORG_SVN_PASSWORD`. The manual SVN workflow above remains a valid fallback.

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
| `spamanvil_cache_enabled` | `'1'` | Reuse recent LLM verdicts for identical comment content (verdict cache) |
| `spamanvil_cache_ttl_days` | `7` | How long a cached verdict is reused |
| `spamanvil_honeypot_enabled` | `'1'` | Hidden honeypot field on comment forms; bots that fill it are auto-spammed |
| `spamanvil_timetrap_enabled` | `'1'` | Flag comments submitted faster than a human plausibly could |
| `spamanvil_timetrap_seconds` | `3` | Time-trap minimum seconds before submit |

**Form traps (1.5.0 honeypot, 1.6.0 time-trap):** free, pre-LLM bot filters in `SpamAnvil_Comment_Processor`, both hooked to `comment_form` and checked at the top of `process_new_comment()` (before heuristics/LLM) via the shared `mark_trap_spam()` helper (marks spam — recoverable — + stat + IP + log with `provider = honeypot|timetrap`).
- **Honeypot** — `render_honeypot()` outputs an off-screen `spamanvil_hp` field; a filled value = bot. Cache-safe.
- **Time-trap** — `render_time_trap()` outputs a **signed** (`hash_hmac` w/ `wp_salt('nonce')`) `spamanvil_ts` timestamp; `time_trap_triggered()` flags submissions under the threshold. **Fails open** on missing/malformed/forged fields (no false positives) and is **inert under full-page caching** (frozen timestamp). These form fields are the plugin's only intentional frontend output — invisible/functional, not promotional.

**Verdict cache (1.2.9):** `process_single()` reuses a recent LLM verdict for identical comment content (transient keyed on normalized content + author URL via `verdict_cache_key()`), skipping the API call. Only raw `score`/`reason`/`provider`/`model` are cached; the threshold is applied per-read. Anvil Mode never uses the cache. Cache hits increment the `cache_hits` stat and are marked `provider (cached)` in the logs.

**Atomic claim (1.2.9):** `claim_items()` claims each row with a compare-and-swap `UPDATE ... WHERE id = ? AND status = 'queued'` (checking affected-rows), so concurrent cron + manual runs can never double-claim a row and pay for a duplicate LLM call.

## Dashboard Widget

A WordPress admin dashboard widget (`spamanvil_dashboard_widget`) shows the total spam blocked count with AI/Heuristic/IP breakdown. Links to Settings and Statistics pages. A "Rate ★★★★★" link appears when `alltime_blocked >= 20` and the review notice hasn't been dismissed.

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
