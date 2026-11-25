<?php
/**
 * Front-end submission forms for the three GBL post types, built on SCF/ACF's acf_form().
 * Shortcode: [gbl_form type="item"] | [gbl_form type="entry"] | [gbl_form type="nomination"]
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function gbl_fd_form_defs() {
	return array(
		'item'       => array(
			'post_type' => 'gbl_item',
			'fields'    => array(
				'field_gbl_item_club', 'field_gbl_item_section', 'field_gbl_item_type', 'field_gbl_item_more',
				'field_gbl_item_pdf', 'field_gbl_item_year', 'field_gbl_item_people', 'field_gbl_item_event',
				'field_gbl_item_source', 'field_gbl_item_contact', 'field_gbl_item_permission',
			),
		),
		'entry'      => array(
			'post_type' => 'gbl_entry',
			'fields'    => array( 'field_gbl_entry_club', 'field_gbl_entry_kind', 'field_gbl_entry_role', 'field_gbl_entry_years' ),
		),
		'nomination' => array(
			'post_type' => 'gbl_nomination',
			'fields'    => array(
				'field_gbl_nom_category', 'field_gbl_nom_clubs', 'field_gbl_nom_roles', 'field_gbl_nom_years',
				'field_gbl_nom_more', 'field_gbl_nom_sources', 'field_gbl_nom_family', 'field_gbl_nom_by',
				'field_gbl_nom_contact', 'field_gbl_nom_ack',
			),
		),
	);
}

// acf_form_head() must run before any output, so detect pages that hold a [gbl_form] early.
function gbl_fd_page_has_form() {
	if ( ! is_singular() ) {
		return false;
	}
	$id   = get_queried_object_id();
	$post = $id ? get_post( $id ) : null;
	if ( ! $post ) {
		return false;
	}
	if ( has_shortcode( $post->post_content, 'gbl_form' ) ) {
		return true;
	}
	$el = get_post_meta( $id, '_elementor_data', true );
	return is_string( $el ) && false !== strpos( $el, '[gbl_form' );
}
add_action( 'template_redirect', function () {
	if ( function_exists( 'acf_form_head' ) && is_user_logged_in() && gbl_fd_page_has_form() ) {
		acf_form_head();
	}
}, 5 );

function gbl_fd_form_notice( $html ) {
	return '<div class="gbl-fw"><div class="gbl-fw__note">' . $html . '</div></div>';
}

add_shortcode( 'gbl_form', 'gbl_fd_sc_form' );
function gbl_fd_sc_form( $atts ) {
	gbl_fd_enqueue();
	$a    = shortcode_atts( array( 'type' => 'item' ), $atts );
	$defs = gbl_fd_form_defs();
	if ( ! isset( $defs[ $a['type'] ] ) ) {
		return '';
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	$def  = $defs[ $a['type'] ];
	$dash = gbl_fd_dashboard_url();

	if ( ! function_exists( 'acf_form' ) ) {
		return gbl_fd_form_notice( 'This form needs the SCF (or ACF) plugin to be active.' );
	}
	if ( ! is_user_logged_in() ) {
		return gbl_fd_form_notice( 'Please <a href="' . esc_url( $dash ) . '">sign in</a> to add to the archive. The dashboard link above takes you to the login page.' );
	}
	if ( ! gbl_fd_can( 'gbl_submit_items' ) ) {
		return gbl_fd_form_notice( 'Your account is not allowed to submit content. Ask the GBL 80s Team for a club role.' );
	}

	$fields = $def['fields'];
	$club   = gbl_fd_user_club_id();
	if ( $club > 0 ) {
		// Club users never pick a club: it is assigned automatically (and enforced on save).
		$fields = array_values( array_diff( $fields, array( 'field_gbl_item_club', 'field_gbl_entry_club' ) ) );
	} elseif ( ! gbl_fd_is_global() && in_array( $a['type'], array( 'item', 'entry' ), true ) ) {
		return gbl_fd_form_notice( 'Your account is not linked to a club yet. Ask the GBL 80s Team to assign your club, then reload this page.' );
	}

	ob_start();
	echo '<div class="gbl-fw">';
	acf_form( array(
		'id'                 => 'gbl-form-' . $a['type'],
		'post_id'            => 'new_post',
		'new_post'           => array( 'post_type' => $def['post_type'], 'post_status' => 'pending' ),
		'post_title'         => true,
		'post_content'       => true,
		'fields'             => $fields,
		'submit_value'       => 'Submit for review',
		'return'             => add_query_arg( 'gbl_submitted', $a['type'], $dash ),
		'uploader'           => 'wp',
		'updated_message'    => false,
		'label_placement'    => 'top',
		'instruction_placement' => 'field',
	) );
	echo '</div>';
	return ob_get_clean();
}

// The first gallery image becomes the item's main image.
add_action( 'acf/save_post', function ( $post_id ) {
	if ( ! is_numeric( $post_id ) ) {
		return;
	}
	$post = get_post( $post_id );
	if ( ! $post || 'gbl_item' !== $post->post_type || has_post_thumbnail( $post_id ) ) {
		return;
	}
	$g = get_post_meta( $post_id, 'gbl_more_images', true );
	if ( is_array( $g ) && $g ) {
		set_post_thumbnail( $post_id, (int) reset( $g ) );
	}
}, 30 );

// Pre-fill the private contact fields from the signed-in account on front-end forms.
function gbl_fd_prefill_contact( $value ) {
	if ( $value || is_admin() || ! is_user_logged_in() ) {
		return $value;
	}
	$u = wp_get_current_user();
	return $u->display_name . ' - ' . $u->user_email;
}
add_filter( 'acf/load_value/name=gbl_contact', 'gbl_fd_prefill_contact' );
add_filter( 'acf/load_value/name=gbl_nom_nominator_contact', function ( $value ) {
	if ( $value || is_admin() || ! is_user_logged_in() ) {
		return $value;
	}
	return wp_get_current_user()->user_email;
} );
add_filter( 'acf/load_value/name=gbl_nom_nominator_name', function ( $value ) {
	if ( $value || is_admin() || ! is_user_logged_in() ) {
		return $value;
	}
	return wp_get_current_user()->display_name;
} );

// Buttons on the club page open the form with the club and section already chosen.
add_filter( 'acf/prepare_field/name=gbl_club_choice', function ( $field ) {
	if ( ! is_admin() && empty( $field['value'] ) && isset( $_GET['gbl_club'] ) ) {
		$id = absint( $_GET['gbl_club'] );
		if ( $id && term_exists( $id, 'gbl_club' ) ) {
			$field['value'] = $id;
		}
	}
	return $field;
} );
add_filter( 'acf/prepare_field/name=gbl_section_choice', function ( $field ) {
	if ( ! is_admin() && empty( $field['value'] ) && isset( $_GET['gbl_section'] ) ) {
		$term = get_term_by( 'slug', sanitize_key( wp_unslash( $_GET['gbl_section'] ) ), 'gbl_section' );
		if ( $term ) {
			$field['value'] = (int) $term->term_id;
		}
	}
	return $field;
} );
