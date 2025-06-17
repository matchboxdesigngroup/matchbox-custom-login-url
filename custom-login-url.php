<?php
/**
 * Plugin Name: Custom Login URL
 * Description: MU Plugin to change the default login URL.
 * Version: 1.0.0
 * Requires at least: 5.0
 * Requires PHP: 8.0
 * Author: Matchbox
 *
 * @package custom-login-url
 */

defined( 'ABSPATH' ) || exit;

$login_slug = defined( 'LOGIN_URL' ) ? LOGIN_URL : 'web-ad';

/**
 * Checks if the request is for the custom login slug (e.g., /web-ad), sets a short-lived cookie to allow access to wp-login.php, and redirects to wp-login.php with any query vars preserved.
 */
add_action(
	'template_redirect',
	function () use ( $login_slug ) {
		$request_uri = $_SERVER['REQUEST_URI'];
		$parsed_url = wp_parse_url( $request_uri );
		parse_str( $parsed_url['query'] ?? '', $query_vars );

		if ( isset( $parsed_url['path'] ) && ( "/$login_slug/" === $parsed_url['path'] || "/$login_slug" === $parsed_url['path'] ) ) {
			setcookie( 'custom-login-url', hash_hmac( 'sha256', 'allowed', AUTH_SALT ), time() + 300, COOKIEPATH, COOKIE_DOMAIN );
			$redirect_url = site_url( 'wp-login.php' );
			if ( ! empty( $query_vars ) ) {
				$redirect_url = add_query_arg( $query_vars, $redirect_url );
			}
			wp_safe_redirect( $redirect_url );
			exit();
		}
	}
);

/**
 * Changes the default login URL to the custom slug and preserves redirect and reauth query parameters.
 */
add_filter(
	'login_url',
	function ( $login_url, $redirect, $force_reauth) use ( $login_slug ) {
		$login_url = site_url( '/' . $login_slug . '/', 'login' );
		if ( $redirect ) {
			$login_url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $login_url );
		}
		if ( $force_reauth ) {
			$login_url = add_query_arg( 'reauth', '1', $login_url );
		}
		return $login_url;
	},
	10,
	3
);

/**
 * Changes the lost password URL to use the custom login slug and appends the lostpassword action.
 */
add_filter(
	'lostpassword_url',
	function () use ( $login_slug ) {
		return site_url( '/' . $login_slug . '/?action=lostpassword', 'login' );
	},
	10,
	2
);

/**
 * Changes the registration URL to use the custom login slug and appends the register action.
 */
add_filter(
	'register_url',
	function () use ( $login_slug ) {
		return site_url( '/' . $login_slug . '/?action=register', 'login' );
	}
);

/**
 * Changes the logout URL to use the custom login slug and preserves any query parameters.
 */
add_filter(
	'logout_url',
	function ( $logout_url ) use  ( $login_slug ) {
		$parsed = wp_parse_url( $logout_url );
		if ( ! empty( $parsed['query'] ) ) {
			return site_url( '/' . $login_slug . '/?' . $parsed['query'], 'login' );
		}
		return site_url( '/' . $login_slug . '/', 'login' );
	},
	10,
	2
);

/**
 * Protects wp-login.php by requiring the custom-login-url cookie for access, except for postpass and logout actions. Otherwise, returns a 403 Forbidden error.
 */
add_action(
	'login_init',
	function () {
		$req_path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
		$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( strpos( $req_path, 'wp-login.php' ) !== false && ! in_array( $action, array( 'postpass', 'logout' ), true ) ) {
			if ( isset( $_COOKIE['custom-login-url'] ) && hash_equals( $_COOKIE['custom-login-url'], hash_hmac( 'sha256', 'allowed', AUTH_SALT ) ) ) {
				return;
			}
			wp_die( esc_html__( 'Forbidden', 'custom-login-url' ), esc_html__( 'Forbidden', 'custom-login-url' ), 403 );
		}
	}
);
