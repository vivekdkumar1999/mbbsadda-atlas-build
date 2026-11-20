// Atlas deploy: syncs public_html/atlas/v2 with the latest public build in GitHub (vivekdkumar1999/mbbsadda-atlas-build).
// Content only ever comes from that repo; calls are rate-limited.
add_action('rest_api_init', function () {
	register_rest_route('atlas/v1', '/deploy', array(
		'methods' => 'POST',
		'permission_callback' => '__return_true',
		'callback' => function () {
			if (get_transient('atlas_deploy_lock')) return new WP_Error('busy', 'Try again in a minute', array('status' => 429));
			set_transient('atlas_deploy_lock', 1, 60);
			@set_time_limit(600);
			$repo = 'vivekdkumar1999/mbbsadda-atlas-build';
			$dir = '';
			foreach (array(ABSPATH . 'atlas/v2/', dirname(ABSPATH) . '/atlas/v2/', $_SERVER['DOCUMENT_ROOT'] . '/atlas/v2/') as $c) {
				if (file_exists($c . 'index.html')) { $dir = $c; break; }
			}
			if (!$dir) return new WP_Error('nodir', 'atlas/v2 not found', array('status' => 500));
			$h = array('timeout' => 60, 'headers' => array('User-Agent' => 'atlas-deploy'));
			$c = json_decode(wp_remote_retrieve_body(wp_remote_get('https://api.github.com/repos/' . $repo . '/commits/main', $h)), true);
			$sha = isset($c['sha']) ? preg_replace('/[^a-f0-9]/', '', $c['sha']) : '';
			if (strlen($sha) != 40) return new WP_Error('nosha', 'could not read main', array('status' => 502));
			$t = json_decode(wp_remote_retrieve_body(wp_remote_get('https://api.github.com/repos/' . $repo . '/git/trees/' . $sha . '?recursive=1', $h)), true);
			if (empty($t['tree'])) return new WP_Error('notree', 'could not read tree', array('status' => 502));
			$out = array();
			$last = null;
			foreach ($t['tree'] as $e) {
				$p = $e['path'];
				if ($e['type'] != 'blob' || strpos($p, '..') !== false || !preg_match('#^(index\.html|logo\.svg|assets/[A-Za-z0-9_.\-]+|models/[A-Za-z0-9_./\-]+)$#', $p)) continue;
				if ($p == 'index.html') { $last = $e; continue; }
				$out[$p] = atlas_deploy_file($repo, $sha, $dir, $e);
			}
			if ($last) $out['index.html'] = atlas_deploy_file($repo, $sha, $dir, $last);
			delete_transient('atlas_deploy_lock');
			return array('sha' => $sha, 'dir' => $dir, 'files' => $out);
		},
	));
});
function atlas_deploy_file($repo, $sha, $dir, $e) {
	$f = $dir . $e['path'];
	if (file_exists($f) && sha1('blob ' . filesize($f) . "\0" . file_get_contents($f)) === $e['sha']) return 'same';
	$r = wp_remote_get('https://raw.githubusercontent.com/' . $repo . '/' . $sha . '/' . $e['path'], array('timeout' => 180));
	$b = wp_remote_retrieve_body($r);
	if (wp_remote_retrieve_response_code($r) != 200 || strlen($b) != $e['size']) return 'fail';
	wp_mkdir_p(dirname($f));
	return file_put_contents($f, $b) ? 'written' : 'fail';
}
