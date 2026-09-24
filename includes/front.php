<?php
/**
 * Front end: the loader on every page, the board page and the toolbar links.
 *
 * @package FeedbackPins
 */

defined( 'ABSPATH' ) || exit;

/**
 * Path of the board, relative to the site root, without slashes.
 *
 * @return string
 */
function fpins_board_path() {
	/**
	 * Filters the path of the feedback board (default `feedback-board`).
	 *
	 * If a real page already uses this path, the page wins and the board is
	 * not served: pick another path with this filter.
	 *
	 * @param string $path Path without leading or trailing slash.
	 */
	return trim( (string) apply_filters( 'fpins_board_path', 'feedback-board' ), '/' );
}

/**
 * Public URL of the board.
 *
 * @return string
 */
function fpins_board_url() {
	return home_url( user_trailingslashit( fpins_board_path() ) );
}

/**
 * Full URL of a page from the path stored with a note.
 *
 * Notes keep the whole path as the browser saw it (`/blog/about/` on a site
 * living in `/blog/`), so only the origin is added: `home_url()` would repeat
 * the sub-folder.
 *
 * @param string $path Stored path.
 * @return string
 */
function fpins_page_url( $path ) {
	$home   = wp_parse_url( home_url() );
	$origin = ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );

	return $origin . '/' . ltrim( (string) $path, '/' );
}

/**
 * Invitation link: review mode on, key in the fragment.
 *
 * The fragment (`#…`) never leaves the browser: the key does not end up in
 * the server logs nor in analytics tools.
 *
 * @param string $url Page to open, defaults to the home page.
 * @return string
 */
function fpins_invite_url( $url = '' ) {
	$url = $url ? $url : home_url( '/' );

	return add_query_arg( 'fpins', '1', $url ) . '#fpins_key=' . rawurlencode( fpins_key() );
}

/**
 * Board link with the key.
 *
 * @return string
 */
function fpins_board_invite_url() {
	return fpins_board_url() . '#fpins_key=' . rawurlencode( fpins_key() );
}

/**
 * Versioned URL of a plugin asset.
 *
 * @param string $file Path inside `assets/`.
 * @return string
 */
function fpins_asset_url( $file ) {
	return add_query_arg( 'ver', fpins_asset_version( $file ), FPINS_URL . 'assets/' . $file );
}

/**
 * Version of a plugin asset: its modification time, so that browsers pick up
 * any change, even without a new plugin version.
 *
 * @param string $file Path inside `assets/`.
 * @return string
 */
function fpins_asset_version( $file ) {
	$path = FPINS_DIR . 'assets/' . $file;

	return file_exists( $path ) ? (string) filemtime( $path ) : FPINS_VERSION;
}

/**
 * Site language as a BCP 47 tag (`fr-FR`), for dates in the browser.
 *
 * @return string
 */
function fpins_locale_tag() {
	return str_replace( '_', '-', determine_locale() );
}

/**
 * Configuration handed to the JavaScript.
 *
 * @param string $view `site` or `board`.
 * @return array
 */
function fpins_config( $view ) {
	$config = array(
		'rest'          => esc_url_raw( rest_url( FPINS_REST_NS . '/notes' ) ),
		'board'         => esc_url_raw( fpins_board_url() ),
		'home'          => esc_url_raw( home_url( '/' ) ),
		'css'           => esc_url_raw( fpins_asset_url( 'css/feedback-pins.css' ) ),
		'screenshotLib' => esc_url_raw( fpins_asset_url( 'vendor/html2canvas.min.js' ) ),
		'locale'        => fpins_locale_tag(),
		'view'          => $view,
		/**
		 * Filters how often open browsers reload the notes, in milliseconds.
		 *
		 * @param int $interval Default 15000.
		 */
		'interval'      => max( 5000, (int) apply_filters( 'fpins_sync_interval', 15000 ) ),
	);

	if ( 'board' === $view ) {
		$config['i18n']   = fpins_js_strings()['i18n'];
		$config['labels'] = fpins_labels();
		$config['credit'] = get_option( 'fpins_credit' ) ? esc_url_raw( fpins_avangardism_url( 'board-credit' ) ) : '';
		$config['logo']   = esc_url_raw( FPINS_URL . 'assets/images/avangardism.svg' );
	} else {
		$config['script']  = esc_url_raw( fpins_asset_url( 'js/feedback-pins.js' ) );
		$config['strings'] = esc_url_raw( rest_url( FPINS_REST_NS . '/strings' ) );
	}

	return $config;
}

