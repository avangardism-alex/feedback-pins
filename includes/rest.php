<?php
/**
 * REST API used by the browser.
 *
 * - GET    /notes        every note, newest first
 * - POST   /notes        creates a note
 * - POST   /notes/{id}   updates a note (status, answer…)
 * - DELETE /notes/{id}   deletes a note
 * - GET    /strings      interface labels, public
 *
 * Updates go through POST rather than PATCH: some shared hosts filter the less
 * common verbs. The browser sends deletions as POST with
 * `X-HTTP-Method-Override: DELETE` for the same reason, WordPress handles it.
 *
 * @package FeedbackPins
 */

defined( 'ABSPATH' ) || exit;

/** REST namespace. */
const FPINS_REST_NS = 'feedback-pins/v1';

/**
 * Checks the key sent in the `X-Feedback-Pins-Key` header.
 *
 * It travels in a header rather than in the query string so that it never
 * ends up in the server access logs.
 *
 * @param WP_REST_Request $request REST request.
 * @return true|WP_Error
 */
function fpins_rest_permission( $request ) {
	$received = (string) $request->get_header( 'x_feedback_pins_key' );

	if ( '' !== $received && hash_equals( fpins_key(), $received ) ) {
		return true;
	}

	return new WP_Error( 'fpins_key', __( 'Missing or invalid review key.', 'feedback-pins' ), array( 'status' => 401 ) );
}

/**
 * Registers the routes.
 */
function fpins_register_routes() {
	register_rest_route(
		FPINS_REST_NS,
		'/notes',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'fpins_rest_list',
				'permission_callback' => 'fpins_rest_permission',
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'fpins_rest_create',
				'permission_callback' => 'fpins_rest_permission',
			),
		)
	);

	register_rest_route(
		FPINS_REST_NS,
		'/notes/(?P<id>\d+)',
		array(
			array(
				'methods'             => 'POST, PUT, PATCH',
				'callback'            => 'fpins_rest_update',
				'permission_callback' => 'fpins_rest_permission',
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => 'fpins_rest_delete',
				'permission_callback' => 'fpins_rest_permission',
			),
		)
	);

	register_rest_route(
		FPINS_REST_NS,
		'/strings',
		array(
			'methods'             => 'GET',
			'callback'            => 'fpins_rest_strings',
			// Interface labels only, nothing private.
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'fpins_register_routes' );

/**
 * REST response that is never cached: the list must reflect the present
 * moment so that everyone sees everyone else's notes.
 *
 * @param mixed $data   Response body.
 * @param int   $status HTTP status.
 * @return WP_REST_Response
 */
function fpins_rest_response( $data, $status = 200 ) {
	$response = new WP_REST_Response( $data, $status );
	$response->set_headers( wp_get_nocache_headers() );

	return $response;
}

/**
 * Loads an existing note or returns a 404 error.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_Post|WP_Error
 */
function fpins_rest_load( $request ) {
	$post = get_post( (int) $request['id'] );

	if ( ! $post || FPINS_CPT !== $post->post_type ) {
		return new WP_Error( 'fpins_not_found', __( 'Note not found (deleted in the meantime?).', 'feedback-pins' ), array( 'status' => 404 ) );
	}

	return $post;
}

/**
 * GET /notes
 *
 * @return WP_REST_Response
 */
function fpins_rest_list() {
	$posts = get_posts(
		array(
			'post_type'        => FPINS_CPT,
			'post_status'      => 'publish',
			/**
			 * Filters the maximum number of notes returned to the browser.
			 *
			 * @param int $limit Default 1000.
			 */
			'posts_per_page'   => (int) apply_filters( 'fpins_notes_limit', 1000 ),
			'orderby'          => 'date',
			'order'            => 'DESC',
		)
	);

	return fpins_rest_response( array( 'notes' => array_map( 'fpins_format', $posts ) ) );
}

/**
 * POST /notes
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function fpins_rest_create( $request ) {
	$data        = (array) $request->get_json_params();
	$description = fpins_sanitize( 'text', $data['description'] ?? '' );

	if ( '' === trim( (string) $description ) ) {
		return new WP_Error( 'fpins_empty', __( 'The note is empty.', 'feedback-pins' ), array( 'status' => 400 ) );
	}

	$id = wp_insert_post(
		array(
			'post_type'   => FPINS_CPT,
			'post_status' => 'publish',
			'post_title'  => fpins_title( $description ),
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		/* translators: %s: database error message. */
		return new WP_Error( 'fpins_write', sprintf( __( 'Could not save the note: %s', 'feedback-pins' ), $id->get_error_message() ), array( 'status' => 500 ) );
	}

	$data['status'] = 'open';
	unset( $data['fixedAt'] );
	fpins_save_fields( $id, $data );

	if ( ! empty( $data['screenshot'] ) && is_string( $data['screenshot'] ) ) {
		fpins_save_screenshot( $id, $data['screenshot'] );
	}

	$note = fpins_format( get_post( $id ) );

	/**
	 * Fires once a note has been created from the browser.
	 *
	 * @param array $note Formatted note.
	 */
	do_action( 'fpins_note_created', $note );

	fpins_slack_later( $note );

	return fpins_rest_response( array( 'note' => $note ), 201 );
}

/**
 * POST /notes/{id}
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function fpins_rest_update( $request ) {
	$post = fpins_rest_load( $request );

	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$data = (array) $request->get_json_params();

	// The fix date is set by the server, not by the reviewer's clock.
	if ( isset( $data['status'] ) ) {
		$data['fixedAt'] = 'fixed' === $data['status'] ? gmdate( 'c' ) : '';
	}

	fpins_save_fields( $post->ID, $data );

	$update = array( 'ID' => $post->ID );

	if ( isset( $data['description'] ) ) {
		$update['post_title'] = fpins_title( (string) $data['description'] );
	}

	// Touches the modification date, used to sort and to spot changes.
	wp_update_post( $update );

	return fpins_rest_response( array( 'note' => fpins_format( get_post( $post->ID ) ) ) );
}

/**
 * DELETE /notes/{id}
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function fpins_rest_delete( $request ) {
	$post = fpins_rest_load( $request );

	if ( is_wp_error( $post ) ) {
		return $post;
	}

	wp_delete_post( $post->ID, true );

	return fpins_rest_response( array( 'ok' => true ) );
}

/**
 * GET /strings
 *
 * The toolbar script is only downloaded by reviewers, so its labels are
 * fetched on demand instead of being printed on every page for every visitor.
 *
 * @return WP_REST_Response
 */
function fpins_rest_strings() {
	return new WP_REST_Response( fpins_js_strings() );
}
