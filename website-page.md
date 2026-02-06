# SpamAnvil – AI Anti-Spam Plugin for WordPress

**Stop comment spam with AI. SpamAnvil uses ChatGPT, Claude, Gemini and other LLMs to detect spam that traditional filters miss. 100% free, no subscription.**

[Download v1.0.3 from GitHub](https://github.com/alexandreamato/spamanvil/releases/tag/v1.0.3)

## Overview

SpamAnvil is a free, open-source WordPress anti-spam plugin that uses artificial intelligence to block comment spam. Unlike Akismet (which requires a paid plan for commercial sites) or simple keyword-based filters, SpamAnvil leverages large language models (LLMs) to actually *understand* your comments and detect even the most sophisticated spam.

Traditional spam filters rely on static word lists and link counting. Spammers have evolved. **SpamAnvil fights back with AI that understands context, intent, and language patterns** — catching spam that looks legitimate and approving real comments that others would flag.

## Why SpamAnvil?

- **100% Free** — No premium tier, no subscription, no hidden costs. Bring your own API key (free options available).
- **Smarter Than Rules** — AI understands context. A comment about "buying a new home" won't be flagged just because it contains "buy".
- **Works With Free AI Models** — Use OpenRouter's free Llama models for $0 cost, or connect premium models for maximum accuracy.
- **Privacy-First** — Your data stays between you and your chosen AI provider. IP addresses are stored as irreversible SHA-256 hashes. GDPR/LGPD compliant by design.
- **No Cloud Lock-in** — Choose from 6+ AI providers. Switch anytime. Your anti-spam, your rules.

## Supported AI Providers

- **OpenAI** (GPT-4o-mini, GPT-4o, etc.)
- **Anthropic Claude** (Claude Sonnet, Haiku, etc.)
- **Google Gemini** (Gemini 2.0 Flash, Pro, etc.)
- **OpenRouter** (100+ models, including FREE ones)
- **Featherless.ai** (Open-source models)
- **Any OpenAI-compatible API** (LM Studio, Ollama via proxy, vLLM, etc.)

## Key Features

- **AI-Powered Spam Detection** — Each comment is analyzed by an LLM that scores it 0-100 for spam probability
- **Intelligent Heuristics Engine** — Pre-analyzes comments with regex patterns, spam word detection, URL counting, and prompt injection detection to catch obvious spam without API calls
- **Scan Pending Comments** — Instantly analyze all comments already sitting in your moderation queue. Perfect for new installations on sites with existing pending comments
- **Async Background Processing** — Comments are queued and processed via WP-Cron so your site stays fast
- **Smart IP Blocking** — Automatically blocks repeat offenders with escalating ban durations (24h, 48h, 96h...)
- **Automatic Retry with Backoff** — Failed API calls retry up to 3 times with exponential delays
- **Encrypted API Key Storage** — AES-256-CBC encryption for all stored API keys. Optional wp-config.php constants for maximum security
- **Statistics Dashboard** — Track how many comments were checked, how much spam was caught, API usage and errors
- **Full Evaluation Logs** — See the AI's reasoning for every comment scored, with provider, model, response time, and score
- **Customizable AI Prompts** — Full control over what the AI is instructed to do
- **Fallback Provider** — Configure a backup AI so spam checking never stops
- **Prompt Injection Defense** — Multi-layered protection prevents attackers from manipulating the AI through crafted comments
- **Configurable Spam Threshold** — Slide between aggressive (catch more spam) and permissive (fewer false positives)
- **Moderator Bypass** — Trusted users skip spam checking entirely

## How It Works

1. A visitor submits a comment
2. SpamAnvil checks if the IP is blocked from previous spam attempts
3. The heuristic engine runs a quick pre-analysis (URL count, spam words, suspicious patterns)
4. If the heuristic score is very high, the comment is instantly marked as spam — no API call needed
5. Otherwise, the comment is queued for AI analysis (or processed immediately in sync mode)
6. The AI analyzes the comment in context (post title, author info, heuristic data) and returns a spam score
7. Comments scoring above your threshold are marked as spam; clean comments are auto-approved
8. Repeat offender IPs are automatically blocked with escalating durations

## Installation

### Automatic Installation

1. Go to **Plugins > Add New** in your WordPress admin
2. Search for **SpamAnvil**
3. Click **Install Now** and then **Activate**
4. Go to **Settings > SpamAnvil**
5. Choose an AI provider and enter your API key
6. Done! Comments will now be analyzed for spam.

### Manual Installation

1. [Download the latest release](https://github.com/alexandreamato/spamanvil/releases/tag/v1.0.3)
2. Go to **Plugins > Add New > Upload Plugin**
3. Upload the zip file and click **Install Now**
4. Activate the plugin
5. Configure your AI provider in **Settings > SpamAnvil**

### Getting a Free API Key

Want to use SpamAnvil for completely free? Here's how:

1. Go to [OpenRouter.ai](https://openrouter.ai/) and create a free account
2. Generate an API key
3. In SpamAnvil settings, select **OpenRouter** as your primary provider
4. Paste your API key
5. The default model (meta-llama/llama-3.3-70b-instruct:free) is free to use!

## Which AI Provider Should I Use?

- **For free usage:** OpenRouter with the free Llama 3.3 70B model works surprisingly well for spam detection.
- **For best accuracy:** OpenAI GPT-4o-mini offers the best quality-to-price ratio.
- **For privacy:** Google Gemini or a self-hosted model via the Generic OpenAI-compatible option.
- **For maximum reliability:** Configure a primary + fallback provider so spam checking never stops.

## Security

SpamAnvil follows WordPress security best practices throughout:

- AES-256-CBC encrypted API key storage
- wp-config.php constant support for API keys (never touch the database)
- Nonce verification on all forms and AJAX requests
- Capability checks on all admin actions
- Prepared SQL statements on every database query
- Output escaping on all rendered content
- Prompt injection defense: boundary tags, system prompt hardening, heuristic injection detection, strict JSON validation, temperature 0

## Frequently Asked Questions

**Is SpamAnvil really free?**
Yes, 100% free and open source (GPLv2). There is no premium version. You only need an API key from an AI provider, and free options are available (e.g., OpenRouter with free Llama models).

**How is SpamAnvil different from Akismet?**
Akismet uses a centralized cloud service owned by Automattic. It requires a paid subscription for commercial sites, and all your comments are sent to Akismet's servers. SpamAnvil lets you choose your own AI provider, works with free models, keeps you in control of your data, and uses true AI understanding instead of statistical pattern matching.

**How is SpamAnvil different from Antispam Bee?**
Antispam Bee uses traditional techniques like honeypot fields, country blocking, and regex rules. These work for basic spam but miss sophisticated attacks. SpamAnvil adds AI analysis that actually reads and understands comments in context, catching spam that looks legitimate to keyword-based systems.

**Does this slow down my website?**
No. In the default async mode, comments are held as "pending" and processed in the background via WP-Cron every 5 minutes. Your visitors experience zero added latency.

**What happens if the AI service is down?**
SpamAnvil has built-in resilience: failed requests are retried up to 3 times with exponential backoff (1 min, 5 min, 15 min). You can also configure a fallback provider that kicks in automatically when the primary fails.

**Does it work with multilingual sites?**
Yes! AI language models understand comments in virtually any language. This is a major advantage over keyword-based spam filters that only work for English.

**Can spammers trick the AI with prompt injection?**
SpamAnvil uses 6 layers of prompt injection defense: (1) comment content is wrapped in boundary tags, (2) the system prompt explicitly instructs the AI to ignore instructions within comments, (3) heuristic patterns detect common injection phrases and raise the spam score, (4) responses are validated as strict JSON, (5) LLM temperature is set to 0 for deterministic behavior, (6) content is truncated at 5,000 characters.

**Can I use a local/self-hosted AI model?**
Yes! If you run a local model with an OpenAI-compatible API (e.g., LM Studio, Ollama with a proxy, vLLM), you can connect it using the "Generic OpenAI-Compatible" provider option with your local URL.

## Changelog

### 1.0.3
- New: Scan Pending Comments — analyze all comments already in the moderation queue with one click. Runs heuristics, auto-blocks obvious spam, and enqueues the rest for LLM analysis. Ideal for new installations on sites with existing pending comments.

### 1.0.1
- Fix: Test Connection now works without saving the page first — reads API key and model directly from form fields
- Fix: Improved error messages on Test Connection failures
- Fix: Updated default OpenRouter model from deprecated llama-3.1-8b to llama-3.3-70b-instruct:free

### 1.0.0
- Initial release

## Links

- [GitHub Repository](https://github.com/alexandreamato/spamanvil)
- [Download Latest Release (v1.0.3)](https://github.com/alexandreamato/spamanvil/releases/tag/v1.0.3)
- [Report Issues](https://github.com/alexandreamato/spamanvil/issues)
