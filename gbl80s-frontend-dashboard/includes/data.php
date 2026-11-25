<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------- scope */
// Adds the viewer's club restriction to WP_Query args. Global viewers are unrestricted.
function gbl_fd_apply_scope( array $args ) {
	$scope = gbl_fd_scope();
	if ( 0 === $scope ) {
		return $args;
	}
	if ( $scope < 0 ) {
		$args['post__in'] = array( 0 );
		return $args;
	}
	$tax   = isset( $args['tax_query'] ) ? $args['tax_query'] : array();
	$tax[] = array( 'taxonomy' => 'gbl_club', 'field' => 'term_id', 'terms' => array( $scope ) );
	$args['tax_query'] = $tax;
	return $args;
}

function gbl_fd_item_club( $post_id ) {
	$terms = get_the_terms( $post_id, 'gbl_club' );
	if ( $terms && ! is_wp_error( $terms ) ) {
		return reset( $terms );
	}
	return null;
}

// May the current viewer see this post at all?
function gbl_fd_can_view_post( $post ) {
	if ( ! $post || ! gbl_fd_can( 'gbl_view_dashboard' ) ) {
		return false;
	}
	if ( gbl_fd_is_global() ) {
		return true;
	}
	if ( ! in_array( $post->post_type, array( 'gbl_item', 'gbl_entry' ), true ) ) {
		return false;
	}
	$scope = gbl_fd_scope();
	$club  = gbl_fd_item_club( $post->ID );
	return $scope > 0 && $club && (int) $club->term_id === $scope;
}

// May the current viewer approve / decline this post?
function gbl_fd_can_review_post( $post ) {
	if ( ! $post ) {
		return false;
	}
	if ( gbl_fd_is_global() ) {
		return true;
	}
	if ( ! in_array( $post->post_type, array( 'gbl_item', 'gbl_entry' ), true ) || ! gbl_fd_can( 'gbl_review_club' ) ) {
		return false;
	}
	if ( ! gbl_fd_can_view_post( $post ) ) {
		return false;
	}
	// Separation of duties: nobody approves their own submission (except the GBL 80s Team).
	$allow_self = apply_filters( 'gbl_fd_allow_self_review', false, $post );
	if ( ! $allow_self && (int) $post->post_author === get_current_user_id() ) {
		return false;
	}
	return true;
}

/* ------------------------------------------------------------------ counts */
function gbl_fd_count( $type, $status ) {
	$args = gbl_fd_apply_scope( array(
		'post_type'      => $type,
		'post_status'    => $status,
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );
	$q = new WP_Query( $args );
	return (int) $q->found_posts;
}

function gbl_fd_count_this_month( $type, $status ) {
	$args = gbl_fd_apply_scope( array(
		'post_type'      => $type,
		'post_status'    => $status,
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'date_query'     => array( array( 'year' => (int) wp_date( 'Y' ), 'month' => (int) wp_date( 'n' ) ) ),
	) );
	$q = new WP_Query( $args );
	return (int) $q->found_posts;
}

function gbl_fd_count_mine() {
	$q = new WP_Query( array(
		'post_type'      => array( 'gbl_item', 'gbl_entry' ),
		'post_status'    => array( 'publish', 'pending', 'draft' ),
		'author'         => get_current_user_id(),
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );
	return (int) $q->found_posts;
}

function gbl_fd_club_counts( $type = 'gbl_item' ) {
	global $wpdb;
	$sql  = "SELECT tt.term_id AS tid, p.post_status AS st, COUNT(DISTINCT p.ID) AS c
		FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
		INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'gbl_club'
		WHERE p.post_type = %s AND p.post_status IN ('publish','pending','draft')
		GROUP BY tt.term_id, p.post_status";
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $type ) );
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$out[ (int) $r->tid ][ $r->st ] = (int) $r->c;
	}
	return $out;
}

// Clubs the viewer may see (all for the team, one for club users).
function gbl_fd_visible_clubs() {
	$terms = get_terms( array( 'taxonomy' => 'gbl_club', 'hide_empty' => false, 'orderby' => 'name' ) );
	if ( is_wp_error( $terms ) ) {
		return array();
	}
	$scope = gbl_fd_scope();
	if ( 0 === $scope ) {
		return $terms;
	}
	$out = array();
	foreach ( $terms as $t ) {
		if ( (int) $t->term_id === $scope ) {
			$out[] = $t;
		}
	}
	return $out;
}

