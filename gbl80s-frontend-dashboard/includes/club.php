<?php
/**
 * Dynamic club pages: /clubs/{club}/ with four tabs (Players & Teams, Press & Reports, Social, Juniors).
 * Content comes live from the Archive items (gbl_item) and Players/teams (gbl_entry) of that club.
 * Shortcodes: [gbl_club] (club taken from the page) and [gbl_club_directory].
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------- club helpers */
function gbl_fd_club_name( $term ) {
	return 'gippsland-lakers' === $term->slug ? 'Gippsland Nubrik Lakers' : $term->name;
}

function gbl_fd_club_division_label( $term ) {
	$d = gbl_fd_division( $term->slug );
	return 'Representative' === $d ? 'East & West representative team' : $d . ' division';
}

function gbl_fd_club_page_for_term( $term ) {
	$page = get_page_by_path( 'clubs/' . $term->slug );
	return $page ? $page : null;
}

function gbl_fd_club_url( $term ) {
	$page = gbl_fd_club_page_for_term( $term );
	return $page ? get_permalink( $page ) : home_url( '/clubs/' . $term->slug . '/' );
}

function gbl_fd_club_term_from_context( $slug = '' ) {
	if ( '' === $slug ) {
		$post = get_post();
		if ( $post && 'page' === $post->post_type ) {
			$slug = (string) get_post_meta( $post->ID, 'gbl_club_slug', true );
			if ( '' === $slug ) {
				$slug = $post->post_name;
			}
		}
	}
	if ( '' === $slug ) {
		return null;
	}
	$term = get_term_by( 'slug', sanitize_title( $slug ), 'gbl_club' );
	return ( $term && ! is_wp_error( $term ) ) ? $term : null;
}

// Is this page one of the club pages (child of "clubs" whose slug matches a club)?
function gbl_fd_is_club_page( $id ) {
	static $cache = array();
	$id = (int) $id;
	if ( isset( $cache[ $id ] ) ) {
		return $cache[ $id ];
	}
	$ok = false;
	$p  = get_post( $id );
	if ( $p && 'page' === $p->post_type && $p->post_parent ) {
		$parent = get_post( $p->post_parent );
		if ( $parent && 'clubs' === $parent->post_name ) {
			$slug = (string) get_post_meta( $id, 'gbl_club_slug', true );
			$slug = '' === $slug ? $p->post_name : $slug;
			$ok   = (bool) term_exists( $slug, 'gbl_club' );
		}
	}
	$cache[ $id ] = $ok;
	return $ok;
}

function gbl_fd_crest( $term, $page = null ) {
	$img = $page ? get_post_meta( $page->ID, 'gbl_club_featured_image', true ) : '';
	$id  = is_array( $img ) && isset( $img['ID'] ) ? (int) $img['ID'] : (int) $img;
	if ( $id && wp_attachment_is_image( $id ) ) {
		return '<span class="gbl-crest gbl-crest--img">' . wp_get_attachment_image( $id, 'medium', false, array( 'alt' => '' ) ) . '</span>';
	}
	if ( 'gippsland-lakers' === $term->slug ) {
		return '<span class="gbl-crest gbl-crest--img"><img src="' . esc_url( GBL_FD_URL . 'assets/lakers.webp' ) . '" alt=""></span>';
	}
	return '<span class="gbl-crest gbl-crest--' . esc_attr( strtolower( gbl_fd_division( $term->slug ) ) ) . '">' . esc_html( strtoupper( substr( $term->name, 0, 3 ) ) ) . '</span>';
}

