<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------ capabilities */
function gbl_fd_caps() {
	return array(
		'gbl_view_dashboard' => 'View the GBL 80s front-end dashboard',
		'gbl_submit_items'   => 'Submit archive items and players/teams (they stay Pending)',
		'gbl_review_club'    => 'Review and approve items for their own club only',
		'gbl_review_all'     => 'Review items, nominations and reports for every club',
		'gbl_manage_users'   => 'Assign clubs and roles to club members and contributors',
		'gbl_view_audit'     => 'View the audit log',
	);
}

function gbl_fd_install() {
	$base = array(
		'read'               => true,
		'edit_posts'         => true,
		'upload_files'       => true,
		'gbl_view_dashboard' => true,
		'gbl_submit_items'   => true,
	);
	$roles = array(
		'gbl_club_member'      => array( 'GBL Club Member', $base ),
		'gbl_club_contributor' => array( 'GBL Club Contributor', $base + array( 'gbl_review_club' => true ) ),
	);
	foreach ( $roles as $slug => $def ) {
		$role = get_role( $slug );
		if ( ! $role ) {
			add_role( $slug, $def[0], $def[1] );
		} else {
			foreach ( $def[1] as $cap => $grant ) {
				$role->add_cap( $cap );
			}
		}
	}
	foreach ( array( 'administrator', 'editor' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}
		foreach ( array_keys( gbl_fd_caps() ) as $cap ) {
			if ( 'gbl_review_club' === $cap ) {
				continue;
			}
			if ( 'editor' === $role_name && 'gbl_manage_users' === $cap ) {
				continue;
			}
			$role->add_cap( $cap );
		}
	}
	update_option( 'gbl_fd_version', GBL_FD_VERSION );
}

add_action( 'init', function () {
	if ( get_option( 'gbl_fd_version' ) !== GBL_FD_VERSION ) {
		gbl_fd_install();
	}
} );

// Show the capabilities inside the Members plugin role editor.
add_action( 'members_register_cap_groups', function () {
	if ( function_exists( 'members_register_cap_group' ) ) {
		members_register_cap_group( 'gbl80s', array( 'label' => 'GBL 80s', 'icon' => 'dashicons-groups', 'priority' => 5 ) );
	}
} );
add_action( 'members_register_caps', function () {
	if ( ! function_exists( 'members_register_cap' ) ) {
		return;
	}
	foreach ( gbl_fd_caps() as $cap => $label ) {
		members_register_cap( $cap, array( 'label' => $label, 'group' => 'gbl80s' ) );
	}
} );

/* ----------------------------------------------------------------- helpers */
function gbl_fd_can( $cap ) {
	return is_user_logged_in() && ( current_user_can( 'manage_options' ) || current_user_can( $cap ) );
}

// "Global" viewers (GBL 80s Team, administrators) see every club.
function gbl_fd_is_global() {
	return gbl_fd_can( 'gbl_review_all' );
}

// 0 = every club, -1 = no club assigned yet, >0 = the viewer's club term id.
function gbl_fd_scope() {
	if ( gbl_fd_is_global() ) {
		return 0;
	}
	$club = (int) get_user_meta( get_current_user_id(), 'gbl_club', true );
	return $club > 0 ? $club : -1;
}

function gbl_fd_dashboard_url() {
	$page = get_page_by_path( 'dashboard' );
	if ( $page ) {
		return get_permalink( $page );
	}
	return home_url( '/dashboard/' );
}

function gbl_fd_view_url( $view, $args = array() ) {
	$args = array_merge( array( 'gbl_view' => $view ), $args );
	if ( 'dashboard' === $view ) {
		unset( $args['gbl_view'] );
	}
	return add_query_arg( $args, gbl_fd_dashboard_url() );
}

function gbl_fd_settings() {
	return wp_parse_args( get_option( 'gbl_fd_settings', array() ), array( 'add_item_url' => '', 'add_entry_url' => '' ) );
}

function gbl_fd_role_label( $user = null ) {
	$user  = $user ? $user : wp_get_current_user();
	$names = wp_roles()->role_names;
	foreach ( (array) $user->roles as $role ) {
		if ( isset( $names[ $role ] ) ) {
			return translate_user_role( $names[ $role ] );
		}
	}
	return 'User';
}

function gbl_fd_division( $slug ) {
	$east = array( 'bairnsdale', 'stratford', 'sale', 'maffra', 'heyfield', 'traralgon', 'morwell', 'moe' );
	$west = array( 'pakenham', 'sherbrooke', 'warragul', 'poowong', 'korumburra', 'leongatha', 'wonthaggi' );
	if ( in_array( $slug, $east, true ) ) {
		return 'East';
	}
	if ( in_array( $slug, $west, true ) ) {
		return 'West';
	}
	return 'Representative';
}

function gbl_fd_set_field( $name, $value, $post_id ) {
	if ( function_exists( 'update_field' ) ) {
		update_field( $name, $value, $post_id );
	} else {
		update_post_meta( $post_id, $name, $value );
	}
}

function gbl_fd_icon( $name, $size = 20 ) {
	$p = array(
		'home'     => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
		'image'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="1.6"/><path d="M21 16l-5-5-9 9"/>',
		'users'    => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="9" r="2.4"/><path d="M17 14c2.8 0 5 2.2 5 5"/>',
		'trophy'   => '<path d="M8 4h8v5a4 4 0 01-8 0z"/><path d="M8 6H4v1a4 4 0 004 4M16 6h4v1a4 4 0 01-4 4"/><path d="M12 13v4M8 21h8M10 17h4"/>',
		'star'     => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
		'alert'    => '<path d="M12 3L2 20h20z"/><path d="M12 10v4M12 17.5v.1"/>',
		'news'     => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
		'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>',
		'history'  => '<path d="M3 12a9 9 0 109-9 9 9 0 00-6.4 2.6L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
		'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
		'search'   => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
		'bell'     => '<path d="M6 8a6 6 0 0112 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10.3 21a1.9 1.9 0 003.4 0"/>',
		'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
		'chevron'  => '<path d="M6 9l6 6 6-6"/>',
		'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
		'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'inbox'    => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M8 15h8"/>',
		'file'     => '<path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
		'up'       => '<path d="M12 19V5M5 12l7-7 7 7"/>',
		'right'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
		'more'     => '<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>',
		'logout'   => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>',
		'plus'     => '<path d="M12 5v14M5 12h14"/>',
		'x'        => '<path d="M6 6l12 12M18 6L6 18"/>',
		'menu'     => '<path d="M4 6h16M4 12h16M4 18h16"/>',
		'external' => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5"/>',
	);
	$inner = isset( $p[ $name ] ) ? $p[ $name ] : '';
	return '<svg class="gbl-i" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}

function gbl_fd_ball( $size = 48 ) {
	return '<svg class="gbl-ball" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="22" fill="#f08a1c"/><g stroke="#3a1d08" stroke-width="2" fill="none"><path d="M2 24h44M24 2v44"/><path d="M7 9c9 6 9 24 0 30M41 9c-9 6-9 24 0 30"/></g></svg>';
}
