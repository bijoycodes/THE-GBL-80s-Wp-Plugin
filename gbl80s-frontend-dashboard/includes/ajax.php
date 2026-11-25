<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function gbl_fd_fail( $message, $code = 400 ) {
	wp_send_json_error( array( 'message' => $message ), $code );
}

function gbl_fd_post_input( $key, $default = '' ) {
	return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default;
}

// Loads a post the viewer may open, or stops the request.
function gbl_fd_load_post_or_fail( $id ) {
	$post = $id ? get_post( $id ) : null;
	if ( ! $post || ! in_array( $post->post_type, array( 'gbl_item', 'gbl_entry', 'gbl_nomination', 'gbl_report' ), true ) ) {
		gbl_fd_fail( 'Item not found.', 404 );
	}
	if ( ! gbl_fd_can_view_post( $post ) ) {
		gbl_fd_fail( 'You do not have access to this item.', 403 );
	}
	return $post;
}

/* ------------------------------------------------------------ item details */
add_action( 'wp_ajax_gbl_fd_detail', function () {
	check_ajax_referer( 'gbl_fd', 'nonce' );
	$post = gbl_fd_load_post_or_fail( absint( gbl_fd_post_input( 'id', 0 ) ) );
	wp_send_json_success( array( 'html' => gbl_fd_detail_html( $post ) ) );
} );

/* ----------------------------------------------------- approve / decline etc */
add_action( 'wp_ajax_gbl_fd_review', function () {
	check_ajax_referer( 'gbl_fd', 'nonce' );
	$post = gbl_fd_load_post_or_fail( absint( gbl_fd_post_input( 'id', 0 ) ) );
	if ( ! in_array( $post->post_type, array( 'gbl_item', 'gbl_entry', 'gbl_nomination' ), true ) ) {
		gbl_fd_fail( 'This item type cannot be reviewed here.' );
	}
	if ( ! gbl_fd_can_review_post( $post ) ) {
		gbl_fd_fail( 'You cannot review this item. Club reviewers cannot approve their own submissions.', 403 );
	}
	if ( 'pending' !== $post->post_status && 'remove' !== sanitize_key( gbl_fd_post_input( 'decision' ) ) ) {
		gbl_fd_fail( 'This item is no longer pending.' );
	}
	$decision = sanitize_key( gbl_fd_post_input( 'decision' ) );
	$note     = sanitize_textarea_field( gbl_fd_post_input( 'note' ) );
	$is_item  = ( 'gbl_item' === $post->post_type );
	$allowed  = $is_item ? array( 'approve', 'corrections', 'decline', 'remove' ) : array( 'approve', 'decline', 'remove' );
	if ( ! in_array( $decision, $allowed, true ) ) {
		gbl_fd_fail( 'Unknown decision.' );
	}
	if ( 'remove' === $decision && ! gbl_fd_is_global() ) {
		gbl_fd_fail( 'Only the GBL 80s Team can remove items.', 403 );
	}
	if ( in_array( $decision, array( 'corrections', 'decline' ), true ) && '' === trim( $note ) ) {
		gbl_fd_fail( 'Add a short note so the contributor knows why.' );
	}

	$GLOBALS['gbl_fd_decision'] = array( 'act' => $decision, 'note' => $note );
	$message = '';

	if ( 'approve' === $decision ) {
		$GLOBALS['gbl_fd_approving'] = true;
		$result = wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'publish' ), true );
		unset( $GLOBALS['gbl_fd_approving'] );
		if ( is_wp_error( $result ) ) {
			gbl_fd_fail( $result->get_error_message(), 500 );
		}
		if ( $is_item ) {
			gbl_fd_set_field( 'gbl_review_outcome', '', $post->ID );
		}
		$message = 'Approved and published.';
	} elseif ( 'remove' === $decision ) {
		wp_trash_post( $post->ID );
		$message = 'Moved to Trash.';
	} else {
		$result = wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' ), true );
		if ( is_wp_error( $result ) ) {
			gbl_fd_fail( $result->get_error_message(), 500 );
		}
		if ( $is_item ) {
			gbl_fd_set_field( 'gbl_review_outcome', 'corrections' === $decision ? 'corrections_requested' : 'declined', $post->ID );
			gbl_fd_set_field( 'gbl_review_note', $note, $post->ID );
		} elseif ( 'gbl_nomination' === $post->post_type ) {
			gbl_fd_set_field( 'gbl_nom_editor_note', $note, $post->ID );
		} else {
			update_post_meta( $post->ID, 'gbl_review_note', $note );
		}
		$message = ( 'corrections' === $decision ) ? 'Corrections requested.' : 'Declined.';
	}
	unset( $GLOBALS['gbl_fd_decision'] );
	wp_send_json_success( array( 'message' => $message ) );
} );

