<?php
/**
 * Plugin Name: Custom Login URL
 * Description: MU Plugin that serves the WordPress login screen from a custom slug and hides wp-login.php.
 * Version: 2.0.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Matchbox
 *
 * @package custom-login-url
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves wp-login.php from a custom slug and returns a 404 for the real file.
 *
 * The login screen is rendered in place at the custom slug rather than redirected
 * to, so the browser never lands on wp-login.php and no access cookie is needed.
 */
final class Matchbox_Custom_Login_URL {

	/**
	 * Slug used when the LOGIN_URL constant is not defined.
	 */
	const DEFAULT_SLUG = 'web-ad';

	/**
	 * Transient caching whether existing content already occupies the login slug.
	 */
	const CONFLICT_TRANSIENT = 'matchbox_clu_slug_conflict';

	/**
	 * Resolved login slug, or null until first resolved.
	 *
	 * @var string|null
	 */
	private static $slug = null;

	/**
	 * Whether this request should be served as the login screen.
	 *
	 * @var bool
	 */
	private static $serve_login = false;

	/**
	 * Whether this request is a direct hit on wp-login.php and should 404.
	 *
	 * @var bool
	 */
	private static $block_request = false;

	/**
	 * Whether init() has already run.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		add_action( 'plugins_loaded', array( __CLASS__, 'on_plugins_loaded' ), 1 );
		add_action( 'wp_loaded', array( __CLASS__, 'on_wp_loaded' ), 1 );

		add_filter( 'site_url', array( __CLASS__, 'filter_site_url' ), 10, 3 );
		add_filter( 'network_site_url', array( __CLASS__, 'filter_site_url' ), 10, 3 );
		add_filter( 'wp_redirect', array( __CLASS__, 'filter_wp_redirect' ), 10, 1 );

		// Core redirects /wp-login.php and /login to wp_login_url() on any 404,
		// which would hand the custom slug to anyone who guessed either path.
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );

		add_filter( 'wp_unique_post_slug', array( __CLASS__, 'reserve_slug' ), 10, 5 );
		add_action( 'save_post', array( __CLASS__, 'flush_conflict_cache' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
	}

	/**
	 * Resolves the login slug once and caches it for the request.
	 *
	 * Returns an empty string if the configured slug is unusable, in which case
	 * the plugin stands down entirely rather than risk locking everyone out.
	 *
	 * @return string
	 */
	public static function slug() {
		if ( null === self::$slug ) {
			$slug = defined( 'LOGIN_URL' ) ? LOGIN_URL : self::DEFAULT_SLUG;

			/**
			 * Filters the slug the login screen is served from.
			 *
			 * @param string $slug Sanitized login slug.
			 */
			self::$slug = (string) apply_filters( 'matchbox_custom_login_slug', sanitize_title( (string) $slug ) );
		}

		return self::$slug;
	}

	/**
	 * Returns the full URL of the custom login screen.
	 *
	 * @param string|null $scheme Optional. URL scheme to use.
	 * @return string
	 */
	public static function login_url( $scheme = null ) {
		return home_url( '/' . self::slug() . '/', $scheme );
	}

