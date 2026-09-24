<?php
/**
 * Settings page: Tools > Feedback Pins.
 *
 * Invitation links, key, Slack webhook and the optional credit on the board.
 *
 * @package FeedbackPins
 */

defined( 'ABSPATH' ) || exit;

/** Admin page slug. */
const FPINS_PAGE = 'feedback-pins';

/**
 * Adds the page under Tools.
 */
function fpins_admin_menu() {
	add_management_page(
		__( 'Feedback Pins', 'feedback-pins' ),
		__( 'Feedback Pins', 'feedback-pins' ),
		fpins_capability(),
		FPINS_PAGE,
		'fpins_admin_page'
	);
}
add_action( 'admin_menu', 'fpins_admin_menu' );

/**
 * URL of the settings page, with optional query arguments.
 *
 * @param array $args Query arguments.
 * @return string
 */
function fpins_admin_url( $args = array() ) {
	return add_query_arg( $args, admin_url( 'tools.php?page=' . FPINS_PAGE ) );
}

/**
 * "Settings" link in the plugin list.
 *
 * @param array $links Action links.
 * @return array
 */
function fpins_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( fpins_admin_url() ) . '">' . esc_html__( 'Settings', 'feedback-pins' ) . '</a>' );

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( FPINS_FILE ), 'fpins_action_links' );

/**
 * Extra links under the plugin description.
 *
 * @param array  $links Row meta links.
 * @param string $file  Plugin file.
 * @return array
 */
function fpins_row_meta( $links, $file ) {
	if ( plugin_basename( FPINS_FILE ) !== $file ) {
		return $links;
	}

	$links[] = '<a href="https://github.com/avangardism-alex/feedback-pins" target="_blank" rel="noopener">' . esc_html__( 'Source code', 'feedback-pins' ) . '</a>';
	$links[] = '<a href="' . esc_url( fpins_avangardism_url( 'plugin-row', '/contact' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Need a hand with your site?', 'feedback-pins' ) . '</a>';

	return $links;
}
add_filter( 'plugin_row_meta', 'fpins_row_meta', 10, 2 );

/**
 * Stops a form sent to `admin-post.php` by someone without the capability.
 * Each handler then checks its own nonce.
 */
function fpins_admin_check_capability() {
	if ( ! current_user_can( fpins_capability() ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'feedback-pins' ), 403 );
	}
}

/**
 * Generates a new key on request.
 */
function fpins_admin_regenerate() {
	fpins_admin_check_capability();
	check_admin_referer( 'fpins_regenerate' );
	update_option( 'fpins_key', wp_generate_password( 20, false, false ), false );

	wp_safe_redirect( fpins_admin_url( array( 'fpins_msg' => 'regenerated' ) ) );
	exit;
}
add_action( 'admin_post_fpins_regenerate', 'fpins_admin_regenerate' );

/**
 * Saves the Slack webhook, and sends a test message when asked.
 */
function fpins_admin_slack() {
	fpins_admin_check_capability();
	check_admin_referer( 'fpins_slack' );

	$webhook = isset( $_POST['webhook'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['webhook'] ) ) ) : '';
	$args    = array( 'fpins_msg' => 'slack_saved' );

	if ( '' !== $webhook && ! fpins_slack_is_valid( $webhook ) ) {
		$args['fpins_msg'] = 'slack_invalid';
	} else {
		update_option( 'fpins_slack_webhook', $webhook, false );

		if ( '' !== $webhook && isset( $_POST['test'] ) ) {
			$test = fpins_slack_send(
				array(
					'id'           => 0,
					'description'  => __( 'Test message: new feedback notes will show up here.', 'feedback-pins' ),
					'author'       => wp_get_current_user()->display_name,
					'url'          => (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ),
					'severity'     => 'nice',
					'sectionLabel' => __( 'Test', 'feedback-pins' ),
				)
			);

			if ( is_wp_error( $test ) ) {
				$args = array(
					'fpins_msg'    => 'slack_failed',
					'fpins_detail' => rawurlencode( $test->get_error_message() ),
				);
			} else {
				$args['fpins_msg'] = 'slack_tested';
			}
		}
	}

	wp_safe_redirect( fpins_admin_url( $args ) );
	exit;
}
add_action( 'admin_post_fpins_slack', 'fpins_admin_slack' );

