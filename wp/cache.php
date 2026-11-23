<?php
/**
 * Page cache rules for LiteSpeed Cache (guest pages only).
 *
 * LiteSpeed Cache serves signed-out visitors a stored copy of a page. Some guest pages must
 * never be stored or served from the store, because they differ per visitor:
 *  - a verified search-engine crawler sees full answers (marked as paywalled content);
 *  - a guest who used today's free answer (mbx_gf cookie) sees that answer in full;
 *  - the Atlas sign-in hand-off (?mbx_atlas=…) and admin "view as" (mbx_as).
 * The matching user agents and cookies are also listed in LiteSpeed Cache → Excludes, so the
 * server skips the store before WordPress even runs; this file is the second line of defence.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	$why = '';
	if ( ! empty( $_GET['mbx_atlas'] ) ) { // phpcs:ignore
		$why = 'atlas sign-in';
	} elseif ( ! empty( $_COOKIE['mbx_gf'] ) || ! empty( $_COOKIE['mbx_back'] ) || ! empty( $_COOKIE['mbx_as'] ) || ! empty( $_COOKIE['mbx_pro_preview'] ) ) { // phpcs:ignore
		$why = 'per-visitor cookie';
	} elseif ( preg_match( '/bot|crawl|spider|slurp|google|bing|yandex|baidu|duckduck|facebookexternalhit|whatsapp/i', (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) ) { // phpcs:ignore
		$why = 'crawler';
	}
	if ( $why ) {
		do_action( 'litespeed_control_set_nocache', 'mbx: ' . $why );
		if ( ! headers_sent() ) {
			header( 'X-MBX-Cache: skip (' . $why . ')' );
		}
	}
}, 1 );
