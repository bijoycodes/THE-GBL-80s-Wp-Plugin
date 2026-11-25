<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------- login / wp-admin access */
function gbl_fd_is_team_user( $user = null ) {
	$user = $user ? $user : wp_get_current_user();
	return user_can( $user, 'edit_others_posts' ) || user_can( $user, 'manage_options' );
}

// Club members and contributors never need wp-admin: send them to the dashboard.
add_action( 'admin_init', function () {
	if ( ! is_user_logged_in() || wp_doing_ajax() || gbl_fd_is_team_user() ) {
		return;
	}
	global $pagenow;
	$allowed = array( 'admin-post.php', 'async-upload.php', 'media-upload.php', 'profile.php' );
	if ( in_array( $pagenow, $allowed, true ) ) {
		return;
	}
	wp_safe_redirect( gbl_fd_dashboard_url() );
	exit;
} );

add_filter( 'show_admin_bar', function ( $show ) {
	if ( is_user_logged_in() && ! gbl_fd_is_team_user() ) {
		return false;
	}
	return $show;
} );

add_filter( 'login_redirect', function ( $redirect, $requested, $user ) {
	if ( $user && ! is_wp_error( $user ) && ! gbl_fd_is_team_user( $user ) ) {
		return gbl_fd_dashboard_url();
	}
	return $redirect;
}, 10, 3 );

// A failed login on the front-end form comes back to the dashboard with a message.
add_action( 'wp_login_failed', function () {
	$ref  = wp_get_referer();
	$dash = gbl_fd_dashboard_url();
	if ( $ref && 0 === strpos( $ref, $dash ) ) {
		wp_safe_redirect( add_query_arg( 'gbl_login', 'failed', $dash ) );
		exit;
	}
} );

/* ------------------------------------------- submissions always start Pending */
add_filter( 'wp_insert_post_data', function ( $data, $postarr ) {
	if ( ! empty( $GLOBALS['gbl_fd_approving'] ) ) {
		return $data;
	}
	if ( ! in_array( $data['post_type'], array( 'gbl_item', 'gbl_entry' ), true ) || ! is_user_logged_in() ) {
		return $data;
	}
	if ( gbl_fd_can( 'gbl_review_all' ) ) {
		return $data;
	}
	if ( in_array( $data['post_status'], array( 'publish', 'future' ), true ) ) {
		$data['post_status'] = 'pending';
	}
	return $data;
}, 10, 2 );

/* ----------------------------------------------------- club locking (saves) */
function gbl_fd_user_club_id() {
	if ( ! is_user_logged_in() || gbl_fd_is_global() ) {
		return 0;
	}
	return (int) get_user_meta( get_current_user_id(), 'gbl_club', true );
}

function gbl_fd_enforce_club( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || ! in_array( $post->post_type, array( 'gbl_item', 'gbl_entry' ), true ) ) {
		return;
	}
	$club = gbl_fd_user_club_id();
	if ( $club > 0 ) {
		wp_set_object_terms( $post_id, array( $club ), 'gbl_club', false );
		update_post_meta( $post_id, 'gbl_club_choice', $club );
	}
}
add_action( 'acf/save_post', function ( $post_id ) {
	if ( is_numeric( $post_id ) ) {
		gbl_fd_enforce_club( (int) $post_id );
	}
}, 20 );
add_action( 'save_post_gbl_item', 'gbl_fd_enforce_club', 99 );
add_action( 'save_post_gbl_entry', 'gbl_fd_enforce_club', 99 );

// Best effort in the form UI: only offer the user's own club.
add_filter( 'acf/fields/taxonomy/query/name=gbl_club_choice', function ( $args ) {
	$club = gbl_fd_user_club_id();
	if ( $club > 0 ) {
		$args['include'] = array( $club );
	}
	return $args;
} );

// Review fields can only be changed by the team (and through the dashboard).
function gbl_fd_review_field_lock( $field ) {
	if ( ! gbl_fd_is_global() && ! gbl_fd_can( 'gbl_review_club' ) ) {
		$field['disabled'] = 1;
	}
	return $field;
}
add_filter( 'acf/prepare_field/name=gbl_review_outcome', 'gbl_fd_review_field_lock' );
add_filter( 'acf/prepare_field/name=gbl_review_note', 'gbl_fd_review_field_lock' );
add_filter( 'acf/prepare_field/name=gbl_nom_editor_note', function ( $field ) {
	return gbl_fd_is_global() ? $field : false;
} );

