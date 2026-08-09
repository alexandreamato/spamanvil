=== SpamAnvil – AI Anti-Spam & Comment Spam Protection ===
Contributors: aamato
Donate link: https://github.com/sponsors/alexandreamato
Tags: antispam, comment spam, spam protection, ai, moderation
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.13.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free AI anti-spam & Akismet alternative. Uses ChatGPT, Claude, Gemini & other LLMs to block comment spam that traditional filters miss.

== Description ==

**SpamAnvil is a free, open-source WordPress anti-spam plugin — and a genuine Akismet alternative — that uses artificial intelligence to block comment spam.** Unlike Akismet (which requires a paid plan for commercial sites) or simple keyword-based filters, SpamAnvil leverages large language models (LLMs) to actually *understand* your comments and detect even the most sophisticated spam. It layers a honeypot, a time trap and per-IP rate limiting in front of the AI, so obvious bots are blocked for free.

Traditional spam filters rely on static word lists and link counting. Spammers have evolved. **SpamAnvil fights back with AI that understands context, intent, and language patterns** - catching spam that looks legitimate and approving real comments that others would flag.

= Why SpamAnvil? =

* **100% Free** - No premium tier, no subscription, no hidden costs. Bring your own API key (free options available).
* **Smarter Than Rules** - AI understands context. A comment about "buying a new home" won't be flagged just because it contains "buy".
* **Defense in Depth** - Honeypot, time trap, per-IP rate limit and heuristics block obvious bots for free, so the AI is only spent on the subtle cases.
* **Open Mode** - Because the filtering is invisible and automatic, you can drop the usual barriers (name/email, login, moderation) and get more real comments - even anonymous ones - without opening the door to spam.
* **Works With Free AI Models** - Use OpenRouter's free models for $0 cost, or connect premium models for maximum accuracy.
* **Privacy-First** - Your data stays between you and your chosen AI provider. IP addresses are stored as salted, keyed hashes (HMAC-SHA-256), not recoverable without your site's secret. GDPR/LGPD compliant by design.
* **No Cloud Lock-in** - Choose from 6+ AI providers. Switch anytime. Your anti-spam, your rules.

= Supported AI Providers =

* **OpenAI** (GPT-4o-mini, GPT-4o, etc.)
* **Anthropic Claude** (Claude Sonnet, Haiku, etc.)
* **Google Gemini** (Gemini 2.0 Flash, Pro, etc.)
* **OpenRouter** (100+ models, including FREE ones)
* **Featherless.ai** (Open-source models)
* **Any OpenAI-compatible API** (LM Studio, Ollama via proxy, vLLM, etc.)

= A Swiss-Army-Knife of Defenses =

SpamAnvil layers several spam defenses so cheap, invisible filters catch obvious bots for free and the AI only handles the subtle cases. Every layer is optional and runs before any paid API call:

* **Honeypot** - A hidden field bots fill but humans never see. Instant, free block.
* **Time trap** - Comments submitted faster than a human could type are flagged. Tamper-proof (signed) and fails open, so it never blocks a real visitor.
* **Per-IP rate limit** - Throttles comment floods from a single IP before they even reach the database.
* **Heuristics engine** - Regex/statistical pre-analysis (URLs, spam words, gambling/SEO author names, language mismatch, prompt-injection patterns) that auto-spams the obvious cases with no API call.
* **AI verdict** - An LLM scores the rest 0-100 in context.

= Key Features =