/* ------------------------------------------ pages: auto-create + Elementor swap */
// Makes sure /clubs/ and /clubs/{club}/ exist for every club term.
function gbl_fd_ensure_club_pages() {
	$created = 0;
	if ( ! taxonomy_exists( 'gbl_club' ) ) {
		return 0;
	}
	$parent = get_page_by_path( 'clubs' );
	if ( ! $parent ) {
		$pid = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Clubs',
			'post_name'    => 'clubs',
			'post_content' => '[gbl_club_directory]',
			'meta_input'   => array( '_wp_page_template' => 'elementor_header_footer' ),
		), true );
		if ( is_wp_error( $pid ) ) {
			return 0;
		}
		$parent = get_post( $pid );
		$created++;
	}
	$terms = get_terms( array( 'taxonomy' => 'gbl_club', 'hide_empty' => false ) );
	if ( is_wp_error( $terms ) ) {
		return $created;
	}
	foreach ( $terms as $t ) {
		if ( get_page_by_path( 'clubs/' . $t->slug ) ) {
			continue;
		}
		$pid = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_parent'  => $parent->ID,
			'post_title'   => gbl_fd_club_name( $t ),
			'post_name'    => $t->slug,
			'post_content' => '[gbl_club]',
			'meta_input'   => array( '_wp_page_template' => 'elementor_header_footer', 'gbl_club_slug' => $t->slug ),
		), true );
		if ( ! is_wp_error( $pid ) ) {
			$created++;
		}
	}
	return $created;
}
add_action( 'created_gbl_club', 'gbl_fd_ensure_club_pages' );
add_action( 'init', function () {
	if ( taxonomy_exists( 'gbl_club' ) && get_option( 'gbl_fd_clubs_ensured' ) !== GBL_FD_VERSION ) {
		gbl_fd_ensure_club_pages();
		update_option( 'gbl_fd_clubs_ensured', GBL_FD_VERSION, false );
	}
}, 30 );

// Existing static Elementor club pages are rendered live instead (front-end only, editor untouched).
add_filter( 'get_post_metadata', function ( $value, $object_id, $meta_key, $single ) {
	if ( '_elementor_data' !== $meta_key || is_admin() || isset( $_GET['elementor-preview'] ) ) {
		return $value;
	}
	if ( ! gbl_fd_is_club_page( $object_id ) ) {
		return $value;
	}
	$zero = array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true );
	$json = wp_json_encode( array( array(
		'id'       => 'gblclub1',
		'elType'   => 'container',
		'isInner'  => false,
		'settings' => array( 'content_width' => 'full', 'padding' => $zero, 'padding_mobile' => $zero, 'flex_gap' => array( 'column' => '0', 'row' => '0', 'isLinked' => true, 'unit' => 'px', 'size' => 0 ) ),
		'elements' => array( array( 'id' => 'gblclub2', 'elType' => 'widget', 'widgetType' => 'shortcode', 'settings' => array( 'shortcode' => '[gbl_club]' ), 'elements' => array() ) ),
	) ) );
	return $single ? $json : array( $json );
}, 10, 4 );

// The taxonomy archive /club/{club}/ sends visitors to the real club page.
add_action( 'template_redirect', function () {
	if ( is_tax( 'gbl_club' ) ) {
		$term = get_queried_object();
		if ( $term && ! empty( $term->slug ) ) {
			wp_safe_redirect( gbl_fd_club_url( $term ), 301 );
			exit;
		}
	}
} );

function gbl_fd_club_assets() {
	wp_enqueue_style( 'gbl-fd-club-font', 'https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=DM+Sans:wght@400;500;700&display=swap', array(), null );
	wp_enqueue_style( 'gbl-fd-club', GBL_FD_URL . 'assets/club.css', array(), GBL_FD_VERSION );
	wp_enqueue_script( 'gbl-fd-club', GBL_FD_URL . 'assets/club.js', array(), GBL_FD_VERSION, true );
}
add_action( 'wp_enqueue_scripts', function () {
	if ( is_page() && gbl_fd_is_club_page( get_queried_object_id() ) ) {
		gbl_fd_club_assets();
	}
} );

/* ----------------------------------------------------------------- queries */
function gbl_fd_club_items( $club_id, $sections, $limit = 80 ) {
	return get_posts( array(
		'post_type'        => 'gbl_item',
		'post_status'      => 'publish',
		'posts_per_page'   => $limit,
		'orderby'          => 'date',
		'order'            => 'DESC',
		'no_found_rows'    => true,
		'suppress_filters' => false,
		'tax_query'        => array(
			'relation' => 'AND',
			array( 'taxonomy' => 'gbl_club', 'field' => 'term_id', 'terms' => array( (int) $club_id ) ),
			array( 'taxonomy' => 'gbl_section', 'field' => 'slug', 'terms' => (array) $sections ),
		),
	) );
}