/**
 * Link to the AVANGARDISM website, tagged so the studio knows where visits
 * come from. Nothing is sent anywhere unless someone clicks.
 *
 * @param string $campaign Where the link sits.
 * @param string $path     Page on the website.
 * @return string
 */
function fpins_avangardism_url( $campaign, $path = '/' ) {
	return add_query_arg(
		array(
			'utm_source'   => 'feedback-pins',
			'utm_medium'   => 'plugin',
			'utm_campaign' => $campaign,
		),
		'https://avangardism.com' . $path
	);
}

/**
 * Loads a tiny loader on public pages.
 *
 * Regular visitors only get these few hundred bytes: the toolbar script is
 * downloaded only once `?fpins=1` has been opened in this browser. Deciding in
 * PHP (with a cookie) would break as soon as a page cache sits in front of the
 * site.
 */
function fpins_enqueue() {
	if ( is_admin() ) {
		return;
	}

	$loader = <<<'JS'
(function (c) {
	var p = new URLSearchParams(window.location.search);
	var on = p.has('fpins') || p.has('fpins_note') || /[#&]fpins_key=/.test(window.location.hash);
	if (!on) {
		try { on = window.localStorage.getItem('fpins:' + c.rest + ':mode') === '1'; } catch (e) {}
	}
	if (!on) return;
	var s = document.createElement('script');
	s.src = c.script;
	s.async = true;
	document.head.appendChild(s);
})(window.FEEDBACK_PINS);
JS;

	wp_register_script( 'feedback-pins-loader', false, array(), FPINS_VERSION, array( 'in_footer' => true ) );
	wp_enqueue_script( 'feedback-pins-loader' );
	wp_add_inline_script( 'feedback-pins-loader', 'window.FEEDBACK_PINS = ' . wp_json_encode( fpins_config( 'site' ) ) . ";\n" . $loader );
}
add_action( 'wp_enqueue_scripts', 'fpins_enqueue' );

/**
 * Serves the board on `/feedback-board/`, outside the theme templates.
 *
 * Caught on `parse_request`, before WordPress looks for content at this path:
 * no rewrite rule to register, no permalinks to flush. A real page with the
 * same path keeps priority.
 *
 * @param WP $wp Parsed request.
 */
function fpins_board( $wp ) {
	$path = fpins_board_path();

	if ( '' === $path || trim( (string) $wp->request, '/' ) !== $path || get_page_by_path( $path ) ) {
		return;
	}

	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );

	wp_register_style( 'feedback-pins', FPINS_URL . 'assets/css/feedback-pins.css', array(), fpins_asset_version( 'css/feedback-pins.css' ) );
	wp_register_script( 'feedback-pins', FPINS_URL . 'assets/js/feedback-pins.js', array(), fpins_asset_version( 'js/feedback-pins.js' ), array( 'in_footer' => true ) );
	wp_register_script( 'feedback-pins-board', FPINS_URL . 'assets/js/feedback-pins-board.js', array( 'feedback-pins' ), fpins_asset_version( 'js/feedback-pins-board.js' ), array( 'in_footer' => true ) );
	wp_add_inline_script( 'feedback-pins', 'window.FEEDBACK_PINS = ' . wp_json_encode( fpins_config( 'board' ) ) . ';', 'before' );

	?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( sprintf( /* translators: %s: site name. */ __( 'Feedback board · %s', 'feedback-pins' ), get_bloginfo( 'name' ) ) ); ?></title>
	<?php wp_print_styles( array( 'feedback-pins' ) ); ?>
</head>
<body class="fpins-page">
	<main id="fpins-board"></main>
	<?php wp_print_scripts( array( 'feedback-pins-board' ) ); ?>
</body>
</html>
	<?php
	exit;
}
add_action( 'parse_request', 'fpins_board', 0 );

/**
 * Toolbar shortcuts for admins: review the current page, open the board.
 *
 * @param WP_Admin_Bar $bar Admin bar.
 */
