<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

settings_errors( 'spamanvil' );

$system_prompt = get_option( 'spamanvil_system_prompt', SpamAnvil_Activator::get_default_system_prompt() );
$user_prompt   = get_option( 'spamanvil_user_prompt', SpamAnvil_Activator::get_default_user_prompt() );
$spam_words    = get_option( 'spamanvil_spam_words', '' );
?>

<form method="post" action="">
	<?php wp_nonce_field( 'spamanvil_prompt' ); ?>
	<input type="hidden" name="spamanvil_tab" value="prompt">
	<input type="hidden" name="spamanvil_save_settings" value="1">

	<div class="spamanvil-card">
		<h3><?php esc_html_e( 'Prompt Injection Defense', 'spamanvil' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'SpamAnvil protects against prompt injection by:', 'spamanvil' ); ?>
		</p>
		<ul class="spamanvil-list">
			<li><?php esc_html_e( 'Wrapping comment content in <comment_data> boundary tags', 'spamanvil' ); ?></li>
			<li><?php esc_html_e( 'System prompt explicitly instructs the LLM to ignore instructions within comments', 'spamanvil' ); ?></li>
			<li><?php esc_html_e( 'Strict JSON response validation - only {"score", "reason"} accepted', 'spamanvil' ); ?></li>
			<li><?php esc_html_e( 'Content is truncated at 5,000 characters to prevent oversized payloads', 'spamanvil' ); ?></li>
			<li><?php esc_html_e( 'Temperature set to 0 for deterministic, consistent responses', 'spamanvil' ); ?></li>
			<li><?php esc_html_e( 'Heuristic engine detects common injection patterns (e.g., "ignore previous instructions") and raises spam score', 'spamanvil' ); ?></li>
		</ul>
	</div>

	<table class="form-table">
		<tr>
			<th scope="row">
				<?php esc_html_e( 'System Prompt', 'spamanvil' ); ?>
			</th>
			<td>
				<textarea name="spamanvil_system_prompt"
						  rows="12" class="large-text code"><?php echo esc_textarea( $system_prompt ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'The system prompt tells the LLM how to behave. The CRITICAL SECURITY INSTRUCTION section is essential for prompt injection defense - do not remove it.', 'spamanvil' ); ?>
				</p>
				<button type="button" class="button spamanvil-reset-prompt" data-target="spamanvil_system_prompt" data-default="system">
					<?php esc_html_e( 'Reset to Default', 'spamanvil' ); ?>
				</button>
			</td>
		</tr>

		<tr>
			<th scope="row">
				<?php esc_html_e( 'User Prompt', 'spamanvil' ); ?>
			</th>
			<td>
				<textarea name="spamanvil_user_prompt"
						  rows="14" class="large-text code"><?php echo esc_textarea( $user_prompt ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'Available placeholders:', 'spamanvil' ); ?>
					<code>{post_title}</code>,
					<code>{post_excerpt}</code>,
					<code>{author_name}</code>,
					<code>{author_email}</code>,
					<code>{author_url}</code>,
					<code>{heuristic_data}</code>,
					<code>{heuristic_score}</code>,
					<code>{comment_content}</code>
				</p>
				<button type="button" class="button spamanvil-reset-prompt" data-target="spamanvil_user_prompt" data-default="user">
					<?php esc_html_e( 'Reset to Default', 'spamanvil' ); ?>
				</button>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Spam Words', 'spamanvil' ); ?></th>
			<td>
				<textarea name="spamanvil_spam_words"
						  rows="10" class="large-text"><?php echo esc_textarea( $spam_words ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'One word or phrase per line. Used by the heuristic pre-analysis engine.', 'spamanvil' ); ?>
				</p>
			</td>
		</tr>
	</table>

	<?php submit_button(); ?>
</form>
