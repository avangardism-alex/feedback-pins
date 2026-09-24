<?php
/**
 * Notes: storage, sanitising and formatting, plus the shared access key.
 *
 * Each note is a post of the private `fpins_note` type. Every field lives in a
 * `_fpins_{field}` meta, the description included: when nobody is logged in,
 * WordPress runs `post_content` through kses and would strip anything that
 * looks like a tag, while a note is plain text ("the <h2> is too big").
 *
 * @package FeedbackPins
 */

defined( 'ABSPATH' ) || exit;

/** Post type holding the notes. */
const FPINS_CPT = 'fpins_note';

/** Allowed values, in display order. */
const FPINS_SEVERITIES = array( 'blocker', 'major', 'minor', 'nice' );
const FPINS_STATUSES   = array( 'open', 'in_progress', 'fixed', 'wontfix' );

/**
 * Note fields and their type. The keys are the JSON names used by the browser.
 */
const FPINS_FIELDS = array(
	'description'    => 'text',
	'author'         => 'string',
	'url'            => 'path',
	'pageTitle'      => 'string',
	'sectionLabel'   => 'string',
	'selector'       => 'string',
	'severity'       => 'severity',
	'status'         => 'status',
	'fixNote'        => 'text',
	'fixedAt'        => 'string',
	'x'              => 'number',
	'y'              => 'number',
	'scrollY'        => 'number',
	'relX'           => 'number',
	'relY'           => 'number',
	'viewportWidth'  => 'number',
	'viewportHeight' => 'number',
	'userAgent'      => 'string',
);

/**
 * Translated labels for severities and statuses.
 *
 * @return array{severities: array<string,string>, statuses: array<string,string>}
 */
function fpins_labels() {
	return array(
		'severities' => array(
			'blocker' => __( 'Blocker', 'feedback-pins' ),
			'major'   => __( 'Major', 'feedback-pins' ),
			'minor'   => __( 'Minor', 'feedback-pins' ),
			'nice'    => __( 'Nice to have', 'feedback-pins' ),
		),
		'statuses'   => array(
			'open'        => __( 'To do', 'feedback-pins' ),
			'in_progress' => __( 'In progress', 'feedback-pins' ),
			'fixed'       => __( 'Fixed', 'feedback-pins' ),
			'wontfix'     => __( "Won't fix", 'feedback-pins' ),
		),
	);
}

/* ── Shared key ──────────────────────────────────────────────────────────── */

/**
 * Returns the review key.
 *
 * The `FPINS_KEY` constant wins when defined. Otherwise a key is generated on
 * first use and kept in a non-autoloaded option: nothing to edit in
 * `wp-config.php` to get started.
 *
 * @return string
 */
function fpins_key() {
	if ( defined( 'FPINS_KEY' ) && '' !== (string) FPINS_KEY ) {
		return (string) FPINS_KEY;
	}

	$key = (string) get_option( 'fpins_key', '' );

	if ( '' === $key ) {
		$key = wp_generate_password( 20, false, false );
		add_option( 'fpins_key', $key, '', false );
	}

	return $key;
}

/**
 * Whether the key comes from the `FPINS_KEY` constant.
 *
 * @return bool
 */
function fpins_key_is_constant() {
	return defined( 'FPINS_KEY' ) && '' !== (string) FPINS_KEY;
}

/**
 * Capability required to manage the plugin and to see the key.
 *
 * @return string
 */
function fpins_capability() {
	/**
	 * Filters the capability needed for the settings page and the toolbar links.
	 *
	 * @param string $capability Default `manage_options`.
	 */
	return (string) apply_filters( 'fpins_capability', 'manage_options' );
}

/* ── Storage ─────────────────────────────────────────────────────────────── */

/**
 * Registers the post type. Hidden from visitors, but reachable in the admin to
 * inspect or fix a note by hand.
 */