function fpins_admin_bar( $bar ) {
	if ( ! current_user_can( fpins_capability() ) ) {
		return;
	}

	$current = home_url( '/' );

	if ( ! is_admin() && isset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] ) ) {
		$current = esc_url_raw( set_url_scheme( 'http://' . wp_unslash( $_SERVER['HTTP_HOST'] ) . wp_unslash( $_SERVER['REQUEST_URI'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	$bar->add_node(
		array(
			'id'    => 'feedback-pins',
			'title' => '<span class="ab-icon dashicons dashicons-location" aria-hidden="true" style="margin-top:2px"></span><span class="ab-label">' . esc_html__( 'Feedback', 'feedback-pins' ) . '</span>',
			'href'  => fpins_invite_url( $current ),
			'meta'  => array( 'title' => __( 'Pin feedback on this page', 'feedback-pins' ) ),
		)
	);
	$bar->add_node(
		array(
			'parent' => 'feedback-pins',
			'id'     => 'feedback-pins-review',
			'title'  => esc_html__( 'Review this page', 'feedback-pins' ),
			'href'   => fpins_invite_url( $current ),
		)
	);
	$bar->add_node(
		array(
			'parent' => 'feedback-pins',
			'id'     => 'feedback-pins-board',
			'title'  => esc_html__( 'Open the board', 'feedback-pins' ),
			'href'   => fpins_board_invite_url(),
		)
	);
	$bar->add_node(
		array(
			'parent' => 'feedback-pins',
			'id'     => 'feedback-pins-settings',
			'title'  => esc_html__( 'Settings', 'feedback-pins' ),
			'href'   => admin_url( 'tools.php?page=feedback-pins' ),
		)
	);
}
add_action( 'admin_bar_menu', 'fpins_admin_bar', 80 );

/**
 * Labels used by the JavaScript, keyed by their English source.
 *
 * Kept here as plain `__()` calls so that they are picked up by the usual
 * translation tools and by translate.wordpress.org.
 *
 * @return array{i18n: array<string,string>, labels: array}
 */
function fpins_js_strings() {
	$i18n = array(
		/* translators: 1: number of notes to do, 2: in progress, 3: done. */
		'%1$d to do · %2$d in progress · %3$d done.' => __( '%1$d to do · %2$d in progress · %3$d done.', 'feedback-pins' ),
		/* translators: number of open notes on the page. */
		'%d open' => __( '%d open', 'feedback-pins' ),
		'(no section)' => __( '(no section)', 'feedback-pins' ),
		'+ Note' => __( '+ Note', 'feedback-pins' ),
		'Add' => __( 'Add', 'feedback-pins' ),
		'Add a note' => __( 'Add a note', 'feedback-pins' ),
		'Add or edit an answer' => __( 'Add or edit an answer', 'feedback-pins' ),
		'All pages' => __( 'All pages', 'feedback-pins' ),
		'All severities' => __( 'All severities', 'feedback-pins' ),
		'Anonymous' => __( 'Anonymous', 'feedback-pins' ),
		'Answer' => _x( 'Answer', 'Verb, button label.', 'feedback-pins' ),
		'Answer the note' => __( 'Answer the note', 'feedback-pins' ),
		'Answer:' => __( 'Answer:', 'feedback-pins' ),
		'Board' => _x( 'Board', 'Noun, link to the feedback board.', 'feedback-pins' ),
		'Both fields are required.' => __( 'Both fields are required.', 'feedback-pins' ),
		'Cancel' => __( 'Cancel', 'feedback-pins' ),
		'Click the element you want to comment on, or reach it with Tab and press Enter · Esc to cancel' => __( 'Click the element you want to comment on, or reach it with Tab and press Enter · Esc to cancel', 'feedback-pins' ),
		'Click “Sign in” and enter the key that came with your review link.' => __( 'Click “Sign in” and enter the key that came with your review link.', 'feedback-pins' ),
		'Close' => __( 'Close', 'feedback-pins' ),
		'Close the list' => __( 'Close the list', 'feedback-pins' ),
		'Continue' => __( 'Continue', 'feedback-pins' ),
		'Copied.' => __( 'Copied.', 'feedback-pins' ),
		'Copy' => __( 'Copy', 'feedback-pins' ),
		/* translators: error message. */
		'Could not add: %s' => __( 'Could not add: %s', 'feedback-pins' ),
		/* translators: error message. */
		'Could not delete: %s' => __( 'Could not delete: %s', 'feedback-pins' ),
		/* translators: error message. */
		'Could not move: %s' => __( 'Could not move: %s', 'feedback-pins' ),
		/* translators: error message. */
		'Could not save: %s' => __( 'Could not save: %s', 'feedback-pins' ),
		/* translators: error message. */
		'Could not update: %s' => __( 'Could not update: %s', 'feedback-pins' ),
		'Delete' => __( 'Delete', 'feedback-pins' ),
		'Delete the note' => __( 'Delete the note', 'feedback-pins' ),
		'Delete this note for the whole team?' => __( 'Delete this note for the whole team?', 'feedback-pins' ),
		'Do not attach' => __( 'Do not attach', 'feedback-pins' ),
		'Done' => __( 'Done', 'feedback-pins' ),
		'Download' => __( 'Download', 'feedback-pins' ),
		'Edit the answer' => __( 'Edit the answer', 'feedback-pins' ),
		'Enlarge the screenshot' => __( 'Enlarge the screenshot', 'feedback-pins' ),
		'Everyone' => __( 'Everyone', 'feedback-pins' ),
		'Export' => __( 'Export', 'feedback-pins' ),
		/* translators: number of notes. */
		'Export %d note(s)' => __( 'Export %d note(s)', 'feedback-pins' ),
		'Feedback Pins' => __( 'Feedback Pins', 'feedback-pins' ),
		'Feedback Pins, a free plugin by' => __( 'Feedback Pins, a free plugin by', 'feedback-pins' ),
		'Feedback board' => __( 'Feedback board', 'feedback-pins' ),
		/* translators: number of notes. */
		'Feedback · %d note(s)' => __( 'Feedback · %d note(s)', 'feedback-pins' ),
		'Filter by page' => __( 'Filter by page', 'feedback-pins' ),
		'Filter by person' => __( 'Filter by person', 'feedback-pins' ),
		'Filter by severity' => __( 'Filter by severity', 'feedback-pins' ),
		'Follows the current filters. Grouped by page then section: paste it as is into a ticket or an AI assistant.' => __( 'Follows the current filters. Grouped by page then section: paste it as is into a ticket or an AI assistant.', 'feedback-pins' ),
		'Footer' => __( 'Footer', 'feedback-pins' ),
		'Header' => __( 'Header', 'feedback-pins' ),
		'In progress' => __( 'In progress', 'feedback-pins' ),
		'In sync with the team' => __( 'In sync with the team', 'feedback-pins' ),
		/* translators: error message. */
		'Key refused: %s' => __( 'Key refused: %s', 'feedback-pins' ),
		'Leave review mode' => __( 'Leave review mode', 'feedback-pins' ),
		'List' => _x( 'List', 'Noun, button that opens the list of notes.', 'feedback-pins' ),
		'Mark as fixed' => __( 'Mark as fixed', 'feedback-pins' ),
		/* translators: %s: column name, e.g. "In progress". */
		'Moved to %s' => __( 'Moved to %s', 'feedback-pins' ),
		'Missing review key.' => __( 'Missing review key.', 'feedback-pins' ),
		/* translators: column name, e.g. "In progress". */
		'Move to %s' => __( 'Move to %s', 'feedback-pins' ),
		'New note' => __( 'New note', 'feedback-pins' ),
		'New “To do” note' => __( 'New “To do” note', 'feedback-pins' ),
		'No open note on this page. Click “+ Note” to add one.' => __( 'No open note on this page. Click “+ Note” to add one.', 'feedback-pins' ),
		'No screenshot on this page: the note will be located by its position.' => __( 'No screenshot on this page: the note will be located by its position.', 'feedback-pins' ),
		/* translators: pin number. */
		'Note %d' => __( 'Note %d', 'feedback-pins' ),
		'Note saved, the whole team can see it.' => __( 'Note saved, the whole team can see it.', 'feedback-pins' ),
		'Notes are shared: what one person pins shows up for everyone else, without reloading.' => __( 'Notes are shared: what one person pins shows up for everyone else, without reloading.', 'feedback-pins' ),
		'Notes on this page' => __( 'Notes on this page', 'feedback-pins' ),
		'Nothing to do. Add a note (+) or pin one right on the site.' => __( 'Nothing to do. Add a note (+) or pin one right on the site.', 'feedback-pins' ),
		'Open the page at this spot' => __( 'Open the page at this spot', 'feedback-pins' ),
		'Page (path)' => __( 'Page (path)', 'feedback-pins' ),
		'Pin number on the page' => __( 'Pin number on the page', 'feedback-pins' ),
		'Reopen' => __( 'Reopen', 'feedback-pins' ),
		'Review key' => __( 'Review key', 'feedback-pins' ),
		'Review the site →' => __( 'Review the site →', 'feedback-pins' ),
		'Save' => __( 'Save', 'feedback-pins' ),
		'Screenshot attached to the note' => __( 'Screenshot attached to the note', 'feedback-pins' ),
		/* translators: pin number. */
		'Screenshot of note #%d' => __( 'Screenshot of note #%d', 'feedback-pins' ),
		'Screenshot removed.' => __( 'Screenshot removed.', 'feedback-pins' ),
		'Screenshot taken with the note' => __( 'Screenshot taken with the note', 'feedback-pins' ),
		'Screenshot…' => __( 'Screenshot…', 'feedback-pins' ),
		'Section (optional)' => __( 'Section (optional)', 'feedback-pins' ),
		/* translators: section name, e.g. "Footer". */
		'Section: %s' => __( 'Section: %s', 'feedback-pins' ),
		'Sending…' => __( 'Sending…', 'feedback-pins' ),
		'Severity' => __( 'Severity', 'feedback-pins' ),
		/* translators: number of closed notes. */
		'Show %d closed note(s)' => __( 'Show %d closed note(s)', 'feedback-pins' ),
		'Sign in' => __( 'Sign in', 'feedback-pins' ),
		/* translators: reviewer first name. */
		'Signed “%s”. ⌘/Ctrl + Enter to save.' => __( 'Signed “%s”. ⌘/Ctrl + Enter to save.', 'feedback-pins' ),
		/* translators: error message. */
		'Sync failed: %s' => __( 'Sync failed: %s', 'feedback-pins' ),
		'Taking a screenshot…' => __( 'Taking a screenshot…', 'feedback-pins' ),
		'The browser refused to copy: the text is selected, press ⌘/Ctrl + C.' => __( 'The browser refused to copy: the text is selected, press ⌘/Ctrl + C.', 'feedback-pins' ),
		'This note has been deleted in the meantime.' => __( 'This note has been deleted in the meantime.', 'feedback-pins' ),
		'To do' => __( 'To do', 'feedback-pins' ),
		'View on the page →' => __( 'View on the page →', 'feedback-pins' ),
		'Who is reviewing?' => __( 'Who is reviewing?', 'feedback-pins' ),
		/* translators: reviewer first name. */
		'You are %s' => __( 'You are %s', 'feedback-pins' ),
		'Your first name' => __( 'Your first name', 'feedback-pins' ),
		'Your note' => __( 'Your note', 'feedback-pins' ),
		'Your first name signs your notes, so the team knows who spotted what. The key came with your review link.' => __( 'Your first name signs your notes, so the team knows who spotted what. The key came with your review link.', 'feedback-pins' ),
		'answer' => _x( 'answer', 'Noun, label in the Markdown export.', 'feedback-pins' ),
		'e.g. Camille' => __( 'e.g. Camille', 'feedback-pins' ),
		'e.g. Footer' => __( 'e.g. Footer', 'feedback-pins' ),
		'e.g. align the spacing between the result cards' => __( 'e.g. align the spacing between the result cards', 'feedback-pins' ),
		'e.g. fixed, live in 3 minutes · or: won’t fix, because…' => __( 'e.g. fixed, live in 3 minutes · or: won’t fix, because…', 'feedback-pins' ),
		'e.g. the button is too small on mobile, the text should say…' => __( 'e.g. the button is too small on mobile, the text should say…', 'feedback-pins' ),
		/* translators: export date. */
		'exported %s' => __( 'exported %s', 'feedback-pins' ),
		/* translators: pin number. */
		'pin #%d' => __( 'pin #%d', 'feedback-pins' ),
		'screen' => _x( 'screen', 'Noun, label in the Markdown export.', 'feedback-pins' ),
		'screenshot' => _x( 'screenshot', 'Noun, label in the Markdown export.', 'feedback-pins' ),
		'selector' => _x( 'selector', 'Noun, CSS selector label in the Markdown export.', 'feedback-pins' ),
	);

	// Untranslated strings are left out: the browser falls back to English.
	$i18n = array_filter(
		$i18n,
		static function ( $translation, $source ) {
			return $translation !== $source;
		},
		ARRAY_FILTER_USE_BOTH
	);

	return array(
		'i18n'   => $i18n,
		'labels' => fpins_labels(),
	);
}
