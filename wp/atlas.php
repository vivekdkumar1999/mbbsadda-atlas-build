<?php
/**
 * Atlas by MBBS Adda (atlas.mbbsadda.in) ↔ MBBS Adda accounts.
 *
 *  - Sign-in: the Atlas sends the student to https://mbbsadda.in/?mbx_atlas=login&back=<atlas url>.
 *    Signed in here → an app token (same format as the app, so every API accepts it) is handed
 *    back in the URL fragment. Not signed in → the normal MBBS Adda login first, then back here.
 *  - GET  mbx/v1/atlas/me      the student, their Pro plans and their saved Atlas state
 *  - POST mbx/v1/atlas/state   saves the Atlas state (saved structures, recently viewed, practice best)
 *  - POST mbx/v1/atlas/logout  forgets this token
 *  - CORS for the Atlas origin on these routes.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MBX_ATLAS_ORIGIN = 'https://atlas.mbbsadda.in';

function mbx_atlas_back_ok( $u ) {
	return is_string( $u ) && 0 === strpos( $u, MBX_ATLAS_ORIGIN . '/' ) && ! preg_match( '/[\r\n<>"]/', $u );
}

/* ------------------------------------------------------------ sign-in hand-off */
add_action( 'template_redirect', function () {
	if ( empty( $_GET['mbx_atlas'] ) ) { // phpcs:ignore
		return;
	}
	$back = isset( $_GET['back'] ) ? esc_url_raw( wp_unslash( $_GET['back'] ) ) : MBX_ATLAS_ORIGIN . '/'; // phpcs:ignore
	if ( ! mbx_atlas_back_ok( $back ) ) {
		$back = MBX_ATLAS_ORIGIN . '/';
	}
	$back = preg_replace( '/#.*$/', '', $back );
	nocache_headers();
	$uid = get_current_user_id();
	if ( ! $uid ) {
		$self = add_query_arg( array( 'mbx_atlas' => 'login', 'back' => rawurlencode( $back ) ), home_url( '/' ) );
		wp_safe_redirect( wp_login_url( $self ) );
		exit;
	}
	if ( ! function_exists( 'mbx_app_issue_token' ) ) {
		wp_die( 'Sign-in is not available right now.' );
	}
	$tok = mbx_app_issue_token( $uid, 'atlas' );
	update_user_meta( $uid, 'mbx_atlas_seen', time() );
	// a fragment never reaches any server log
	wp_redirect( $back . '#mba_token=' . rawurlencode( $tok ) ); // phpcs:ignore -- other host, checked above
	exit;
} );

/* Allow the redirect back to the Atlas after the normal login (wp_safe_redirect). */
add_filter( 'allowed_redirect_hosts', function ( $h ) {
	$h[] = 'atlas.mbbsadda.in';
	return $h;
} );

/* ------------------------------------------------------------ CORS */
add_action( 'rest_api_init', function () {
	add_filter( 'rest_pre_serve_request', function ( $served, $result, $request ) {
		if ( 0 === strpos( $request->get_route(), '/mbx/v1/atlas' ) ) {
			header( 'Access-Control-Allow-Origin: ' . MBX_ATLAS_ORIGIN );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Max-Age: 600' );
			header( 'Vary: Origin' );
		}
		return $served;
	}, 20, 3 );
}, 15 );

/* "last used the Atlas" — written at most once an hour per student, not on every call */
function mbx_atlas_touch( $uid ) {
	if ( (int) get_user_meta( $uid, 'mbx_atlas_seen', true ) < time() - HOUR_IN_SECONDS ) {
		update_user_meta( $uid, 'mbx_atlas_seen', time() );
	}
}

function mbx_atlas_uid() {
	return function_exists( 'mbx_pro_bearer_uid' ) ? mbx_pro_bearer_uid() : 0;
}

