<?php
/**
 * First-run wizard.
 *
 * Rendered by SpamAnvil_Admin::render_setup_wizard() at
 * options-general.php?page=spamanvil&tab=setup.
 *
 * @var bool   $configured   Whether a primary provider is already set.
 * @var string $settings_url Link back to the full settings screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap spamanvil-wrap spamanvil-setup">

	<div class="spamanvil-setup-head">
		<h1><?php esc_html_e( 'Set up SpamAnvil', 'spamanvil' ); ?></h1>
		<p class="spamanvil-setup-lead">
			<?php esc_html_e( 'One API key is all SpamAnvil needs. It takes about a minute, and the free option costs nothing.', 'spamanvil' ); ?>
		</p>
	</div>

	<?php if ( $configured ) : ?>
		<div class="notice notice-success inline spamanvil-setup-configured">
			<p>
				<strong><?php esc_html_e( 'SpamAnvil is already configured.', 'spamanvil' ); ?></strong>
				<?php esc_html_e( 'You can run through this again to replace the key, or go straight to the settings.', 'spamanvil' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $settings_url ); ?>" class="button"><?php esc_html_e( 'Go to settings', 'spamanvil' ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<ol class="spamanvil-steps" id="spamanvil-setup-steps">

		<li class="spamanvil-step">
			<span class="spamanvil-step-num">1</span>
			<div class="spamanvil-step-body">
				<h2><?php esc_html_e( 'Get a free API key', 'spamanvil' ); ?></h2>
				<p>
					<?php esc_html_e( 'SpamAnvil asks an AI model to judge each comment, so it needs access to one. OpenRouter offers free models: create an account, click "Create key", and copy it. Free usage is rate-limited, which is plenty for a normal blog — a busy site can add credit later.', 'spamanvil' ); ?>
				</p>
				<a class="button button-secondary" href="https://openrouter.ai/keys" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Open openrouter.ai/keys', 'spamanvil' ); ?>
					<span class="dashicons dashicons-external" aria-hidden="true"></span>
				</a>
			</div>
		</li>

		<li class="spamanvil-step">
			<span class="spamanvil-step-num">2</span>
			<div class="spamanvil-step-body">
				<h2><?php esc_html_e( 'Paste it here', 'spamanvil' ); ?></h2>
				<label class="screen-reader-text" for="spamanvil-setup-key"><?php esc_html_e( 'OpenRouter API key', 'spamanvil' ); ?></label>
				<input
					type="password"
					id="spamanvil-setup-key"
					class="regular-text spamanvil-setup-key"
					autocomplete="off"
					spellcheck="false"
					placeholder="<?php esc_attr_e( 'sk-or-v1-…', 'spamanvil' ); ?>"
				/>
				<p class="description">
					<?php esc_html_e( 'The key is encrypted before it is stored, and it never leaves your site except to call the provider.', 'spamanvil' ); ?>
				</p>
			</div>
		</li>

		<li class="spamanvil-step">
			<span class="spamanvil-step-num">3</span>
			<div class="spamanvil-step-body">
				<h2><?php esc_html_e( 'Test and finish', 'spamanvil' ); ?></h2>
				<p><?php esc_html_e( 'SpamAnvil classifies one sample comment to prove the key works. Nothing is saved unless it does.', 'spamanvil' ); ?></p>
				<p class="spamanvil-setup-actions">
					<button type="button" class="button button-primary button-hero" id="spamanvil-setup-finish">
						<?php esc_html_e( 'Test and finish', 'spamanvil' ); ?>
					</button>
					<span class="spinner spamanvil-setup-spinner"></span>
				</p>
				<div class="spamanvil-setup-result" id="spamanvil-setup-result" role="status" aria-live="polite"></div>
			</div>
		</li>

	</ol>

	<div class="spamanvil-setup-done" id="spamanvil-setup-done" hidden>
		<h2><?php esc_html_e( 'SpamAnvil is protecting your comments', 'spamanvil' ); ?></h2>
		<p><?php esc_html_e( 'Every new comment now goes through these layers, in this order:', 'spamanvil' ); ?></p>
		<ul class="spamanvil-setup-layers">
			<li><?php esc_html_e( 'Honeypot and time trap — catch bots for free, before any API call', 'spamanvil' ); ?></li>
			<li><?php esc_html_e( 'Per-IP rate limiting and repeat-offender blocking', 'spamanvil' ); ?></li>
			<li><?php esc_html_e( 'Heuristics — links, spam wording, prompt-injection attempts', 'spamanvil' ); ?></li>
			<li><?php esc_html_e( 'The AI verdict, for everything that survives the cheap checks', 'spamanvil' ); ?></li>
		</ul>
		<p>
			<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary"><?php esc_html_e( 'Go to settings', 'spamanvil' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=spamanvil&tab=logs' ) ); ?>" class="button"><?php esc_html_e( 'See the evaluation log', 'spamanvil' ); ?></a>
		</p>
	</div>

	<p class="spamanvil-setup-alt">
		<?php
		printf(
			/* translators: %s: link to the Providers tab */
			esc_html__( 'Prefer OpenAI, Anthropic Claude, Gemini, or your own endpoint? %s', 'spamanvil' ),
			'<a href="' . esc_url( admin_url( 'options-general.php?page=spamanvil&tab=providers' ) ) . '">' . esc_html__( 'Advanced setup', 'spamanvil' ) . '</a>'
		);
		?>
	</p>

</div>
