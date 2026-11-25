<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ================================================================ helpers */
function gbl_fd_meta( $id, $key ) {
	$v = get_post_meta( $id, $key, true );
	return is_scalar( $v ) ? (string) $v : '';
}

function gbl_fd_badge( $post ) {
	$s     = $post->post_status;
	$label = 'Pending';
	$cls   = 'pending';
	if ( 'gbl_report' === $post->post_type ) {
		$label = ( 'publish' === $s ) ? 'Resolved' : 'Open';
		$cls   = ( 'publish' === $s ) ? 'ok' : 'bad';
	} elseif ( 'publish' === $s ) {
		$label = 'Published';
		$cls   = 'ok';
	} elseif ( 'draft' === $s ) {
		$o = gbl_fd_meta( $post->ID, 'gbl_review_outcome' );
		if ( 'corrections_requested' === $o ) {
			$label = 'Corrections';
			$cls   = 'warn';
		} elseif ( 'declined' === $o ) {
			$label = 'Declined';
			$cls   = 'bad';
		} else {
			$label = 'Draft';
			$cls   = 'muted';
		}
	} elseif ( 'trash' === $s ) {
		$label = 'Removed';
		$cls   = 'bad';
	}
	return '<span class="gbl-badge gbl-badge--' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</span>';
}

function gbl_fd_thumb( $post ) {
	$url = get_the_post_thumbnail_url( $post, 'thumbnail' );
	if ( ! $url ) {
		$g = get_post_meta( $post->ID, 'gbl_more_images', true );
		if ( is_array( $g ) && $g ) {
			$url = wp_get_attachment_image_url( (int) reset( $g ), 'thumbnail' );
		}
	}
	if ( $url ) {
		return '<img class="gbl-thumb" src="' . esc_url( $url ) . '" alt="" loading="lazy">';
	}
	return '<span class="gbl-thumb gbl-thumb--ph">' . gbl_fd_icon( 'image', 22 ) . '</span>';
}

function gbl_fd_club_chip( $term ) {
	if ( ! $term ) {
		return '<span class="gbl-muted">&mdash;</span>';
	}
	$div = strtolower( gbl_fd_division( $term->slug ) );
	return '<span class="gbl-chip"><i class="gbl-dot gbl-dot--' . esc_attr( $div ) . '">' . esc_html( strtoupper( substr( $term->name, 0, 2 ) ) ) . '</i>' . esc_html( $term->name ) . '</span>';
}

function gbl_fd_first_term_name( $post_id, $taxonomy ) {
	$t = get_the_terms( $post_id, $taxonomy );
	return ( $t && ! is_wp_error( $t ) ) ? reset( $t )->name : '';
}

function gbl_fd_add_url( $kind ) {
	$s    = gbl_fd_settings();
	$keys = array( 'item' => 'add_item_url', 'entry' => 'add_entry_url' );
	if ( isset( $keys[ $kind ] ) && $s[ $keys[ $kind ] ] ) {
		return $s[ $keys[ $kind ] ];
	}
	$slugs = array( 'item' => 'add-item', 'entry' => 'add-entry', 'nomination' => 'add-nomination' );
	$page  = isset( $slugs[ $kind ] ) ? get_page_by_path( $slugs[ $kind ] ) : null;
	if ( $page ) {
		return get_permalink( $page );
	}
	if ( gbl_fd_is_team_user() ) {
		$types = array( 'item' => 'gbl_item', 'entry' => 'gbl_entry', 'nomination' => 'gbl_nomination' );
		return admin_url( 'post-new.php?post_type=' . ( isset( $types[ $kind ] ) ? $types[ $kind ] : 'gbl_item' ) );
	}
	return '';
}

function gbl_fd_add_button( $kind, $label ) {
	if ( ! gbl_fd_can( 'gbl_submit_items' ) ) {
		return '';
	}
	$url = gbl_fd_add_url( $kind );
	if ( '' === $url ) {
		return '';
	}
	return '<a class="gbl-btn" href="' . esc_url( $url ) . '">' . gbl_fd_icon( 'plus', 18 ) . esc_html( $label ) . '</a>';
}

function gbl_fd_pagination( $query, $paged, $args ) {
	$total = (int) $query->max_num_pages;
	if ( $total < 2 ) {
		return;
	}
	echo '<div class="gbl-pager">';
	if ( $paged > 1 ) {
		echo '<a class="gbl-btn gbl-btn--ghost gbl-btn--sm" href="' . esc_url( add_query_arg( array_merge( $args, array( 'paged' => $paged - 1 ) ), gbl_fd_dashboard_url() ) ) . '">Previous</a>';
	}
	echo '<span>Page ' . (int) $paged . ' of ' . (int) $total . '</span>';
	if ( $paged < $total ) {
		echo '<a class="gbl-btn gbl-btn--ghost gbl-btn--sm" href="' . esc_url( add_query_arg( array_merge( $args, array( 'paged' => $paged + 1 ) ), gbl_fd_dashboard_url() ) ) . '">Next</a>';
	}
	echo '</div>';
}

