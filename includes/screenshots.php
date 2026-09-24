<?php
/**
 * Screenshots attached to notes, stored in `uploads/feedback-pins/`.
 *
 * The file name carries a random part: the URL is public (Slack has to be
 * able to display it) but cannot be guessed.
 *
 * @package FeedbackPins
 */

defined( 'ABSPATH' ) || exit;

/** Maximum size of a decoded screenshot, in bytes. */
const FPINS_SCREENSHOT_MAX = 3 * 1024 * 1024;

/**
 * Screenshot folder, created on first use.
 *
 * @return array{dir: string, url: string}
 */
function fpins_screenshot_dir() {
	$uploads = wp_upload_dir( null, false );
	$dir     = trailingslashit( $uploads['basedir'] ) . 'feedback-pins';

	if ( ! is_dir( $dir ) && wp_mkdir_p( $dir ) ) {
		// No directory listing, even where the server allows it.
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	return array(
		'dir' => $dir,
		'url' => trailingslashit( $uploads['baseurl'] ) . 'feedback-pins',
	);
}

/**
 * Public URL of a screenshot from the file name stored in meta.
 *
 * @param string $file File name.
 * @return string
 */
function fpins_screenshot_url( $file ) {
	return fpins_screenshot_dir()['url'] . '/' . rawurlencode( basename( $file ) );
}

/**
 * Saves a screenshot sent as a data URL (JPEG or PNG).
 *
 * The content is checked to be a real image once written; anything else is
 * deleted straight away.
 *
 * @param int    $id      Note ID.
 * @param string $dataurl Encoded image, `data:image/jpeg;base64,…`.
 * @return bool
 */
function fpins_save_screenshot( $id, $dataurl ) {
	if ( ! preg_match( '#^data:image/(jpeg|png);base64,#', $dataurl, $m ) ) {
		return false;
	}

	// Decoding a data URL sent by the browser, not obfuscated code.
	$bytes = base64_decode( substr( $dataurl, strlen( $m[0] ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

	if ( false === $bytes || strlen( $bytes ) > FPINS_SCREENSHOT_MAX ) {
		return false;
	}

	$dir  = fpins_screenshot_dir();
	$ext  = 'png' === $m[1] ? 'png' : 'jpg';
	$file = sprintf( 'note-%d-%s.%s', $id, strtolower( wp_generate_password( 12, false, false ) ), $ext );
	$path = $dir['dir'] . '/' . $file;

	if ( false === file_put_contents( $path, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		fpins_log( 'screenshot not written for note ' . $id );
		return false;
	}

	$info = wp_getimagesize( $path );

	if ( ! $info || ! in_array( $info['mime'], array( 'image/jpeg', 'image/png' ), true ) ) {
		wp_delete_file( $path );
		return false;
	}

	update_post_meta( $id, '_fpins_screenshot', $file );

	return true;
}

/**
 * Deletes the screenshot together with its note, whether the note goes from
 * the board or from the WordPress admin.
 *
 * @param int $id ID of the post being deleted.
 */
function fpins_delete_screenshot( $id ) {
	if ( FPINS_CPT !== get_post_type( $id ) ) {
		return;
	}

	$file = get_post_meta( $id, '_fpins_screenshot', true );

	if ( $file ) {
		wp_delete_file( fpins_screenshot_dir()['dir'] . '/' . basename( $file ) );
	}
}
add_action( 'before_delete_post', 'fpins_delete_screenshot' );

/**
 * Writes to the PHP error log when `WP_DEBUG` is on. Never blocking.
 *
 * @param string $message Message.
 */
function fpins_log( $message ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[feedback-pins] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
