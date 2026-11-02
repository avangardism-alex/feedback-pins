<?php
/**
 * Settings page: Tools > Pinora.
 *
 * Invitation links, key, Slack webhook and the optional credit on the board.
 *
 * @package Pinora
 */

defined( 'ABSPATH' ) || exit;

/** Admin page slug. */
const PINORA_PAGE = 'pinora';

/**
 * Adds the page under Tools.
 */
function pinora_admin_menu() {
	add_management_page(
		__( 'Pinora', 'pinora' ),
		__( 'Pinora', 'pinora' ),
		pinora_capability(),
		PINORA_PAGE,
		'pinora_admin_page'
	);
}
add_action( 'admin_menu', 'pinora_admin_menu' );

/**
 * URL of the settings page, with optional query arguments.
 *
 * @param array $args Query arguments.
 * @return string
 */
function pinora_admin_url( $args = array() ) {
	return add_query_arg( $args, admin_url( 'tools.php?page=' . PINORA_PAGE ) );
}

/**
 * "Settings" link in the plugin list.
 *
 * @param array $links Action links.
 * @return array
 */
function pinora_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( pinora_admin_url() ) . '">' . esc_html__( 'Settings', 'pinora' ) . '</a>' );

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( PINORA_FILE ), 'pinora_action_links' );

/**
 * Extra links under the plugin description.
 *
 * @param array  $links Row meta links.
 * @param string $file  Plugin file.
 * @return array
 */
function pinora_row_meta( $links, $file ) {
	if ( plugin_basename( PINORA_FILE ) !== $file ) {
		return $links;
	}

	$links[] = '<a href="https://github.com/avangardism-alex/feedback-pins" target="_blank" rel="noopener">' . esc_html__( 'Source code', 'pinora' ) . '</a>';
	$links[] = '<a href="' . esc_url( pinora_avangardism_url( 'plugin-row', '/contact' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Need a hand with your site?', 'pinora' ) . '</a>';

	return $links;
}
add_filter( 'plugin_row_meta', 'pinora_row_meta', 10, 2 );

/**
 * Stops a form sent to `admin-post.php` by someone without the capability.
 * Each handler then checks its own nonce.
 */
function pinora_admin_check_capability() {
	if ( ! current_user_can( pinora_capability() ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'pinora' ), 403 );
	}
}

/**
 * Generates a new key on request.
 */
function pinora_admin_regenerate() {
	pinora_admin_check_capability();
	check_admin_referer( 'pinora_regenerate' );
	update_option( 'pinora_key', wp_generate_password( 20, false, false ), false );

	wp_safe_redirect( pinora_admin_url( array( 'pinora_msg' => 'regenerated' ) ) );
	exit;
}
add_action( 'admin_post_pinora_regenerate', 'pinora_admin_regenerate' );

/**
 * Saves the Slack webhook, and sends a test message when asked.
 */
function pinora_admin_slack() {
	pinora_admin_check_capability();
	check_admin_referer( 'pinora_slack' );

	$webhook = isset( $_POST['webhook'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['webhook'] ) ) ) : '';
	$args    = array( 'pinora_msg' => 'slack_saved' );

	if ( '' !== $webhook && ! pinora_slack_is_valid( $webhook ) ) {
		$args['pinora_msg'] = 'slack_invalid';
	} else {
		update_option( 'pinora_slack_webhook', $webhook, false );

		if ( '' !== $webhook && isset( $_POST['test'] ) ) {
			$test = pinora_slack_send(
				array(
					'id'           => 0,
					'description'  => __( 'Test message: new feedback notes will show up here.', 'pinora' ),
					'author'       => wp_get_current_user()->display_name,
					'url'          => (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ),
					'severity'     => 'nice',
					'sectionLabel' => __( 'Test', 'pinora' ),
				)
			);

			if ( is_wp_error( $test ) ) {
				$args = array(
					'pinora_msg'    => 'slack_failed',
					'pinora_detail' => rawurlencode( $test->get_error_message() ),
				);
			} else {
				$args['pinora_msg'] = 'slack_tested';
			}
		}
	}

	wp_safe_redirect( pinora_admin_url( $args ) );
	exit;
}
add_action( 'admin_post_pinora_slack', 'pinora_admin_slack' );

/**
 * Saves the board credit preference.
 */
function pinora_admin_credit() {
	pinora_admin_check_capability();
	check_admin_referer( 'pinora_credit' );
	update_option( 'pinora_credit', empty( $_POST['credit'] ) ? 0 : 1, false );

	wp_safe_redirect( pinora_admin_url( array( 'pinora_msg' => 'saved' ) ) );
	exit;
}
add_action( 'admin_post_pinora_credit', 'pinora_admin_credit' );

/**
 * Styles and the copy buttons, on the settings page only.
 *
 * @param string $hook Current admin page.
 */
