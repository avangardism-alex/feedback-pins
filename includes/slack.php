<?php
/**
 * Slack notifications through an incoming webhook.
 *
 * The call is made by the site's own server, right after the browser got its
 * answer. No third-party relay: the note goes straight from your WordPress to
 * your Slack workspace.
 *
 * @package Pinora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Slack webhook: `PINORA_SLACK_WEBHOOK` constant, otherwise the admin setting.
 * A webhook is a secret (it lets anyone post in the channel).
 *
 * @return string Empty when not configured.
 */
function pinora_slack_webhook() {
	if ( pinora_slack_is_constant() ) {
		return (string) PINORA_SLACK_WEBHOOK;
	}

	return (string) get_option( 'pinora_slack_webhook', '' );
}

/**
 * Whether the webhook comes from the `PINORA_SLACK_WEBHOOK` constant.
 *
 * @return bool
 */
function pinora_slack_is_constant() {
	return defined( 'PINORA_SLACK_WEBHOOK' ) && '' !== (string) PINORA_SLACK_WEBHOOK;
}

/**
 * Whether a URL looks like a Slack incoming webhook.
 *
 * @param string $url URL.
 * @return bool
 */
function pinora_slack_is_valid( $url ) {
	return (bool) preg_match( '#^https://hooks\.slack\.com/#', $url );
}

/**
 * Schedules the Slack call after the response is sent.
 *
 * The reviewer does not wait for Slack: the REST response leaves first
 * (`fastcgi_finish_request` where available), the webhook is called next.
 *
 * @param array $note Formatted note.
 */
function pinora_slack_later( $note ) {
	if ( '' === pinora_slack_webhook() ) {
		return;
	}

	add_action(
		'shutdown',
		function () use ( $note ) {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			}
			pinora_slack_send( $note );
		}
	);
}

/**
 * Builds the Slack message for a note (Block Kit).
 *
 * @param array $note Formatted note.
 * @return array
 */
function pinora_slack_message( $note ) {
	$icons    = array(
		'blocker' => ':red_circle:',
		'major'   => ':large_orange_circle:',
		'minor'   => ':large_yellow_circle:',
		'nice'    => ':large_purple_circle:',
	);
	$labels   = pinora_labels()['severities'];
	$escape   = static function ( $text ) {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), (string) $text );
	};
	$author   = $note['author'] ?? __( 'Anonymous', 'pinora' );
	$severity = $note['severity'] ?? 'major';
	$page     = pinora_page_url( $note['url'] ?? '/' );
	$view     = add_query_arg(
		array(
			'pinora'      => '1',
			'pinora_note' => $note['id'],
		),
		$page
	);
	$context  = array_filter(
		array(
			'*' . $escape( $author ) . '*',
			'<' . esc_url_raw( $page ) . '|' . $escape( $note['url'] ?? '/' ) . '>',
			/* translators: %s: name of the page section, e.g. "Footer". */
			isset( $note['sectionLabel'] ) ? sprintf( __( 'section “%s”', 'pinora' ), $escape( $note['sectionLabel'] ) ) : '',
			/* translators: %d: browser window width in pixels. */
			isset( $note['viewportWidth'] ) ? sprintf( __( '%d px wide', 'pinora' ), (int) $note['viewportWidth'] ) : '',
		)
	);

	$blocks = array(
		array(
			'type' => 'section',
			'text' => array(
				'type' => 'mrkdwn',
				'text' => ( $icons[ $severity ] ?? '' ) . ' ' . ( $labels[ $severity ] ?? $severity )
					/* translators: %d: note number. */
					. '  ·  ' . sprintf( __( 'new note #%d', 'pinora' ), (int) $note['id'] )
					. "\n" . $escape( mb_substr( $note['description'] ?? '', 0, 2800 ) ),
			),
		),
		array(
			'type'     => 'context',
			'elements' => array(
				array(
					'type' => 'mrkdwn',
					'text' => implode( '  ·  ', $context ),
				),
			),
		),
	);

	if ( ! empty( $note['screenshot'] ) ) {
		$blocks[] = array(
			'type'      => 'image',
			'image_url' => $note['screenshot'],
			'alt_text'  => __( 'Screenshot taken with the note', 'pinora' ),
		);
	}

	$blocks[] = array(
		'type'     => 'actions',
		'elements' => array(
			array(
				'type' => 'button',
				'text' => array(
					'type' => 'plain_text',
					'text' => __( 'View on the page', 'pinora' ),
				),
				'url'  => esc_url_raw( $view ),
			),
			array(
				'type' => 'button',
				'text' => array(
					'type' => 'plain_text',
					'text' => __( 'Open the board', 'pinora' ),
				),
				'url'  => esc_url_raw( pinora_board_url() ),
			),
		),
	);

	$message = array(
		// Fallback text: mobile notifications and clients without Block Kit.
		/* translators: 1: author name, 2: page path. */
		'text'   => sprintf( __( 'New feedback from %1$s on %2$s', 'pinora' ), $author, $note['url'] ?? '/' ),
		'blocks' => $blocks,
	);

	/**
	 * Filters the Slack payload before it is sent.
	 *
	 * @param array $message Slack payload (`text` and `blocks`).
	 * @param array $note    Formatted note.
	 */
	return apply_filters( 'pinora_slack_message', $message, $note );
}

/**
 * Posts a note to Slack. A failure is logged, never blocking.
 *
 * @param array $note Formatted note.
 * @return true|WP_Error
 */
function pinora_slack_send( $note ) {
	$webhook = pinora_slack_webhook();

	if ( '' === $webhook ) {
		return new WP_Error( 'pinora_slack', __( 'No Slack webhook configured.', 'pinora' ) );
	}

	$response = wp_remote_post(
		$webhook,
		array(
			'timeout' => 5,
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'    => wp_json_encode( pinora_slack_message( $note ) ),
		)
	);

	if ( is_wp_error( $response ) ) {
		pinora_log( 'Slack unreachable: ' . $response->get_error_message() );
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 !== $code ) {
		/* translators: 1: HTTP status code, 2: Slack error message. */
		$error = sprintf( __( 'Slack answered %1$d: %2$s', 'pinora' ), $code, wp_remote_retrieve_body( $response ) );
		pinora_log( $error );
		return new WP_Error( 'pinora_slack', $error );
	}

	return true;
}