function gbl_fd_club_entries( $club_id, $kind, $limit = 80 ) {
	return get_posts( array(
		'post_type'      => 'gbl_entry',
		'post_status'    => 'publish',
		'posts_per_page' => $limit,
		'orderby'        => 'title',
		'order'          => 'ASC',
		'no_found_rows'  => true,
		'tax_query'      => array(
			'relation' => 'AND',
			array( 'taxonomy' => 'gbl_club', 'field' => 'term_id', 'terms' => array( (int) $club_id ) ),
			array( 'taxonomy' => 'gbl_entry_kind', 'field' => 'slug', 'terms' => array( $kind ) ),
		),
	) );
}

function gbl_fd_club_pending_count( $club_id ) {
	$q = new WP_Query( array(
		'post_type'      => 'gbl_item',
		'post_status'    => 'pending',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'tax_query'      => array( array( 'taxonomy' => 'gbl_club', 'field' => 'term_id', 'terms' => array( (int) $club_id ) ) ),
	) );
	return (int) $q->found_posts;
}

/* ------------------------------------------------------------------- cards */
function gbl_fd_pc_report_url( $title ) {
	$page = get_page_by_path( 'report' );
	$base = $page ? get_permalink( $page ) : home_url( '/report/' );
	return add_query_arg( 'item', $title, $base );
}

function gbl_fd_pc_gallery_urls( $post ) {
	$urls = array();
	$f    = get_the_post_thumbnail_url( $post, 'large' );
	if ( $f ) {
		$urls[] = $f;
	}
	$g = get_post_meta( $post->ID, 'gbl_more_images', true );
	if ( is_array( $g ) ) {
		foreach ( $g as $att ) {
			$u = wp_get_attachment_image_url( (int) $att, 'large' );
			if ( $u && ! in_array( $u, $urls, true ) ) {
				$urls[] = $u;
			}
		}
	}
	return $urls;
}

function gbl_fd_pc_thumb( $post, $section_hint ) {
	$img = get_the_post_thumbnail( $post, 'medium_large', array( 'loading' => 'lazy', 'alt' => '' ) );
	if ( ! $img ) {
		$g = get_post_meta( $post->ID, 'gbl_more_images', true );
		if ( is_array( $g ) && $g ) {
			$img = wp_get_attachment_image( (int) reset( $g ), 'medium_large', false, array( 'loading' => 'lazy', 'alt' => '' ) );
		}
	}
	if ( $img ) {
		return '<span class="gbl-pc__img">' . $img . '</span>';
	}
	$icons = array( 'press' => '📰', 'social' => '🎉', 'juniors' => '📸', 'players' => '🏀', 'history' => '📜' );
	$icon  = isset( $icons[ $section_hint ] ) ? $icons[ $section_hint ] : '🏀';
	if ( gbl_fd_meta( $post->ID, 'gbl_pdf' ) ) {
		$icon = '📄';
	}
	return '<span class="gbl-pc__img gbl-pc__img--ph" aria-hidden="true">' . $icon . '</span>';
}

