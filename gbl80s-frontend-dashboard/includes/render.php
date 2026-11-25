<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function gbl_fd_enqueue() {
	wp_enqueue_style( 'gbl-fd-font', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap', array(), null );
	wp_enqueue_style( 'gbl-fd', GBL_FD_URL . 'assets/dashboard.css', array(), GBL_FD_VERSION );
	wp_enqueue_script( 'gbl-fd', GBL_FD_URL . 'assets/dashboard.js', array(), GBL_FD_VERSION, true );
}
add_action( 'wp_enqueue_scripts', function () {
	if ( is_page( 'dashboard' ) ) {
		gbl_fd_enqueue();
	}
} );

function gbl_fd_nav() {
	$global = gbl_fd_is_global();
	return array(
		'dashboard'   => array( 'Dashboard', 'home', true ),
		'items'       => array( 'Archive Items', 'image', true ),
		'clubs'       => array( 'Clubs', 'users', true ),
		'entries'     => array( 'Entries', 'trophy', true ),
		'nominations' => array( 'Nominations', 'star', $global ),
		'reports'     => array( 'Reports', 'alert', $global ),
		'news'        => array( 'News & Reunions', 'news', $global ),
		'users'       => array( 'Users', 'user', gbl_fd_can( 'gbl_manage_users' ) ),
		'audit'       => array( 'Audit Log', 'history', gbl_fd_can( 'gbl_view_audit' ) ),
		'settings'    => array( 'Settings', 'settings', current_user_can( 'manage_options' ) ),
	);
}

/* ---------------------------------------------------------------- shortcode */
add_shortcode( 'gbl_dashboard', 'gbl_fd_sc_dashboard' );
function gbl_fd_sc_dashboard( $atts ) {
	gbl_fd_enqueue();
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	if ( ! is_user_logged_in() ) {
		return gbl_fd_login_screen();
	}
	if ( ! gbl_fd_can( 'gbl_view_dashboard' ) ) {
		return '<div class="gbl-app gbl-login"><div class="gbl-login__card"><h1>No access</h1><p>Your account does not have access to the dashboard. Ask the GBL 80s Team to give you a club role.</p><p><a class="gbl-btn" href="' . esc_url( wp_logout_url( gbl_fd_dashboard_url() ) ) . '">Sign out</a></p></div></div>';
	}

	$nav  = gbl_fd_nav();
	$view = isset( $_GET['gbl_view'] ) ? sanitize_key( wp_unslash( $_GET['gbl_view'] ) ) : 'dashboard';
	if ( ! isset( $nav[ $view ] ) || ! $nav[ $view ][2] ) {
		$view = 'dashboard';
	}
	$user    = wp_get_current_user();
	$pending = gbl_fd_count( 'gbl_item', 'pending' );
	if ( gbl_fd_is_global() ) {
		$pending += gbl_fd_count( 'gbl_nomination', 'pending' );
	}
	$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

	ob_start();
	?>
	<div class="gbl-app" id="gbl-app" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'gbl_fd' ) ); ?>">
		<aside class="gbl-side" id="gbl-side">
			<a class="gbl-brand" href="<?php echo esc_url( gbl_fd_view_url( 'dashboard' ) ); ?>">
				<?php echo gbl_fd_ball( 54 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span><b>GBL 80s Story</b><small>Gippsland Basketball League<br>1980s History</small></span>
			</a>
			<nav class="gbl-nav" aria-label="Dashboard">
				<?php foreach ( $nav as $slug => $item ) : if ( ! $item[2] ) { continue; } ?>
					<a href="<?php echo esc_url( gbl_fd_view_url( $slug ) ); ?>"<?php echo $slug === $view ? ' class="is-active" aria-current="page"' : ''; ?>>
						<?php echo gbl_fd_icon( $item[1], 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span><?php echo esc_html( $item[0] ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>
			<div class="gbl-side__foot" style="background-image:linear-gradient(rgba(13,33,71,.78),rgba(13,33,71,.94)),url('<?php echo esc_url( GBL_FD_URL . 'assets/sidebar.webp' ); ?>')">
				<p>Preserving Gippsland<br>Basketball History</p>
			</div>
		</aside>

		<div class="gbl-main">
			<header class="gbl-top">
				<button class="gbl-iconbtn gbl-menu" type="button" data-gbl-menu aria-label="Menu"><?php echo gbl_fd_icon( 'menu', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
				<form class="gbl-search" method="get" action="<?php echo esc_url( gbl_fd_dashboard_url() ); ?>" role="search">
					<?php echo gbl_fd_icon( 'search', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<input type="hidden" name="gbl_view" value="items">
					<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="Search archive items, clubs, people, or keywords..." aria-label="Search">
				</form>
				<a class="gbl-iconbtn gbl-bell" href="<?php echo esc_url( gbl_fd_view_url( 'items', array( 'status' => 'pending' ) ) ); ?>" aria-label="Pending reviews">
					<?php echo gbl_fd_icon( 'bell', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php if ( $pending > 0 ) : ?><i><?php echo (int) min( 99, $pending ); ?></i><?php endif; ?>
				</a>
				<div class="gbl-user">
					<button type="button" class="gbl-user__btn" data-gbl-toggle="user-menu" aria-haspopup="true">
						<span class="gbl-avatar"><?php echo gbl_fd_icon( 'user', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<span class="gbl-user__txt"><b><?php echo esc_html( $user->display_name ); ?></b><small><?php echo esc_html( gbl_fd_role_label( $user ) ); ?></small></span>
						<?php echo gbl_fd_icon( 'chevron', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</button>
					<div class="gbl-pop" id="user-menu" hidden>
						<a href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>">Edit my profile</a>
						<?php if ( gbl_fd_is_team_user() ) : ?><a href="<?php echo esc_url( admin_url() ); ?>">WordPress admin</a><?php endif; ?>
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>">View the public site</a>
						<a href="<?php echo esc_url( wp_logout_url( gbl_fd_dashboard_url() ) ); ?>">Sign out</a>
					</div>
				</div>
			</header>
			<main class="gbl-content">
				<?php
				$fn = 'gbl_fd_view_' . $view;
				if ( -1 === gbl_fd_scope() ) {
					echo '<div class="gbl-notice">Your account is not linked to a club yet. Ask the GBL 80s Team to assign your club, then reload this page.</div>';
				}
				if ( isset( $_GET['gbl_submitted'] ) ) {
					echo '<div class="gbl-notice gbl-notice--ok">Thank you. Your submission is saved and waiting for review.</div>';
				}
				call_user_func( $fn );
				?>
			</main>
		</div>

		<div class="gbl-modal" id="gbl-modal" hidden><div class="gbl-modal__box" role="dialog" aria-modal="true"><div id="gbl-modal-body"></div></div></div>
		<div class="gbl-toast" id="gbl-toast" role="status" aria-live="polite"></div>
	</div>
	<?php
	return ob_get_clean();
}

/* ------------------------------------------------------------------- login */
function gbl_fd_login_screen() {
	$dash = gbl_fd_dashboard_url();
	ob_start();
	?>
	<div class="gbl-app gbl-login">
		<div class="gbl-login__card">
			<div class="gbl-login__logo"><?php echo gbl_fd_ball( 60 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><div><b>GBL 80s Story</b><small>Gippsland Basketball League &middot; 1980s History</small></div></div>
			<h1>Sign in</h1>
			<p>Club members, club contributors and the GBL 80s Team sign in here.</p>
			<?php if ( isset( $_GET['gbl_login'] ) ) : ?><p class="gbl-login__err" role="alert">That username or password was not right. Please try again.</p><?php endif; ?>
			<?php
			echo wp_login_form( array( // phpcs:ignore WordPress.Security.EscapeOutput
				'echo'           => false,
				'redirect'       => $dash,
				'label_username' => 'Email or username',
				'label_password' => 'Password',
				'label_log_in'   => 'Sign in',
				'remember'       => true,
			) );
			?>
			<p class="gbl-login__links"><a href="<?php echo esc_url( wp_lostpassword_url( $dash ) ); ?>">Forgot your password?</a> &middot; <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Back to the site</a></p>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
