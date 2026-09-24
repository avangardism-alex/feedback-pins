<?php
/**
 * Removes everything the plugin stored: notes, screenshots, options.
 *
 * Runs only when the plugin is deleted from the Plugins screen, not when it is
 * deactivated.
 *
 * @package FeedbackPins
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Cleans the current site.
 */
function fpins_uninstall_site() {
	$ids = get_posts(
		array(
			'post_type'        => 'fpins_note',
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
		)
	);

	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}

	foreach ( array( 'fpins_key', 'fpins_slack_webhook', 'fpins_credit' ) as $option ) {
		delete_option( $option );
	}

	$uploads = wp_upload_dir( null, false );
	$dir     = trailingslashit( $uploads['basedir'] ) . 'feedback-pins';

	if ( is_dir( $dir ) ) {
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			wp_delete_file( $file );
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $fpins_site_id ) {
		switch_to_blog( $fpins_site_id );
		fpins_uninstall_site();
		restore_current_blog();
	}
} else {
	fpins_uninstall_site();
}
