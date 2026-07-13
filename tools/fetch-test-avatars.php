<?php
/**
 * Dev tool (not loaded by the plugin itself): fetches public-domain / CC0
 * profile portraits for Demo mode's 50-player pool
 * (includes/etr-test-players.php) from English Wikipedia and Wikimedia
 * Commons, resizes them to 256x256 JPEGs, and saves them to
 * assets/test-avatars/{uscf_id}.jpg for handle_add_test_registrants() (see
 * includes/class-etr-settings.php) to sideload into the media library.
 *
 * Run with:
 *   wp eval-file tools/fetch-test-avatars.php
 *
 * Safe to re-run: a player whose assets/test-avatars/{uscf_id}.jpg already
 * exists is skipped without any network calls. Set the ETR_AVATAR_FORCE
 * environment variable to 1 to re-fetch and overwrite everything instead.
 *
 * Only accepts an image when its Commons license is public domain or CC0.
 * CC-BY and CC-BY-SA are rejected: this plugin has no attribution mechanism
 * for test-data images, and a chess club site should not be redistributing
 * a photographer's work uncredited. Anyone left without a portrait falls
 * back to Demo mode's existing plain silhouette placeholder, so a miss here
 * is harmless - most living players are expected to miss, since a
 * current, freely-licensed portrait of an active grandmaster is uncommon.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once ABSPATH . 'wp-admin/includes/file.php';

$etr_avatar_ua    = 'macchess-test-avatars/1.0 (club dev tool)';
$etr_avatar_force = getenv( 'ETR_AVATAR_FORCE' ) === '1';
$etr_avatar_dir   = ETR_PATH . 'assets/test-avatars/';
wp_mkdir_p( $etr_avatar_dir );

/**
 * Per-player title overrides for guesses that would otherwise miss: name
 * order differs from the pool's last/first split, a diacritic changes the
 * canonical article title, or the plain guess lands on a disambiguation
 * page. Keyed "Last|First" against includes/etr-test-players.php's rows.
 */
$etr_title_overrides = [
	'Fischer|Robert'       => 'Bobby Fischer',
	'Polgar|Judit'         => 'Judit Polgár',
	'Capablanca|Jose Raul' => 'José Raúl Capablanca',
	'Hou|Yifan'            => 'Hou Yifan',
	'Ding|Liren'           => 'Ding Liren',
	'Adams|Michael'        => 'Michael Adams (chess player)',
	'Zhu|Chen'             => 'Zhu Chen',
	'Xie|Jun'              => 'Xie Jun',
	'Ju|Wenjun'            => 'Ju Wenjun',
	'Koneru|Humpy'         => 'Koneru Humpy',
	'Paehtz|Elisabeth'     => 'Elisabeth Pähtz',
];

/**
 * Politely-rate-limited wp_remote_get(): sleeps 300ms before every call.
 * Retries with a longer backoff on HTTP 429 (Wikimedia's rate limit
 * response), up to 4 attempts total, since the anonymous-API burst limit
 * for this IP can still be cooling down from earlier traffic (including
 * from this same tool's own previous runs).
 */
function etr_avatar_get( $url, $ua ) {
	$attempts = 0;
	do {
		usleep( 300000 );
		$resp = wp_remote_get( $url, [
			'timeout'    => 15,
			'user-agent' => $ua,
		] );
		$attempts++;
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		if ( (int) wp_remote_retrieve_response_code( $resp ) !== 429 ) {
			return $resp;
		}
		sleep( 15 * $attempts );
	} while ( $attempts < 4 );
	return $resp;
}

/**
 * Look up a Wikipedia article's lead image (action=query, pageimages +
 * pageprops, piprop=original), following redirects. Returns
 * [ file_title, image_url ] (file_title like "File:Foo.jpg") or null when
 * the title doesn't resolve to an article with a free lead image (missing
 * page, disambiguation page, or an article with no image at all).
 */
function etr_avatar_lookup_lead_image( $title, $ua ) {
	// Built directly rather than via add_query_arg() so the already-percent-
	// encoded "pageimages%7Cpageprops" literal isn't double-encoded.
	$url = 'https://en.wikipedia.org/w/api.php?action=query&prop=pageimages%7Cpageprops&piprop=original&redirects=1&format=json&titles=' . rawurlencode( $title );

	$resp = etr_avatar_get( $url, $ua );
	if ( is_wp_error( $resp ) ) {
		return [ null, null, 'Wikipedia request failed: ' . $resp->get_error_message() ];
	}
	$data = json_decode( wp_remote_retrieve_body( $resp ), true );
	$pages = $data['query']['pages'] ?? [];
	if ( empty( $pages ) ) {
		return [ null, null, 'no Wikipedia page found' ];
	}
	$page = reset( $pages );
	if ( isset( $page['missing'] ) ) {
		return [ null, null, 'no Wikipedia page found' ];
	}
	if ( isset( $page['pageprops']['disambiguation'] ) ) {
		return [ null, null, 'title resolved to a disambiguation page' ];
	}

	$image_url = $page['original']['source'] ?? '';
	if ( $image_url === '' ) {
		return [ null, null, 'article has no lead image' ];
	}

	$filename = $page['pageprops']['page_image_free'] ?? '';
	if ( $filename === '' ) {
		$filename = urldecode( basename( wp_parse_url( $image_url, PHP_URL_PATH ) ) );
	}
	if ( $filename === '' ) {
		return [ null, null, 'could not resolve File: name for lead image' ];
	}

	return [ 'File:' . $filename, $image_url, null ];
}

/**
 * Fetch a Commons file's extmetadata (imageinfo, iiprop=extmetadata).
 * Returns an assoc array of the fields we use (LicenseShortName, ObjectName,
 * ImageDescription, Categories - the last three feeding the name-match
 * guard below), or null on failure.
 */
