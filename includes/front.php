<?php
/**
 * Front end: the loader on every page, the board page and the toolbar links.
 *
 * @package Pinora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Path of the board, relative to the site root, without slashes.
 *
 * @return string
 */
function pinora_board_path() {
	/**
	 * Filters the path of the feedback board (default `feedback-board`).
	 *
	 * If a real page already uses this path, the page wins and the board is
	 * not served: pick another path with this filter.
	 *
	 * @param string $path Path without leading or trailing slash.
	 */
	return trim( (string) apply_filters( 'pinora_board_path', 'feedback-board' ), '/' );
}

/**
 * Public URL of the board.
 *
 * @return string
 */
function pinora_board_url() {
	return home_url( user_trailingslashit( pinora_board_path() ) );
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
function pinora_page_url( $path ) {
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
function pinora_invite_url( $url = '' ) {
	$url = $url ? $url : home_url( '/' );

	return add_query_arg( 'pinora', '1', $url ) . '#pinora_key=' . rawurlencode( pinora_key() );
}

/**
 * Board link with the key.
 *
 * @return string
 */
function pinora_board_invite_url() {
	return pinora_board_url() . '#pinora_key=' . rawurlencode( pinora_key() );
}

/**
 * Versioned URL of a plugin asset.
 *
 * @param string $file Path inside `assets/`.
 * @return string
 */
function pinora_asset_url( $file ) {
	return add_query_arg( 'ver', pinora_asset_version( $file ), PINORA_URL . 'assets/' . $file );
}

/**
 * Version of a plugin asset: its modification time, so that browsers pick up
 * any change, even without a new plugin version.
 *
 * @param string $file Path inside `assets/`.
 * @return string
 */
function pinora_asset_version( $file ) {
	$path = PINORA_DIR . 'assets/' . $file;

	return file_exists( $path ) ? (string) filemtime( $path ) : PINORA_VERSION;
}

/**
 * Site language as a BCP 47 tag (`fr-FR`), for dates in the browser.
 *
 * @return string
 */
function pinora_locale_tag() {
	return str_replace( '_', '-', determine_locale() );
}

/**
 * Configuration handed to the JavaScript.
 *
 * @param string $view `site` or `board`.
 * @return array
 */
function pinora_config( $view ) {
	$config = array(
		'rest'          => esc_url_raw( rest_url( PINORA_REST_NS . '/notes' ) ),
		'board'         => esc_url_raw( pinora_board_url() ),
		'home'          => esc_url_raw( home_url( '/' ) ),
		'css'           => esc_url_raw( pinora_asset_url( 'css/pinora.css' ) ),
		'screenshotLib' => esc_url_raw( pinora_asset_url( 'vendor/html2canvas.min.js' ) ),
		'locale'        => pinora_locale_tag(),
		'view'          => $view,
		/**
		 * Filters how often open browsers reload the notes, in milliseconds.
		 *
		 * @param int $interval Default 15000.
		 */
		'interval'      => max( 5000, (int) apply_filters( 'pinora_sync_interval', 15000 ) ),
	);

	if ( 'board' === $view ) {
		$config['i18n']   = pinora_js_strings()['i18n'];
		$config['labels'] = pinora_labels();
		$config['credit'] = get_option( 'pinora_credit' ) ? esc_url_raw( pinora_avangardism_url( 'board-credit' ) ) : '';
		$config['logo']   = esc_url_raw( PINORA_URL . 'assets/images/avangardism.svg' );
	} else {
		$config['script']  = esc_url_raw( pinora_asset_url( 'js/pinora.js' ) );
		$config['strings'] = esc_url_raw( rest_url( PINORA_REST_NS . '/strings' ) );
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
function pinora_avangardism_url( $campaign, $path = '/' ) {
	return add_query_arg(
		array(
			'utm_source'   => 'pinora',
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
 * downloaded only once `?pinora=1` has been opened in this browser. Deciding in
 * PHP (with a cookie) would break as soon as a page cache sits in front of the
 * site.
 */
function pinora_enqueue() {
	if ( is_admin() ) {
		return;
	}

	$loader = <<<'JS'
(function (c) {
	var p = new URLSearchParams(window.location.search);
	var on = p.has('pinora') || p.has('pinora_note') || /[#&]pinora_key=/.test(window.location.hash);
	if (!on) {
		try { on = window.localStorage.getItem('pinora:' + c.rest + ':mode') === '1'; } catch (e) {}
	}
	if (!on) return;
	var s = document.createElement('script');
	s.src = c.script;
	s.async = true;
	document.head.appendChild(s);
})(window.PINORA);
JS;

	wp_register_script( 'pinora-loader', false, array(), PINORA_VERSION, array( 'in_footer' => true ) );
	wp_enqueue_script( 'pinora-loader' );
	wp_add_inline_script( 'pinora-loader', 'window.PINORA = ' . wp_json_encode( pinora_config( 'site' ) ) . ";\n" . $loader );
}
add_action( 'wp_enqueue_scripts', 'pinora_enqueue' );

/**
 * Serves the board on `/feedback-board/`, outside the theme templates.
 *
 * Caught on `parse_request`, before WordPress looks for content at this path:
 * no rewrite rule to register, no permalinks to flush. A real page with the
 * same path keeps priority.
 *
 * @param WP $wp Parsed request.
 */
function pinora_board( $wp ) {
	$path = pinora_board_path();

	if ( '' === $path || trim( (string) $wp->request, '/' ) !== $path || get_page_by_path( $path ) ) {
		return;
	}

	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );

	wp_register_style( 'pinora', PINORA_URL . 'assets/css/pinora.css', array(), pinora_asset_version( 'css/pinora.css' ) );
	wp_register_script( 'pinora', PINORA_URL . 'assets/js/pinora.js', array(), pinora_asset_version( 'js/pinora.js' ), array( 'in_footer' => true ) );
	wp_register_script( 'pinora-board', PINORA_URL . 'assets/js/pinora-board.js', array( 'pinora' ), pinora_asset_version( 'js/pinora-board.js' ), array( 'in_footer' => true ) );
	wp_add_inline_script( 'pinora', 'window.PINORA = ' . wp_json_encode( pinora_config( 'board' ) ) . ';', 'before' );

	?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( sprintf( /* translators: %s: site name. */ __( 'Feedback board · %s', 'pinora' ), get_bloginfo( 'name' ) ) ); ?></title>
	<?php wp_print_styles( array( 'pinora' ) ); ?>
</head>
<body class="pinora-page">
	<main id="pinora-board"></main>
	<?php wp_print_scripts( array( 'pinora-board' ) ); ?>
</body>
</html>
	<?php
	exit;
}
add_action( 'parse_request', 'pinora_board', 0 );

/**
 * Toolbar shortcuts for admins: review the current page, open the board.
 *
 * @param WP_Admin_Bar $bar Admin bar.
 */
function pinora_admin_bar( $bar ) {
	if ( ! current_user_can( pinora_capability() ) ) {
		return;
	}

	$current = home_url( '/' );

	// The host comes from the site settings, never from the request headers.
	if ( ! is_admin() && isset( $_SERVER['REQUEST_URI'] ) ) {
		$current = esc_url_raw( pinora_page_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) );
	}

	$bar->add_node(
		array(
			'id'    => 'pinora',
			'title' => '<span class="ab-icon dashicons dashicons-location" aria-hidden="true" style="margin-top:2px"></span><span class="ab-label">' . esc_html__( 'Feedback', 'pinora' ) . '</span>',
			'href'  => pinora_invite_url( $current ),
			'meta'  => array( 'title' => __( 'Pin feedback on this page', 'pinora' ) ),
		)
	);
	$bar->add_node(
		array(
			'parent' => 'pinora',
			'id'     => 'pinora-review',
			'title'  => esc_html__( 'Review this page', 'pinora' ),
			'href'   => pinora_invite_url( $current ),
		)
	);
	$bar->add_node(
		array(
			'parent' => 'pinora',
			'id'     => 'pinora-board',
			'title'  => esc_html__( 'Open the board', 'pinora' ),
			'href'   => pinora_board_invite_url(),
		)
	);
	$bar->add_node(
		array(
			'parent' => 'pinora',
			'id'     => 'pinora-settings',
			'title'  => esc_html__( 'Settings', 'pinora' ),
			'href'   => admin_url( 'tools.php?page=pinora' ),
		)
	);
}
add_action( 'admin_bar_menu', 'pinora_admin_bar', 80 );

/**
 * Labels used by the JavaScript, keyed by their English source.
 *
 * Kept here as plain `__()` calls so that they are picked up by the usual
 * translation tools and by translate.wordpress.org.
 *
 * @return array{i18n: array<string,string>, labels: array}
 */
function pinora_js_strings() {
	$i18n = array(
		/* translators: 1: number of notes to do, 2: in progress, 3: done. */
		'%1$d to do · %2$d in progress · %3$d done.' => __( '%1$d to do · %2$d in progress · %3$d done.', 'pinora' ),
		/* translators: number of open notes on the page. */
		'%d open' => __( '%d open', 'pinora' ),
		'(no section)' => __( '(no section)', 'pinora' ),
		'+ Note' => __( '+ Note', 'pinora' ),
		'Add' => __( 'Add', 'pinora' ),
		'Add a note' => __( 'Add a note', 'pinora' ),
		'Add or edit an answer' => __( 'Add or edit an answer', 'pinora' ),
		'All pages' => __( 'All pages', 'pinora' ),
		'All severities' => __( 'All severities', 'pinora' ),
		'Anonymous' => __( 'Anonymous', 'pinora' ),
		'Answer' => _x( 'Answer', 'Verb, button label.', 'pinora' ),
		'Answer the note' => __( 'Answer the note', 'pinora' ),
		'Answer:' => __( 'Answer:', 'pinora' ),
		'Board' => _x( 'Board', 'Noun, link to the feedback board.', 'pinora' ),
		'Both fields are required.' => __( 'Both fields are required.', 'pinora' ),
		'Cancel' => __( 'Cancel', 'pinora' ),
		'Click the element you want to comment on, or reach it with Tab and press Enter · Esc to cancel' => __( 'Click the element you want to comment on, or reach it with Tab and press Enter · Esc to cancel', 'pinora' ),
		'Click “Sign in” and enter the key that came with your review link.' => __( 'Click “Sign in” and enter the key that came with your review link.', 'pinora' ),
		'Close' => __( 'Close', 'pinora' ),
		'Close the list' => __( 'Close the list', 'pinora' ),
		'Continue' => __( 'Continue', 'pinora' ),
		'Copied.' => __( 'Copied.', 'pinora' ),
		'Copy' => __( 'Copy', 'pinora' ),
		/* translators: error message. */
		'Could not add: %s' => __( 'Could not add: %s', 'pinora' ),
		/* translators: error message. */
		'Could not delete: %s' => __( 'Could not delete: %s', 'pinora' ),
		/* translators: error message. */
		'Could not move: %s' => __( 'Could not move: %s', 'pinora' ),
		/* translators: error message. */
		'Could not save: %s' => __( 'Could not save: %s', 'pinora' ),
		/* translators: error message. */
		'Could not update: %s' => __( 'Could not update: %s', 'pinora' ),
		'Delete' => __( 'Delete', 'pinora' ),
		'Delete the note' => __( 'Delete the note', 'pinora' ),
		'Delete this note for the whole team?' => __( 'Delete this note for the whole team?', 'pinora' ),
		'Do not attach' => __( 'Do not attach', 'pinora' ),
		'Done' => __( 'Done', 'pinora' ),
		'Download' => __( 'Download', 'pinora' ),
		'Edit the answer' => __( 'Edit the answer', 'pinora' ),
		'Enlarge the screenshot' => __( 'Enlarge the screenshot', 'pinora' ),
		'Everyone' => __( 'Everyone', 'pinora' ),
		'Export' => __( 'Export', 'pinora' ),
		/* translators: number of notes. */
		'Export %d note(s)' => __( 'Export %d note(s)', 'pinora' ),
		'Pinora' => __( 'Pinora', 'pinora' ),
		'Pinora, a free plugin by' => __( 'Pinora, a free plugin by', 'pinora' ),
		'Feedback board' => __( 'Feedback board', 'pinora' ),
		/* translators: number of notes. */
		'Feedback · %d note(s)' => __( 'Feedback · %d note(s)', 'pinora' ),
		'Filter by page' => __( 'Filter by page', 'pinora' ),
		'Filter by person' => __( 'Filter by person', 'pinora' ),
		'Filter by severity' => __( 'Filter by severity', 'pinora' ),
		'Follows the current filters. Grouped by page then section: paste it as is into a ticket or an AI assistant.' => __( 'Follows the current filters. Grouped by page then section: paste it as is into a ticket or an AI assistant.', 'pinora' ),
		'Footer' => __( 'Footer', 'pinora' ),
		'Header' => __( 'Header', 'pinora' ),
		'In progress' => __( 'In progress', 'pinora' ),
		'In sync with the team' => __( 'In sync with the team', 'pinora' ),
		/* translators: error message. */
		'Key refused: %s' => __( 'Key refused: %s', 'pinora' ),
		'Leave review mode' => __( 'Leave review mode', 'pinora' ),
		'List' => _x( 'List', 'Noun, button that opens the list of notes.', 'pinora' ),
		'Mark as fixed' => __( 'Mark as fixed', 'pinora' ),
		/* translators: %s: column name, e.g. "In progress". */
		'Moved to %s' => __( 'Moved to %s', 'pinora' ),
		'Missing review key.' => __( 'Missing review key.', 'pinora' ),
		/* translators: column name, e.g. "In progress". */
		'Move to %s' => __( 'Move to %s', 'pinora' ),
		'New note' => __( 'New note', 'pinora' ),
		'New “To do” note' => __( 'New “To do” note', 'pinora' ),
		'No open note on this page. Click “+ Note” to add one.' => __( 'No open note on this page. Click “+ Note” to add one.', 'pinora' ),
		'No screenshot on this page: the note will be located by its position.' => __( 'No screenshot on this page: the note will be located by its position.', 'pinora' ),
		/* translators: pin number. */
		'Note %d' => __( 'Note %d', 'pinora' ),
		'Note saved, the whole team can see it.' => __( 'Note saved, the whole team can see it.', 'pinora' ),
		'Notes are shared: what one person pins shows up for everyone else, without reloading.' => __( 'Notes are shared: what one person pins shows up for everyone else, without reloading.', 'pinora' ),
		'Notes on this page' => __( 'Notes on this page', 'pinora' ),
		'Nothing to do. Add a note (+) or pin one right on the site.' => __( 'Nothing to do. Add a note (+) or pin one right on the site.', 'pinora' ),
		'Open the page at this spot' => __( 'Open the page at this spot', 'pinora' ),
		'Page (path)' => __( 'Page (path)', 'pinora' ),
		'Pin number on the page' => __( 'Pin number on the page', 'pinora' ),
		'Reopen' => __( 'Reopen', 'pinora' ),
		'Review key' => __( 'Review key', 'pinora' ),
		'Review the site →' => __( 'Review the site →', 'pinora' ),
		'Save' => __( 'Save', 'pinora' ),
		'Screenshot attached to the note' => __( 'Screenshot attached to the note', 'pinora' ),
		/* translators: pin number. */
		'Screenshot of note #%d' => __( 'Screenshot of note #%d', 'pinora' ),
		'Screenshot removed.' => __( 'Screenshot removed.', 'pinora' ),
		'Screenshot taken with the note' => __( 'Screenshot taken with the note', 'pinora' ),
		'Screenshot…' => __( 'Screenshot…', 'pinora' ),
		'Section (optional)' => __( 'Section (optional)', 'pinora' ),
		/* translators: section name, e.g. "Footer". */
		'Section: %s' => __( 'Section: %s', 'pinora' ),
		'Sending…' => __( 'Sending…', 'pinora' ),
		'Severity' => __( 'Severity', 'pinora' ),
		/* translators: number of closed notes. */
		'Show %d closed note(s)' => __( 'Show %d closed note(s)', 'pinora' ),
		'Sign in' => __( 'Sign in', 'pinora' ),
		/* translators: reviewer first name. */
		'Signed “%s”. ⌘/Ctrl + Enter to save.' => __( 'Signed “%s”. ⌘/Ctrl + Enter to save.', 'pinora' ),
		/* translators: error message. */
		'Sync failed: %s' => __( 'Sync failed: %s', 'pinora' ),
		'Taking a screenshot…' => __( 'Taking a screenshot…', 'pinora' ),
		'The browser refused to copy: the text is selected, press ⌘/Ctrl + C.' => __( 'The browser refused to copy: the text is selected, press ⌘/Ctrl + C.', 'pinora' ),
		'This note has been deleted in the meantime.' => __( 'This note has been deleted in the meantime.', 'pinora' ),
		'To do' => __( 'To do', 'pinora' ),
		'View on the page →' => __( 'View on the page →', 'pinora' ),
		'Who is reviewing?' => __( 'Who is reviewing?', 'pinora' ),
		/* translators: reviewer first name. */
		'You are %s' => __( 'You are %s', 'pinora' ),
		'Your first name' => __( 'Your first name', 'pinora' ),
		'Your note' => __( 'Your note', 'pinora' ),
		'Your first name signs your notes, so the team knows who spotted what. The key came with your review link.' => __( 'Your first name signs your notes, so the team knows who spotted what. The key came with your review link.', 'pinora' ),
		'answer' => _x( 'answer', 'Noun, label in the Markdown export.', 'pinora' ),
		'e.g. Camille' => __( 'e.g. Camille', 'pinora' ),
		'e.g. Footer' => __( 'e.g. Footer', 'pinora' ),
		'e.g. align the spacing between the result cards' => __( 'e.g. align the spacing between the result cards', 'pinora' ),
		'e.g. fixed, live in 3 minutes · or: won’t fix, because…' => __( 'e.g. fixed, live in 3 minutes · or: won’t fix, because…', 'pinora' ),
		'e.g. the button is too small on mobile, the text should say…' => __( 'e.g. the button is too small on mobile, the text should say…', 'pinora' ),
		/* translators: export date. */
		'exported %s' => __( 'exported %s', 'pinora' ),
		/* translators: pin number. */
		'pin #%d' => __( 'pin #%d', 'pinora' ),
		'screen' => _x( 'screen', 'Noun, label in the Markdown export.', 'pinora' ),
		'screenshot' => _x( 'screenshot', 'Noun, label in the Markdown export.', 'pinora' ),
		'selector' => _x( 'selector', 'Noun, CSS selector label in the Markdown export.', 'pinora' ),
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
		'labels' => pinora_labels(),
	);
}