/* -------------------------------------------------------- resolve a report */
add_action( 'wp_ajax_gbl_fd_resolve', function () {
	check_ajax_referer( 'gbl_fd', 'nonce' );
	if ( ! gbl_fd_is_global() ) {
		gbl_fd_fail( 'Only the GBL 80s Team can resolve reports.', 403 );
	}
	$post = get_post( absint( gbl_fd_post_input( 'id', 0 ) ) );
	if ( ! $post || 'gbl_report' !== $post->post_type ) {
		gbl_fd_fail( 'Report not found.', 404 );
	}
	wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'publish' ) );
	wp_send_json_success( array( 'message' => 'Report marked as resolved.' ) );
} );

/* -------------------------------------- assign club / role to a club account */
add_action( 'wp_ajax_gbl_fd_user_update', function () {
	check_ajax_referer( 'gbl_fd', 'nonce' );
	if ( ! gbl_fd_can( 'gbl_manage_users' ) ) {
		gbl_fd_fail( 'You cannot manage users.', 403 );
	}
	$user = get_userdata( absint( gbl_fd_post_input( 'user_id', 0 ) ) );
	$role = sanitize_key( gbl_fd_post_input( 'role' ) );
	$club = absint( gbl_fd_post_input( 'club', 0 ) );
	if ( ! $user ) {
		gbl_fd_fail( 'User not found.', 404 );
	}
	// Only touch club accounts. Administrators and editors are never changed here.
	$club_roles = array( 'gbl_club_member', 'gbl_club_contributor' );
	if ( ! array_intersect( $club_roles, (array) $user->roles ) ) {
		gbl_fd_fail( 'This account is not a club member or contributor.', 403 );
	}
	if ( ! in_array( $role, $club_roles, true ) ) {
		gbl_fd_fail( 'Choose Club Member or Club Contributor.' );
	}
	if ( $club > 0 && ( ! term_exists( $club, 'gbl_club' ) ) ) {
		gbl_fd_fail( 'Club not found.' );
	}
	$user->set_role( $role );
	update_user_meta( $user->ID, 'gbl_club', $club );
	wp_send_json_success( array( 'message' => 'Saved.' ) );
} );

/* ------------------------------------------------------------ plugin settings */
add_action( 'wp_ajax_gbl_fd_settings', function () {
	check_ajax_referer( 'gbl_fd', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		gbl_fd_fail( 'Administrators only.', 403 );
	}
	update_option( 'gbl_fd_settings', array(
		'add_item_url'  => esc_url_raw( gbl_fd_post_input( 'add_item_url' ) ),
		'add_entry_url' => esc_url_raw( gbl_fd_post_input( 'add_entry_url' ) ),
	), false );
	wp_send_json_success( array( 'message' => 'Settings saved.' ) );
} );

/* ----------------------------------------------------- club profile (description) */
add_action( 'wp_ajax_gbl_fd_club_profile', function () {
	check_ajax_referer( 'gbl_fd', 'nonce' );
	$club_id = absint( gbl_fd_post_input( 'club', 0 ) );
	if ( ! gbl_fd_can_edit_club( $club_id ) ) {
		gbl_fd_fail( 'You cannot edit this club.', 403 );
	}
	$term = get_term( $club_id, 'gbl_club' );
	$page = ( $term && ! is_wp_error( $term ) ) ? gbl_fd_club_page_for_term( $term ) : null;
	if ( ! $page ) {
		gbl_fd_fail( 'The club page does not exist yet. Ask an administrator to run Create / repair club pages.', 404 );
	}
	$desc = sanitize_textarea_field( gbl_fd_post_input( 'description' ) );
	$desc = function_exists( 'mb_substr' ) ? mb_substr( $desc, 0, 1200 ) : substr( $desc, 0, 1200 );
	gbl_fd_set_field( 'gbl_club_description', $desc, $page->ID );
	$hist = sanitize_textarea_field( gbl_fd_post_input( 'history' ) );
	$hist = function_exists( 'mb_substr' ) ? mb_substr( $hist, 0, 4000 ) : substr( $hist, 0, 4000 );
	gbl_fd_set_field( 'gbl_club_history', $hist, $page->ID );
	wp_send_json_success( array( 'message' => 'Club profile saved.' ) );
} );

add_action( 'wp_ajax_gbl_fd_repair_clubs', function () {
	check_ajax_referer( 'gbl_fd', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		gbl_fd_fail( 'Administrators only.', 403 );
	}
	$n = gbl_fd_ensure_club_pages();
	wp_send_json_success( array( 'message' => $n ? $n . ' page(s) created.' : 'All club pages already exist.' ) );
} );