/* ----------------------------------------------- user profile: assign a club */
function gbl_fd_user_club_field( $user ) {
	if ( ! current_user_can( 'edit_users' ) ) {
		return;
	}
	$terms   = get_terms( array( 'taxonomy' => 'gbl_club', 'hide_empty' => false ) );
	$current = (int) get_user_meta( $user->ID, 'gbl_club', true );
	echo '<h2>GBL 80s</h2><table class="form-table"><tr><th><label for="gbl_club">Club or team</label></th><td>';
	echo '<select name="gbl_club" id="gbl_club"><option value="0">None</option>';
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $t ) {
			printf( '<option value="%d"%s>%s</option>', (int) $t->term_id, selected( $current, $t->term_id, false ), esc_html( $t->name ) );
		}
	}
	echo '</select><p class="description">Club members and contributors only see and add content for this club.</p></td></tr></table>';
}
add_action( 'show_user_profile', 'gbl_fd_user_club_field' );
add_action( 'edit_user_profile', 'gbl_fd_user_club_field' );

function gbl_fd_save_user_club( $user_id ) {
	if ( current_user_can( 'edit_users' ) && isset( $_POST['gbl_club'] ) ) {
		update_user_meta( $user_id, 'gbl_club', absint( $_POST['gbl_club'] ) );
	}
}
add_action( 'personal_options_update', 'gbl_fd_save_user_club' );
add_action( 'edit_user_profile_update', 'gbl_fd_save_user_club' );

/* ------------------------------------------------ reports (corrections/removal) */
add_action( 'init', function () {
	register_post_type( 'gbl_report', array(
		'labels'       => array( 'name' => 'Reports', 'singular_name' => 'Report', 'menu_name' => 'Reports' ),
		'public'       => false,
		'show_ui'      => true,
		'show_in_menu' => true,
		'menu_icon'    => 'dashicons-warning',
		'supports'     => array( 'title', 'editor' ),
		'show_in_rest' => false,
	) );
} );

add_shortcode( 'gbl_report_form', function () {
	gbl_fd_enqueue();
	$ok = isset( $_GET['gbl_reported'] );
	ob_start();
	if ( $ok ) {
		echo '<p class="gbl-rf__ok">Thank you. Your report has been sent to the GBL 80s Team.</p>';
	}
	?>
	<form class="gbl-rf" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="gbl_fd_report">
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( get_permalink() ); ?>">
		<?php wp_nonce_field( 'gbl_fd_report', 'gbl_fd_rn' ); ?>
		<p style="position:absolute;left:-9999px" aria-hidden="true"><label>Website <input type="text" name="gbl_website" tabindex="-1" autocomplete="off"></label></p>
		<label>Which item? (title or page link)<input type="text" name="item" required maxlength="300" value="<?php echo esc_attr( isset( $_GET['item'] ) ? sanitize_text_field( wp_unslash( $_GET['item'] ) ) : '' ); ?>"></label>
		<label>What needs to change?<textarea name="message" rows="5" required maxlength="3000"></textarea></label>
		<label>Your email or phone (optional)<input type="text" name="contact" maxlength="200"></label>
		<button type="submit">Send report</button>
	</form>
	<?php
	return ob_get_clean();
} );