	/**
	 * Flags the request as either the login screen or a direct wp-login.php hit.
	 *
	 * Runs early so that $pagenow is corrected before core reads it, but the
	 * login screen itself is not rendered until wp_loaded, by which point every
	 * plugin has registered its login hooks.
	 */
	public static function on_plugins_loaded() {
		global $pagenow;

		if ( '' === self::slug() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		if ( self::is_login_slug_request() ) {
			self::$serve_login = true;
			$pagenow           = 'wp-login.php';
			return;
		}

		if ( 'wp-login.php' === $pagenow ) {
			self::$block_request = true;
		}
	}

	/**
	 * Renders either the login screen or a 404, then ends the request.
	 */
	public static function on_wp_loaded() {
		if ( self::$serve_login ) {
			self::serve_login();
		}

		if ( self::$block_request ) {
			self::render_404();
		}

		if ( self::should_block_admin() ) {
			self::block_admin();
		}
	}

	/**
	 * Sends a logged-out wp-admin request to the home page.
	 *
	 * The theme's 404 cannot be rendered here. wp-admin/admin.php defines
	 * WP_ADMIN before WordPress boots, so is_admin() is true for the rest of the
	 * request and cannot be unset; is_admin_bar_showing() then returns true
	 * unconditionally, before the show_admin_bar filter runs, and rendering a
	 * front-end template fatals in admin-bar.php on get_current_screen() -
	 * which only exists once the admin bootstrap has loaded
	 * wp-admin/includes/screen.php. Redirecting keeps the slug out of the
	 * response without depending on any of that.
	 */
	private static function block_admin() {
		nocache_headers();
		wp_safe_redirect( home_url( '/' ), 302 );
		exit;
	}

	/**
	 * Determines whether to block a logged-out request to wp-admin.
	 *
	 * /wp-admin/ is a public probe target. Left alone, core's auth_redirect()
	 * sends anonymous requests to wp_login_url(), putting the custom slug in a
	 * Location header for anyone who asks - which gives away the slug more
	 * cheaply than wp-login.php ever did.
	 *
	 * @return bool
	 */
	private static function should_block_admin() {
		global $pagenow;

		if ( '' === self::slug() || ! is_admin() || wp_doing_ajax() || is_user_logged_in() ) {
			return false;
		}

		// Endpoints under wp-admin that legitimately serve logged-out requests,
		// plus the install and repair routines, which must stay reachable when
		// there is no session to be had.
		$public_endpoints = array(
			'admin-ajax.php',
			'admin-post.php',
			'load-scripts.php',
			'load-styles.php',
			'install.php',
			'setup-config.php',
			'upgrade.php',
			'repair.php',
		);

		return ! in_array( $pagenow, $public_endpoints, true );
	}

	/**
	 * Loads wp-login.php in place of the current request.
	 */
	private static function serve_login() {
		// wp-login.php assigns these at its top level. Requiring it from inside a
		// method would otherwise scope them locally, hiding them from the core
		// helpers (login_header(), login_footer(), the login_form_* hooks) that
		// declare them global.
		global $error, $errors, $interim_login, $action, $user_login, $redirect_to, $requested_redirect_to, $user, $secure_cookie, $reauth;

		// Keep full-page caches away from the login screen.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		require_once ABSPATH . 'wp-login.php';
		exit;
	}

	/**
	 * Renders the theme's 404 template so wp-login.php looks like it is not there.
	 *
	 * A 403 would confirm the file exists, which defeats the point of moving it.
	 */
	private static function render_404() {
		global $wp_query, $pagenow;

		$pagenow = 'index.php';

		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', true );
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		// Resolve the main query, then force the 404 rather than trusting how
		// /wp-login.php happens to parse against the site's rewrite rules.
		wp();

		$wp_query->set_404();
		$wp_query->posts      = array();
		$wp_query->post_count = 0;
		$wp_query->post       = null;
		status_header( 404 );
		nocache_headers();

		// Both of these would happily redirect a 404 to a real post.
		remove_action( 'template_redirect', 'redirect_canonical' );
		remove_action( 'template_redirect', 'wp_old_slug_redirect' );

		require_once ABSPATH . WPINC . '/template-loader.php';
		exit;
	}

	/**
	 * Determines whether the current request path is the login slug.
	 *
	 * @return bool
	 */
	private static function is_login_slug_request() {
		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

		return self::slug() === self::path_relative_to_home( $path );
	}

	/**
	 * Strips the site's home path from a request path, for subdirectory installs.
	 *
	 * @param string $path Request path.
	 * @return string|null Path relative to home without surrounding slashes, or
	 *                     null if the path falls outside the site's home path.
	 */
	private static function path_relative_to_home( $path ) {
		$home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$path      = trim( rawurldecode( $path ), '/' );

		if ( '' === $home_path ) {
			return $path;
		}

		if ( $path === $home_path ) {
			return '';
		}

		if ( 0 === strpos( $path, $home_path . '/' ) ) {
			return substr( $path, strlen( $home_path ) + 1 );
		}

		return null;
	}

	/**
	 * Rewrites any wp-login.php URL built by core to the custom slug.
	 *
	 * Filtering site_url() and network_site_url() covers wp_login_url(),
	 * wp_logout_url(), wp_registration_url(), wp_lostpassword_url(), the login
	 * form's own action attribute, and the reset links inside password reset and
	 * new user notification emails.
	 *
	 * @param string      $url    The complete URL.
	 * @param string      $path   Unused. Path relative to the site URL.
	 * @param string|null $scheme URL scheme.
	 * @return string
	 */
	public static function filter_site_url( $url, $path = '', $scheme = null ) {
		return self::rewrite_login_url( $url, $scheme );
	}

	/**
	 * Rewrites redirects that point back at wp-login.php.
	 *
	 * @param string $location Redirect target.
	 * @return string
	 */
	public static function filter_wp_redirect( $location ) {
		return self::rewrite_login_url( $location, null );
	}

	/**
	 * Swaps wp-login.php for the custom slug, preserving any query arguments.
	 *
	 * @param string      $url    URL to inspect.
	 * @param string|null $scheme URL scheme.
	 * @return string
	 */
	private static function rewrite_login_url( $url, $scheme = null ) {
		if ( '' === self::slug() || ! is_string( $url ) || false === strpos( $url, 'wp-login.php' ) ) {
			return $url;
		}

		// Keep whatever scheme core already decided on; it accounts for
		// force_ssl_admin() and friends better than a fresh guess would.
		$url_scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( $url_scheme ) {
			$scheme = $url_scheme;
		}

		$new_url = self::login_url( $scheme );

		// Carry the query string across verbatim. Round-tripping it through
		// parse_str() and add_query_arg() is lossy: add_query_arg() does not
		// re-encode values, so a reset key for a user_login containing "+"
		// would come back out as a space.
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! empty( $query ) ) {
			$new_url .= '?' . $query;
		}

		$fragment = wp_parse_url( $url, PHP_URL_FRAGMENT );
		if ( ! empty( $fragment ) ) {
			$new_url .= '#' . $fragment;
		}

		return $new_url;
	}