// Public-safe details for the lightbox. Never prints the private contact or review fields.
function gbl_fd_pc_lightbox( $post, $club_name ) {
	$type   = gbl_fd_first_term_name( $post->ID, 'gbl_material_type' );
	$rows   = array(
		'Type'   => $type,
		'Year'   => gbl_fd_meta( $post->ID, 'gbl_year' ),
		'People' => gbl_fd_meta( $post->ID, 'gbl_people' ),
		'Event'  => gbl_fd_meta( $post->ID, 'gbl_event' ),
		'Credit' => gbl_fd_meta( $post->ID, 'gbl_source' ),
		'Club'   => $club_name,
	);
	$out = '<div class="gbl-lbsrc" id="lb-' . (int) $post->ID . '" hidden>';
	$imgs = gbl_fd_pc_gallery_urls( $post );
	if ( $imgs ) {
		$out .= '<div class="gbl-lb__imgs">';
		foreach ( $imgs as $u ) {
			$out .= '<img src="' . esc_url( $u ) . '" alt="" loading="lazy">';
		}
		$out .= '</div>';
	}
	$out .= '<h3>' . esc_html( get_the_title( $post ) ) . '</h3><dl>';
	foreach ( $rows as $k => $v ) {
		if ( '' !== trim( (string) $v ) ) {
			$out .= '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd>';
		}
	}
	$out .= '</dl>';
	if ( '' !== trim( $post->post_content ) ) {
		$out .= '<div class="gbl-lb__text">' . wp_kses_post( wpautop( $post->post_content ) ) . '</div>';
	}
	$out .= '<p class="gbl-lb__links">';
	$pdf  = (int) gbl_fd_meta( $post->ID, 'gbl_pdf' );
	if ( $pdf && wp_get_attachment_url( $pdf ) ) {
		$out .= '<a class="gbl-b" href="' . esc_url( wp_get_attachment_url( $pdf ) ) . '" target="_blank" rel="noopener">Open scanned PDF</a> ';
	}
	$out .= '<a class="gbl-b gbl-b--ghost" href="' . esc_url( get_permalink( $post ) ) . '">Open full page</a> ';
	$out .= '<a class="gbl-b gbl-b--ghost" href="' . esc_url( gbl_fd_pc_report_url( get_the_title( $post ) . ' (' . $club_name . ')' ) ) . '">Report a problem</a></p></div>';
	return $out;
}

// Returns array( html, type, year, search ) for an archive item card.
function gbl_fd_pc_item_card( $post, $section_hint, $club_name ) {
	$type = gbl_fd_first_term_name( $post->ID, 'gbl_material_type' );
	$year = gbl_fd_meta( $post->ID, 'gbl_year' );
	$ppl  = gbl_fd_meta( $post->ID, 'gbl_people' );
	$ev   = gbl_fd_meta( $post->ID, 'gbl_event' );
	$srch = strtolower( implode( ' ', array( get_the_title( $post ), $type, $year, $ppl, $ev, gbl_fd_meta( $post->ID, 'gbl_source' ), wp_strip_all_tags( $post->post_content ) ) ) );
	$html = '<article class="gbl-pc" data-type="' . esc_attr( $type ) . '" data-year="' . esc_attr( '' !== $year ? $year : 'unknown' ) . '" data-search="' . esc_attr( $srch ) . '">';
	$html .= '<button type="button" class="gbl-pc__open" data-gbl-lb="' . (int) $post->ID . '">' . gbl_fd_pc_thumb( $post, $section_hint );
	$html .= '<span class="gbl-pc__meta">' . esc_html( strtoupper( $type ? $type : 'Item' ) ) . '</span>';
	$html .= '<span class="gbl-pc__title">' . esc_html( get_the_title( $post ) ) . '</span>';
	$html .= '<span class="gbl-pc__sub">' . esc_html( trim( ( $ppl ? $ppl . ' · ' : '' ) . ( '' !== $year ? $year : 'Date unknown' ) ) ) . '</span></button>';
	$html .= gbl_fd_pc_lightbox( $post, $club_name ) . '</article>';
	return array( 'html' => $html, 'type' => $type, 'year' => $year );
}

function gbl_fd_pc_entry_card( $post, $kind_label ) {
	$role  = gbl_fd_meta( $post->ID, 'gbl_role' );
	$years = gbl_fd_meta( $post->ID, 'gbl_years' );
	$thumb = get_the_post_thumbnail( $post, 'thumbnail', array( 'loading' => 'lazy', 'alt' => '' ) );
	$av    = $thumb ? '<span class="gbl-pc__av">' . $thumb . '</span>' : '<span class="gbl-pc__av gbl-pc__av--ph">' . esc_html( strtoupper( substr( get_the_title( $post ), 0, 1 ) ) ) . '</span>';
	$srch  = strtolower( implode( ' ', array( get_the_title( $post ), $role, $years, $kind_label, wp_strip_all_tags( $post->post_content ) ) ) );
	$html  = '<article class="gbl-pc gbl-pc--entry" data-type="' . esc_attr( $kind_label ) . '" data-year="' . esc_attr( '' !== $years ? $years : 'unknown' ) . '" data-search="' . esc_attr( $srch ) . '">' . $av . '<div>';
	$html .= '<span class="gbl-pc__meta">' . esc_html( strtoupper( $kind_label ) ) . '</span><span class="gbl-pc__title">' . esc_html( get_the_title( $post ) ) . '</span>';
	$html .= '<span class="gbl-pc__sub">' . esc_html( trim( $role . ( $role && $years ? ' · ' : '' ) . $years ) ) . '</span>';
	$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 28 );
	if ( $excerpt ) {
		$html .= '<span class="gbl-pc__txt">' . esc_html( $excerpt ) . '</span>';
	}
	return array( 'html' => $html . '</div></article>', 'type' => $kind_label, 'year' => $years );
}