function mbx_atlas_clean_state( $s ) {
	$ids = function ( $a, $n ) {
		$out = array();
		foreach ( (array) $a as $x ) {
			$x = preg_replace( '/[^A-Za-z0-9_.:\-]/', '', (string) $x );
			if ( $x && strlen( $x ) < 80 && ! in_array( $x, $out, true ) ) {
				$out[] = $x;
			}
			if ( count( $out ) >= $n ) {
				break;
			}
		}
		return $out;
	};
	$pr = array();
	foreach ( (array) ( $s['practice'] ?? array() ) as $k => $v ) {
		$k = sanitize_key( $k );
		if ( $k && is_array( $v ) && count( $pr ) < 30 ) {
			$pr[ $k ] = array( 'best' => max( 0, min( 1000, (int) ( $v['best'] ?? 0 ) ) ), 'of' => max( 0, min( 1000, (int) ( $v['of'] ?? 0 ) ) ), 'n' => max( 0, min( 100000, (int) ( $v['n'] ?? 0 ) ) ) );
		}
	}
	return array(
		'saved'    => $ids( $s['saved'] ?? array(), 300 ),
		'recent'   => $ids( $s['recent'] ?? array(), 30 ),
		'practice' => (object) $pr,
		't'        => time(),
	);
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'mbx/v1', '/atlas/me', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function () {
			$uid = mbx_atlas_uid();
			if ( ! $uid ) {
				return new WP_REST_Response( array( 'signedIn' => false ), 200 );
			}
			$u      = get_userdata( $uid );
			$active = array();
			if ( function_exists( 'mbx_pro_active_scopes' ) ) {
				foreach ( mbx_pro_active_scopes( $uid ) as $sk => $until ) {
					$active[ $sk ] = gmdate( 'c', $until );
				}
			}
			$st = get_user_meta( $uid, 'mbx_atlas_state', true );
			mbx_atlas_touch( $uid );
			$res = new WP_REST_Response( array(
				'signedIn' => true,
				'name'     => $u ? ( $u->display_name ?: $u->user_login ) : '',
				'email'    => $u ? $u->user_email : '',
				'avatar'   => get_avatar_url( $uid, array( 'size' => 64, 'default' => '404' ) ),
				'pro'      => (object) $active,
				'state'    => is_array( $st ) ? $st : null,
			), 200 );
			$res->header( 'Cache-Control', 'private, no-store' );
			return $res;
		},
	) );

	register_rest_route( 'mbx/v1', '/atlas/state', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $r ) {
			$uid = mbx_atlas_uid();
			if ( ! $uid ) {
				return new WP_Error( 'auth', 'Please sign in again.', array( 'status' => 401 ) );
			}
			$rk = 'mbx_atlas_w_' . $uid;
			$n  = (int) get_transient( $rk );
			if ( $n >= 30 ) {
				return new WP_Error( 'slow', 'Too many saves; try again in a minute.', array( 'status' => 429 ) );
			}
			set_transient( $rk, $n + 1, MINUTE_IN_SECONDS );
			$body = $r->get_json_params();
			if ( ! is_array( $body ) || strlen( (string) $r->get_body() ) > 65536 ) {
				return new WP_Error( 'bad', 'Bad data', array( 'status' => 400 ) );
			}
			$st = mbx_atlas_clean_state( $body );
			update_user_meta( $uid, 'mbx_atlas_state', $st );
			mbx_atlas_touch( $uid );
			return array( 'ok' => true, 't' => $st['t'] );
		},
	) );

	register_rest_route( 'mbx/v1', '/atlas/logout', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function () {
			$uid = mbx_atlas_uid();
			$h   = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ); // phpcs:ignore
			if ( $uid && preg_match( '/Bearer\s+\d+\.([a-f0-9]{48})/i', $h, $m ) ) {
				$all = get_user_meta( $uid, 'mba_tokens', true );
				if ( is_array( $all ) ) {
					unset( $all[ hash( 'sha256', $m[1] ) ] );
					update_user_meta( $uid, 'mba_tokens', $all );
				}
			}
			return array( 'ok' => true );
		},
	) );
} );

/* OPTIONS preflight for the Atlas routes (no auth needed to answer it). */
add_action( 'init', function () {
	if ( 'OPTIONS' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && false !== strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '/mbx/v1/atlas' ) ) { // phpcs:ignore
		header( 'Access-Control-Allow-Origin: ' . MBX_ATLAS_ORIGIN );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Max-Age: 600' );
		status_header( 204 );
		exit;
	}
}, 0 );