	/**
	 * Stops new top-level content from claiming the login slug and shadowing it.
	 *
	 * @param string $slug        Proposed post slug.
	 * @param int    $post_id     Post ID.
	 * @param string $post_status Post status.
	 * @param string $post_type   Post type.
	 * @param int    $post_parent Post parent ID.
	 * @return string
	 */
	public static function reserve_slug( $slug, $post_id, $post_status, $post_type, $post_parent ) {
		if ( 0 === (int) $post_parent && '' !== self::slug() && $slug === self::slug() ) {
			return $slug . '-2';
		}

		return $slug;
	}

	/**
	 * Clears the cached slug conflict check when content is saved.
	 */
	public static function flush_conflict_cache() {
		delete_transient( self::CONFLICT_TRANSIENT );
	}

	/**
	 * Warns administrators about a misconfigured or shadowed login slug.
	 */
	public static function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( '' === self::slug() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( 'Custom Login URL: the LOGIN_URL constant is empty or invalid, so wp-login.php has been left unprotected.' )
			);
			return;
		}

		$conflict = get_transient( self::CONFLICT_TRANSIENT );

		if ( false === $conflict ) {
			$conflict = self::slug_is_taken() ? '1' : '0';
			set_transient( self::CONFLICT_TRANSIENT, $conflict, DAY_IN_SECONDS );
		}

		if ( '1' === $conflict ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html( sprintf( 'Custom Login URL: existing content uses the slug "%s" and is unreachable while it serves the login screen.', self::slug() ) )
			);
		}
	}

	/**
	 * Checks whether any existing content already uses the login slug.
	 *
	 * Only ever runs in the admin, where every post type has been registered.
	 *
	 * @return bool
	 */
	private static function slug_is_taken() {
		$posts = get_posts(
			array(
				'name'                   => self::slug(),
				'post_type'              => 'any',
				'post_status'            => 'any',
				'numberposts'            => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return ! empty( $posts );
	}
}

Matchbox_Custom_Login_URL::init();