/* ------------------------------------------------------------------- panel */
function gbl_fd_pc_can_add( $term ) {
	if ( ! gbl_fd_can( 'gbl_submit_items' ) ) {
		return false;
	}
	return gbl_fd_is_global() || gbl_fd_scope() === (int) $term->term_id;
}

function gbl_fd_pc_add_link( $kind, $term, $section, $label ) {
	if ( ! gbl_fd_pc_can_add( $term ) ) {
		return '';
	}
	$url = gbl_fd_add_url( $kind );
	if ( '' === $url ) {
		return '';
	}
	$args = array( 'gbl_club' => (int) $term->term_id );
	if ( $section ) {
		$args['gbl_section'] = $section;
	}
	return '<a class="gbl-b" href="' . esc_url( add_query_arg( $args, $url ) ) . '">+ ' . esc_html( $label ) . '</a>';
}

// $groups: array of array( 'label', 'cards' => list of card arrays )
function gbl_fd_pc_panel( $key, $title, $lead, $groups, $add_html, $intro = '' ) {
	$types = array();
	$years = array();
	$has_unknown = false;
	$total = 0;
	foreach ( $groups as $g ) {
		foreach ( $g['cards'] as $c ) {
			$total++;
			if ( $c['type'] ) {
				$types[ $c['type'] ] = true;
			}
			if ( '' !== $c['year'] ) {
				$years[ $c['year'] ] = true;
			} else {
				$has_unknown = true;
			}
		}
	}
	ksort( $types );
	krsort( $years );
	$out  = '<section class="gbl-cp__panel" id="' . esc_attr( $key ) . '" role="tabpanel" aria-labelledby="tab-' . esc_attr( $key ) . '" data-panel="' . esc_attr( $key ) . '">';
	$out .= '<div class="gbl-wrap"><div class="gbl-cp__ph"><div><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $lead ) . '</p></div><div class="gbl-cp__add">' . $add_html . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	if ( '' !== $intro ) {
		$out .= '<div class="gbl-cp__intro">' . $intro . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	if ( ! $total ) {
		$out .= '<div class="gbl-cp__empty"><p><b>Nothing here yet.</b> Approved photos, clippings and stories for this section will appear here.</p></div></div></section>';
		return $out;
	}
	$out .= '<div class="gbl-pf"><input type="search" data-f="q" placeholder="Search this section" aria-label="Search this section">';
	if ( count( $types ) > 1 ) {
		$out .= '<select data-f="type" aria-label="Type"><option value="">All types</option>';
		foreach ( array_keys( $types ) as $t ) {
			$out .= '<option>' . esc_html( $t ) . '</option>';
		}
		$out .= '</select>';
	}
	if ( count( $years ) + ( $has_unknown ? 1 : 0 ) > 1 ) {
		$out .= '<select data-f="year" aria-label="Year"><option value="">Any year</option>';
		foreach ( array_keys( $years ) as $y ) {
			$out .= '<option>' . esc_html( $y ) . '</option>';
		}
		if ( $has_unknown ) {
			$out .= '<option value="unknown">Date unknown</option>';
		}
		$out .= '</select>';
	}
	$out .= '<span class="gbl-pf__count" aria-live="polite"></span></div>';
	foreach ( $groups as $g ) {
		if ( ! $g['cards'] ) {
			continue;
		}
		$out .= '<div class="gbl-pgroup">';
		if ( '' !== $g['label'] ) {
			$out .= '<h3>' . esc_html( $g['label'] ) . ' <small>' . count( $g['cards'] ) . '</small></h3>';
		}
		$out .= '<div class="gbl-pgrid">';
		foreach ( $g['cards'] as $c ) {
			$out .= $c['html'];
		}
		$out .= '</div><button type="button" class="gbl-b gbl-b--ghost gbl-more" hidden>Show more</button></div>';
	}
	$out .= '<p class="gbl-cp__none" hidden>No matches. Try fewer filters.</p></div></section>';
	return $out;
}