function etr_avatar_lookup_extmetadata( $file_title, $ua ) {
	$url = 'https://commons.wikimedia.org/w/api.php?action=query&prop=imageinfo&iiprop=extmetadata&format=json&titles=' . rawurlencode( $file_title );
	$resp = etr_avatar_get( $url, $ua );
	if ( is_wp_error( $resp ) ) {
		return null;
	}
	$data  = json_decode( wp_remote_retrieve_body( $resp ), true );
	$pages = $data['query']['pages'] ?? [];
	if ( empty( $pages ) ) {
		return null;
	}
	$page = reset( $pages );
	$meta = $page['imageinfo'][0]['extmetadata'] ?? null;
	if ( ! is_array( $meta ) ) {
		return null;
	}
	$out = [];
	foreach ( [ 'LicenseShortName', 'ObjectName', 'ImageDescription', 'Categories' ] as $key ) {
		$out[ $key ] = (string) ( $meta[ $key ]['value'] ?? '' );
	}
	return $out;
}

/**
 * Loose sanity check that the found lead image is plausibly of the named
 * player, beyond just "an image existed and had an acceptable license".
 * Wikipedia's automatic page-image choice can occasionally be wrong (a
 * stale/mis-set lead image on the live article) - this caught a real case
 * during development where "Susan Polgar" resolved to an unrelated award
 * photo of a different player entirely. Requires the surname to appear, as
 * a whole word, somewhere in the file title/description/categories;
 * skipped for surnames of 2 letters or less (too likely to false-negative
 * on legitimate matches, e.g. "Ju").
 */
function etr_avatar_name_matches( $last, $file_title, $meta ) {
	$needle = strtolower( remove_accents( $last ) );
	if ( strlen( $needle ) <= 2 ) {
		return true;
	}
	$haystack = strtolower( remove_accents(
		str_replace( '_', ' ', $file_title ) . ' ' . implode( ' ', $meta )
	) );
	return (bool) preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/', $haystack );
}

/** Resize/crop a downloaded image file to 256x256 JPEG q82 at $dest. */
function etr_avatar_save_resized( $src_path, $dest_path ) {
	$editor = wp_get_image_editor( $src_path );
	if ( is_wp_error( $editor ) ) {
		return $editor;
	}
	$editor->resize( 256, 256, true ); // true = hard crop to exact dimensions.
	$editor->set_quality( 82 );
	$saved = $editor->save( $dest_path, 'image/jpeg' );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	return true;
}

// -----------------------------------------------------------------------

$players = include ETR_PATH . 'includes/etr-test-players.php';

$hits  = [];
$misses = [];

foreach ( $players as $p ) {
	$last  = $p['last'];
	$first = $p['first'];
	$uscf  = $p['uscf_id'];
	$name  = "{$last}, {$first}";
	$dest  = $etr_avatar_dir . $uscf . '.jpg';

	if ( ! $etr_avatar_force && file_exists( $dest ) ) {
		$hits[] = sprintf( '%-9s %-28s cached (already fetched)', $uscf, $name );
		continue;
	}

	$title = $etr_title_overrides["{$last}|{$first}"] ?? "{$first} {$last}";

	list( $file_title, $image_url, $err ) = etr_avatar_lookup_lead_image( $title, $etr_avatar_ua );
	if ( ! $file_title ) {
		$misses[] = sprintf( '%-9s %-28s MISS: %s (title guess "%s")', $uscf, $name, $err, $title );
		continue;
	}

	$meta = etr_avatar_lookup_extmetadata( $file_title, $etr_avatar_ua );
	if ( ! $meta ) {
		$misses[] = sprintf( '%-9s %-28s MISS: could not read Commons license metadata for %s', $uscf, $name, $file_title );
		continue;
	}

	$license = $meta['LicenseShortName'];
	if ( ! preg_match( '/public domain|^pd|cc0/i', $license ) ) {
		$misses[] = sprintf( '%-9s %-28s MISS: license "%s" is not public domain/CC0 (%s)', $uscf, $name, $license, $file_title );
		continue;
	}

	if ( ! etr_avatar_name_matches( $last, $file_title, $meta ) ) {
		$misses[] = sprintf( '%-9s %-28s MISS: lead image %s does not appear to depict %s (name-match guard)', $uscf, $name, $file_title, $name );
		continue;
	}

	$tmp = download_url( $image_url, 15 );
	if ( is_wp_error( $tmp ) ) {
		$misses[] = sprintf( '%-9s %-28s MISS: download failed: %s', $uscf, $name, $tmp->get_error_message() );
		continue;
	}

	$saved = etr_avatar_save_resized( $tmp, $dest );
	wp_delete_file( $tmp );

	if ( is_wp_error( $saved ) ) {
		$misses[] = sprintf( '%-9s %-28s MISS: resize/save failed: %s', $uscf, $name, $saved->get_error_message() );
		continue;
	}

	$hits[] = sprintf( '%-9s %-28s HIT: %s (%s)', $uscf, $name, $license, $file_title );
}

echo "\n=== ETR test-avatar fetch: coverage report ===\n\n";
echo count( $hits ) . " hit(s), " . count( $misses ) . " miss(es), out of " . count( $players ) . " players.\n\n";

echo "--- Hits ---\n";
foreach ( $hits as $line ) {
	echo $line . "\n";
}

echo "\n--- Misses (fall back to silhouette in Demo mode) ---\n";
foreach ( $misses as $line ) {
	echo $line . "\n";
}

echo "\nSaved to: " . $etr_avatar_dir . "\n";
