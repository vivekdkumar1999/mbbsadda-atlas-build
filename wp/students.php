<?php
/**
 * MBBS Adda — Students: every account, free and paid, with each Pro plan,
 * its validity, orders, and a quick control to add or end days.
 *
 * Admin only (manage_options). Reads user meta written by pro.php
 * (mbx_pro_until_<scope>, mbx_pro_log) and WooCommerce orders.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	add_submenu_page( 'mbx-sales', 'Students', 'Students', 'manage_options', 'mbx-students', 'mbx_students_page' );
}, 20 );

/** user_id => [scope => until] for every user who ever had Pro. */
function mbx_students_pro_map() {
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE 'mbx\\_pro\\_until\\_%' AND meta_value > 0" ); // phpcs:ignore
	$map  = array();
	foreach ( (array) $rows as $r ) {
		$map[ (int) $r->user_id ][ substr( $r->meta_key, 14 ) ] = (int) $r->meta_value;
	}
	return $map;
}

function mbx_students_url( $args = array() ) {
	$keep = array();
	foreach ( array( 'show', 's', 'paged', 'orderby' ) as $k ) {
		if ( isset( $_GET[ $k ] ) && '' !== $_GET[ $k ] ) { // phpcs:ignore
			$keep[ $k ] = sanitize_text_field( wp_unslash( $_GET[ $k ] ) ); // phpcs:ignore
		}
	}
	$q = array_filter( array_merge( $keep, $args ), function ( $v ) {
		return null !== $v && '' !== $v;
	} );
	return add_query_arg( $q, admin_url( 'admin.php?page=mbx-students' ) );
}

/* Add or end days for one student. */
add_action( 'admin_post_mbx_students_grant', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'mbx_students_grant' ) ) {
		wp_die( 'Not allowed.' );
	}
	$uid   = (int) ( $_POST['uid'] ?? 0 ); // phpcs:ignore
	$scope = sanitize_key( $_POST['scope'] ?? 'all' ); // phpcs:ignore
	$days  = (int) ( $_POST['days'] ?? 0 ); // phpcs:ignore
	$back  = esc_url_raw( wp_unslash( $_POST['back'] ?? admin_url( 'admin.php?page=mbx-students' ) ) ); // phpcs:ignore
	$msg   = 'bad';
	if ( isset( $_POST['end'] ) ) { // phpcs:ignore
		$days = -10000;
	}
	if ( $uid && get_userdata( $uid ) && $days && function_exists( 'mbx_pro_scopes' ) && isset( mbx_pro_scopes()[ $scope ] ) ) {
		$days = max( -10000, min( MBX_PRO_MAX_DAYS, $days ) );
		mbx_pro_add_days( $uid, $scope, $days, ( $days < 0 ? 'ended' : 'manual' ) . ' by ' . wp_get_current_user()->user_login . ' (Students page)' );
		$msg = $days < 0 ? 'ended' : 'added';
	}
	wp_safe_redirect( add_query_arg( array( 'mbxs' => $msg, 'u' => $uid ), $back ) . '#u' . $uid );
	exit;
} );

/* CSV of the current view. */
add_action( 'admin_post_mbx_students_csv', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'mbx_students_csv' ) ) {
		wp_die( 'Not allowed.' );
	}
	$sc  = mbx_pro_scopes();
	$map = mbx_students_pro_map();
	$ids = get_users( array( 'fields' => 'ID', 'orderby' => 'registered', 'order' => 'DESC', 'number' => -1 ) );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=mbbsadda-students-' . gmdate( 'Y-m-d' ) . '.csv' );
	$out = fopen( 'php://output', 'w' );
	$head = array( 'ID', 'Name', 'Email', 'Phone', 'College', 'Joined', 'Signed up via', 'Status' );
	foreach ( $sc as $k => $s ) {
		$head[] = $s['label'] . ' valid till';
	}
	$head[] = 'Paid orders';
	$head[] = 'Total spent';
	fputcsv( $out, $head );
	foreach ( $ids as $id ) {
		$u   = get_userdata( $id );
		$row = mbx_students_row( $u, isset( $map[ $id ] ) ? $map[ $id ] : array() );
		$line = array( $id, $u->display_name, $u->user_email, $row['phone'], $row['college'], wp_date( 'Y-m-d', strtotime( $u->user_registered ) ), $row['via'], $row['status'] );
		foreach ( $sc as $k => $s ) {
			$line[] = isset( $row['pro'][ $k ] ) ? wp_date( 'Y-m-d', $row['pro'][ $k ] ) : '';
		}
		$line[] = $row['orders'];
		$line[] = $row['spent'];
		fputcsv( $out, $line );
	}
	fclose( $out );
	exit;
} );