// Pending vs approved submissions for the last N months (for the chart).
function gbl_fd_month_series( $months = 6 ) {
	global $wpdb;
	$labels  = array();
	$keys    = array();
	$cursor  = new DateTime( 'first day of this month', wp_timezone() );
	$cursor->modify( '-' . ( $months - 1 ) . ' months' );
	$start = $cursor->format( 'Y-m-01' ) . ' 00:00:00';
	for ( $i = 0; $i < $months; $i++ ) {
		$keys[]   = $cursor->format( 'Y-m' );
		$labels[] = $cursor->format( 'M Y' );
		$cursor->modify( '+1 month' );
	}
	$series = array( 'labels' => $labels, 'pending' => array_fill( 0, $months, 0 ), 'approved' => array_fill( 0, $months, 0 ) );

	$scope = gbl_fd_scope();
	if ( $scope < 0 ) {
		return $series;
	}
	$join  = '';
	$where = '';
	if ( $scope > 0 ) {
		$term = get_term( $scope, 'gbl_club' );
		if ( ! $term || is_wp_error( $term ) ) {
			return $series;
		}
		$join  = " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID ";
		$where = $wpdb->prepare( ' AND tr.term_taxonomy_id = %d ', (int) $term->term_taxonomy_id );
	}
	$sql  = "SELECT DATE_FORMAT(p.post_date, '%%Y-%%m') AS ym, p.post_status AS st, COUNT(*) AS c
		FROM {$wpdb->posts} p {$join}
		WHERE p.post_type = 'gbl_item' AND p.post_status IN ('publish','pending') AND p.post_date >= %s {$where}
		GROUP BY ym, st";
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $start ) );
	foreach ( (array) $rows as $r ) {
		$i = array_search( $r->ym, $keys, true );
		if ( false === $i ) {
			continue;
		}
		if ( 'publish' === $r->st ) {
			$series['approved'][ $i ] = (int) $r->c;
		} else {
			$series['pending'][ $i ] = (int) $r->c;
		}
	}
	return $series;
}

/* --------------------------------------------------------------- audit log */
function gbl_fd_log( $entry ) {
	$log = get_option( 'gbl_fd_audit', array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	array_unshift( $log, $entry );
	update_option( 'gbl_fd_audit', array_slice( $log, 0, 300 ), false );
}

add_action( 'transition_post_status', 'gbl_fd_on_transition', 10, 3 );
function gbl_fd_on_transition( $new, $old, $post ) {
	$types = array( 'gbl_item', 'gbl_entry', 'gbl_nomination', 'gbl_report' );
	if ( ! in_array( $post->post_type, $types, true ) || $new === $old ) {
		return;
	}
	$act = '';
	if ( 'pending' === $new ) {
		$act = 'submitted';
	} elseif ( 'publish' === $new ) {
		$act = ( 'pending' === $old ) ? 'approved' : 'published';
	} elseif ( 'draft' === $new && in_array( $old, array( 'pending', 'publish' ), true ) ) {
		$act = 'returned';
	} elseif ( 'trash' === $new ) {
		$act = 'removed';
	}
	if ( '' === $act ) {
		return;
	}
	$decision = isset( $GLOBALS['gbl_fd_decision'] ) ? $GLOBALS['gbl_fd_decision'] : array();
	gbl_fd_log( array(
		't'     => time(),
		'u'     => get_current_user_id(),
		'pt'    => $post->post_type,
		'id'    => (int) $post->ID,
		'title' => get_the_title( $post ),
		'act'   => isset( $decision['act'] ) ? $decision['act'] : $act,
		'note'  => isset( $decision['note'] ) ? $decision['note'] : '',
	) );
}

// Log entries the viewer is allowed to see.
function gbl_fd_visible_log( $limit = 6 ) {
	$log = get_option( 'gbl_fd_audit', array() );
	if ( ! is_array( $log ) ) {
		return array();
	}
	$scope = gbl_fd_scope();
	if ( $scope < 0 ) {
		return array();
	}
	$out = array();
	foreach ( $log as $e ) {
		if ( $scope > 0 ) {
			if ( ! in_array( $e['pt'], array( 'gbl_item', 'gbl_entry' ), true ) ) {
				continue;
			}
			$club = gbl_fd_item_club( $e['id'] );
			if ( ! $club || (int) $club->term_id !== $scope ) {
				continue;
			}
		}
		$out[] = $e;
		if ( count( $out ) >= $limit ) {
			break;
		}
	}
	return $out;
}