function gbl_fd_page_head( $title, $sub = '', $right = '' ) {
	echo '<div class="gbl-head"><div><h1>' . esc_html( $title ) . '</h1>';
	if ( '' !== $sub ) {
		echo '<p>' . esc_html( $sub ) . '</p>';
	}
	echo '</div><div class="gbl-head__r">' . $right . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

// Table of reviewable posts (items, entries, nominations).
function gbl_fd_items_table( $posts ) {
	if ( ! $posts ) {
		echo '<p class="gbl-empty">Nothing to show here yet.</p>';
		return;
	}
	echo '<div class="gbl-tablewrap"><table class="gbl-table"><thead><tr><th>Item</th><th>Club</th><th>Type</th><th>Submitted</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
	foreach ( $posts as $p ) {
		$club   = gbl_fd_item_club( $p->ID );
		$type   = ( 'gbl_entry' === $p->post_type ) ? gbl_fd_first_term_name( $p->ID, 'gbl_entry_kind' ) : gbl_fd_first_term_name( $p->ID, 'gbl_material_type' );
		$can    = ( 'pending' === $p->post_status ) && gbl_fd_can_review_post( $p );
		$excerpt = wp_trim_words( wp_strip_all_tags( $p->post_content ), 12 );
		echo '<tr>';
		echo '<td><div class="gbl-item">' . gbl_fd_thumb( $p ) . '<div><b>' . esc_html( get_the_title( $p ) ) . '</b><small>' . esc_html( $excerpt ) . '</small></div></div></td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<td>' . gbl_fd_club_chip( $club ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<td>' . esc_html( $type ) . '</td>';
		echo '<td>' . esc_html( get_the_date( 'j M Y', $p ) ) . '<small class="gbl-block">' . esc_html( get_the_time( 'g:i A', $p ) ) . '</small></td>';
		echo '<td>' . gbl_fd_badge( $p ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<td class="gbl-actions"><button type="button" class="gbl-btn gbl-btn--sm" data-gbl-open="' . (int) $p->ID . '">' . ( $can ? 'Review' : 'View' ) . '</button>';
		echo '<span class="gbl-more"><button type="button" class="gbl-btn gbl-btn--ghost gbl-btn--sm" data-gbl-toggle="m-' . (int) $p->ID . '" aria-label="More actions">' . gbl_fd_icon( 'more', 18 ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<span class="gbl-pop" id="m-' . (int) $p->ID . '" hidden>';
		echo '<a href="#" data-gbl-open="' . (int) $p->ID . '">View details</a>';
		if ( $can ) {
			echo '<a href="#" data-gbl-quick="' . (int) $p->ID . '">Approve now</a>';
		}
		if ( gbl_fd_is_team_user() && get_edit_post_link( $p->ID ) ) {
			echo '<a href="' . esc_url( get_edit_post_link( $p->ID, 'raw' ) ) . '">Open in WordPress editor</a>';
		}
		echo '</span></span></td></tr>';
	}
	echo '</tbody></table></div>';
}

/* ================================================================ chart */
function gbl_fd_chart_svg( $s ) {
	$w = 640; $h = 250; $l = 44; $r = 14; $t = 14; $b = 34;
	$n   = count( $s['labels'] );
	$max = max( 10, max( array_merge( $s['pending'], $s['approved'] ) ) );
	$max = (int) ( ceil( $max / 10 ) * 10 );
	$pw  = $w - $l - $r;
	$ph  = $h - $t - $b;
	$x   = function ( $i ) use ( $l, $pw, $n ) {
		return $l + ( $n > 1 ? $pw * $i / ( $n - 1 ) : 0 );
	};
	$y   = function ( $v ) use ( $t, $ph, $max ) {
		return $t + $ph - ( $ph * $v / $max );
	};
	$svg = '<svg class="gbl-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Archive submissions over the last six months">';
	for ( $k = 0; $k <= 5; $k++ ) {
		$val = $max * $k / 5;
		$yy  = round( $y( $val ), 1 );
		$svg .= '<line class="g" x1="' . $l . '" x2="' . ( $w - $r ) . '" y1="' . $yy . '" y2="' . $yy . '"/><text class="a" x="' . ( $l - 8 ) . '" y="' . ( $yy + 4 ) . '" text-anchor="end">' . (int) $val . '</text>';
	}
	foreach ( $s['labels'] as $i => $lab ) {
		$xx  = round( $x( $i ), 1 );
		$svg .= '<line class="g" x1="' . $xx . '" x2="' . $xx . '" y1="' . $t . '" y2="' . ( $t + $ph ) . '"/><text class="a" x="' . $xx . '" y="' . ( $h - 10 ) . '" text-anchor="middle">' . esc_html( $lab ) . '</text>';
	}
	foreach ( array( 'pending' => 'p', 'approved' => 'ap' ) as $key => $cls ) {
		$pts = array();
		foreach ( $s[ $key ] as $i => $v ) {
			$pts[] = round( $x( $i ), 1 ) . ',' . round( $y( $v ), 1 );
		}
		$svg .= '<polyline class="' . $cls . '" fill="none" points="' . esc_attr( implode( ' ', $pts ) ) . '"/>';
		foreach ( $s[ $key ] as $i => $v ) {
			$svg .= '<circle class="' . $cls . 'd" cx="' . round( $x( $i ), 1 ) . '" cy="' . round( $y( $v ), 1 ) . '" r="3.6"><title>' . (int) $v . '</title></circle>';
		}
	}
	return $svg . '</svg>';
}

/* ========================================================= recent activity */
function gbl_fd_activity_row( $e ) {
	$pt   = $e['pt'];
	$act  = $e['act'];
	$noun = array( 'gbl_item' => 'Archive item', 'gbl_entry' => 'Entry', 'gbl_nomination' => 'Nomination', 'gbl_report' => 'Report' );
	$map  = array(
		'submitted'   => array( 'submitted', 'file', 'blue' ),
		'approved'    => array( 'approved', 'check', 'green' ),
		'approve'     => array( 'approved', 'check', 'green' ),
		'published'   => array( 'published', 'check', 'green' ),
		'returned'    => array( 'returned to draft', 'clock', 'orange' ),
		'corrections' => array( 'corrections requested', 'clock', 'orange' ),
		'decline'     => array( 'declined', 'alert', 'red' ),
		'removed'     => array( 'removed', 'alert', 'red' ),
		'remove'      => array( 'removed', 'alert', 'red' ),
	);
	$m = isset( $map[ $act ] ) ? $map[ $act ] : array( $act, 'file', 'blue' );
	if ( 'gbl_report' === $pt ) {
		$title = ( 'submitted' === $act ) ? 'Report opened' : 'Report ' . ( 'published' === $act || 'approved' === $act ? 'resolved' : $m[0] );
	} elseif ( 'submitted' === $act ) {
		$title = 'New submission';
	} else {
		$title = ( isset( $noun[ $pt ] ) ? $noun[ $pt ] : 'Item' ) . ' ' . $m[0];
	}
	$club = in_array( $pt, array( 'gbl_item', 'gbl_entry' ), true ) ? gbl_fd_item_club( $e['id'] ) : null;
	$sub  = ( $club ? $club->name . ' - ' : '' ) . ( get_the_title( $e['id'] ) ? get_the_title( $e['id'] ) : $e['title'] );
	$ago  = human_time_diff( (int) $e['t'], time() ) . ' ago';
	return '<li><span class="gbl-ic gbl-ic--' . esc_attr( $m[2] ) . '">' . gbl_fd_icon( $m[1], 18 ) . '</span><div><b>' . esc_html( $title ) . '</b><small>' . esc_html( wp_trim_words( $sub, 9 ) ) . '</small></div><time>' . esc_html( $ago ) . '</time></li>';
}

/* ============================================================== dashboard */
function gbl_fd_view_dashboard() {
	$user   = wp_get_current_user();
	$global = gbl_fd_is_global();
	$pub    = gbl_fd_count( 'gbl_item', 'publish' );
	$pend   = gbl_fd_count( 'gbl_item', 'pending' ) + gbl_fd_count( 'gbl_entry', 'pending' );
	$ent    = gbl_fd_count( 'gbl_entry', 'publish' );
	$pub_m  = gbl_fd_count_this_month( 'gbl_item', 'publish' );
	$ent_m  = gbl_fd_count_this_month( 'gbl_entry', 'publish' );

	gbl_fd_page_head( 'Dashboard', 'Welcome back, ' . $user->display_name, '<span class="gbl-date">' . gbl_fd_icon( 'calendar', 18 ) . ' ' . esc_html( wp_date( 'D, j M Y' ) ) . '</span>' );
	echo '<div class="gbl-grid">';

	echo gbl_fd_stat( 'blue', 'inbox', 'Total Archive Items', $pub, '<span class="up">' . gbl_fd_icon( 'up', 14 ) . ' +' . (int) $pub_m . ' this month</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo gbl_fd_stat( 'orange', 'clock', 'Pending Review', $pend, '<span class="dot dot--o"></span> Needs attention' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo gbl_fd_stat( 'green', 'check', 'Published Entries', $ent, '<span class="up">' . gbl_fd_icon( 'up', 14 ) . ' +' . (int) $ent_m . ' this month</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput
	if ( $global ) {
		echo gbl_fd_stat( 'red', 'alert', 'Open Reports', gbl_fd_count( 'gbl_report', 'pending' ), '<span class="dot dot--r"></span> Requires action' ); // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		echo gbl_fd_stat( 'red', 'file', 'My Submissions', gbl_fd_count_mine(), '<span class="dot dot--r"></span> Added by you' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/* club activity */
	$counts = gbl_fd_club_counts( 'gbl_item' );
	$groups = array();
	foreach ( gbl_fd_visible_clubs() as $term ) {
		$c   = isset( $counts[ $term->term_id ] ) ? $counts[ $term->term_id ] : array();
		$div = gbl_fd_division( $term->slug );
		$key = $global ? $div : $term->name;
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array( 'div' => $div, 'name' => $key, 'items' => 0, 'pending' => 0, 'pub' => 0 );
		}
		$groups[ $key ]['pub']     += isset( $c['publish'] ) ? $c['publish'] : 0;
		$groups[ $key ]['pending'] += isset( $c['pending'] ) ? $c['pending'] : 0;
		$groups[ $key ]['items']   += array_sum( $c );
	}
	echo '<section class="gbl-card gbl-club"><div class="gbl-card__h"><h2>Club Activity</h2><a href="' . esc_url( gbl_fd_view_url( 'clubs' ) ) . '">View all clubs ' . gbl_fd_icon( 'right', 14 ) . '</a></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	if ( ! $groups ) {
		echo '<p class="gbl-empty">No club to show yet.</p>';
	}
	foreach ( $groups as $g ) {
		$title = $g['name'];
		if ( $global ) {
			$title = ( 'Representative' === $g['div'] ) ? 'Gippsland Lakers' : $g['div'] . ' Division';
		}
		$tile = '<span class="gbl-tile gbl-tile--' . esc_attr( strtolower( $g['div'] ) ) . '">' . esc_html( $global ? ( 'Representative' === $g['div'] ? 'LAKERS' : strtoupper( $g['div'] ) ) : strtoupper( substr( $g['name'], 0, 3 ) ) ) . '</span>';
		echo '<div class="gbl-clubrow">' . $tile . '<div><b>' . esc_html( $title ) . '</b><dl><dt>Items</dt><dd>' . (int) $g['items'] . '</dd><dt>Pending</dt><dd>' . (int) $g['pending'] . '</dd><dt>Published</dt><dd>' . (int) $g['pub'] . '</dd></dl></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</section>';

	/* chart */
	echo '<section class="gbl-card gbl-chartcard"><div class="gbl-card__h"><div><h2>Submission Overview</h2><p>Archive submissions over the last 6 months</p></div><div class="gbl-legend"><span><i class="p"></i>Pending</span><span><i class="ap"></i>Approved</span></div></div>';
	echo gbl_fd_chart_svg( gbl_fd_month_series( 6 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</section>';

	/* activity */
	echo '<section class="gbl-card gbl-activity"><div class="gbl-card__h"><h2>Recent Activity</h2>';
	if ( gbl_fd_can( 'gbl_view_audit' ) ) {
		echo '<a href="' . esc_url( gbl_fd_view_url( 'audit' ) ) . '">View all ' . gbl_fd_icon( 'right', 14 ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</div>';
	$log = gbl_fd_visible_log( 6 );
	if ( ! $log ) {
		echo '<p class="gbl-empty">No activity yet.</p>';
	} else {
		echo '<ul class="gbl-feed">';
		foreach ( $log as $e ) {
			echo gbl_fd_activity_row( $e ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul>';
	}
	echo '</section>';

	/* pending table */
	$args  = gbl_fd_apply_scope( array( 'post_type' => 'gbl_item', 'post_status' => 'pending', 'posts_per_page' => 5, 'orderby' => 'date', 'order' => 'DESC' ) );
	$query = new WP_Query( $args );
	echo '<section class="gbl-card gbl-pending"><div class="gbl-card__h"><h2>Pending Review</h2><a href="' . esc_url( gbl_fd_view_url( 'items', array( 'status' => 'pending' ) ) ) . '">View all pending ' . gbl_fd_icon( 'right', 14 ) . '</a></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	gbl_fd_items_table( $query->posts );
	echo '</section></div>';
}

function gbl_fd_stat( $tone, $icon, $label, $value, $foot ) {
	return '<div class="gbl-card gbl-stat gbl-stat--' . esc_attr( $tone ) . '"><span class="gbl-stat__ic">' . gbl_fd_icon( $icon, 26 ) . '</span><div><p>' . esc_html( $label ) . '</p><b>' . esc_html( number_format_i18n( $value ) ) . '</b><em>' . $foot . '</em></div></div>';
}

/* ============================================================ archive items */
function gbl_fd_view_items() {
	$global = gbl_fd_is_global();
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
	$q      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$club   = isset( $_GET['club'] ) ? absint( $_GET['club'] ) : 0;
	$tabs   = array( 'all' => 'All', 'pending' => 'Pending', 'publish' => 'Published', 'draft' => 'Drafts' );
	if ( $global ) {
		$tabs['trash'] = 'Removed';
	}
	if ( ! isset( $tabs[ $status ] ) ) {
		$status = 'all';
	}
	$args = array(
		'post_type'      => 'gbl_item',
		'post_status'    => ( 'all' === $status ) ? array( 'publish', 'pending', 'draft' ) : $status,
		'posts_per_page' => 15,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	if ( '' !== $q ) {
		$args['s'] = $q;
	}
	if ( $club && $global ) {
		$args['tax_query'] = array( array( 'taxonomy' => 'gbl_club', 'field' => 'term_id', 'terms' => array( $club ) ) );
	}
	$query = new WP_Query( gbl_fd_apply_scope( $args ) );

	gbl_fd_page_head( 'Archive Items', 'Photos, clippings, programmes, scorecards, social posts and juniors history.', gbl_fd_add_button( 'item', 'Add item' ) );
	echo '<section class="gbl-card"><div class="gbl-toolbar"><div class="gbl-tabs">';
	foreach ( $tabs as $k => $lab ) {
		$url = gbl_fd_view_url( 'items', array_filter( array( 'status' => $k, 'q' => $q, 'club' => $club ) ) );
		echo '<a href="' . esc_url( $url ) . '"' . ( $k === $status ? ' class="is-active"' : '' ) . '>' . esc_html( $lab ) . '</a>';
	}
	echo '</div>';
	if ( '' !== $q ) {
		echo '<span class="gbl-muted">Search: &ldquo;' . esc_html( $q ) . '&rdquo; <a href="' . esc_url( gbl_fd_view_url( 'items', array( 'status' => $status ) ) ) . '">clear</a></span>';
	}
	echo '</div>';
	gbl_fd_items_table( $query->posts );
	gbl_fd_pagination( $query, $paged, array_filter( array( 'gbl_view' => 'items', 'status' => $status, 'q' => $q, 'club' => $club ) ) );
	echo '</section>';
}

/* ==================================================================== clubs */
function gbl_fd_can_edit_club( $club_id ) {
	$club_id = (int) $club_id;
	return $club_id > 0 && ( gbl_fd_is_global() || ( gbl_fd_can( 'gbl_review_club' ) && gbl_fd_scope() === $club_id ) );
}

function gbl_fd_view_club_edit( $club_id ) {
	$term = get_term( $club_id, 'gbl_club' );
	if ( ! $term || is_wp_error( $term ) ) {
		echo '<p class="gbl-empty">Club not found.</p>';
		return;
	}
	$page = gbl_fd_club_page_for_term( $term );
	$desc = $page ? (string) get_post_meta( $page->ID, 'gbl_club_description', true ) : '';
	gbl_fd_page_head( 'Club profile: ' . gbl_fd_club_name( $term ), 'This text appears at the top of the public club page.', '<a class="gbl-btn gbl-btn--ghost" href="' . esc_url( gbl_fd_club_url( $term ) ) . '" target="_blank" rel="noopener">View public page ' . gbl_fd_icon( 'external', 16 ) . '</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<section class="gbl-card gbl-pad">';
	if ( ! $page ) {
		echo '<p class="gbl-notice">The page for this club does not exist yet. An administrator can create it under Settings &rarr; Create / repair club pages.</p>';
	}
	echo '<form class="gbl-form" id="gbl-clubform" data-club="' . (int) $club_id . '"><label>Club description<textarea name="description" rows="6" maxlength="1200" placeholder="A few lines about the club, its history and its place in the league.">' . esc_textarea( $desc ) . '</textarea></label>';
	$hist = $page ? (string) get_post_meta( $page->ID, 'gbl_club_history', true ) : '';
	echo '<label>Club history introduction (shown at the top of the Club History tab)<textarea name="history" rows="9" maxlength="4000" placeholder="How and when the club started, early colours and grounds, key people and milestones. Use a blank line between paragraphs.">' . esc_textarea( $hist ) . '</textarea></label>';
	echo '<button class="gbl-btn" type="submit"' . ( $page ? '' : ' disabled' ) . '>Save profile</button>';
	echo '<p class="gbl-muted">The club image or crest is set by the GBL 80s Team: edit the club page in WordPress and fill in <b>Club Featured Image</b>.</p></form></section>';
}

function gbl_fd_view_clubs() {
	$edit = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
	if ( $edit && gbl_fd_can_edit_club( $edit ) ) {
		gbl_fd_view_club_edit( $edit );
		return;
	}
	gbl_fd_page_head( 'Clubs', gbl_fd_is_global() ? 'East and West divisions plus the Gippsland Lakers.' : 'Your club.' );
	$counts = gbl_fd_club_counts( 'gbl_item' );
	echo '<div class="gbl-clubs">';
	foreach ( gbl_fd_visible_clubs() as $term ) {
		$c   = isset( $counts[ $term->term_id ] ) ? $counts[ $term->term_id ] : array();
		$div = gbl_fd_division( $term->slug );
		$url = gbl_fd_view_url( 'items', array( 'club' => $term->term_id ) );
		echo '<div class="gbl-card gbl-clubcard"><a class="gbl-clubcard__main" href="' . esc_url( $url ) . '"><span class="gbl-tile gbl-tile--' . esc_attr( strtolower( $div ) ) . '">' . esc_html( strtoupper( substr( $term->name, 0, 3 ) ) ) . '</span><div><b>' . esc_html( gbl_fd_club_name( $term ) ) . '</b><small>' . esc_html( gbl_fd_club_division_label( $term ) ) . '</small><dl><dt>Published</dt><dd>' . (int) ( isset( $c['publish'] ) ? $c['publish'] : 0 ) . '</dd><dt>Pending</dt><dd>' . (int) ( isset( $c['pending'] ) ? $c['pending'] : 0 ) . '</dd></dl></div></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="gbl-clubcard__links"><a href="' . esc_url( gbl_fd_club_url( $term ) ) . '" target="_blank" rel="noopener">Public page</a>';
		if ( gbl_fd_can_edit_club( $term->term_id ) ) {
			echo '<a href="' . esc_url( gbl_fd_view_url( 'clubs', array( 'edit' => $term->term_id ) ) ) . '">Edit profile</a>';
		}
		echo '</div></div>';
	}
	echo '</div>';
}

/* ================================================================== entries */
function gbl_fd_view_entries() {
	$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$query = new WP_Query( gbl_fd_apply_scope( array(
		'post_type'      => 'gbl_entry',
		'post_status'    => array( 'publish', 'pending', 'draft' ),
		'posts_per_page' => 20,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) ) );
	gbl_fd_page_head( 'Entries', 'Players and teams shown on each club page.', gbl_fd_add_button( 'entry', 'Add entry' ) );
	echo '<section class="gbl-card">';
	if ( ! $query->posts ) {
		echo '<p class="gbl-empty">No players or teams yet.</p>';
	} else {
		echo '<div class="gbl-tablewrap"><table class="gbl-table"><thead><tr><th>Name</th><th>Club</th><th>Kind</th><th>Role</th><th>Years</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $query->posts as $p ) {
			$can = ( 'pending' === $p->post_status ) && gbl_fd_can_review_post( $p );
			echo '<tr><td><b>' . esc_html( get_the_title( $p ) ) . '</b></td><td>' . gbl_fd_club_chip( gbl_fd_item_club( $p->ID ) ) . '</td><td>' . esc_html( gbl_fd_first_term_name( $p->ID, 'gbl_entry_kind' ) ) . '</td><td>' . esc_html( gbl_fd_meta( $p->ID, 'gbl_role' ) ) . '</td><td>' . esc_html( gbl_fd_meta( $p->ID, 'gbl_years' ) ) . '</td><td>' . gbl_fd_badge( $p ) . '</td><td class="gbl-actions"><button type="button" class="gbl-btn gbl-btn--sm" data-gbl-open="' . (int) $p->ID . '">' . ( $can ? 'Review' : 'View' ) . '</button></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></div>';
	}
	gbl_fd_pagination( $query, $paged, array( 'gbl_view' => 'entries' ) );
	echo '</section>';
}

/* ============================================================== nominations */
function gbl_fd_view_nominations() {
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'pending';
	$tabs   = array( 'pending' => 'Pending', 'publish' => 'Approved', 'draft' => 'Declined' );
	if ( ! isset( $tabs[ $status ] ) ) {
		$status = 'pending';
	}
	$query = new WP_Query( array( 'post_type' => 'gbl_nomination', 'post_status' => $status, 'posts_per_page' => 20, 'orderby' => 'date', 'order' => 'DESC' ) );
	gbl_fd_page_head( 'Nominations', 'Remarkables, Legends and Tributes. The GBL 80s Team makes the final decision.', gbl_fd_add_button( 'nomination', 'Add nomination' ) );
	echo '<section class="gbl-card"><div class="gbl-toolbar"><div class="gbl-tabs">';
	foreach ( $tabs as $k => $lab ) {
		echo '<a href="' . esc_url( gbl_fd_view_url( 'nominations', array( 'status' => $k ) ) ) . '"' . ( $k === $status ? ' class="is-active"' : '' ) . '>' . esc_html( $lab ) . '</a>';
	}
	echo '</div></div>';
	if ( ! $query->posts ) {
		echo '<p class="gbl-empty">No nominations here.</p>';
	} else {
		echo '<div class="gbl-tablewrap"><table class="gbl-table"><thead><tr><th>Nominee</th><th>Category</th><th>Club(s)</th><th>Roles &amp; years</th><th>Submitted</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $query->posts as $p ) {
			$clubs = get_the_terms( $p->ID, 'gbl_club' );
			$names = ( $clubs && ! is_wp_error( $clubs ) ) ? implode( ', ', wp_list_pluck( $clubs, 'name' ) ) : '';
			echo '<tr><td><b>' . esc_html( get_the_title( $p ) ) . '</b></td><td>' . esc_html( gbl_fd_first_term_name( $p->ID, 'gbl_nomination_category' ) ) . '</td><td>' . esc_html( $names ) . '</td><td>' . esc_html( trim( gbl_fd_meta( $p->ID, 'gbl_nom_roles' ) . ' ' . gbl_fd_meta( $p->ID, 'gbl_nom_years' ) ) ) . '</td><td>' . esc_html( get_the_date( 'j M Y', $p ) ) . '</td><td>' . gbl_fd_badge( $p ) . '</td><td class="gbl-actions"><button type="button" class="gbl-btn gbl-btn--sm" data-gbl-open="' . (int) $p->ID . '">' . ( 'pending' === $p->post_status ? 'Review' : 'View' ) . '</button></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></div>';
	}
	echo '</section>';
}

/* ================================================================== reports */
function gbl_fd_view_reports() {
	$query = new WP_Query( array( 'post_type' => 'gbl_report', 'post_status' => array( 'pending', 'publish' ), 'posts_per_page' => 30, 'orderby' => 'date', 'order' => 'DESC' ) );
	gbl_fd_page_head( 'Reports', 'Corrections and removal requests from the public report form.' );
	echo '<section class="gbl-card">';
	if ( ! $query->posts ) {
		echo '<p class="gbl-empty">No reports.</p>';
	} else {
		echo '<div class="gbl-tablewrap"><table class="gbl-table"><thead><tr><th>Item</th><th>Message</th><th>Contact</th><th>Received</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $query->posts as $p ) {
			echo '<tr><td><b>' . esc_html( get_the_title( $p ) ) . '</b></td><td>' . esc_html( wp_trim_words( wp_strip_all_tags( $p->post_content ), 22 ) ) . '</td><td>' . esc_html( gbl_fd_meta( $p->ID, 'gbl_report_contact' ) ) . '</td><td>' . esc_html( get_the_date( 'j M Y', $p ) ) . '</td><td>' . gbl_fd_badge( $p ) . '</td><td class="gbl-actions">'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<button type="button" class="gbl-btn gbl-btn--ghost gbl-btn--sm" data-gbl-open="' . (int) $p->ID . '">View</button>';
			if ( 'pending' === $p->post_status ) {
				echo '<button type="button" class="gbl-btn gbl-btn--sm" data-gbl-resolve="' . (int) $p->ID . '">Mark resolved</button>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
	echo '</section>';
}

/* ==================================================================== news */
function gbl_fd_view_news() {
	$posts = get_posts( array( 'post_type' => 'post', 'post_status' => array( 'publish', 'draft', 'pending' ), 'posts_per_page' => 30, 'category_name' => 'reunion,announcement' ) );
	gbl_fd_page_head( 'News & Reunions', 'Posts in the Reunion and Announcement categories.', '<a class="gbl-btn" href="' . esc_url( admin_url( 'post-new.php' ) ) . '">' . gbl_fd_icon( 'plus', 18 ) . 'Add post</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<section class="gbl-card">';
	if ( ! $posts ) {
		echo '<p class="gbl-empty">No news yet.</p></section>';
		return;
	}
	echo '<div class="gbl-tablewrap"><table class="gbl-table"><thead><tr><th>Title</th><th>Category</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
	foreach ( $posts as $p ) {
		$cats = wp_list_pluck( (array) get_the_category( $p->ID ), 'name' );
		echo '<tr><td><b>' . esc_html( get_the_title( $p ) ) . '</b></td><td>' . esc_html( implode( ', ', $cats ) ) . '</td><td>' . esc_html( get_the_date( 'j M Y', $p ) ) . '</td><td><span class="gbl-badge gbl-badge--' . ( 'publish' === $p->post_status ? 'ok' : 'muted' ) . '">' . esc_html( ucfirst( $p->post_status ) ) . '</span></td><td class="gbl-actions"><a class="gbl-btn gbl-btn--sm" href="' . esc_url( get_edit_post_link( $p->ID, 'raw' ) ) . '">Edit</a></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</tbody></table></div></section>';
}

/* ==================================================================== users */
function gbl_fd_view_users() {
	$users = get_users( array( 'role__in' => array( 'gbl_club_member', 'gbl_club_contributor' ), 'orderby' => 'display_name', 'number' => 200 ) );
	$clubs = get_terms( array( 'taxonomy' => 'gbl_club', 'hide_empty' => false, 'orderby' => 'name' ) );
	gbl_fd_page_head( 'Users', 'Club members can submit. Club contributors can also approve for their own club.', '<a class="gbl-btn" href="' . esc_url( admin_url( 'user-new.php' ) ) . '">' . gbl_fd_icon( 'plus', 18 ) . 'Add user</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<section class="gbl-card">';
	if ( ! $users ) {
		echo '<p class="gbl-empty">No club accounts yet. Use Add user and choose the role GBL Club Member or GBL Club Contributor, then come back here to set the club.</p></section>';
		return;
	}
	echo '<div class="gbl-tablewrap"><table class="gbl-table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Club</th><th>Actions</th></tr></thead><tbody>';
	foreach ( $users as $u ) {
		$cur  = (int) get_user_meta( $u->ID, 'gbl_club', true );
		$role = in_array( 'gbl_club_contributor', (array) $u->roles, true ) ? 'gbl_club_contributor' : 'gbl_club_member';
		echo '<tr data-gbl-user="' . (int) $u->ID . '"><td><b>' . esc_html( $u->display_name ) . '</b></td><td>' . esc_html( $u->user_email ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<td><select data-f="role"><option value="gbl_club_member"' . selected( $role, 'gbl_club_member', false ) . '>Club Member</option><option value="gbl_club_contributor"' . selected( $role, 'gbl_club_contributor', false ) . '>Club Contributor</option></select></td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<td><select data-f="club"><option value="0">No club</option>';
		if ( ! is_wp_error( $clubs ) ) {
			foreach ( $clubs as $t ) {
				echo '<option value="' . (int) $t->term_id . '"' . selected( $cur, $t->term_id, false ) . '>' . esc_html( $t->name ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		echo '</select></td><td class="gbl-actions"><button type="button" class="gbl-btn gbl-btn--sm" data-gbl-saveuser="' . (int) $u->ID . '">Save</button> <a class="gbl-btn gbl-btn--ghost gbl-btn--sm" href="' . esc_url( get_edit_user_link( $u->ID ) ) . '">Edit</a></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</tbody></table></div></section>';
}

/* ===================================================================== audit */
function gbl_fd_view_audit() {
	gbl_fd_page_head( 'Audit Log', 'The latest 100 changes to submissions, entries, nominations and reports.' );
	$log = get_option( 'gbl_fd_audit', array() );
	$log = is_array( $log ) ? array_slice( $log, 0, 100 ) : array();
	echo '<section class="gbl-card">';
	if ( ! $log ) {
		echo '<p class="gbl-empty">Nothing logged yet.</p></section>';
		return;
	}
	echo '<div class="gbl-tablewrap"><table class="gbl-table"><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Type</th><th>Item</th><th>Note</th></tr></thead><tbody>';
	foreach ( $log as $e ) {
		$u = $e['u'] ? get_userdata( $e['u'] ) : null;
		echo '<tr><td>' . esc_html( wp_date( 'j M Y, g:i A', (int) $e['t'] ) ) . '</td><td>' . esc_html( $u ? $u->display_name : 'Public form / system' ) . '</td><td>' . esc_html( $e['act'] ) . '</td><td>' . esc_html( str_replace( 'gbl_', '', $e['pt'] ) ) . '</td><td>' . esc_html( $e['title'] ) . '</td><td>' . esc_html( $e['note'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</tbody></table></div></section>';
}

/* ================================================================== settings */
function gbl_fd_view_settings() {
	$s     = gbl_fd_settings();
	$check = array(
		'Elementor Pro'          => defined( 'ELEMENTOR_PRO_VERSION' ),
		'SCF / ACF'              => function_exists( 'get_field' ) || function_exists( 'acf' ),
		'Members'                => function_exists( 'members_register_cap' ),
		'Frontend Admin'         => shortcode_exists( 'frontend_admin' ),
		'Archive items type'     => post_type_exists( 'gbl_item' ),
		'Clubs taxonomy'         => taxonomy_exists( 'gbl_club' ),
	);
	gbl_fd_page_head( 'Settings', 'Administrators only.' );
	echo '<section class="gbl-card gbl-pad"><h2>Add forms</h2><p class="gbl-muted">Optional. By default the buttons open the /add-item/ and /add-entry/ pages. Paste a different address (for example a Frontend Admin form page) to override them.</p>';
	echo '<form class="gbl-form" id="gbl-settings"><label>Add archive item page URL<input type="url" name="add_item_url" value="' . esc_attr( $s['add_item_url'] ) . '" placeholder="https://yoursite.com/add-item/"></label>';
	echo '<label>Add player or team page URL<input type="url" name="add_entry_url" value="' . esc_attr( $s['add_entry_url'] ) . '" placeholder="https://yoursite.com/add-entry/"></label><button class="gbl-btn" type="submit">Save settings</button></form></section>';
	echo '<section class="gbl-card gbl-pad"><h2>System check</h2><ul class="gbl-checks">';
	foreach ( $check as $label => $ok ) {
		echo '<li class="' . ( $ok ? 'ok' : 'no' ) . '">' . gbl_fd_icon( $ok ? 'check' : 'x', 16 ) . esc_html( $label ) . ' <small>' . ( $ok ? 'detected' : 'not detected' ) . '</small></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</ul><p><button class="gbl-btn gbl-btn--ghost" type="button" data-gbl-repair>Create / repair club pages</button> <span class="gbl-muted">Creates /clubs/ and a page for every club that does not have one.</span></p><p class="gbl-muted">Role permissions are edited in <a href="' . esc_url( admin_url( 'users.php?page=roles' ) ) . '">Users &rarr; Roles</a> (Members plugin). GBL 80s capabilities are in the GBL 80s group.</p></section>';
}

/* ====================================================== review modal content */
function gbl_fd_detail_html( $post ) {
	$club   = gbl_fd_item_club( $post->ID );
	$can    = gbl_fd_can_review_post( $post ) && 'pending' === $post->post_status && 'gbl_report' !== $post->post_type;
	$author = get_userdata( $post->post_author );
	$rows   = array();
	$images = array();
	$pdf    = '';

	if ( 'gbl_item' === $post->post_type ) {
		$rows = array(
			'Club'         => $club ? $club->name : '',
			'Content type' => gbl_fd_first_term_name( $post->ID, 'gbl_material_type' ),
			'Section'      => gbl_fd_first_term_name( $post->ID, 'gbl_section' ),
			'Year'         => gbl_fd_meta( $post->ID, 'gbl_year' ),
			'People'       => gbl_fd_meta( $post->ID, 'gbl_people' ),
			'Event'        => gbl_fd_meta( $post->ID, 'gbl_event' ),
			'Source'       => gbl_fd_meta( $post->ID, 'gbl_source' ),
			'Submitter'    => gbl_fd_meta( $post->ID, 'gbl_contact' ),
			'Permission'   => gbl_fd_meta( $post->ID, 'gbl_permission' ) ? 'Confirmed by submitter' : 'Not confirmed',
		);
		$g = get_post_meta( $post->ID, 'gbl_more_images', true );
		$images = is_array( $g ) ? $g : array();
		$pdf    = gbl_fd_meta( $post->ID, 'gbl_pdf' );
	} elseif ( 'gbl_entry' === $post->post_type ) {
		$rows = array(
			'Club'  => $club ? $club->name : '',
			'Kind'  => gbl_fd_first_term_name( $post->ID, 'gbl_entry_kind' ),
			'Role'  => gbl_fd_meta( $post->ID, 'gbl_role' ),
			'Years' => gbl_fd_meta( $post->ID, 'gbl_years' ),
		);
	} elseif ( 'gbl_nomination' === $post->post_type ) {
		$clubs = get_the_terms( $post->ID, 'gbl_club' );
		$rows  = array(
			'Category'          => gbl_fd_first_term_name( $post->ID, 'gbl_nomination_category' ),
			'Club(s)'           => ( $clubs && ! is_wp_error( $clubs ) ) ? implode( ', ', wp_list_pluck( $clubs, 'name' ) ) : '',
			'Roles'             => gbl_fd_meta( $post->ID, 'gbl_nom_roles' ),
			'Years'             => gbl_fd_meta( $post->ID, 'gbl_nom_years' ),
			'Sources'           => gbl_fd_meta( $post->ID, 'gbl_nom_sources' ),
			'Family consulted'  => gbl_fd_meta( $post->ID, 'gbl_nom_family_consulted' ) ? 'Yes' : 'No',
			'Nominator'         => gbl_fd_meta( $post->ID, 'gbl_nom_nominator_name' ),
			'Nominator contact' => gbl_fd_meta( $post->ID, 'gbl_nom_nominator_contact' ),
		);
		$g      = get_post_meta( $post->ID, 'gbl_nom_more_images', true );
		$images = is_array( $g ) ? $g : array();
	} else {
		$rows = array( 'Contact' => gbl_fd_meta( $post->ID, 'gbl_report_contact' ) );
	}
	$rows['Submitted'] = get_the_date( 'j M Y, g:i A', $post );
	if ( $author && 'gbl_report' !== $post->post_type ) {
		$rows['Account'] = $author->display_name;
	}

	$note = gbl_fd_meta( $post->ID, 'gbl_review_note' );
	if ( '' === $note ) {
		$note = gbl_fd_meta( $post->ID, 'gbl_nom_editor_note' );
	}

	ob_start();
	echo '<div class="gbl-d"><div class="gbl-d__h"><div><h2>' . esc_html( get_the_title( $post ) ) . '</h2>' . gbl_fd_badge( $post ) . '</div><button type="button" class="gbl-iconbtn" data-gbl-close aria-label="Close">' . gbl_fd_icon( 'x', 22 ) . '</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

	$thumb = get_the_post_thumbnail_url( $post, 'large' );
	if ( $thumb || $images ) {
		echo '<div class="gbl-d__imgs">';
		if ( $thumb ) {
			echo '<a href="' . esc_url( get_the_post_thumbnail_url( $post, 'full' ) ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $thumb ) . '" alt=""></a>';
		}
		foreach ( $images as $img_id ) {
			$u = wp_get_attachment_image_url( (int) $img_id, 'large' );
			if ( $u ) {
				echo '<a href="' . esc_url( wp_get_attachment_image_url( (int) $img_id, 'full' ) ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $u ) . '" alt="" loading="lazy"></a>';
			}
		}
		echo '</div>';
	}
	if ( $pdf && wp_get_attachment_url( (int) $pdf ) ) {
		echo '<p><a class="gbl-btn gbl-btn--ghost gbl-btn--sm" href="' . esc_url( wp_get_attachment_url( (int) $pdf ) ) . '" target="_blank" rel="noopener">' . gbl_fd_icon( 'file', 16 ) . 'Open scanned PDF</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '<dl class="gbl-d__dl">';
	foreach ( $rows as $label => $value ) {
		if ( '' === trim( (string) $value ) ) {
			continue;
		}
		echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
	}
	echo '</dl>';
	if ( '' !== trim( $post->post_content ) ) {
		echo '<div class="gbl-d__body">' . wp_kses_post( wpautop( $post->post_content ) ) . '</div>';
	}
	if ( '' !== $note && 'pending' !== $post->post_status ) {
		echo '<p class="gbl-d__note"><b>Review note:</b> ' . esc_html( $note ) . '</p>';
	}

	if ( $can ) {
		$is_item = ( 'gbl_item' === $post->post_type );
		echo '<div class="gbl-d__review" data-id="' . (int) $post->ID . '"><label for="gbl-note">Note to the contributor (required for corrections or decline)</label><textarea id="gbl-note" rows="3" maxlength="1000"></textarea><div class="gbl-d__btns">';
		echo '<button type="button" class="gbl-btn gbl-btn--green" data-decision="approve">' . gbl_fd_icon( 'check', 18 ) . 'Approve and publish</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $is_item ) {
			echo '<button type="button" class="gbl-btn gbl-btn--orange" data-decision="corrections">Request corrections</button>';
		}
		echo '<button type="button" class="gbl-btn gbl-btn--red" data-decision="decline">Decline</button>';
		if ( gbl_fd_is_global() ) {
			echo '<button type="button" class="gbl-btn gbl-btn--ghost" data-decision="remove">Move to Trash</button>';
		}
		echo '</div></div>';
	} elseif ( 'pending' === $post->post_status && in_array( $post->post_type, array( 'gbl_item', 'gbl_entry' ), true ) && gbl_fd_can( 'gbl_review_club' ) && (int) $post->post_author === get_current_user_id() && ! gbl_fd_is_global() ) {
		echo '<p class="gbl-d__note">You submitted this item, so another reviewer (or the GBL 80s Team) must approve it.</p>';
	}
	echo '</div>';
	return ob_get_clean();
}