/** Everything shown for one student. */
function mbx_students_row( $u, $pro ) {
	$now    = time();
	$active = array_filter( $pro, function ( $t ) use ( $now ) {
		return $t > $now;
	} );
	$status = $active ? 'paid' : ( $pro ? 'expired' : 'free' );
	if ( user_can( $u, 'edit_posts' ) ) {
		$status = 'staff';
	}
	$orders = 0;
	$spent  = 0.0;
	if ( function_exists( 'wc_get_orders' ) ) {
		$list = wc_get_orders( array( 'customer_id' => $u->ID, 'status' => array( 'wc-completed', 'wc-processing' ), 'limit' => 50, 'return' => 'objects' ) );
		foreach ( (array) $list as $o ) {
			$orders++;
			$spent += (float) $o->get_total();
		}
	}
	$via = (string) get_user_meta( $u->ID, 'mbx_signup_via', true );
	if ( '' === $via ) {
		$via = get_user_meta( $u->ID, 'mba_tokens', true ) ? 'app' : 'website';
	}
	$daily = get_user_meta( $u->ID, 'mbx_daily', true );
	return array(
		'pro'     => $pro,
		'active'  => $active,
		'status'  => $status,
		'orders'  => $orders,
		'spent'   => $spent,
		'phone'   => (string) get_user_meta( $u->ID, 'billing_phone', true ),
		'college' => (string) get_user_meta( $u->ID, 'mbx_college', true ),
		'via'     => $via,
		'reads'   => (int) get_user_meta( $u->ID, 'mbx_reads_total', true ),
		'last'    => is_array( $daily ) && ! empty( $daily['d'] ) ? (string) $daily['d'] : '',
		'log'     => array_reverse( array_filter( (array) get_user_meta( $u->ID, 'mbx_pro_log', true ) ) ),
	);
}