function gbl_fd_pc_cards( $posts, $kind, $section_hint, $club_name, $kind_label = '' ) {
	$cards = array();
	foreach ( $posts as $p ) {
		$cards[] = ( 'entry' === $kind ) ? gbl_fd_pc_entry_card( $p, $kind_label ) : gbl_fd_pc_item_card( $p, $section_hint, $club_name );
	}
	return $cards;
}

/* ------------------------------------------------------------ club history */
function gbl_fd_history_year( $post ) {
	if ( preg_match( '/(1[89]\d\d|20\d\d)/', gbl_fd_meta( $post->ID, 'gbl_year' ), $m ) ) {
		return (int) $m[1];
	}
	return 0;
}

// Groups Club History items by era (from the Year field), oldest first.
function gbl_fd_history_groups( $posts, $club_name ) {
	$buckets = array(
		'early'   => array( 'Early beginnings', array() ),
		'1960s'   => array( 'The 1960s', array() ),
		'1970s'   => array( 'The 1970s', array() ),
		'1980s'   => array( 'The 1980s', array() ),
		'later'   => array( 'The 1990s and later', array() ),
		'legends' => array( 'Legends and memories', array() ),
		'honours' => array( 'Honours and achievements', array() ),
		'undated' => array( 'Undated', array() ),
	);
	foreach ( $posts as $p ) {
		$t    = get_the_terms( $p->ID, 'gbl_material_type' );
		$slug = ( $t && ! is_wp_error( $t ) ) ? reset( $t )->slug : '';
		$y    = gbl_fd_history_year( $p );
		if ( 'legends-memories' === $slug ) {
			$k = 'legends';
		} elseif ( 'honours-achievements' === $slug ) {
			$k = 'honours';
		} elseif ( 0 === $y ) {
			$k = 'undated';
		} elseif ( $y < 1960 ) {
			$k = 'early';
		} elseif ( $y < 1970 ) {
			$k = '1960s';
		} elseif ( $y < 1980 ) {
			$k = '1970s';
		} elseif ( $y < 1990 ) {
			$k = '1980s';
		} else {
			$k = 'later';
		}
		$buckets[ $k ][1][] = $p;
	}
	$groups = array();
	foreach ( $buckets as $b ) {
		$list = $b[1];
		usort( $list, function ( $a, $c ) {
			return gbl_fd_history_year( $a ) - gbl_fd_history_year( $c );
		} );
		$groups[] = array( 'label' => $b[0], 'cards' => gbl_fd_pc_cards( $list, 'item', 'history', $club_name ) );
	}
	return $groups;
}

// The Club History section term and two history material types are created automatically.
function gbl_fd_ensure_terms() {
	if ( taxonomy_exists( 'gbl_section' ) && ! term_exists( 'history', 'gbl_section' ) ) {
		wp_insert_term( 'Club History', 'gbl_section', array( 'slug' => 'history' ) );
	}
	if ( taxonomy_exists( 'gbl_material_type' ) ) {
		$types = array( 'legends-memories' => 'Legends and memories', 'honours-achievements' => 'Honours and achievements' );
		foreach ( $types as $slug => $name ) {
			if ( ! term_exists( $slug, 'gbl_material_type' ) ) {
				wp_insert_term( $name, 'gbl_material_type', array( 'slug' => $slug ) );
			}
		}
	}
}
add_action( 'init', function () {
	if ( get_option( 'gbl_fd_terms_ensured' ) !== GBL_FD_VERSION ) {
		gbl_fd_ensure_terms();
		update_option( 'gbl_fd_terms_ensured', GBL_FD_VERSION, false );
	}
}, 31 );