function fpins_register_post_type() {
	register_post_type(
		FPINS_CPT,
		array(
			'label'               => __( 'Feedback notes', 'feedback-pins' ),
			'labels'              => array(
				'name'          => __( 'Feedback notes', 'feedback-pins' ),
				'singular_name' => __( 'Feedback note', 'feedback-pins' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'supports'            => array( 'title', 'custom-fields' ),
			'rewrite'             => false,
			'query_var'           => false,
		)
	);
}
add_action( 'init', 'fpins_register_post_type' );

/**
 * Sanitises a value according to its type in `FPINS_FIELDS`.
 *
 * @param string $type  Field type.
 * @param mixed  $value Raw value.
 * @return mixed|null Null when the value must be ignored.
 */
function fpins_sanitize( $type, $value ) {
	if ( null === $value || is_array( $value ) || is_object( $value ) ) {
		return null;
	}

	switch ( $type ) {
		case 'text':
			// Plain text kept as typed. Safe: the browser always renders it
			// with `textContent`, and WordPress with the `esc_*` functions.
			$text = wp_check_invalid_utf8( str_replace( "\0", '', (string) $value ) );
			return mb_substr( trim( str_replace( "\r\n", "\n", $text ) ), 0, 5000 );
		case 'string':
			return mb_substr( sanitize_text_field( (string) $value ), 0, 500 );
		case 'path':
			// Only the path matters: a page groups its notes whatever the
			// query string.
			$path = wp_parse_url( (string) $value, PHP_URL_PATH );
			return $path ? mb_substr( sanitize_text_field( $path ), 0, 500 ) : '/';
		case 'severity':
			return in_array( $value, FPINS_SEVERITIES, true ) ? $value : null;
		case 'status':
			return in_array( $value, FPINS_STATUSES, true ) ? $value : null;
		case 'number':
			return is_numeric( $value ) ? round( (float) $value, 4 ) : null;
	}

	return null;
}

/**
 * Turns a post into a note, in the shape the browser expects.
 *
 * @param WP_Post $post Post of the `fpins_note` type.
 * @return array
 */
function fpins_format( $post ) {
	$note = array(
		'id'        => (int) $post->ID,
		'createdAt' => mysql2date( 'c', $post->post_date_gmt, false ),
		'updatedAt' => mysql2date( 'c', $post->post_modified_gmt, false ),
	);

	foreach ( FPINS_FIELDS as $field => $type ) {
		$value = get_post_meta( $post->ID, '_fpins_' . $field, true );

		if ( '' === $value ) {
			continue;
		}

		$note[ $field ] = 'number' === $type ? (float) $value : $value;
	}

	$screenshot = get_post_meta( $post->ID, '_fpins_screenshot', true );

	if ( $screenshot ) {
		$note['screenshot'] = fpins_screenshot_url( $screenshot );
	}

	$note['severity'] = $note['severity'] ?? 'major';
	$note['status']   = $note['status'] ?? 'open';

	/**
	 * Filters a note before it is sent to the browser or to Slack.
	 *
	 * @param array   $note Formatted note.
	 * @param WP_Post $post Underlying post.
	 */
	return apply_filters( 'fpins_note', $note, $post );
}

/**
 * Saves the given fields on a note. An empty string clears a text field.
 *
 * @param int   $id   Post ID.
 * @param array $data Received fields.
 */
function fpins_save_fields( $id, $data ) {
	foreach ( FPINS_FIELDS as $field => $type ) {
		if ( ! array_key_exists( $field, $data ) ) {
			continue;
		}

		if ( '' === $data[ $field ] && in_array( $type, array( 'text', 'string' ), true ) ) {
			delete_post_meta( $id, '_fpins_' . $field );
			continue;
		}

		$value = fpins_sanitize( $type, $data[ $field ] );

		if ( null !== $value ) {
			update_post_meta( $id, '_fpins_' . $field, wp_slash( $value ) );
		}
	}
}

/**
 * Admin-friendly title: the start of the note.
 *
 * @param string $description Note text.
 * @return string
 */
function fpins_title( $description ) {
	$title = wp_strip_all_tags( $description );

	return mb_strlen( $title ) > 70 ? mb_substr( $title, 0, 69 ) . '…' : $title;
}

/**
 * Number of stored notes.
 *
 * @return int
 */
function fpins_count() {
	$counts = wp_count_posts( FPINS_CPT );

	return isset( $counts->publish ) ? (int) $counts->publish : 0;
}