function mbx_students_page() {
	if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'mbx_pro_scopes' ) ) {
		echo '<div class="wrap"><h1>Students</h1><p>MBBS Adda Pro is not loaded.</p></div>';
		return;
	}
	global $wpdb;
	$sc   = mbx_pro_scopes();
	$now  = time();
	$map  = mbx_students_pro_map();
	$paid = array();
	$exp  = array();
	foreach ( $map as $uid => $p ) {
		if ( max( $p ) > $now ) {
			$paid[] = $uid;
		} else {
			$exp[] = $uid;
		}
	}
	$ever   = array_keys( $map );
	$total  = (int) count_users()['total_users'];
	$show   = sanitize_key( $_GET['show'] ?? 'all' ); // phpcs:ignore
	$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore
	$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
	$per    = 40;

	$q = array( 'number' => $per, 'paged' => $paged, 'orderby' => 'registered', 'order' => 'DESC', 'count_total' => true );
	if ( '' !== $search ) {
		$q['search']         = '*' . $search . '*';
		$q['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
	}
	$none = array( 0 );
	if ( 'paid' === $show ) {
		$q['include'] = $paid ? $paid : $none;
	} elseif ( 'expired' === $show ) {
		$q['include'] = $exp ? $exp : $none;
	} elseif ( 'free' === $show ) {
		$q['exclude'] = $ever;
	} elseif ( 'soon' === $show ) {
		$soon = array();
		foreach ( $map as $uid => $p ) {
			$m = max( $p );
			if ( $m > $now && $m < $now + 7 * DAY_IN_SECONDS ) {
				$soon[] = $uid;
			}
		}
		$q['include'] = $soon ? $soon : $none;
	}
	$uq    = new WP_User_Query( $q );
	$users = $uq->get_results();
	$found = (int) $uq->get_total();
	$pages = max( 1, (int) ceil( $found / $per ) );

	$soon_n = 0;
	foreach ( $map as $p ) {
		$m = max( $p );
		if ( $m > $now && $m < $now + 7 * DAY_IN_SECONDS ) {
			$soon_n++;
		}
	}
	$tabs = array(
		'all'     => array( 'All students', $total ),
		'paid'    => array( 'Paid (active)', count( $paid ) ),
		'soon'    => array( 'Ending in 7 days', $soon_n ),
		'expired' => array( 'Expired', count( $exp ) ),
		'free'    => array( 'Free (never paid)', max( 0, $total - count( $ever ) ) ),
	);
	$back = mbx_students_url();
	?>
	<div class="wrap mbxs">
		<style>
			.mbxs .cards{display:flex;gap:12px;flex-wrap:wrap;margin:14px 0}
			.mbxs .card2{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:12px 16px;min-width:150px;text-decoration:none;color:#1d2327}
			.mbxs .card2 b{display:block;font-size:24px;line-height:1.2}
			.mbxs .card2.on{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
			.mbxs table{border-radius:8px}
			.mbxs td{vertical-align:top}
			.mbxs .tag{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600}
			.mbxs .t-paid{background:#d7f5e3;color:#0a6b35}.mbxs .t-expired{background:#fde2e1;color:#9b1c1c}
			.mbxs .t-free{background:#eef0f2;color:#50575e}.mbxs .t-staff{background:#e5eefc;color:#1d4f91}
			.mbxs .plan{white-space:nowrap;margin:1px 0}
			.mbxs .plan.off{color:#a0a5aa;text-decoration:line-through}
			.mbxs .left{color:#0a6b35;font-weight:600}.mbxs .left.warn{color:#b45309}
			.mbxs .ctl{display:flex;gap:4px;align-items:center;flex-wrap:wrap}
			.mbxs .ctl input[type=number]{width:64px}
			.mbxs .muted{color:#646970;font-size:12px}
			.mbxs details summary{cursor:pointer;color:#2271b1;font-size:12px}
			.mbxs tr.hl td{background:#fff8e1}
			@media (max-width:782px){.mbxs .col-hide{display:none}}
		</style>
		<h1 class="wp-heading-inline">Students</h1>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<?php wp_nonce_field( 'mbx_students_csv' ); ?><input type="hidden" name="action" value="mbx_students_csv">
			<button class="page-title-action">Download CSV (all)</button>
		</form>
		<hr class="wp-header-end">
		<?php
		$m = sanitize_key( $_GET['mbxs'] ?? '' ); // phpcs:ignore
		if ( 'added' === $m ) {
			echo '<div class="notice notice-success is-dismissible"><p>Days added.</p></div>';
		} elseif ( 'ended' === $m ) {
			echo '<div class="notice notice-warning is-dismissible"><p>Plan ended.</p></div>';
		} elseif ( 'bad' === $m ) {
			echo '<div class="notice notice-error is-dismissible"><p>Nothing changed — pick a plan and a number of days.</p></div>';
		}
		?>
		<div class="cards">
			<?php foreach ( $tabs as $k => $t ) : ?>
				<a class="card2 <?php echo $show === $k ? 'on' : ''; ?>" href="<?php echo esc_url( mbx_students_url( array( 'show' => $k, 'paged' => null ) ) ); ?>"><b><?php echo (int) $t[1]; ?></b><?php echo esc_html( $t[0] ); ?></a>
			<?php endforeach; ?>
		</div>
		<form method="get" style="margin:8px 0 12px">
			<input type="hidden" name="page" value="mbx-students"><input type="hidden" name="show" value="<?php echo esc_attr( $show ); ?>">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Name or email" style="min-width:240px">
			<button class="button">Search</button>
			<span class="muted">&nbsp;<?php echo (int) $found; ?> shown in this view · newest first</span>
		</form>
		<table class="widefat striped">
			<thead><tr>
				<th>Student</th><th>Status</th><th>Plans &amp; validity</th><th class="col-hide">Orders</th><th class="col-hide">Activity</th><th>Control</th>
			</tr></thead>
			<tbody>
			<?php if ( ! $users ) : ?>
				<tr><td colspan="6">No students in this view.</td></tr>
			<?php endif; ?>
			<?php
			foreach ( $users as $u ) :
				$r  = mbx_students_row( $u, isset( $map[ $u->ID ] ) ? $map[ $u->ID ] : array() );
				$hl = (int) ( $_GET['u'] ?? 0 ) === (int) $u->ID; // phpcs:ignore
				?>
				<tr id="u<?php echo (int) $u->ID; ?>" class="<?php echo $hl ? 'hl' : ''; ?>">
					<td>
						<strong><a href="<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>"><?php echo esc_html( $u->display_name ? $u->display_name : $u->user_login ); ?></a></strong><br>
						<span class="muted"><?php echo esc_html( $u->user_email ); ?><?php echo $r['phone'] ? ' · ' . esc_html( $r['phone'] ) : ''; ?></span><br>
						<?php if ( $r['college'] ) : ?><span class="muted"><?php echo esc_html( $r['college'] ); ?></span><br><?php endif; ?>
						<span class="muted">Joined <?php echo esc_html( wp_date( 'j M Y', strtotime( $u->user_registered ) ) ); ?> · via <?php echo esc_html( $r['via'] ); ?></span>
					</td>
					<td><span class="tag t-<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( ucfirst( $r['status'] ) ); ?></span></td>
					<td>
						<?php
						if ( ! $r['pro'] ) {
							echo '<span class="muted">No plan</span>';
						}
						arsort( $r['pro'] );
						foreach ( $r['pro'] as $s => $until ) {
							$label = isset( $sc[ $s ] ) ? $sc[ $s ]['label'] : $s;
							if ( $until > $now ) {
								$d = (int) ceil( ( $until - $now ) / DAY_IN_SECONDS );
								echo '<div class="plan"><b>' . esc_html( $label ) . '</b> till ' . esc_html( wp_date( 'j M Y', $until ) ) . ' <span class="left ' . ( $d <= 7 ? 'warn' : '' ) . '">· ' . (int) $d . ' day' . ( 1 === $d ? '' : 's' ) . ' left</span></div>';
							} else {
								echo '<div class="plan off">' . esc_html( $label ) . ' ended ' . esc_html( wp_date( 'j M Y', $until ) ) . '</div>';
							}
						}
						if ( $r['log'] ) :
							?>
							<details><summary>History (<?php echo count( $r['log'] ); ?>)</summary>
								<?php foreach ( array_slice( $r['log'], 0, 12 ) as $l ) : ?>
									<div class="muted"><?php echo esc_html( wp_date( 'j M Y', (int) ( $l['t'] ?? 0 ) ) . ' — ' . ( isset( $sc[ $l['scope'] ?? '' ] ) ? $sc[ $l['scope'] ]['label'] : ( $l['scope'] ?? '' ) ) . ' ' . ( ( (int) ( $l['days'] ?? 0 ) ) >= 0 ? '+' : '' ) . (int) ( $l['days'] ?? 0 ) . 'd · ' . ( $l['why'] ?? '' ) ); ?></div>
								<?php endforeach; ?>
							</details>
						<?php endif; ?>
					</td>
					<td class="col-hide">
						<?php if ( $r['orders'] ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&_customer_user=' . $u->ID ) ); ?>"><?php echo (int) $r['orders']; ?> paid</a><br><span class="muted">₹<?php echo esc_html( number_format_i18n( $r['spent'] ) ); ?></span>
						<?php else : ?>
							<span class="muted">—</span>
						<?php endif; ?>
					</td>
					<td class="col-hide"><span class="muted"><?php echo (int) $r['reads']; ?> answers read<?php echo $r['last'] ? '<br>last ' . esc_html( $r['last'] ) : ''; ?></span></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ctl">
							<?php wp_nonce_field( 'mbx_students_grant' ); ?>
							<input type="hidden" name="action" value="mbx_students_grant">
							<input type="hidden" name="uid" value="<?php echo (int) $u->ID; ?>">
							<input type="hidden" name="back" value="<?php echo esc_attr( $back ); ?>">
							<select name="scope">
								<?php
								$first = $r['active'] ? array_keys( $r['active'] )[0] : 'all';
								foreach ( $sc as $k => $s ) {
									echo '<option value="' . esc_attr( $k ) . '"' . selected( $k, $first, false ) . '>' . esc_html( $s['label'] ) . '</option>';
								}
								?>
							</select>
							<input type="number" name="days" value="30" min="1" max="<?php echo (int) MBX_PRO_MAX_DAYS; ?>" title="Days to add">
							<button class="button button-small" title="Add these days (from today, or from the current end date)">+ Add days</button>
							<?php if ( $r['active'] ) : ?>
								<button class="button button-small button-link-delete" name="end" value="1" onclick="return confirm('End the selected plan for <?php echo esc_js( $u->user_email ); ?> now?')">End</button>
							<?php endif; ?>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => esc_url_raw( mbx_students_url( array( 'paged' => '%#%' ) ) ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					)
				);
				?>
			</div></div>
		<?php endif; ?>
		<p class="muted">Paid = at least one plan still running. Expired = had a plan, all ended. Staff accounts see everything anyway. Adding days to a running plan extends it from its end date.</p>
	</div>
	<?php
}