/* ----------------------------------------------------------- the club page */
add_shortcode( 'gbl_club', 'gbl_fd_sc_club' );
function gbl_fd_sc_club( $atts ) {
	$a = shortcode_atts( array( 'club' => '' ), $atts );
	gbl_fd_club_assets();
	$term = gbl_fd_club_term_from_context( sanitize_title( $a['club'] ) );
	if ( ! $term ) {
		return gbl_fd_club_directory_html();
	}
	return gbl_fd_club_page_html( $term );
}

function gbl_fd_club_page_html( $term ) {
	$id    = (int) $term->term_id;
	$name  = gbl_fd_club_name( $term );
	$page  = gbl_fd_club_page_for_term( $term );
	$desc  = $page ? trim( (string) get_post_meta( $page->ID, 'gbl_club_description', true ) ) : '';
	if ( '' === $desc ) {
		$desc = $name . ' in the Gippsland Basketball League. Browse players and teams, press and reports, social photos and juniors history from the 1980s.';
	}

	$history_raw   = $page ? trim( (string) get_post_meta( $page->ID, 'gbl_club_history', true ) ) : '';
	$history_intro = '' !== $history_raw ? wp_kses_post( wpautop( esc_html( $history_raw ) ) ) : '';

	$sections = array(
		'players' => array(
			'title' => 'Players & Teams',
			'lead'  => 'Squads, players and team photographs.',
			'add'   => gbl_fd_pc_add_link( 'entry', $term, '', 'Add a player or team' ) . ' ' . gbl_fd_pc_add_link( 'item', $term, 'players', 'Add a team photo' ),
			'groups' => array(
				array( 'label' => 'Teams', 'cards' => gbl_fd_pc_cards( gbl_fd_club_entries( $id, 'team' ), 'entry', 'players', $name, 'Team' ) ),
				array( 'label' => 'Players', 'cards' => gbl_fd_pc_cards( gbl_fd_club_entries( $id, 'player' ), 'entry', 'players', $name, 'Player' ) ),
				array( 'label' => 'Team photographs and records', 'cards' => gbl_fd_pc_cards( gbl_fd_club_items( $id, array( 'players', 'teams' ) ), 'item', 'players', $name ) ),
			),
		),
		'press'   => array(
			'title' => 'Press & Reports',
			'lead'  => 'Newspaper clippings, programmes, scorecards and club reports.',
			'add'   => gbl_fd_pc_add_link( 'item', $term, 'press', 'Add a clipping or report' ),
			'groups' => array( array( 'label' => '', 'cards' => gbl_fd_pc_cards( gbl_fd_club_items( $id, array( 'press' ) ), 'item', 'press', $name ) ) ),
		),
		'social'  => array(
			'title' => 'Social',
			'lead'  => 'Photos and stories from social occasions and celebrations.',
			'add'   => gbl_fd_pc_add_link( 'item', $term, 'social', 'Add a social post' ),
			'groups' => array( array( 'label' => '', 'cards' => gbl_fd_pc_cards( gbl_fd_club_items( $id, array( 'social' ) ), 'item', 'social', $name ) ) ),
		),
		'juniors' => array(
			'title' => 'Juniors',
			'lead'  => 'Junior teams and pictorial history.',
			'add'   => gbl_fd_pc_add_link( 'item', $term, 'juniors', 'Add junior history' ),
			'groups' => array(
				array( 'label' => 'Junior players and teams', 'cards' => gbl_fd_pc_cards( gbl_fd_club_entries( $id, 'junior' ), 'entry', 'juniors', $name, 'Junior' ) ),
				array( 'label' => 'Pictorial history', 'cards' => gbl_fd_pc_cards( gbl_fd_club_items( $id, array( 'juniors' ) ), 'item', 'juniors', $name ) ),
			),
		),
		'history' => array(
			'title'  => 'Club History',
			'lead'   => 'From the early beginnings through the decades.',
			'intro'  => $history_intro,
			'add'    => gbl_fd_pc_add_link( 'item', $term, 'history', 'Add club history' ),
			'groups' => gbl_fd_history_groups( gbl_fd_club_items( $id, array( 'history' ), 200 ), $name ),
		),
	);

	$tabs = '';
	$panels = '';
	foreach ( $sections as $key => $s ) {
		$count = 0;
		foreach ( $s['groups'] as $g ) {
			$count += count( $g['cards'] );
		}
		$tabs   .= '<a role="tab" id="tab-' . esc_attr( $key ) . '" href="#' . esc_attr( $key ) . '" data-tab="' . esc_attr( $key ) . '" aria-controls="' . esc_attr( $key ) . '">' . esc_html( $s['title'] ) . ' <span>' . (int) $count . '</span></a>';
		$panels .= gbl_fd_pc_panel( $key, $s['title'], $s['lead'], $s['groups'], $s['add'], isset( $s['intro'] ) ? $s['intro'] : '' );
	}

	$notice = '';
	if ( is_user_logged_in() && ( gbl_fd_is_global() || ( gbl_fd_can( 'gbl_review_club' ) && gbl_fd_scope() === $id ) ) ) {
		$n = gbl_fd_club_pending_count( $id );
		if ( $n > 0 ) {
			$notice = '<div class="gbl-cp__review"><div class="gbl-wrap"><b>' . (int) $n . ' item' . ( 1 === $n ? ' is' : 's are' ) . ' waiting for review.</b> <a href="' . esc_url( gbl_fd_view_url( 'items', array( 'status' => 'pending', 'club' => $id ) ) ) . '">Open the review queue &rarr;</a></div></div>';
		}
	}

	$meta = array( gbl_fd_club_division_label( $term ) );
	ob_start();
	?>
	<div class="gbl-cp" id="gbl-cp" data-club="<?php echo esc_attr( $term->slug ); ?>">
		<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<header class="gbl-cp__hero">
			<div class="gbl-wrap">
				<p class="gbl-cp__crumb"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a> / <a href="<?php echo esc_url( home_url( '/clubs/' ) ); ?>">Clubs</a> / <?php echo esc_html( $name ); ?></p>
				<div class="gbl-cp__id">
					<?php echo gbl_fd_crest( $term, $page ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<div>
						<p class="gbl-cp__kick">&mdash;&ensp;<?php echo esc_html( strtoupper( implode( ' · ', $meta ) ) ); ?></p>
						<h1><?php echo esc_html( $name ); ?></h1>
					</div>
				</div>
				<p class="gbl-cp__desc"><?php echo esc_html( $desc ); ?></p>
			</div>
		</header>
		<nav class="gbl-cp__tabs" aria-label="<?php echo esc_attr( $name ); ?> sections">
			<div class="gbl-wrap"><div class="gbl-cp__tablist" role="tablist"><?php echo $tabs; // phpcs:ignore WordPress.Security.EscapeOutput ?></div></div>
		</nav>
		<div class="gbl-cp__panels"><?php echo $panels; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
		<div class="gbl-lb" id="gbl-lb" hidden>
			<div class="gbl-lb__box" role="dialog" aria-modal="true" aria-label="Item details">
				<button type="button" class="gbl-lb__x" data-lb-close aria-label="Close">&times;</button>
				<div class="gbl-lb__body" id="gbl-lb-body"></div>
				<div class="gbl-lb__nav"><button type="button" data-lb-prev>&larr; Previous</button><button type="button" data-lb-next>Next &rarr;</button></div>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/* --------------------------------------------------------------- directory */
add_shortcode( 'gbl_club_directory', function () {
	gbl_fd_club_assets();
	return gbl_fd_club_directory_html();
} );

function gbl_fd_club_directory_html() {
	$terms = get_terms( array( 'taxonomy' => 'gbl_club', 'hide_empty' => false, 'orderby' => 'name' ) );
	if ( is_wp_error( $terms ) || ! $terms ) {
		return '<div class="gbl-cp"><div class="gbl-wrap"><p>No clubs yet.</p></div></div>';
	}
	$out = '<div class="gbl-cp gbl-cp--dir"><div class="gbl-wrap"><div class="gbl-dir">';
	foreach ( $terms as $t ) {
		$out .= '<a class="gbl-dir__card" href="' . esc_url( gbl_fd_club_url( $t ) ) . '">' . gbl_fd_crest( $t, gbl_fd_club_page_for_term( $t ) ) . '<span><b>' . esc_html( gbl_fd_club_name( $t ) ) . '</b><small>' . esc_html( gbl_fd_club_division_label( $t ) ) . '</small></span></a>';
	}
	return $out . '</div></div></div>';
}
