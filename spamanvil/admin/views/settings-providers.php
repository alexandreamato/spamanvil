<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: Template variables in an included view file, scoped by the calling method.

settings_errors( 'spamanvil' );

$providers        = SpamAnvil_Provider_Factory::get_available_providers();
$primary          = get_option( 'spamanvil_primary_provider', '' );
$fallback         = get_option( 'spamanvil_fallback_provider', '' );

$default_models = array(
	'openai'      => 'gpt-4o-mini',
	'openrouter'  => 'deepseek/deepseek-r1-0528:free',
	'featherless' => 'meta-llama/Meta-Llama-3.1-8B-Instruct',
	'anthropic'   => 'claude-sonnet-4-5-20250929',
	'gemini'      => 'gemini-2.0-flash',
	'generic'     => '',
);

$signup_urls = array(
	'openai'      => 'https://platform.openai.com/signup',
	'openrouter'  => 'https://openrouter.ai/',
	'featherless' => 'https://featherless.ai/',
	'anthropic'   => 'https://console.anthropic.com/',
	'gemini'      => 'https://aistudio.google.com/apikey',
	'generic'     => '',
);
?>

<form method="post" action="">
	<?php wp_nonce_field( 'spamanvil_providers' ); ?>
	<input type="hidden" name="spamanvil_tab" value="providers">
	<input type="hidden" name="spamanvil_save_settings" value="1">

	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e( 'Primary Provider', 'spamanvil' ); ?></th>
			<td>
				<select name="spamanvil_primary_provider">
					<option value=""><?php esc_html_e( '-- Select --', 'spamanvil' ); ?></option>
					<?php foreach ( $providers as $slug => $name ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $primary, $slug ); ?>>
							<?php echo esc_html( $name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Fallback Provider', 'spamanvil' ); ?></th>
			<td>
				<select name="spamanvil_fallback_provider">
					<option value=""><?php esc_html_e( '-- None --', 'spamanvil' ); ?></option>
					<?php foreach ( $providers as $slug => $name ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $fallback, $slug ); ?>>
							<?php echo esc_html( $name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description">
					<?php esc_html_e( 'Used when the primary provider fails.', 'spamanvil' ); ?>
				</p>
			</td>
		</tr>
	</table>

	<hr>

	<?php foreach ( $providers as $slug => $name ) :
		$model_key     = 'spamanvil_' . $slug . '_model';
		$model_value   = get_option( $model_key, $default_models[ $slug ] ?? '' );
		$masked_key    = $this->get_masked_key( $slug );
		$has_constant  = $this->has_constant_key( $slug );
		$signup_url    = isset( $signup_urls[ $slug ] ) ? $signup_urls[ $slug ] : '';
	?>
		<div class="spamanvil-card">
			<h3>
				<?php echo esc_html( $name ); ?>
				<?php if ( ! empty( $signup_url ) ) : ?>
					<a href="<?php echo esc_url( $signup_url ); ?>" target="_blank" rel="noopener noreferrer" class="spamanvil-get-key-link">
						<?php esc_html_e( 'Get API Key', 'spamanvil' ); ?> &rarr;
					</a>
				<?php endif; ?>
			</h3>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'API Key', 'spamanvil' ); ?></th>
					<td>
						<?php if ( $has_constant ) : ?>
							<input type="text" value="<?php echo esc_attr( $masked_key ); ?>" disabled class="regular-text">
							<p class="description">
								<?php esc_html_e( 'Key set via wp-config.php constant.', 'spamanvil' ); ?>
							</p>
						<?php else : ?>
							<input type="password"
								   name="<?php echo esc_attr( 'spamanvil_' . $slug . '_api_key' ); ?>"
								   value=""
								   placeholder="<?php echo esc_attr( $masked_key ? $masked_key : __( 'Enter API key', 'spamanvil' ) ); ?>"
								   class="regular-text"
								   autocomplete="off">
							<?php if ( $masked_key ) : ?>
								<button type="button"
										class="button button-small spamanvil-clear-key-btn"
										data-provider="<?php echo esc_attr( $slug ); ?>"
										style="margin-left: 4px; color: #b32d2e;">
									<?php esc_html_e( 'Clear Key', 'spamanvil' ); ?>
								</button>
								<p class="description">
									<?php
									printf(
										/* translators: %s: masked API key */
										esc_html__( 'Current key: %s (leave blank to keep current key)', 'spamanvil' ),
										esc_html( $masked_key )
									);
									?>
								</p>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Model', 'spamanvil' ); ?></th>
					<td>
						<input type="text"
							   name="<?php echo esc_attr( $model_key ); ?>"
							   value="<?php echo esc_attr( $model_value ); ?>"
							   class="regular-text"
							   placeholder="<?php echo esc_attr( $default_models[ $slug ] ?? '' ); ?>">
					</td>
				</tr>

				<?php if ( 'generic' === $slug ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'API URL', 'spamanvil' ); ?></th>
						<td>
							<input type="url"
								   name="spamanvil_generic_api_url"
								   value="<?php echo esc_attr( get_option( 'spamanvil_generic_api_url', '' ) ); ?>"
								   class="regular-text"
								   placeholder="https://your-api.example.com/v1/chat/completions">
						</td>
					</tr>
				<?php endif; ?>

				<tr>
					<th scope="row"></th>
					<td>
						<button type="button"
								class="button spamanvil-test-btn"
								data-provider="<?php echo esc_attr( $slug ); ?>">
							<?php esc_html_e( 'Test Connection', 'spamanvil' ); ?>
						</button>
						<span class="spamanvil-test-result" data-provider="<?php echo esc_attr( $slug ); ?>"></span>
					</td>
				</tr>
			</table>
		</div>
	<?php endforeach; ?>

	<?php submit_button(); ?>
</form>