* **AI-Powered Spam Detection** - Each comment is analyzed by an LLM that scores it 0-100 for spam probability. Works with reasoning models (their thinking is parsed correctly).
* **Layered Bot Defense** - Honeypot, time trap and per-IP rate limiting block obvious bots for free, before any AI call.
* **Model Picker** - Browse and search each provider's live model list right from the settings page (OpenAI, OpenRouter, Featherless). OpenRouter shows a "free" badge and context size.
* **"Open Mode"** - One toggle removes WordPress comment friction (no required name/email, no login, no moderation hold) so leaving a real, even anonymous, comment is effortless. Comments appear instantly and spam is removed in the background.
* **Verdict Cache** - Identical repeated spam reuses a recent AI verdict instead of calling the API again, cutting cost during floods.
* **Intelligent Heuristics Engine** - Pre-analyzes comments to catch obvious spam without API calls.
* **Async Background Processing** - Comments are queued and processed via WP-Cron so your site stays fast.
* **Smart IP Blocking** - Automatically blocks repeat offenders with escalating ban durations (24h, 48h, 96h...).
* **Automatic Retry with Backoff** - Failed API calls retry with exponential delays; a real "Test Connection" verifies actual classification, not just the HTTP status.
* **Encrypted API Key Storage** - Authenticated AES-256-GCM encryption, or wp-config.php constants for maximum security.
* **Statistics Dashboard & Logs** - Track spam caught by each layer, API usage and errors, with the AI's reasoning for every comment. An admin warning surfaces silent failures (no provider, or a backlog of failed items).
* **Customizable AI Prompts, Fallback Providers, Prompt-Injection Defense, Configurable Threshold, Moderator Bypass.**

= How It Works =

1. A visitor submits a comment.
2. **Rate limit** - too many comments from this IP too fast? Throttled with an HTTP 429 (before anything is stored).
3. **IP block** - is the IP already banned for repeat spam? Blocked.
4. **Form traps** - was the hidden honeypot filled, or the comment submitted implausibly fast? Marked as spam instantly, no API call.
5. **Heuristics** - a quick regex/statistical pre-analysis; obvious spam is auto-marked with no API call.
6. Otherwise the comment is queued for AI analysis (or processed immediately in sync mode). Identical repeated content reuses a cached verdict.
7. The AI analyzes the comment in context (post title, author info, heuristic data) and returns a spam score.
8. Comments above your threshold are marked spam; clean comments are auto-approved. Repeat-offender IPs are escalated.

With **Open Mode** on, comments publish instantly and any spam is removed in the background instead of being held for moderation.

= Use Cases =

* **Blogs** receiving hundreds of spam comments per day
* **WooCommerce stores** where comment spam affects SEO and credibility
* **Membership sites** that need to protect community discussions
* **Multilingual sites** - AI understands comments in any language, unlike keyword-based filters
* **High-traffic sites** - Async processing handles any volume without slowing down your site
* **Sites tired of Akismet** - Free alternative with no cloud dependency and full data control

= Security =

SpamAnvil follows WordPress security best practices throughout:

* Authenticated AES-256-GCM encrypted API key storage
* wp-config.php constant support for API keys (never touch the database)
* Configurable trusted IP header (default REMOTE_ADDR) so a forged X-Forwarded-For cannot bypass IP blocking or rate limiting
* Nonce verification on all forms and AJAX requests
* Capability checks on all admin actions
* Prepared SQL statements on every database query
* Output escaping on all rendered content
* Prompt injection defense: boundary tags, system prompt hardening, heuristic injection detection, strict JSON validation, temperature 0

= Languages =

* English (default)
* Translation-ready (.pot file included)

= Third-Party Services =

SpamAnvil sends comment data (content, author name, email, and URL) to external AI services for spam analysis. The specific service used depends on your configuration. No data is sent until you configure and enable a provider.