function pinora_admin_assets( $hook ) {
	if ( 'tools_page_' . PINORA_PAGE !== $hook ) {
		return;
	}

	$css = <<<'CSS'
.pinora-admin { max-width: 1180px; }
.pinora-admin-hero { display: flex; align-items: center; gap: 14px; margin: 18px 0 6px; }
.pinora-admin-logo { display: grid; place-items: center; width: 44px; height: 44px; border-radius: 12px; background: #714edd; color: #fff; }
.pinora-admin-logo .dashicons { width: 26px; height: 26px; font-size: 26px; }
.pinora-admin-hero h1 { margin: 0; padding: 0; font-size: 26px; }
.pinora-admin-hero p { margin: 2px 0 0; color: #50575e; }
.pinora-admin-grid { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 20px; align-items: start; margin-top: 16px; }
@media (max-width: 1000px) { .pinora-admin-grid { grid-template-columns: 1fr; } }
.pinora-admin .card { max-width: none; margin: 0 0 20px; padding: 18px 22px; }
.pinora-admin .card h2 { margin: 0 0 6px; font-size: 16px; }
.pinora-admin .card > p:first-of-type { margin-top: 0; }
.pinora-copy { display: flex; gap: 8px; margin: 6px 0 4px; }
.pinora-copy input { flex: 1; min-width: 0; font-family: Menlo, Consolas, monospace; font-size: 12px; }
.pinora-steps { margin: 8px 0 0 18px; }
.pinora-steps li { margin-bottom: 4px; }
.pinora-count { display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 999px; background: #eae2fe; color: #5b3cc4; font-size: 12px; font-weight: 600; vertical-align: middle; }
.pinora-studio { background: #1e0e4e !important; color: #fff; border: 0 !important; }
.pinora-studio h2, .pinora-studio p { color: #fff; }
.pinora-studio p { opacity: .85; }
.pinora-studio .button-primary { background: #feffb7; border-color: #feffb7; color: #1e0e4e; }
.pinora-studio .button-primary:hover, .pinora-studio .button-primary:focus { background: #fff; border-color: #fff; color: #1e0e4e; }
.pinora-studio a:not(.button) { color: #feffb7; }
.pinora-studio-logo { margin: 0 0 14px; }
.pinora-studio-logo img { display: block; width: 140px; height: auto; opacity: 1; }
.pinora-studio .pinora-studio-logo { opacity: 1; }
CSS;

	$js = <<<'JS'
document.addEventListener('click', function (e) {
	var ask = e.target.closest('[data-pinora-confirm]');
	if (ask && !window.confirm(ask.getAttribute('data-pinora-confirm'))) {
		e.preventDefault();
		return;
	}
	if (e.target.matches('.pinora-copy input')) e.target.select();
	var b = e.target.closest('[data-pinora-copy]');
	if (!b) return;
	var field = document.getElementById(b.getAttribute('data-pinora-copy'));
	var done = function () {
		var label = b.textContent;
		b.textContent = b.getAttribute('data-done');
		setTimeout(function () { b.textContent = label; }, 1500);
	};
	if (navigator.clipboard) navigator.clipboard.writeText(field.value).then(done, function () { field.select(); });
	else { field.select(); document.execCommand('copy'); done(); }
});
JS;

	wp_register_style( 'pinora-admin', false, array(), PINORA_VERSION );
	wp_enqueue_style( 'pinora-admin' );
	wp_add_inline_style( 'pinora-admin', $css );
	wp_register_script( 'pinora-admin', false, array(), PINORA_VERSION, array( 'in_footer' => true ) );
	wp_enqueue_script( 'pinora-admin' );
	wp_add_inline_script( 'pinora-admin', $js );
}
add_action( 'admin_enqueue_scripts', 'pinora_admin_assets' );

/**
 * Read-only field with a copy button.
 *
 * @param string $id    Field ID.
 * @param string $value Value.
 * @param string $label Accessible name of the field.
 */
function pinora_copy_field( $id, $value, $label ) {
	?>
	<div class="pinora-copy">
		<input type="text" id="<?php echo esc_attr( $id ); ?>" class="regular-text" readonly value="<?php echo esc_attr( $value ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
		<button type="button" class="button" data-pinora-copy="<?php echo esc_attr( $id ); ?>" data-done="<?php esc_attr_e( 'Copied!', 'pinora' ); ?>"><?php esc_html_e( 'Copy', 'pinora' ); ?></button>
	</div>
	<?php
}

/**
 * Notice after a form was sent.
 */
function pinora_admin_notice() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only, after a redirect.
	$code   = isset( $_GET['pinora_msg'] ) ? sanitize_key( $_GET['pinora_msg'] ) : '';
	$detail = isset( $_GET['pinora_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['pinora_detail'] ) ) : '';
	// phpcs:enable

	$messages = array(
		'regenerated'   => array( 'warning', __( 'New key generated. Links sent before no longer work: send the new link below.', 'pinora' ) ),
		'slack_saved'   => array( 'success', __( 'Slack setting saved.', 'pinora' ) ),
		'slack_tested'  => array( 'success', __( 'Test message sent: check your Slack channel.', 'pinora' ) ),
		'slack_invalid' => array( 'error', __( 'This is not a Slack webhook URL (it must start with https://hooks.slack.com/).', 'pinora' ) ),
		/* translators: %s: error returned by Slack. */
		'slack_failed'  => array( 'error', sprintf( __( 'Slack refused the message: %s', 'pinora' ), $detail ) ),
		'saved'         => array( 'success', __( 'Settings saved.', 'pinora' ) ),
	);

	if ( isset( $messages[ $code ] ) ) {
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $code ][0] ), esc_html( $messages[ $code ][1] ) );
	}
}

/**
 * Renders the settings page.
 */
function pinora_admin_page() {
	$count = pinora_count();
	?>
	<div class="wrap pinora-admin">
		<div class="pinora-admin-hero">
			<span class="pinora-admin-logo" aria-hidden="true"><span class="dashicons dashicons-location"></span></span>
			<div>
				<h1><?php esc_html_e( 'Pinora', 'pinora' ); ?></h1>
				<p><?php esc_html_e( 'Your clients pin their feedback right on the pages. Your team sees it all, live, on one board.', 'pinora' ); ?></p>
			</div>
		</div>

		<?php pinora_admin_notice(); ?>

		<div class="pinora-admin-grid">
			<div>
				<div class="card">
					<h2>
						<?php esc_html_e( 'Invite your reviewers', 'pinora' ); ?>
						<span class="pinora-count">
							<?php
							/* translators: %d: number of notes. */
							echo esc_html( sprintf( _n( '%d note', '%d notes', $count, 'pinora' ), $count ) );
							?>
						</span>
					</h2>
					<p><?php esc_html_e( 'Send this link to your client, your project manager, your team. Anyone who opens it can pin notes on any page and sees everyone else’s notes.', 'pinora' ); ?></p>
					<?php pinora_copy_field( 'pinora-invite', pinora_invite_url(), __( 'Invitation link', 'pinora' ) ); ?>
					<ol class="pinora-steps">
						<li><?php esc_html_e( 'The link turns on the review toolbar and stores the key in the browser. The key is then removed from the address bar.', 'pinora' ); ?></li>
						<li><?php esc_html_e( 'On first use, the reviewer types a first name to sign the notes.', 'pinora' ); ?></li>
						<li><?php esc_html_e( '“+ Note”, click the element, write, save: a screenshot is attached and a numbered pin stays on the element.', 'pinora' ); ?></li>
					</ol>
				</div>

				<div class="card">
					<h2><?php esc_html_e( 'Feedback board', 'pinora' ); ?></h2>
					<p><?php esc_html_e( 'Every note in three columns (To do, In progress, Done), with filters, drag and drop, answers and a Markdown or JSON export.', 'pinora' ); ?></p>
					<?php pinora_copy_field( 'pinora-board', pinora_board_invite_url(), __( 'Board link', 'pinora' ) ); ?>
					<p><a class="button" href="<?php echo esc_url( pinora_board_invite_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the board', 'pinora' ); ?></a></p>
				</div>

				<div class="card">
					<h2><?php esc_html_e( 'Review key', 'pinora' ); ?></h2>
					<p><?php esc_html_e( 'Nobody can read or write notes without this key. It is already included in the links above.', 'pinora' ); ?></p>
					<p><code><?php echo esc_html( pinora_key() ); ?></code></p>
					<?php if ( pinora_key_is_constant() ) : ?>
						<p class="description">
							<?php
							/* translators: 1: constant name, 2: file name. */
							printf( esc_html__( 'Set by the %1$s constant in %2$s.', 'pinora' ), '<code>PINORA_KEY</code>', '<code>wp-config.php</code>' );
							?>
						</p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="pinora_regenerate">
							<?php wp_nonce_field( 'pinora_regenerate' ); ?>
							<?php submit_button( __( 'Generate a new key', 'pinora' ), 'secondary', 'submit', false, array( 'data-pinora-confirm' => __( 'Links already sent will stop working. Continue?', 'pinora' ) ) ); ?>
						</form>
					<?php endif; ?>
				</div>

				<div class="card">
					<h2><?php esc_html_e( 'Slack notifications', 'pinora' ); ?></h2>
					<p>
						<?php esc_html_e( 'Every new note is posted to a channel, with its screenshot and a direct link to the spot on the page. The message is sent by your own server, straight to Slack: nothing goes through a third party.', 'pinora' ); ?>
						<a href="https://api.slack.com/messaging/webhooks" target="_blank" rel="noopener"><?php esc_html_e( 'How to get a webhook URL', 'pinora' ); ?></a>
					</p>
					<?php if ( pinora_slack_is_constant() ) : ?>
						<p class="description">
							<?php
							/* translators: 1: constant name, 2: file name. */
							printf( esc_html__( 'Set by the %1$s constant in %2$s.', 'pinora' ), '<code>PINORA_SLACK_WEBHOOK</code>', '<code>wp-config.php</code>' );
							?>
						</p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="pinora_slack">
							<?php wp_nonce_field( 'pinora_slack' ); ?>
							<label class="screen-reader-text" for="pinora-webhook"><?php esc_html_e( 'Slack webhook URL', 'pinora' ); ?></label>
							<input type="url" id="pinora-webhook" name="webhook" class="large-text code" placeholder="https://hooks.slack.com/services/…" value="<?php echo esc_attr( pinora_slack_webhook() ); ?>">
							<p class="description"><?php esc_html_e( 'Leave empty to turn notifications off.', 'pinora' ); ?></p>
							<p>
								<?php submit_button( __( 'Save', 'pinora' ), 'primary', 'save', false ); ?>
								<?php submit_button( __( 'Save and send a test', 'pinora' ), 'secondary', 'test', false ); ?>
							</p>
						</form>
					<?php endif; ?>
				</div>

				<div class="card">
					<h2><?php esc_html_e( 'Good to know', 'pinora' ); ?></h2>
					<ul class="pinora-steps" style="list-style:disc">
						<li><?php esc_html_e( 'Visitors who never opened a review link only download a few hundred bytes: the toolbar is loaded on demand.', 'pinora' ); ?></li>
						<li><?php esc_html_e( 'Screenshots are stored in uploads/pinora/ under random names, and deleted with their note.', 'pinora' ); ?></li>
						<li>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PINORA_CPT ) ); ?>"><?php esc_html_e( 'See the raw notes in the admin', 'pinora' ); ?></a>
						</li>
					</ul>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pinora_credit">
						<?php wp_nonce_field( 'pinora_credit' ); ?>
						<p>
							<label>
								<input type="checkbox" name="credit" value="1" <?php checked( (bool) get_option( 'pinora_credit' ) ); ?>>
								<?php esc_html_e( 'Show a small “made by AVANGARDISM” credit at the bottom of the board. Off by default, it helps us keep the plugin free.', 'pinora' ); ?>
							</label>
						</p>
						<?php submit_button( __( 'Save', 'pinora' ), 'secondary', 'save', false ); ?>
					</form>
				</div>
			</div>

			<aside>
				<div class="card pinora-studio">
					<p class="pinora-studio-logo"><a href="<?php echo esc_url( pinora_avangardism_url( 'admin-logo' ) ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( PINORA_URL . 'assets/images/avangardism-light.svg' ); ?>" width="140" height="32" alt="AVANGARDISM"></a></p>
					<h2><?php esc_html_e( 'Free, for good', 'pinora' ); ?></h2>
					<p><?php esc_html_e( 'Pinora is the review tool we use with our own clients. We share it for free: no account, no premium version, your data stays in your WordPress.', 'pinora' ); ?></p>
					<p><?php esc_html_e( 'AVANGARDISM is a web agency: websites, e-commerce, business software and UX/UI design, with a user-first approach.', 'pinora' ); ?></p>
					<p><a class="button button-primary" href="<?php echo esc_url( pinora_avangardism_url( 'admin-sidebar', '/contact' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Talk about your project', 'pinora' ); ?></a></p>
					<p><a href="<?php echo esc_url( pinora_avangardism_url( 'admin-sidebar' ) ); ?>" target="_blank" rel="noopener">avangardism.com</a></p>
				</div>
				<div class="card">
					<h2><?php esc_html_e( 'Help the project', 'pinora' ); ?></h2>
					<p><?php esc_html_e( 'A bug, an idea? The code is open.', 'pinora' ); ?></p>
					<p>
						<a href="https://github.com/avangardism-alex/feedback-pins/issues" target="_blank" rel="noopener"><?php esc_html_e( 'Report an issue', 'pinora' ); ?></a><br>
						<a href="https://github.com/avangardism-alex/feedback-pins" target="_blank" rel="noopener"><?php esc_html_e( 'Star it on GitHub', 'pinora' ); ?></a><br>
						<a href="https://wordpress.org/support/plugin/pinora/reviews/#new-post" target="_blank" rel="noopener"><?php esc_html_e( 'Leave a review on WordPress.org', 'pinora' ); ?></a>
					</p>
				</div>
			</aside>
		</div>
	</div>
	<?php
}