function gbl_fd_handle_report() {
	$back = isset( $_POST['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ), home_url( '/' ) ) : home_url( '/' );
	if ( ! isset( $_POST['gbl_fd_rn'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gbl_fd_rn'] ) ), 'gbl_fd_report' ) ) {
		wp_safe_redirect( $back );
		exit;
	}
	if ( ! empty( $_POST['gbl_website'] ) ) { // honeypot
		wp_safe_redirect( add_query_arg( 'gbl_reported', '1', $back ) );
		exit;
	}
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'gbl_fd_rl_' . md5( $ip );
	if ( get_transient( $key ) ) {
		wp_safe_redirect( $back );
		exit;
	}
	set_transient( $key, 1, 60 );
	$item    = isset( $_POST['item'] ) ? sanitize_text_field( wp_unslash( $_POST['item'] ) ) : '';
	$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
	$contact = isset( $_POST['contact'] ) ? sanitize_text_field( wp_unslash( $_POST['contact'] ) ) : '';
	if ( '' !== $item && '' !== $message ) {
		$id = wp_insert_post( array(
			'post_type'    => 'gbl_report',
			'post_status'  => 'pending',
			'post_title'   => wp_html_excerpt( $item, 120 ),
			'post_content' => $message,
		) );
		if ( $id && ! is_wp_error( $id ) ) {
			update_post_meta( $id, 'gbl_report_contact', $contact );
		}
	}
	wp_safe_redirect( add_query_arg( 'gbl_reported', '1', $back ) );
	exit;
}
add_action( 'admin_post_nopriv_gbl_fd_report', 'gbl_fd_handle_report' );
add_action( 'admin_post_gbl_fd_report', 'gbl_fd_handle_report' );

/* ---------------------- public Elementor Pro forms -> pending posts (optional) */
function gbl_fd_term_ids( $names, $taxonomy ) {
	$ids = array();
	foreach ( array_filter( array_map( 'trim', (array) $names ) ) as $name ) {
		$term = get_term_by( 'name', $name, $taxonomy );
		if ( $term ) {
			$ids[] = (int) $term->term_id;
		}
	}
	return $ids;
}

function gbl_fd_files_html( $value ) {
	$urls = array_filter( array_map( 'trim', preg_split( '/[,\s]+/', (string) $value ) ) );
	if ( ! $urls ) {
		return '';
	}
	$out = '<p><strong>Uploaded files</strong></p><ul>';
	foreach ( $urls as $url ) {
		$out .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( basename( $url ) ) . '</a></li>';
	}
	return $out . '</ul>';
}

add_action( 'elementor_pro/forms/new_record', function ( $record, $handler ) {
	$form = $record->get_form_settings( 'form_name' );
	$f    = array();
	foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
		$f[ $id ] = isset( $field['value'] ) ? $field['value'] : '';
	}
	$val = function ( $key ) use ( $f ) {
		return isset( $f[ $key ] ) ? sanitize_text_field( $f[ $key ] ) : '';
	};
	$body = ( isset( $f['story'] ) ? wp_kses_post( wpautop( $f['story'] ) ) : '' ) . ( isset( $f['files'] ) ? gbl_fd_files_html( $f['files'] ) : '' );

	if ( 'GBL 80s contribution' === $form ) {
		$post_id = wp_insert_post( array(
			'post_type'    => 'gbl_item',
			'post_status'  => 'pending',
			'post_title'   => $val( 'title' ) ? $val( 'title' ) : 'Untitled submission',
			'post_content' => $body,
		), true );
		if ( is_wp_error( $post_id ) ) {
			return;
		}
		$type    = $val( 'content_type' );
		$section = array(
			'Social' => 'Social', 'Junior history' => 'Juniors', 'Newspaper clipping' => 'Press',
			'Programme' => 'Press', 'Scorecard' => 'Press', 'Grand final' => 'Press',
		);
		wp_set_object_terms( $post_id, gbl_fd_term_ids( $val( 'club' ), 'gbl_club' ), 'gbl_club' );
		wp_set_object_terms( $post_id, gbl_fd_term_ids( $type, 'gbl_material_type' ), 'gbl_material_type' );
		if ( isset( $section[ $type ] ) ) {
			wp_set_object_terms( $post_id, gbl_fd_term_ids( $section[ $type ], 'gbl_section' ), 'gbl_section' );
		}
		gbl_fd_set_field( 'gbl_year', $val( 'year' ), $post_id );
		gbl_fd_set_field( 'gbl_people', $val( 'people' ), $post_id );
		gbl_fd_set_field( 'gbl_event', $val( 'event' ), $post_id );
		gbl_fd_set_field( 'gbl_source', $val( 'source' ), $post_id );
		gbl_fd_set_field( 'gbl_contact', trim( $val( 'your_name' ) . ' - ' . $val( 'your_contact' ), ' -' ), $post_id );
		gbl_fd_set_field( 'gbl_permission', 1, $post_id );
	}

	if ( 'GBL 80s nomination' === $form ) {
		$post_id = wp_insert_post( array(
			'post_type'    => 'gbl_nomination',
			'post_status'  => 'pending',
			'post_title'   => $val( 'nominee' ) ? $val( 'nominee' ) : 'Unnamed nominee',
			'post_content' => $body,
		), true );
		if ( is_wp_error( $post_id ) ) {
			return;
		}
		wp_set_object_terms( $post_id, gbl_fd_term_ids( $val( 'category' ), 'gbl_nomination_category' ), 'gbl_nomination_category' );
		wp_set_object_terms( $post_id, gbl_fd_term_ids( $val( 'clubs' ), 'gbl_club' ), 'gbl_club' );
		gbl_fd_set_field( 'gbl_nom_roles', $val( 'roles' ), $post_id );
		gbl_fd_set_field( 'gbl_nom_years', $val( 'years' ), $post_id );
		gbl_fd_set_field( 'gbl_nom_sources', $val( 'sources' ), $post_id );
		gbl_fd_set_field( 'gbl_nom_family_consulted', $val( 'family' ) ? 1 : 0, $post_id );
		gbl_fd_set_field( 'gbl_nom_nominator_name', $val( 'nominator_name' ), $post_id );
		gbl_fd_set_field( 'gbl_nom_nominator_contact', $val( 'nominator_contact' ), $post_id );
		gbl_fd_set_field( 'gbl_nom_acknowledged', 1, $post_id );
	}
}, 10, 2 );
