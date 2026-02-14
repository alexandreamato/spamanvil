=== SpamAnvil ===
Contributors: aamato
Donate link: https://software.amato.com.br/spamanvil-antispam-plugin-for-wordpress/
Tags: anti-spam, spam, comments, ai, artificial-intelligence
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Stop comment spam with AI. Uses ChatGPT, Claude, Gemini and other LLMs to catch spam that traditional filters miss. 100% free.

== Description ==

**SpamAnvil is a free, open-source WordPress anti-spam plugin that uses artificial intelligence to block comment spam.** Unlike Akismet (which requires a paid plan for commercial sites) or simple keyword-based filters, SpamAnvil leverages large language models (LLMs) to actually *understand* your comments and detect even the most sophisticated spam.

Traditional spam filters rely on static word lists and link counting. Spammers have evolved. **SpamAnvil fights back with AI that understands context, intent, and language patterns** - catching spam that looks legitimate and approving real comments that others would flag.

= Why SpamAnvil? =

* **100% Free** - No premium tier, no subscription, no hidden costs. Bring your own API key (free options available).
* **Smarter Than Rules** - AI understands context. A comment about "buying a new home" won't be flagged just because it contains "buy".
* **Works With Free AI Models** - Use OpenRouter's free Llama models for $0 cost, or connect premium models for maximum accuracy.
* **Privacy-First** - Your data stays between you and your chosen AI provider. IP addresses are stored as irreversible SHA-256 hashes. GDPR/LGPD compliant by design.
* **No Cloud Lock-in** - Choose from 6+ AI providers. Switch anytime. Your anti-spam, your rules.

= Supported AI Providers =

* **OpenAI** (GPT-4o-mini, GPT-4o, etc.)
* **Anthropic Claude** (Claude Sonnet, Haiku, etc.)
* **Google Gemini** (Gemini 2.0 Flash, Pro, etc.)
* **OpenRouter** (100+ models, including FREE ones)
* **[Featherless.ai](https://featherless.ai/)** (Open-source models)
* **Any OpenAI-compatible API** (LM Studio, Ollama via proxy, vLLM, etc.)

= Key Features =

* **AI-Powered Spam Detection** - Each comment is analyzed by an LLM that scores it 0-100 for spam probability
* **Intelligent Heuristics Engine** - Pre-analyzes comments with regex patterns, spam word detection, URL counting, and prompt injection detection to catch obvious spam without API calls
* **Async Background Processing** - Comments are queued and processed via WP-Cron so your site stays fast
* **Smart IP Blocking** - Automatically blocks repeat offenders with escalating ban durations (24h, 48h, 96h...)
* **Automatic Retry with Backoff** - Failed API calls retry up to 3 times with exponential delays
* **Encrypted API Key Storage** - AES-256-CBC encryption for all stored API keys. Optional wp-config.php constants for maximum security
* **Statistics Dashboard** - Track how many comments were checked, how much spam was caught, API usage and errors
* **Full Evaluation Logs** - See the AI's reasoning for every comment scored, with provider, model, response time, and score
* **Customizable AI Prompts** - Full control over what the AI is instructed to do
* **Fallback Provider** - Configure a backup AI so spam checking never stops
* **Prompt Injection Defense** - Multi-layered protection prevents attackers from manipulating the AI through crafted comments
* **Configurable Spam Threshold** - Slide between aggressive (catch more spam) and permissive (fewer false positives)
* **Moderator Bypass** - Trusted users skip spam checking entirely

= How It Works =

1. A visitor submits a comment
2. SpamAnvil checks if the IP is blocked from previous spam attempts
3. The heuristic engine runs a quick pre-analysis (URL count, spam words, suspicious patterns)
4. If the heuristic score is very high, the comment is instantly marked as spam - no API call needed
5. Otherwise, the comment is queued for AI analysis (or processed immediately in sync mode)
6. The AI analyzes the comment in context (post title, author info, heuristic data) and returns a spam score
7. Comments scoring above your threshold are marked as spam; clean comments are auto-approved
8. Repeat offender IPs are automatically blocked with escalating durations

= Use Cases =

* **Blogs** receiving hundreds of spam comments per day
* **WooCommerce stores** where comment spam affects SEO and credibility
* **Membership sites** that need to protect community discussions
* **Multilingual sites** - AI understands comments in any language, unlike keyword-based filters
* **High-traffic sites** - Async processing handles any volume without slowing down your site
* **Sites tired of Akismet** - Free alternative with no cloud dependency and full data control

= Security =

SpamAnvil follows WordPress security best practices throughout:

* AES-256-CBC encrypted API key storage
* wp-config.php constant support for API keys (never touch the database)
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

Comment content is sent to your chosen AI provider for analysis - this is the only external data transmission. API keys are encrypted with AES-256-CBC in the database (or can be defined in wp-config.php to never touch the database). IP addresses are stored as SHA-256 hashes and displayed masked in the admin panel.

= Does SpamAnvil work with WooCommerce? =

Yes. SpamAnvil hooks into WordPress's standard comment system, which WooCommerce product reviews also use.

= Does it work with multilingual sites? =

Yes! AI language models understand comments in virtually any language. This is a major advantage over keyword-based spam filters that only work for English.

= Can spammers trick the AI with prompt injection? =

SpamAnvil uses 6 layers of prompt injection defense: (1) comment content is wrapped in `<comment_data>` boundary tags, (2) the system prompt explicitly instructs the AI to ignore instructions within comments, (3) heuristic patterns detect common injection phrases and raise the spam score, (4) responses are validated as strict JSON, (5) LLM temperature is set to 0 for deterministic behavior, (6) content is truncated at 5,000 characters.

= Can I use a local/self-hosted AI model? =

Yes! If you run a local model with an OpenAI-compatible API (e.g., LM Studio, Ollama with a proxy, vLLM, Text Generation WebUI), you can connect it using the "Generic OpenAI-Compatible" provider option with your local URL.

= What WordPress versions are supported? =

SpamAnvil requires WordPress 5.8+ and PHP 7.4+.

== Screenshots ==

1. General settings - Enable/disable, processing mode, spam threshold slider, queue status
2. Providers tab - Configure multiple AI providers with API keys and models
3. Prompt tab - Customize the AI prompts with prompt injection defense info
4. IP Management - View and manage blocked IPs with escalation levels
5. Statistics dashboard - Daily activity, spam caught, heuristic blocks, API usage
6. Evaluation logs - Full audit trail with scores, reasons, providers, and response times

== Changelog ==

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

= 1.0.0 =
First release of SpamAnvil. Install, configure an AI provider, and let AI handle your comment spam!
