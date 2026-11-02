<?php
/**
 * Removes everything the plugin stored: notes, screenshots, options.
 *
 * Runs only when the plugin is deleted from the Plugins screen, not when it is
 * deactivated.
 *
 * @package Pinora
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Cleans the current site.
 */
function pinora_uninstall_site() {
	$ids = get_posts(
		array(
			'post_type'        => 'pinora_note',
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
		)
	);

	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}

	foreach ( array( 'pinora_key', 'pinora_slack_webhook', 'pinora_credit' ) as $option ) {
		delete_option( $option );
	}

	$uploads = wp_upload_dir( null, false );
	$dir     = trailingslashit( $uploads['basedir'] ) . 'pinora';

	if ( is_dir( $dir ) ) {
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			wp_delete_file( $file );
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $pinora_site_id ) {
		switch_to_blog( $pinora_site_id );
		pinora_uninstall_site();
		restore_current_blog();
	}
} else {
	pinora_uninstall_site();
}
