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
│   ├── class-spamanvil-encryptor.php          # AES-256-GCM for API keys (AUTH_SALT-derived key); reads legacy CBC
│   ├── class-spamanvil-heuristics.php         # Regex pre-analysis: URLs, spam words, prompt injection
│   ├── class-spamanvil-ip-manager.php         # IP blocking with SHA-256 hashing + escalation; configurable trusted IP header
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
2. **spamanvil_blocked_ips** — Blocked IPs as salted HMAC-SHA-256 hashes (escalation_level, blocked_until)
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
  → If queue is paused on a config error (see below): return immediately
  → Claim batch from queue (transient lock prevents concurrent runs)
  → Loop: claim batch_size items → process each → repeat until queue empty or 50s elapsed
  → For each: Build prompt → Call LLM (provider chain × model chain) → Parse JSON → Apply threshold
  → score >= threshold(70): Mark spam + record IP attempt
  → score < threshold: Auto-approve
  → On TRANSIENT failure (network, rate limit): exponential backoff (60s, 300s, 900s), max 3 retries
  → On PERMANENT config error (no/undecryptable key, no provider/model): PAUSE the whole
    queue (item released untouched) — resumes automatically when the provider config
    hash changes. Never burns retries, never floods logs (the 1.12.0 fix for the
    1M-log-row production incident).
  → max_retries items resurrect via three paths (1.14.0): config hash changed (1h,
    counter reset — clean slate), provider PROVED healthy again (a fresh successful
    classification or green Test Connection recorded in spamanvil_last_llm_success
    after the item's failure → retry within ~1h, capped at 5 fast cycles per item via
    the queue `resurrections` column so poison items can't churn forever), or the
    24h daily safety net (uncapped — everything is eventually retried)
  → completed queue rows purged after log_retention days (daily cron); failed/
    max_retries rows are never purged — they are pending work
  → spawn_cron() called after Scan Pending to trigger immediate processing
```

**Error classification (1.12.0):** `SpamAnvil_Provider_Factory::is_permanent_config_error_code()` (pure, unit-tested in `tests/unit/ErrorClassificationTest.php`) decides pause-vs-retry. `get_config_hash()` fingerprints chain + model lists + stored keys; `SpamAnvil_Queue::pause()/is_paused()/resume()` persist the pause in `spamanvil_queue_paused` (auto-clears on hash change). The health notice shows the pause reason, and also warns when comments are queued but WP-Cron hasn't run in 30+ min (e.g. `DISABLE_WP_CRON` without a system cron).

**Model chains (1.12.0):** every provider's Model field accepts a comma/newline-separated list, parsed by `parse_model_list()` and tried in order by `try_provider_chain()`/`try_anvil_mode()` (log rows record which model failed/answered). The auto free-model fallback runs per provider as a last resort and only persists its discovery when a single model (not a list) was configured. The model picker has a "+" button to append to the chain.

**Prompt-injection hardening (1.12.0, audit S1):** author name/email/URL and post title are sanitized via `SpamAnvil_Queue::sanitize_prompt_field()` (strips boundary tags, collapses newlines, caps length; unit-tested) and the default user prompt template wraps commenter metadata in `<commenter_data>` (system prompt declares both tags untrusted). Installs whose stored prompts match an old default verbatim (MD5 whitelist in `SpamAnvil_Activator::LEGACY_*_PROMPT_HASHES`) are migrated on upgrade via `maybe_upgrade_default_prompts()`; customized prompts are never touched. Heuristic injection detection also scans author fields.

**Anthropic provider (1.12.0, audit B5):** no `temperature` (removed on current Claude models — HTTP 400), `thinking: disabled` sent for claude-sonnet-5/opus-5 (adaptive thinking is on by default there and would eat the token budget), omitted for fable/mythos (always-on, disabled → 400) and older models; `max_tokens` 1024; response parsed via `extract_text_block()` (first text block, not `content[0]`); `stop_reason: refusal` → distinct WP_Error. Unit-tested in `tests/unit/AnthropicProviderTest.php`.

**IP escalation cap (1.12.0, audit S3):** `SpamAnvil_IP_Manager::block_hours_for_level()` — 24/48/96/192/384h then hard cap 720h (30 days). Unbounded doubling gave multi-year bans and overflowed DATETIME.

**Open Mode degradation (1.12.0, audit S2):** in open mode, `hold_for_review()` publishes optimistically only while a provider is configured AND the queue is not paused; otherwise it degrades to holding comments for review.

**Smart email notifications (1.13.0):** `SpamAnvil_Notifier` (static class, hooked in `define_hooks()`) filters `notify_moderator`/`notify_post_author` to hold WordPress' insert-time comment emails for comments SpamAnvil will evaluate, then re-sends after the verdict via a `$sending_deferred` flag that lets the deliberate send pass its own suppression filter: ham → `send_postauthor()` (respects `comments_notify`), max_retries → `send_moderator()` (called from `handle_failure()`), spam → silence. `digest` mode sends one daily summary (`spamanvil_email_digest` daily cron, handler no-ops in other modes; `build_digest_body()` is pure/unit-tested; quiet days send nothing). Mode option `spamanvil_email_mode` defaults to `'smart'` for everyone including upgrades (add_option on activate + `'smart'` as the get_option default everywhere). Skipped users (moderators) always keep normal notifications. Tests: `tests/unit/NotifierUnitTest.php`, `tests/integration/NotifierTest.php` (uses the WP mock PHPMailer).

## Supported Providers

| Provider    | Class                        | Default Model                              |
|-------------|------------------------------|--------------------------------------------|
| OpenAI      | SpamAnvil_OpenAI_Compatible  | gpt-4o-mini                                |
| OpenRouter  | SpamAnvil_OpenAI_Compatible  | openrouter/free, openrouter/auto (router chain; legacy single-model defaults migrate on upgrade) |
| Featherless | SpamAnvil_OpenAI_Compatible  | meta-llama/Meta-Llama-3.1-8B-Instruct      |
| Anthropic   | SpamAnvil_Anthropic          | claude-sonnet-5                            |
| Gemini      | SpamAnvil_Gemini             | gemini-2.0-flash                           |
| Generic     | SpamAnvil_OpenAI_Compatible  | (user-defined)                             |

## Security — Critical Requirements

- **Every PHP file** must start with `if ( ! defined( 'ABSPATH' ) ) { exit; }`
- **All SQL** must use `$wpdb->prepare()` for user-supplied values
- **All forms** must use `wp_nonce_field()` + `check_admin_referer()`
- **All AJAX** must use `check_ajax_referer('spamanvil_ajax', 'nonce')` + `current_user_can('manage_options')`
- **All output** must be escaped: `esc_html()`, `esc_attr()`, `esc_url()`, `esc_textarea()`
- **All input** must be sanitized: `sanitize_text_field()`, `absint()`, `wp_kses_post()`, `esc_url_raw()`
- **API keys** are AES-256-GCM (AEAD) encrypted in DB — legacy AES-256-CBC values are still read — or defined via wp-config.php constants
- **IPs** are stored as salted, keyed hashes — `hash_hmac('sha256', $ip, wp_salt('nonce'))` via `SpamAnvil_IP_Manager::compute_ip_hash()` (unsalted SHA-256 of an IP is brute-forceable) — displayed masked (last octet hidden)
- **Client IP resolution** trusts only the admin-configured header (`spamanvil_trusted_ip_header`, default `remote_addr`) — never a raw client-supplied `X-Forwarded-For`
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

### Screenshots (`.wordpress-org/`)

WordPress.org page assets — icons, banners and `screenshot-N.png` — are versioned in **`.wordpress-org/`** at the repo root and published by `.github/workflows/assets.yml` (10up asset-update action) on any push to `main` that touches that directory. Assets are versioned independently of the plugin: a new screenshot needs no release. The directory is synced as a whole, so **every** asset must live there — a file missing locally is removed from SVN.

To regenerate the screenshots, build a throwaway demo install (no MySQL needed):

```bash
# WordPress on SQLite, in a scratch dir
curl -sL -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
curl -sL https://wordpress.org/latest.tar.gz | tar xz
curl -sL -o sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
unzip -q sqlite.zip -d wordpress/wp-content/plugins/
# copy db.copy → wp-content/db.php replacing {SQLITE_IMPLEMENTATION_FOLDER_PATH} / {SQLITE_PLUGIN}
php wp-cli.phar core install --url=http://localhost:8931 --title="Demo Site" --admin_user=demo ...
php wp-cli.phar eval-file bin/demo-seed.php      # fictional data: 180 days of stats, logs, blocked IPs
PHP_CLI_SERVER_WORKERS=8 php -S localhost:8931 -t wordpress   # workers matter: single-threaded hangs Chrome
```

Four traps that cost time the first time:
- **`PHP_CLI_SERVER_WORKERS`** — with the single-threaded built-in server the admin page's own subrequests deadlock and the headless capture hangs until timeout.
- **`siteurl`/`home`** — `wp core install --url=…` can still leave `/wordpress` in the option, and every stylesheet 404s: the capture comes out completely unstyled. Check `wp option get siteurl` before capturing.
- **Cron** — with `DISABLE_WP_CRON` off, WP-Cron fires on page loads, `cleanup_old_logs()` **purges stats older than 90 days** (so the "all-time" hero shrinks), and the queue tries to process the seeded items. Disable it, then set `spamanvil_last_cron_run` to now and delete the `spamanvil_health_check` transient, or the health notice lands in every screenshot.
- **Auth** — mu-plugin redefining the pluggable `auth_redirect()` as a no-op + `wp_set_current_user()` on `init` is what makes headless wp-admin capture work.

Capture with `--headless=new --force-device-scale-factor=2 --window-size=1440,2600 --screenshot=…`, then crop and downscale to 2160px wide.

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
- **Unit** (`tests/unit/`) — no WordPress/DB; `tests/unit/bootstrap.php` stubs the few WP functions used. Covers `SpamAnvil_Encryptor` (GCM round-trip + legacy CBC read), `SpamAnvil_Heuristics`, and `SpamAnvil_IP_Manager::resolve_client_ip()` (trusted-header selection). Run: `composer test:unit`. Fast, runs anywhere.
- **Integration** (`tests/integration/`) — real WordPress + MySQL. Covers the `SpamAnvil_Queue` state machine and the **UTC timestamp invariant** (retry eligibility, stale/max_retries reclaim — the 1.2.8 regression). Locally: `bin/install-wp-tests.sh <db> <user> <pass> [host] [wp-version]` then `composer test:integration`. Runs in CI automatically (no local MySQL needed).

**Coding standards:** `composer lint` (WPCS + PHPCompatibility). **Advisory** in CI for now — the shipped code predates WPCS (~300 mostly auto-fixable findings); clean incrementally with `composer lint:fix`, don't gate merges on it yet.

**Version gate:** `php bin/check-version.php [X.Y.Z]` asserts the version matches across all 4 places + has a changelog entry. Runs in CI and (with the tag) during deploy.

**Plugin Check:** `wordpress/plugin-check-action` runs on every push (advisory, like phpcs) — the same tool WordPress.org uses to scan plugin updates. Baseline since 1.14.4: **0 errors, 1 warning** (`mismatched_plugin_name` — the readme's long SEO title differs from the `Plugin Name` header; kept deliberately). Two gotchas: `phpcs:ignore` annotations are honoured, including for the tool's own `PluginCheck.*` sniffs, and `upgrade_notice_limit` measures the notice **HTML-escaped**, so quotes and apostrophes expand (284 visible chars measured 309 and still failed).

**CI** (`.github/workflows/ci.yml`, on push/PR to `main`): unit + version check (PHP 7.4/8.3), advisory phpcs, advisory Plugin Check, integration matrix (PHP 7.4–8.3, WP latest, MariaDB).

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
| `spamanvil_trusted_ip_header` | `'remote_addr'` | Which header identifies the visitor IP: `remote_addr` / `cf` / `x_real_ip` / `xff_last` / `auto` |
| `spamanvil_delete_data` | `'0'` | Delete all data on uninstall (off by default) |
| `spamanvil_cache_enabled` | `'1'` | Reuse recent LLM verdicts for identical comment content (verdict cache) |
| `spamanvil_cache_ttl_days` | `7` | How long a cached verdict is reused |
| `spamanvil_honeypot_enabled` | `'1'` | Hidden honeypot field on comment forms; bots that fill it are auto-spammed |
| `spamanvil_timetrap_enabled` | `'1'` | Flag comments submitted faster than a human plausibly could |
| `spamanvil_timetrap_seconds` | `3` | Time-trap minimum seconds before submit |
| `spamanvil_ratelimit_enabled` | `'1'` | Throttle rapid repeat comments per IP |
| `spamanvil_ratelimit_max` / `_window` | `5` / `60` | Max comments per window (seconds) per IP |
| `spamanvil_open_mode` | `'0'` | "Crazy Open" — strip WP comment friction + optimistic publish |
| `spamanvil_email_mode` | `'smart'` | Email notifications: `smart` (notify after verdict) / `digest` (daily summary) / `off` (WP default) |
| `spamanvil_auto_free_fallback` | `'1'` | Auto-switch to another free model when the configured one is unavailable |

**Trusted IP header + AES-256-GCM keys (1.10.0):** two security fixes from a production review.
- **Configurable client IP source.** `SpamAnvil_IP_Manager::get_client_ip()` previously trusted the left-most `X-Forwarded-For` value — client-supplied and forgeable, so a bot could rotate a fake IP per request to evade IP blocking and rate limiting. The trusted header is now the `spamanvil_trusted_ip_header` option (`remote_addr` default / `cf` / `x_real_ip` / `xff_last` / `auto`), resolved by the **pure, unit-tested** static `resolve_client_ip( $source, $server )` (`tests/unit/IpManagerTest.php`). REMOTE_ADDR is always the final fallback; `auto` prefers proxy-set headers but never the left-most XFF. UI: a "Visitor IP source" selector on the IP tab that also lists the proxy headers seen on the current request. Sites behind a proxy/CDN must pick their edge's header (Cloudflare → `cf`).
- **Authenticated key storage.** `SpamAnvil_Encryptor` now writes API keys with AES-256-GCM (AEAD), tagged with a `g:` format marker; `decrypt()` still reads legacy unprefixed CBC values, so upgrading never invalidates a stored key and re-saving migrates it. When a stored key no longer decrypts (rotated AUTH_SALT), `SpamAnvil_Provider_Factory::has_undecryptable_key()` drives an explicit `admin_notice` pointing to the Providers tab instead of a silent `provider='none'`.

**Auto free-model fallback (1.9.0):** in `try_provider_chain()`, when a provider's `analyze()` fails and `SpamAnvil_Provider_Factory::is_model_unavailable_error()` matches (404 / "no endpoints" / "not a valid model" — never auth/rate-limit), `try_free_model_fallback()` calls `find_free_alternative()` (list_models → `pick_free_model()` picks a free id ≠ the failed one), retries, and on success **persists** the new model (`update_option`), increments `model_auto_switched`, and logs the switch. Detection + selection are pure and unit-tested (`tests/unit/ModelFallbackTest.php`).

**Open Mode (1.8.0):** opt-in preset for maximum openness. `SpamAnvil::define_hooks()` adds `pre_option_*` filters (`require_name_email`, `comment_registration`, `comment_moderation`, `comment_previously_approved` → 0) so WP requires no name/email/login and holds nothing — reversible, never overwrites stored options. `hold_for_review()` returns `1` (approve now) instead of `0` (hold) in async mode: comments publish instantly and the async LLM removes spam later. The invisible pre-LLM layers still block obvious bots at `comment_post`. Trade-off: subtle spam can be briefly visible until evaluated (use Sync mode for zero delay).

**Form traps (1.5.0 honeypot, 1.6.0 time-trap):** free, pre-LLM bot filters in `SpamAnvil_Comment_Processor`, both hooked to `comment_form` and checked at the top of `process_new_comment()` (before heuristics/LLM) via the shared `mark_trap_spam()` helper (marks spam — recoverable — + stat + IP + log with `provider = honeypot|timetrap`).
- **Honeypot** — `render_honeypot()` outputs an off-screen `spamanvil_hp` field; a filled value = bot. Cache-safe.
- **Time-trap** — `render_time_trap()` outputs a **signed** (`hash_hmac` w/ `wp_salt('nonce')`) `spamanvil_ts` timestamp; `time_trap_triggered()` flags submissions under the threshold. **Fails open** on missing/malformed/forged fields (no false positives) and is **inert under full-page caching** (frozen timestamp). These form fields are the plugin's only intentional frontend output — invisible/functional, not promotional.

**Verdict cache (1.2.9):** `process_single()` reuses a recent LLM verdict for identical comment content (transient keyed on normalized content + author URL via `verdict_cache_key()`), skipping the API call. Only raw `score`/`reason`/`provider`/`model` are cached; the threshold is applied per-read. Anvil Mode never uses the cache. Cache hits increment the `cache_hits` stat and are marked `provider (cached)` in the logs.

**Atomic claim (1.2.9):** `claim_items()` claims each row with a compare-and-swap `UPDATE ... WHERE id = ? AND status = 'queued'` (checking affected-rows), so concurrent cron + manual runs can never double-claim a row and pay for a duplicate LLM call.

## Dashboard Widget

A WordPress admin dashboard widget (`spamanvil_dashboard_widget`) shows the total spam blocked count with AI/Heuristic/IP breakdown. Links to Settings and Statistics pages. A "Rate ★★★★★" link appears when `alltime_blocked >= 20` and the review notice hasn't been dismissed.

## Review Request (1.11.0)

`SpamAnvil_Admin::maybe_show_review_notice()` (hooked to `admin_notices`) is a **global**, dismissible "leave a review" ask shown on any admin screen — not just the plugin's settings page. The pure gate `review_notice_due( $dismissed, $snooze_until, $comments_checked, $activated_at, $now, $min_checked = 50, $min_age_seconds = 604800 )` (static, unit-tested in `tests/unit/ReviewNoticeTest.php`) only returns true after value is delivered (`comments_checked >= $min_checked`) AND the plugin has been installed `>= $min_age_seconds`, where both thresholds are filterable (`spamanvil_review_min_checked` / `spamanvil_review_min_age_seconds`, applied in `should_show_review_notice()`), and never when dismissed (`spamanvil_dismiss_review`) or snoozed (`spamanvil_review_snooze_until`). The three buttons are nonce'd links handled by `maybe_handle_review_action()` (`admin_init`): **Leave a review** (marks dismissed, redirects to WordPress.org), **Maybe later** (snoozes 14 days), **don't ask again** (permanent dismiss). Nonce'd links (not JS) so it works on admin screens where the plugin's JS isn't enqueued.

## Classification Prompt — measured, not guessed (1.16.0)

The default system prompt decides what gets deleted, so **change it only against a labeled evaluation**, never by intuition. `SpamAnvil_Activator::get_default_system_prompt()` was rewritten in 1.16.0 after a run against a real OpenRouter key showed the shipped default auto-spamming **3 of 7 genuine comments** on two independent free models. Two rules caused it:

- *"LANGUAGE MISMATCH. A comment in a different language than the site language is highly suspicious. Score 75+"* — a detailed, on-topic Portuguese comment on an English-locale site scored 78 and 85. Note how common the trigger is: any site publishing in one language with wp-admin in another flags **every** reader comment. Replaced by "LANGUAGE IS NOT A SIGNAL".
- *"GENERIC PRAISE … Score 70+ even without a URL"* — praise plus a concrete detail is how satisfied readers write. Now 45–60 on its own, 85+ with a link, and it does not apply when the comment references something specific.

The organizing principle added in 1.16.0 is **SCORING DISCIPLINE**: reaching 70 (the score that hides a comment) requires at least one *promotional or deceptive* signal — a promoted link, monetization keywords, a brand-name author, an injection attempt, an identity that does not add up. Short, vague, polite or foreign-language is none of those. Result on the same set: 3 false positives → 1, false negatives 0 → 0 (spam scores rose).

Also from that run: reasoning models spent the whole budget thinking and returned no JSON, so `max_tokens` for OpenAI-compatible providers is **800** (was 400) and the prompt forbids reasoning out loud — 3 parse failures in 14 → 0.

**When changing the default prompt again:** record the outgoing default's `md5( normalize_prompt( … ) )` in `LEGACY_SYSTEM_PROMPT_HASHES` or existing installs stay on the old text forever; `tests/integration/ActivatorMigrationTest.php` guards this (including that the *current* default is never in the list) with the outgoing text kept in `tests/fixtures/system-prompt-1.12.0.txt`.

**`openrouter/free` is a router, not a model.** It picks a different free model per call, and some of them cannot do this job at all — one run routed to `nvidia/nemotron-3.5-content-safety:free`, whose entire reply is `User Safety: safe`. Roughly 4 in 10 observed calls were unusable, and the chain then fell through to the paid `openrouter/auto`. Fixed in 1.17.0: `SpamAnvil_Admin::probe_working_model()` (wizard) tests the configured model, and on a non-auth failure lists the provider's free models, skips anything `SpamAnvil_Provider_Factory::is_plausible_chat_model()` rejects (safety/code/embedding/vision/speech/media families — unit-tested), and persists `<winner>, <original chain>` so the router stays as the never-stale fallback. Probes are capped at `SETUP_MODEL_PROBES` (3) because the user is waiting; an auth error short-circuits before any probe. `pick_free_model()` applies the same filter, and `test_connection()` now runs the site's real system prompt instead of a simplified one.

## Setup Wizard (1.15.0)

`SpamAnvil_Admin::render_setup_wizard()` renders `admin/views/setup-wizard.php` at `options-general.php?page=spamanvil&tab=setup`. It is **not** a tab — `render_settings_page()` intercepts `tab=setup` before the tab whitelist and returns, so the wizard is a full-screen view with no tab nav; `is_setup_screen()` also keeps the plugin's own health and review notices off it.

`ajax_setup_finish()` (nonce + `manage_options`) **tests before it writes**: it builds the provider with the pasted key as an override, runs `test_connection()`, and only on success stores the encrypted key, sets `spamanvil_primary_provider`, records `spamanvil_last_llm_success` and clears the health transient. A wrong key therefore never becomes the site's configuration.

`SpamAnvil_Activator::activate()` sets the `spamanvil_activation_redirect` transient — `maybe_redirect_after_activation()` had read it since 1.0 but nothing ever set it, so activation silently returned to the plugins list. Unconfigured installs land on the wizard; configured ones keep the old `&welcome=1` destination.

**All-time stat totals (1.15.0):** `cleanup_old_logs()` prunes `spamanvil_stats` to 90 days, but `get_total()` feeds the "all-time" hero, the dashboard widget and the review gate. It now banks the counters it is about to delete into `SpamAnvil_Stats::ARCHIVED_TOTALS_OPTION` (`spamanvil_archived_totals`, not autoloaded) and `get_total()` adds them back. The archive must stay tied to the rows actually deleted — archiving without deleting double-counts. Covered by `tests/integration/StatsRetentionTest.php`.

## Extensibility Hooks

**Filters:** `spamanvil_prompt`, `spamanvil_threshold`, `spamanvil_heuristic_score`, `spamanvil_review_min_checked` (default 50), `spamanvil_review_min_age_seconds` (default 604800 = 7 days)
**Actions:** `spamanvil_before_analysis`, `spamanvil_after_analysis`, `spamanvil_spam_detected`

## Common Tasks

- **Add a new provider**: Create class in `providers/`, add config to `$provider_configs` in `class-spamanvil-provider-factory.php`, add to `get_available_providers()`, add default model to `settings-providers.php`
- **Add a heuristic signal**: Add detection in `class-spamanvil-heuristics.php` `analyze()` method, add weight to `$weights` array
- **Add a new admin tab**: Add tab slug/label in `render_settings_page()`, create view file `admin/views/settings-{slug}.php`, add save handler in `handle_save_settings()`
- **Add a new stat counter**: Call `$this->stats->increment('new_key')` where needed, display in `settings-stats.php`

## Language

The plugin author is Brazilian. Code, comments, and readme are in English. User communication may be in Portuguese (pt-BR). Privacy notice references LGPD (Brazilian data protection law) alongside GDPR.