/**
 * Saves the board credit preference.
 */
function fpins_admin_credit() {
	fpins_admin_check_capability();
	check_admin_referer( 'fpins_credit' );
	update_option( 'fpins_credit', empty( $_POST['credit'] ) ? 0 : 1, false );

	wp_safe_redirect( fpins_admin_url( array( 'fpins_msg' => 'saved' ) ) );
	exit;
}
add_action( 'admin_post_fpins_credit', 'fpins_admin_credit' );

/**
 * Styles and the copy buttons, on the settings page only.
 *
 * @param string $hook Current admin page.
 */
function fpins_admin_assets( $hook ) {
	if ( 'tools_page_' . FPINS_PAGE !== $hook ) {
		return;
	}

	$css = <<<'CSS'
.fpins-admin { max-width: 1180px; }
.fpins-admin-hero { display: flex; align-items: center; gap: 14px; margin: 18px 0 6px; }
.fpins-admin-logo { display: grid; place-items: center; width: 44px; height: 44px; border-radius: 12px; background: #714edd; color: #fff; }
.fpins-admin-logo .dashicons { width: 26px; height: 26px; font-size: 26px; }
.fpins-admin-hero h1 { margin: 0; padding: 0; font-size: 26px; }
.fpins-admin-hero p { margin: 2px 0 0; color: #50575e; }
.fpins-admin-grid { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 20px; align-items: start; margin-top: 16px; }
@media (max-width: 1000px) { .fpins-admin-grid { grid-template-columns: 1fr; } }
.fpins-admin .card { max-width: none; margin: 0 0 20px; padding: 18px 22px; }
.fpins-admin .card h2 { margin: 0 0 6px; font-size: 16px; }
.fpins-admin .card > p:first-of-type { margin-top: 0; }
.fpins-copy { display: flex; gap: 8px; margin: 6px 0 4px; }
.fpins-copy input { flex: 1; min-width: 0; font-family: Menlo, Consolas, monospace; font-size: 12px; }
.fpins-steps { margin: 8px 0 0 18px; }
.fpins-steps li { margin-bottom: 4px; }
.fpins-count { display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 999px; background: #eae2fe; color: #5b3cc4; font-size: 12px; font-weight: 600; vertical-align: middle; }
.fpins-studio { background: #1e0e4e !important; color: #fff; border: 0 !important; }
.fpins-studio h2, .fpins-studio p { color: #fff; }
.fpins-studio p { opacity: .85; }
.fpins-studio .button-primary { background: #feffb7; border-color: #feffb7; color: #1e0e4e; }
.fpins-studio .button-primary:hover, .fpins-studio .button-primary:focus { background: #fff; border-color: #fff; color: #1e0e4e; }
.fpins-studio a:not(.button) { color: #feffb7; }
.fpins-studio-logo { margin: 0 0 14px; }
.fpins-studio-logo img { display: block; width: 140px; height: auto; opacity: 1; }
.fpins-studio .fpins-studio-logo { opacity: 1; }
CSS;

	$js = <<<'JS'
document.addEventListener('click', function (e) {
	var ask = e.target.closest('[data-fpins-confirm]');
	if (ask && !window.confirm(ask.getAttribute('data-fpins-confirm'))) {
		e.preventDefault();
		return;
	}
	if (e.target.matches('.fpins-copy input')) e.target.select();
	var b = e.target.closest('[data-fpins-copy]');
	if (!b) return;
	var field = document.getElementById(b.getAttribute('data-fpins-copy'));
	var done = function () {
		var label = b.textContent;
		b.textContent = b.getAttribute('data-done');
		setTimeout(function () { b.textContent = label; }, 1500);
	};
	if (navigator.clipboard) navigator.clipboard.writeText(field.value).then(done, function () { field.select(); });
	else { field.select(); document.execCommand('copy'); done(); }
});
JS;

	wp_register_style( 'feedback-pins-admin', false, array(), FPINS_VERSION );
	wp_enqueue_style( 'feedback-pins-admin' );
	wp_add_inline_style( 'feedback-pins-admin', $css );
	wp_register_script( 'feedback-pins-admin', false, array(), FPINS_VERSION, array( 'in_footer' => true ) );
	wp_enqueue_script( 'feedback-pins-admin' );
	wp_add_inline_script( 'feedback-pins-admin', $js );
}
add_action( 'admin_enqueue_scripts', 'fpins_admin_assets' );

/**
 * Read-only field with a copy button.
 *
 * @param string $id    Field ID.
 * @param string $value Value.
 * @param string $label Accessible name of the field.
 */
function fpins_copy_field( $id, $value, $label ) {
	?>
	<div class="fpins-copy">
		<input type="text" id="<?php echo esc_attr( $id ); ?>" class="regular-text" readonly value="<?php echo esc_attr( $value ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
		<button type="button" class="button" data-fpins-copy="<?php echo esc_attr( $id ); ?>" data-done="<?php esc_attr_e( 'Copied!', 'feedback-pins' ); ?>"><?php esc_html_e( 'Copy', 'feedback-pins' ); ?></button>
	</div>
	<?php
}

/**
 * Notice after a form was sent.
 */
function fpins_admin_notice() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only, after a redirect.
	$code   = isset( $_GET['fpins_msg'] ) ? sanitize_key( $_GET['fpins_msg'] ) : '';
	$detail = isset( $_GET['fpins_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['fpins_detail'] ) ) : '';
	// phpcs:enable

	$messages = array(
		'regenerated'   => array( 'warning', __( 'New key generated. Links sent before no longer work: send the new link below.', 'feedback-pins' ) ),
		'slack_saved'   => array( 'success', __( 'Slack setting saved.', 'feedback-pins' ) ),
		'slack_tested'  => array( 'success', __( 'Test message sent: check your Slack channel.', 'feedback-pins' ) ),
		'slack_invalid' => array( 'error', __( 'This is not a Slack webhook URL (it must start with https://hooks.slack.com/).', 'feedback-pins' ) ),
		/* translators: %s: error returned by Slack. */
		'slack_failed'  => array( 'error', sprintf( __( 'Slack refused the message: %s', 'feedback-pins' ), $detail ) ),
		'saved'         => array( 'success', __( 'Settings saved.', 'feedback-pins' ) ),
	);

	if ( isset( $messages[ $code ] ) ) {
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $code ][0] ), esc_html( $messages[ $code ][1] ) );
	}
}

/**
 * Renders the settings page.
 */
function fpins_admin_page() {
	$count = fpins_count();
	?>
	<div class="wrap fpins-admin">
		<div class="fpins-admin-hero">
			<span class="fpins-admin-logo" aria-hidden="true"><span class="dashicons dashicons-location"></span></span>
			<div>
				<h1><?php esc_html_e( 'Feedback Pins', 'feedback-pins' ); ?></h1>
				<p><?php esc_html_e( 'Your clients pin their feedback right on the pages. Your team sees it all, live, on one board.', 'feedback-pins' ); ?></p>
			</div>
		</div>

		<?php fpins_admin_notice(); ?>

		<div class="fpins-admin-grid">
			<div>
				<div class="card">
					<h2>
						<?php esc_html_e( 'Invite your reviewers', 'feedback-pins' ); ?>
						<span class="fpins-count">
							<?php
							/* translators: %d: number of notes. */
							echo esc_html( sprintf( _n( '%d note', '%d notes', $count, 'feedback-pins' ), $count ) );
							?>
						</span>
					</h2>
					<p><?php esc_html_e( 'Send this link to your client, your project manager, your team. Anyone who opens it can pin notes on any page and sees everyone else’s notes.', 'feedback-pins' ); ?></p>
					<?php fpins_copy_field( 'fpins-invite', fpins_invite_url(), __( 'Invitation link', 'feedback-pins' ) ); ?>
					<ol class="fpins-steps">
						<li><?php esc_html_e( 'The link turns on the review toolbar and stores the key in the browser. The key is then removed from the address bar.', 'feedback-pins' ); ?></li>
						<li><?php esc_html_e( 'On first use, the reviewer types a first name to sign the notes.', 'feedback-pins' ); ?></li>
						<li><?php esc_html_e( '“+ Note”, click the element, write, save: a screenshot is attached and a numbered pin stays on the element.', 'feedback-pins' ); ?></li>
					</ol>
				</div>

				<div class="card">
					<h2><?php esc_html_e( 'Feedback board', 'feedback-pins' ); ?></h2>
					<p><?php esc_html_e( 'Every note in three columns (To do, In progress, Done), with filters, drag and drop, answers and a Markdown or JSON export.', 'feedback-pins' ); ?></p>
					<?php fpins_copy_field( 'fpins-board', fpins_board_invite_url(), __( 'Board link', 'feedback-pins' ) ); ?>
					<p><a class="button" href="<?php echo esc_url( fpins_board_invite_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the board', 'feedback-pins' ); ?></a></p>
				</div>

				<div class="card">
					<h2><?php esc_html_e( 'Review key', 'feedback-pins' ); ?></h2>
					<p><?php esc_html_e( 'Nobody can read or write notes without this key. It is already included in the links above.', 'feedback-pins' ); ?></p>
					<p><code><?php echo esc_html( fpins_key() ); ?></code></p>
					<?php if ( fpins_key_is_constant() ) : ?>
						<p class="description">
							<?php
							/* translators: 1: constant name, 2: file name. */
							printf( esc_html__( 'Set by the %1$s constant in %2$s.', 'feedback-pins' ), '<code>FPINS_KEY</code>', '<code>wp-config.php</code>' );
							?>
						</p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="fpins_regenerate">
							<?php wp_nonce_field( 'fpins_regenerate' ); ?>
							<?php submit_button( __( 'Generate a new key', 'feedback-pins' ), 'secondary', 'submit', false, array( 'data-fpins-confirm' => __( 'Links already sent will stop working. Continue?', 'feedback-pins' ) ) ); ?>
						</form>
					<?php endif; ?>
				</div>

				<div class="card">
					<h2><?php esc_html_e( 'Slack notifications', 'feedback-pins' ); ?></h2>
					<p>
						<?php esc_html_e( 'Every new note is posted to a channel, with its screenshot and a direct link to the spot on the page. The message is sent by your own server, straight to Slack: nothing goes through a third party.', 'feedback-pins' ); ?>
						<a href="https://api.slack.com/messaging/webhooks" target="_blank" rel="noopener"><?php esc_html_e( 'How to get a webhook URL', 'feedback-pins' ); ?></a>
					</p>
					<?php if ( fpins_slack_is_constant() ) : ?>
						<p class="description">
							<?php
							/* translators: 1: constant name, 2: file name. */
							printf( esc_html__( 'Set by the %1$s constant in %2$s.', 'feedback-pins' ), '<code>FPINS_SLACK_WEBHOOK</code>', '<code>wp-config.php</code>' );
							?>
						</p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="fpins_slack">
							<?php wp_nonce_field( 'fpins_slack' ); ?>
							<label class="screen-reader-text" for="fpins-webhook"><?php esc_html_e( 'Slack webhook URL', 'feedback-pins' ); ?></label>
							<input type="url" id="fpins-webhook" name="webhook" class="large-text code" placeholder="https://hooks.slack.com/services/…" value="<?php echo esc_attr( fpins_slack_webhook() ); ?>">
							<p class="description"><?php esc_html_e( 'Leave empty to turn notifications off.', 'feedback-pins' ); ?></p>
							<p>
								<?php submit_button( __( 'Save', 'feedback-pins' ), 'primary', 'save', false ); ?>
								<?php submit_button( __( 'Save and send a test', 'feedback-pins' ), 'secondary', 'test', false ); ?>
							</p>
						</form>
					<?php endif; ?>
				</div>

				<div class="card">
					<h2><?php esc_html_e( 'Good to know', 'feedback-pins' ); ?></h2>
					<ul class="fpins-steps" style="list-style:disc">
						<li><?php esc_html_e( 'Visitors who never opened a review link only download a few hundred bytes: the toolbar is loaded on demand.', 'feedback-pins' ); ?></li>
						<li><?php esc_html_e( 'Screenshots are stored in uploads/feedback-pins/ under random names, and deleted with their note.', 'feedback-pins' ); ?></li>
						<li>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . FPINS_CPT ) ); ?>"><?php esc_html_e( 'See the raw notes in the admin', 'feedback-pins' ); ?></a>
						</li>
					</ul>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="fpins_credit">
						<?php wp_nonce_field( 'fpins_credit' ); ?>
						<p>
							<label>
								<input type="checkbox" name="credit" value="1" <?php checked( (bool) get_option( 'fpins_credit' ) ); ?>>
								<?php esc_html_e( 'Show a small “made by AVANGARDISM” credit at the bottom of the board. Off by default, it helps us keep the plugin free.', 'feedback-pins' ); ?>
							</label>
						</p>
						<?php submit_button( __( 'Save', 'feedback-pins' ), 'secondary', 'save', false ); ?>
					</form>
				</div>
			</div>

			<aside>
				<div class="card fpins-studio">
					<p class="fpins-studio-logo"><a href="<?php echo esc_url( fpins_avangardism_url( 'admin-logo' ) ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( FPINS_URL . 'assets/images/avangardism-light.svg' ); ?>" width="140" height="32" alt="AVANGARDISM"></a></p>
					<h2><?php esc_html_e( 'Free, for good', 'feedback-pins' ); ?></h2>
					<p><?php esc_html_e( 'Feedback Pins is the review tool we use with our own clients. We share it for free: no account, no premium version, your data stays in your WordPress.', 'feedback-pins' ); ?></p>
					<p><?php esc_html_e( 'AVANGARDISM is a web agency: websites, e-commerce, business software and UX/UI design, with a user-first approach.', 'feedback-pins' ); ?></p>
					<p><a class="button button-primary" href="<?php echo esc_url( fpins_avangardism_url( 'admin-sidebar', '/contact' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Talk about your project', 'feedback-pins' ); ?></a></p>
					<p><a href="<?php echo esc_url( fpins_avangardism_url( 'admin-sidebar' ) ); ?>" target="_blank" rel="noopener">avangardism.com</a></p>
				</div>
				<div class="card">
					<h2><?php esc_html_e( 'Help the project', 'feedback-pins' ); ?></h2>
					<p><?php esc_html_e( 'A bug, an idea? The code is open.', 'feedback-pins' ); ?></p>
					<p>
						<a href="https://github.com/avangardism-alex/feedback-pins/issues" target="_blank" rel="noopener"><?php esc_html_e( 'Report an issue', 'feedback-pins' ); ?></a><br>
						<a href="https://github.com/avangardism-alex/feedback-pins" target="_blank" rel="noopener"><?php esc_html_e( 'Star it on GitHub', 'feedback-pins' ); ?></a><br>
						<a href="https://wordpress.org/support/plugin/feedback-pins/reviews/#new-post" target="_blank" rel="noopener"><?php esc_html_e( 'Leave a review on WordPress.org', 'feedback-pins' ); ?></a>
					</p>
				</div>
			</aside>
		</div>
	</div>
	<?php
}