* **OpenAI** — [https://openai.com](https://openai.com) — [Terms of Use](https://openai.com/policies/terms-of-use) — [Privacy Policy](https://openai.com/policies/privacy-policy)
* **Anthropic (Claude)** — [https://www.anthropic.com](https://www.anthropic.com) — [Terms of Service](https://www.anthropic.com/policies#terms) — [Privacy Policy](https://www.anthropic.com/policies#privacy)
* **Google Gemini** — [https://ai.google.dev](https://ai.google.dev) — [Terms of Service](https://ai.google.dev/gemini-api/terms) — [Privacy Policy](https://policies.google.com/privacy)
* **OpenRouter** — [https://openrouter.ai](https://openrouter.ai) — [Terms of Service](https://openrouter.ai/terms) — [Privacy Policy](https://openrouter.ai/privacy)
* **Featherless.ai** — [https://featherless.ai](https://featherless.ai/) — [Terms of Service](https://featherless.ai/terms) — [Privacy Policy](https://featherless.ai/privacy)

When using the "Generic OpenAI-Compatible" option, data is sent to the URL you configure. You are responsible for ensuring compliance with the privacy policies of your chosen service.

== Installation ==

= Automatic Installation =

1. Go to **Plugins > Add New** in your WordPress admin
2. Search for **SpamAnvil**
3. Click **Install Now** and then **Activate**
4. Go to **Settings > SpamAnvil**
5. Choose an AI provider and enter your API key
6. Done! Comments will now be analyzed for spam.

= Manual Installation =

1. Download the plugin zip file
2. Go to **Plugins > Add New > Upload Plugin**
3. Upload the zip file and click **Install Now**
4. Activate the plugin
5. Configure your AI provider in **Settings > SpamAnvil**

= Getting a Free API Key =

Want to use SpamAnvil for completely free? Here's how:

1. Go to [OpenRouter.ai](https://openrouter.ai/) and create a free account
2. Generate an API key
3. In SpamAnvil settings, select **OpenRouter** as your primary provider
4. Paste your API key
5. The default model (`meta-llama/llama-3.3-70b-instruct:free`) is free to use!

= Optional: Define API keys in wp-config.php =

For maximum security, define API keys as constants in your wp-config.php:

`
define('SPAMANVIL_OPENAI_API_KEY', 'sk-...');
define('SPAMANVIL_OPENROUTER_API_KEY', 'sk-or-...');
define('SPAMANVIL_ANTHROPIC_API_KEY', 'sk-ant-...');
define('SPAMANVIL_GEMINI_API_KEY', '...');
define('SPAMANVIL_FEATHERLESS_API_KEY', '...');
`

When keys are defined in wp-config.php, they are never stored in the database at all.

== Frequently Asked Questions ==

= Is SpamAnvil really free? =

Yes, 100% free and open source. There is no premium version. You only need an API key from an AI provider, and free options are available (e.g., OpenRouter with free Llama models).

= How is SpamAnvil different from Akismet? =

Akismet uses a centralized cloud service owned by Automattic. It requires a paid subscription for commercial sites, and all your comments are sent to Akismet's servers. SpamAnvil lets you choose your own AI provider, works with free models, keeps you in control of your data, and uses true AI understanding instead of statistical pattern matching.

= How is SpamAnvil different from Antispam Bee? =

Antispam Bee uses traditional techniques like honeypot fields, country blocking, and regex rules. These work for basic spam but miss sophisticated attacks. SpamAnvil adds AI analysis that actually reads and understands comments in context, catching spam that looks legitimate to keyword-based systems.

= Which AI provider should I use? =

**For free usage:** OpenRouter with the free Llama 3.1 8B model works surprisingly well for spam detection.
**For best accuracy:** OpenAI GPT-4o-mini offers the best quality-to-price ratio.
**For privacy:** Google Gemini or a self-hosted model via the Generic OpenAI-compatible option.
**For maximum reliability:** Configure a primary + fallback provider so spam checking never stops.

= Does this slow down my website? =

No. In the default async mode, comments are held as "pending" and processed in the background via WP-Cron every 5 minutes. Your visitors experience zero added latency. There's also a sync mode available if you prefer instant results.

= What happens if the AI service is down? =

SpamAnvil has built-in resilience: failed requests are retried up to 3 times with exponential backoff (1 min, 5 min, 15 min). You can also configure a fallback provider that kicks in automatically when the primary fails.

= Is my data safe? =

Comment content is sent to your chosen AI provider for analysis - this is the only external data transmission. API keys are encrypted with authenticated AES-256-GCM in the database (or can be defined in wp-config.php to never touch the database). IP addresses are stored as salted, keyed hashes (HMAC-SHA-256, not reversible without your site's secret) and displayed masked in the admin panel.

= Does SpamAnvil work with WooCommerce? =

Yes. SpamAnvil hooks into WordPress's standard comment system, which WooCommerce product reviews also use.

= Does it work with multilingual sites? =

Yes! AI language models understand comments in virtually any language. This is a major advantage over keyword-based spam filters that only work for English.

= Can spammers trick the AI with prompt injection? =

SpamAnvil uses 6 layers of prompt injection defense: (1) comment content is wrapped in `<comment_data>` boundary tags, (2) the system prompt explicitly instructs the AI to ignore instructions within comments, (3) heuristic patterns detect common injection phrases and raise the spam score, (4) responses are validated as strict JSON, (5) LLM temperature is set to 0 for deterministic behavior, (6) content is truncated at 5,000 characters.

= What is Open Mode? =

Open Mode is an optional toggle (Settings → General) that makes leaving a real comment effortless. It removes WordPress's usual friction - no required name/email (anonymous comments allowed), no login, no first-comment moderation hold - and publishes comments instantly, letting SpamAnvil quietly remove any spam in the background. It's safe to enable because the invisible defense layers (honeypot, time trap, rate limit, heuristics, AI) still filter spam automatically. It's applied via filters, so turning it off restores your original settings. Tip: pair it with Sync mode if you want zero delay on very low-traffic sites.

= Will the honeypot or time trap block real visitors? =

No. The honeypot is a hidden field only bots fill, and the time trap uses a signed timestamp and "fails open" - if anything is missing or looks off, it never flags the comment. Both are invisible to real visitors and, when in doubt, mark a comment as spam (recoverable from the Spam folder) rather than hard-blocking it.

= Can I browse a provider's models? =

Yes. On the Providers tab, click "Browse models" next to the model field for OpenAI, OpenRouter or Featherless to search that provider's live model list and pick one. OpenRouter models show a "free" badge and context size.

= Can I use a local/self-hosted AI model? =

Yes! If you run a local model with an OpenAI-compatible API (e.g., LM Studio, Ollama with a proxy, vLLM, Text Generation WebUI), you can connect it using the "Generic OpenAI-Compatible" provider option with your local URL.

= Who developed SpamAnvil? =

SpamAnvil was created by [Dr. Alexandre Amato](https://vascular.pro), a vascular surgeon and software developer based in Brazil. When he's not in the operating room, he builds open-source tools and medical education resources such as [Enciclopédia Médica](https://enciclopedia.med.br).

= What WordPress versions are supported? =

SpamAnvil requires WordPress 5.8+ and PHP 7.4+.

= How can I support SpamAnvil? =

SpamAnvil is 100% free and always will be. No premium tier, no "pro" upsells. If it's saving you time and money, you can [sponsor the project on GitHub](https://github.com/sponsors/alexandreamato). What's the next WordPress problem I'll solve and make free? I'm tired of expensive solutions for simple problems.

== Screenshots ==

1. General settings - Enable/disable, processing mode, spam threshold slider, queue status
2. Providers tab - Configure multiple AI providers with API keys and models
3. Prompt tab - Customize the AI prompts with prompt injection defense info
4. IP Management - View and manage blocked IPs with escalation levels
5. Statistics dashboard - Daily activity, spam caught, heuristic blocks, API usage
6. Evaluation logs - Full audit trail with scores, reasons, providers, and response times
7. Smart email notifications - No more one email per spam attempt: get notified only after the verdict, or a single daily digest

== Changelog ==

= 1.13.0 =
* New: Smart email notifications (enabled by default). WordPress normally emails you the instant a comment is held — before SpamAnvil has evaluated it, so every spam attempt used to generate a "please moderate" email. SpamAnvil now holds those notifications and emails only after the verdict: legitimate comments notify the post author once approved, spam is completely silent, and you are only asked to moderate when the classifier genuinely could not decide (max retries reached). Comments from users SpamAnvil skips (e.g. moderators) keep their normal notifications.
* New: Daily digest mode — replace per-comment emails entirely with one daily summary (spam blocked, comments approved, items awaiting moderation with a link). Sent only on days with activity.
* The mode is configurable under Settings → SpamAnvil → General → Email Notifications (Smart / Daily digest / Off).

= 1.12.0 =
* Security: Commenter metadata (author name, email, URL) is now sanitized and isolated inside a <commenter_data> boundary in the LLM prompt — previously an instruction-shaped author *name* could inject prompt text outside the <comment_data> isolation block. The prompt-injection heuristics now scan author fields too. Installs using the unmodified default prompts are migrated automatically; customized prompts are never touched.
* Fix: Permanent configuration errors (missing or undecryptable API key, no provider selected) no longer burn retry attempts, recycle hourly forever, or write one log row per comment per cycle. The queue now pauses on a configuration error and resumes automatically the moment the provider settings change. On one audited production site this loop had produced 1,000,000+ identical log rows (350 MB).
* Fix: Items stuck at max retries are re-tried when the provider configuration changes (or at most once a day after a transient outage) — not on the old unconditional hourly loop.
* Fix: The Anthropic provider now works with current Claude models (Sonnet 5 / Opus 5): the removed `temperature` parameter is no longer sent (it caused HTTP 400), adaptive thinking is disabled for classification calls, responses are parsed from the content array instead of assuming the first block is text, the `refusal` stop reason is handled, and `max_tokens` has proper headroom. New default model: claude-sonnet-5.
* Fix: IP block escalation is now capped at 30 days. Unbounded doubling produced effectively permanent multi-year bans (a false positive became a life sentence) and could overflow the database DATETIME at high levels.
* New: Model fallback chains — the Model field accepts several models separated by commas, tried in order until one answers (e.g. two free OpenRouter models, then a paid one). The model picker gained a "+" button to append to the chain, and the automatic free-model fallback no longer overwrites a hand-configured list.
* Improvement: Open Mode degrades safely — when classification is paused on a configuration error (or no provider is configured), comments are held for review again instead of being published unchecked. On the audited site, a three-week provider failure had left 5,000+ comments published without any classification.
* Improvement: The health notice now warns when the queue is paused (with the exact reason), and when comments are waiting but WP-Cron is not running (e.g. DISABLE_WP_CRON without a system cron).
* Improvement: Key-decryption errors now name the provider and distinguish a corrupted stored value from rotated security keys (AUTH_SALT).
* Improvement: Evaluation logs now record which model produced each error/verdict when trying a model chain.

= 1.11.3 =
* Fix: The "a saved API key can no longer be decrypted" notice could persist forever after re-entering your keys. It fired for stale keys left on providers you no longer use — which the Providers tab rendered as if no key were stored, with nothing to clear. The notice now only considers the providers actually selected (primary/fallbacks), the Providers tab flags an undecryptable stored key inline with a visible Clear Key button, and saving or clearing a key refreshes the health check immediately instead of after up to 5 minutes.
* Fix: A provider whose stored key no longer decrypts stayed listed in the Primary/Fallback dropdowns instead of disappearing — previously a save in that state could silently reset your provider selection.

= 1.11.2 =
* Security/Privacy: Blocked-IP hashes are now salted and keyed with your site secret (HMAC-SHA-256) instead of a plain SHA-256. A plain hash of an IP address can be reversed by brute force (the IP space is small), so this makes the stored hashes genuinely non-reversible without your site's key. Existing temporary blocks re-accrue naturally after the update.

= 1.11.1 =
* Developer: The review-request thresholds are now filterable. `spamanvil_review_min_checked` (default 50) and `spamanvil_review_min_age_seconds` (default 604800 = 7 days) let low-traffic sites ask sooner, or others ask later. No change to default behavior.

= 1.11.0 =
* Improvement: The "leave a review" request is now a gentle, dismissible admin notice that appears on any admin screen (previously it only showed on the SpamAnvil settings page, where most users never returned). It only appears after the plugin has proven itself — 50+ comments checked and at least 7 days installed — and offers "Leave a review", "Maybe later" (snoozes for two weeks), and "I already did / don't ask again". No nagging, fully dismissible, no incentives.

= 1.10.0 =
* Security: Configurable visitor-IP source. Previously the plugin trusted the left-most X-Forwarded-For header, which the client sends and can forge — letting a bot rotate a fake IP on every request to bypass IP blocking and rate limiting. The trusted header is now a setting under Settings → IP Management (Direct connection / Cloudflare CF-Connecting-IP / X-Real-IP / X-Forwarded-For right-most / Auto), defaulting to the un-spoofable REMOTE_ADDR. Sites behind a proxy or CDN should select the header their edge sets (Cloudflare → CF-Connecting-IP). The IP tab shows which proxy headers your current request arrived with, to guide the choice.
* Security: API keys are now encrypted with authenticated AES-256-GCM (AEAD) instead of unauthenticated AES-256-CBC. Existing keys keep working — the old format is still read — and re-saving a key upgrades it in place.
* Improvement: When a saved API key can no longer be decrypted (usually because the site's AUTH_SALT changed), an admin notice now says so explicitly and links to the Providers tab, instead of silently falling back to "no provider configured".

= 1.9.0 =
* Feature: Automatic free-model fallback. When the configured model becomes unavailable (free models — especially on OpenRouter — are deprecated or removed often), SpamAnvil automatically finds another free model from the provider, switches to it, and saves the change — so spam checking keeps running with no manual intervention. Only triggers on "model unavailable" errors (never on auth or rate-limit issues), and the switch is recorded in the Logs. Toggle under Settings → Providers.

= 1.8.0 =
* Feature: "Open Mode" — maximum openness for real comments. One toggle removes WordPress comment friction (no required name/email, no login, no first-comment moderation hold) so leaving a genuine, even anonymous, comment is effortless. Comments appear instantly and SpamAnvil removes spam in the background; the invisible anti-spam layers (honeypot, time trap, rate limit, heuristics, AI) do the filtering. Applied via filters, so it never overwrites your stored settings and turning it off restores them. Tip: pair with Sync mode for zero delay on low-traffic sites.

= 1.7.0 =
* Feature: Per-IP rate limiting. Rapid repeat comments from the same IP are throttled (HTTP 429) before the comment is even created — stopping floods with no database, queue or AI cost. Configurable under Settings → IP Management (default: 5 comments per 60 seconds). Logged-in moderators are exempt.

= 1.6.0 =
* Feature: Time trap — a second free, pre-AI defense layer. Comments submitted faster than a human could plausibly write them are marked as spam. The timestamp is signed (tamper-proof) and the check fails open, so it never flags a real commenter. Configurable threshold under Settings → General (default 3s). Note: inactive on sites with full-page caching.

= 1.5.0 =
* Feature: Honeypot spam trap. A hidden field is added to comment forms; automated bots that fill it are marked as spam instantly — before any heuristic or AI check, at zero cost. Enabled by default; toggle under Settings → General. Blocked bots are counted and appear in the Logs tab.

= 1.4.0 =
* Feature: Model picker on the Providers tab. Click "Browse models" next to OpenAI, OpenRouter or Featherless to search the provider's live model list and pick one — no need to remember model IDs. OpenRouter models show a "free" badge and context size, with free models listed first and a "free only" filter.

= 1.3.0 =
* Fix (major): Comment verdicts from reasoning/chatty AI models are now parsed correctly. The response reader strips `<think>...</think>` blocks, extracts the JSON verdict from surrounding prose, and falls back to reading the score directly — previously these models caused every comment to fail classification.
* Fix: "Test Connection" now runs a real spam-classification round-trip and validates the reply, so a provider that returns unparseable output fails the test instead of showing a false success.
* Fix: Provider failures (missing or undecryptable API key, bad config) are now written to the Logs tab. Previously the log stayed empty while comments silently failed.
* Fix: When a stored API key can't be decrypted (usually because the site's security keys changed after a migration), the plugin now says so and asks you to re-enter the key, instead of the misleading "no API key configured".
* Enhancement: An admin warning appears when no provider is configured or comments are piling up unclassified, so silent failures are visible.
* Enhancement: Larger response token budget so reasoning models have room to return the JSON verdict.

= 1.2.9 =
* Performance: Identical comment content now reuses a recent AI verdict instead of calling the LLM again — cuts API cost and latency during repeated spam floods (cache is on by default; keyed on comment content + author link, applied per current threshold).
* Reliability: The processing queue now claims each item atomically, so WP-Cron and a manual "Process Queue Now" running at the same time can no longer grab the same comment and pay for a duplicate AI call.

= 1.2.8 =
* Fix: The async queue now stores all timestamps in UTC. On sites set to a non-UTC timezone, comments whose LLM check failed once were retried hours late and the "stale processing" window could collapse, leaving obvious spam sitting unreviewed in the pending queue. Retry, backoff and stale-reclaim now fire on schedule regardless of the site's timezone.
* Security: API key encryption no longer falls back to a static built-in salt when AUTH_SALT is not defined — it uses a strong, per-site salt instead, so misconfigured installs don't share the same encryption key.
* Security: Comment text can no longer break out of the prompt's comment-data boundary to smuggle instructions to the AI (prompt-injection hardening).
* Hardening: The settings tab parameter is validated against a whitelist, and the "Settings saved" notice is no longer shown to users who lack permission to save.

= 1.2.7 =
* Feature: Cron now automatically scans pending WordPress comments when the queue is empty — no manual "Scan Pending" click needed
* Fix: Comments stuck in "Max Retries" are now automatically retried after 1 hour instead of requiring manual intervention
* Enhancement: Provider dropdowns only show providers with a configured API key — prevents selecting unconfigured providers
* Enhancement: DISABLE_WP_CRON warning only shown when cron is actually not running (no false alarm when a real server cron is configured)

= 1.2.2 =
* Feature: "Last automatic run" timestamp in Queue Status card shows when WP-Cron last processed the queue
* Enhancement: Warning displayed when cron hasn't run in over 10 minutes (helps diagnose stale queues)

= 1.2.1 =
* Fix: Queue now processes automatically — spawn_cron() called after each comment is enqueued so processing starts immediately instead of waiting for the next site visit
* Fix: Cron event is verified on every page load and re-scheduled if missing (prevents stale queues after plugin reinstall or cron table cleanup)

= 1.2.0 =
* Fix: "Process Queue Now" no longer blocked by concurrent WP-Cron lock — manual processing always works immediately
* Fix: Progress counter now correctly tracks completed items (previously showed 0 when items failed)
* Enhancement: Shows clear error message when all items in a batch fail ("check Logs tab for details")
* Enhancement: GitHub Sponsors links added throughout the plugin for those who want to support development
* Enhancement: New FAQ entry about supporting the project

= 1.1.9 =
* Fix: "Process Queue Now" and "Scan Pending Comments" now show an error with a link to configure a provider instead of attempting to process without one
* Feature: Portuguese (Brazilian) translation — pt_BR
* Enhancement: Updated POT file with all translatable strings

= 1.1.7 =
* Enhancement: Spam blocked counter updates in real-time while the queue is being processed
* Enhancement: API key spending limit tip on Providers settings tab

= 1.1.6 =
* Feature: Dashboard widget on the main WordPress admin page showing total spam blocked
* Enhancement: "Rate ★★★★★" link appears in the widget after 20+ spam blocked
* Enhancement: "Spam Comments Blocked" hero banner now shown on both General and Statistics tabs

= 1.1.4 =
* Fix: Cron now processes the entire queue per run (up to 50 seconds) instead of only 5 items — large backlogs clear in minutes, not hours
* Fix: Queue processing starts immediately after "Scan Pending Comments" (spawns cron) instead of waiting up to 5 minutes

= 1.1.3 =
* Fix: "Process Queue Now" button is now enabled immediately after "Scan Pending Comments" enqueues items (no page reload needed)
* Enhancement: Queue counter updates in real-time after scanning

= 1.1.2 =
* Feature: All-time "Spam Comments Blocked" hero banner on Statistics page with AI, heuristic, and IP blocking breakdown
* Enhancement: 30-day stats now clearly labeled under a "Last 30 Days" heading

= 1.1.1 =
* Feature: Data preservation on uninstall — settings, stats, logs, and blocked IPs are kept by default when reinstalling
* Enhancement: New "Delete Data on Uninstall" option in General settings for users who want a complete removal

= 1.1.0 =
* Feature: Welcome redirect after activation with setup guidance
* Feature: Review request notice after 50+ comments checked (dismissible, never nags)
* Feature: Unconfigured provider warning on plugin settings pages
* Feature: Contextual "Tips & Insights" card on Statistics page with actionable suggestions
* Enhancement: "Rate ★★★★★" and "Docs" action links on the Plugins page
* Enhancement: All marketing notices are dismissible and only shown on plugin pages

= 1.0.8 =
* Feature: Anvil Mode — send comments to ALL configured providers; if any flags it as spam, the comment is blocked
* Enhancement: Each provider's evaluation is logged individually in Anvil Mode for full transparency
* Enhancement: Highest score across all providers is used for the spam decision (strictest verdict wins)

= 1.0.7 =
* Fix: Fallback providers now actually triggered on API timeouts and errors (previously only used when API key was missing)
* Fix: HTTP timeout increased from 30s to 60s to support slower reasoning models (e.g. DeepSeek R1)
* Feature: Provider chain tries Primary → Fallback → Fallback 2 before giving up
* Feature: Second fallback provider option (3 providers in the chain)
* Enhancement: Each provider failure is individually logged before trying the next
* Enhancement: "Process Queue Now" also retries max_retries items (resets attempt counter for a fresh cycle)
* Enhancement: Evaluation log "Reason" column now wraps text instead of being truncated

= 1.0.6 =
* Feature: Clear API Key button to delete saved keys from the database
* Feature: Load Extended Spam Words list with 100+ curated terms (gambling, pharma, SEO, piracy, scams)
* Enhancement: Default OpenRouter model updated to openai/gpt-oss-20b:free
* Enhancement: "Process Queue Now" retries failed items immediately (ignores backoff timer)
* Enhancement: API failures are now logged in evaluation logs with error details
* Fix: phpcs warning for set_time_limit resolved

= 1.0.5 =
* Fix: "Process Queue Now" no longer times out - increased AJAX timeout to 3 minutes and extended PHP execution limit
* Enhancement: Completely rewritten system prompt with detailed spammer tactic guidelines (URL promotion, generic flattery, gambling/piracy names, template detection)
* Enhancement: Author URL now a strong spam signal - combo detection with generic praise for near-certain spam identification
* Enhancement: Brand-name author detection expanded with gambling/lottery, piracy/streaming, and per-word alphanumeric pattern checking
* Enhancement: 50+ generic spam template phrases detected (including long-form templates like "I have been surfing online")
* Enhancement: New {site_language}, {author_has_url}, {url_count} placeholders in user prompt
* Enhancement: System prompt now instructs LLM to never reveal its instructions (prompt leak defense)
* Enhancement: Language mismatch, name/email script mismatch heuristic signals added

= 1.0.1 =
* Fix: Test Connection now works without saving the page first - reads API key and model directly from form fields
* Fix: Improved error messages on Test Connection failures - shows actual API error details instead of just HTTP code
* Fix: Updated default OpenRouter model from deprecated llama-3.1-8b to llama-3.3-70b-instruct:free

= 1.0.0 =
* Initial release
* AI-powered spam detection with 6 provider options (OpenAI, Anthropic Claude, Google Gemini, OpenRouter, Featherless.ai, Generic)
* Intelligent heuristic pre-analysis engine with prompt injection detection
* Async (WP-Cron) and sync processing modes
* Smart IP blocking with escalating ban durations
* Automatic retry with exponential backoff
* AES-256-CBC encrypted API key storage with wp-config.php constant support
* Statistics dashboard with daily activity tracking
* Full evaluation logs with AI reasoning
* Customizable system and user prompts
* Multi-layered prompt injection defense
* GDPR/LGPD privacy-first design

== Upgrade Notice ==

= 1.10.0 =
Security update. If your site is behind a proxy or CDN (e.g. Cloudflare), open Settings → IP Management after updating and set "Visitor IP source" to the header your edge provides (Cloudflare → CF-Connecting-IP) — otherwise IP blocking and rate limiting will see your proxy's IP instead of the visitor's. Direct-hosted sites need no action.

= 1.0.0 =
First release of SpamAnvil. Install, configure an AI provider, and let AI handle your comment spam!
