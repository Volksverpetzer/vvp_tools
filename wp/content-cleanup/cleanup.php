<?php
/**
 * Rule-based cleanup of old snippets in WordPress post_content.
 *
 * A rule (rules/<name>.php) describes WHAT to remove; this file handles HOW:
 * empty wrappers, empty Gutenberg blocks, words split by a snippet, Divi wrappers,
 * safety checks, dry run / diff / apply.
 *
 * On the server (wp-cli), from the folder containing this file:
 *   wp eval-file cleanup.php <rule>                 dry run: counts + posts that still contain the needle
 *   wp eval-file cleanup.php <rule> survey          what would be removed and what is left, grouped by form
 *   wp eval-file cleanup.php <rule> show <id> ...   unified diff per post
 *   wp eval-file cleanup.php <rule> apply           write changes
 *   (add --path=/var/www/html --allow-root)
 *
 * Locally, no WordPress needed:
 *   php cleanup.php <rule> test                     run the rule's test cases
 *
 * Changes are written directly via $wpdb: no revisions, no post_modified bump, no save_post
 * hooks/webhooks, and nothing else in the post is touched. Purge the CDN afterwards.
 * See README.md for the full workflow.
 */

const VVP_CLEANUP_MARK = "\x01VVPCLEANUP\x01";

// Whitespace and line breaks that may sit between a removed snippet and its wrapper.
const VVP_CLEANUP_SPACE = '(?:\s|&nbsp;|<br\s*/?>)*+';

// Tags whose open/close balance must not change; a post that would change it is skipped.
const VVP_CLEANUP_BALANCED_TAGS = [ 'p', 'em', 'strong', 'b', 'i', 'a', 'div', 'span', 'blockquote', 'ul', 'ol', 'li', 'h2', 'h3', 'h4' ];

function vvp_cleanup_preg( $result ) {
	if ( null === $result ) {
		throw new RuntimeException( 'preg error ' . preg_last_error() . ' (' . preg_last_error_msg() . ')' );
	}
	return $result;
}

function vvp_cleanup_load_rule( string $name ): array {
	if ( ! preg_match( '/^[a-z0-9_-]+$/', $name ) || ! is_file( __DIR__ . "/rules/$name.php" ) ) {
		$available = array_map( fn( $f ) => basename( $f, '.php' ), glob( __DIR__ . '/rules/[!_]*.php' ) );
		throw new InvalidArgumentException( "Unknown rule '$name'. Available: " . implode( ', ', $available ) );
	}
	$rule = require __DIR__ . "/rules/$name.php";
	return $rule + [
		'description' => '',
		'like'        => [],
		'needle'      => null,
		'flags'       => 's',
		'remove'      => null,
		'post_types'  => [ 'post', 'page' ],
		'statuses'    => [ 'publish' ],
		'tests'       => [],
	];
}

function vvp_cleanup_tag_balance( string $html ): array {
	$balance = [];
	foreach ( VVP_CLEANUP_BALANCED_TAGS as $tag ) {
		$balance[ $tag ] = preg_match_all( "#<{$tag}\b#i", $html ) - preg_match_all( "#</{$tag}>#i", $html );
	}
	return $balance;
}

/**
 * Removes every match of the rule from $content.
 *
 * 1. Each match the rule wants removed becomes a marker.
 * 2. Markers separated only by whitespace/<br>/<p> merge into one (several snippet pieces = one removal).
 * 3. Wrappers that only held a marker (<p>, <em>, <strong>, …) go too.
 * 4. Paragraph/HTML/shortcode/freeform blocks that only held a marker go completely.
 * 5. A marker between two letters (snippet pasted mid-word) disappears so the word rejoins;
 *    any other marker becomes a paragraph break.
 *
 * @return array{0:string,1:int,2:string[]} New content, number of removals, removed snippets.
 * @throws RuntimeException When the post would be damaged (regex failure, tag balance changed, empty result).
 */
function vvp_cleanup_content( string $content, array $rule ): array {
	$removed = [];
	$before  = vvp_cleanup_tag_balance( $content );
	$mark    = VVP_CLEANUP_MARK;
	$space   = VVP_CLEANUP_SPACE;

	$new = vvp_cleanup_preg( preg_replace_callback(
		'#' . $rule['pattern'] . '#' . $rule['flags'],
		function ( $m ) use ( $rule, &$removed, $mark ) {
			if ( $rule['remove'] && ! ( $rule['remove'] )( $m ) ) {
				return $m[0];
			}
			$removed[] = $m[0];
			return $mark;
		},
		$content
	) );

	if ( ! $removed ) {
		return [ $content, 0, [] ];
	}

	do {
		$previous = $new;
		$new      = vvp_cleanup_preg( preg_replace(
			[
				"#{$mark}(?:(?:\s|&nbsp;|<br\s*/?>|</?p>)*+{$mark})++#",
				"#<(p|em|strong|b|i|span)\b[^>]*+>{$space}{$mark}{$space}</\\1>#i",
			],
			$mark,
			$new
		) );
	} while ( $new !== $previous );

	$new = vvp_cleanup_preg( preg_replace_callback(
		'#<!-- wp:(paragraph|html|shortcode|freeform)(?: \{[^}]*+\})? -->([^<]*+(?:<(?!!-- /?wp:)[^<]*+)*+)<!-- /wp:\1 -->\s*+#',
		function ( $m ) use ( $mark ) {
			if ( false === strpos( $m[2], $mark ) ) {
				return $m[0];
			}
			$rest = vvp_cleanup_preg( preg_replace( '#' . $mark . '|</?(?:p|em|strong|b|i|span)\b[^>]*+>|<br\s*/?>|&nbsp;|\s#i', '', $m[2] ) );
			return '' === $rest ? '' : $m[0];
		},
		$new
	) );

	$new = vvp_cleanup_preg( preg_replace(
		[
			"#(?<=[A-Za-z\x80-\xff]){$mark}(?=[a-z\x80-\xff])#",
			"#(?:[ \t]*+\n)*+[ \t]*+{$mark}[ \t]*+(?:\n[ \t]*+)*+#",
		],
		[ '', "\n\n" ],
		$new
	) );

	if ( vvp_cleanup_tag_balance( $new ) !== $before ) {
		throw new RuntimeException( 'tag balance would change' );
	}
	if ( '' === trim( strip_tags( $new ) ) && '' !== trim( strip_tags( $content ) ) ) {
		throw new RuntimeException( 'post would be empty' );
	}

	return [ $new, count( $removed ), $removed ];
}

/** Short, comparable form of a snippet for grouping: tags stripped, whitespace and numbers collapsed. */
function vvp_cleanup_fingerprint( string $html, int $length = 100 ): string {
	$text = preg_replace( [ '/\s+/', '/\d+/' ], [ ' ', 'N' ], $html );
	return mb_substr( trim( $text ), 0, $length );
}

function vvp_cleanup_needle_contexts( string $content, string $needle, int $radius = 150 ): array {
	$contexts = [];
	$offset   = 0;
	while ( false !== ( $pos = stripos( $content, $needle, $offset ) ) ) {
		$start      = max( 0, $pos - $radius );
		$contexts[] = substr( $content, $start, $pos - $start + strlen( $needle ) + $radius );
		$offset     = $pos + strlen( $needle );
	}
	return $contexts;
}

function vvp_cleanup_print_groups( string $title, array $groups, int $limit = 25 ): void {
	uasort( $groups, fn( $a, $b ) => $b['count'] <=> $a['count'] );
	WP_CLI::log( sprintf( "\n%s – %d distinct (top %d):", $title, count( $groups ), min( $limit, count( $groups ) ) ) );
	foreach ( array_slice( $groups, 0, $limit, true ) as $key => $group ) {
		WP_CLI::log( sprintf( "\n%5dx  e.g. %s\n       %s", $group['count'], implode( ', ', $group['ids'] ), $key ) );
	}
}

function vvp_cleanup_add_group( array &$groups, string $key, int $id ): void {
	$groups[ $key ] ??= [ 'count' => 0, 'ids' => [] ];
	$groups[ $key ]['count']++;
	if ( count( $groups[ $key ]['ids'] ) < 3 && ! in_array( $id, $groups[ $key ]['ids'], true ) ) {
		$groups[ $key ]['ids'][] = $id;
	}
}

function vvp_cleanup_run_tests( array $rule ): int {
	$failed = 0;
	foreach ( $rule['tests'] as $name => $test ) {
		try {
			[ $out ] = vvp_cleanup_content( $test['in'], $rule );
			$result  = $out;
		} catch ( RuntimeException $e ) {
			$result = 'SKIP: ' . $e->getMessage();
		}
		$expected = $test['out'];
		$ok       = str_starts_with( $expected, 'SKIP' ) ? str_starts_with( $result, 'SKIP' ) : $result === $expected;
		echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $name . "\n";
		if ( ! $ok ) {
			$failed++;
			echo '      expected: ' . json_encode( $expected, JSON_UNESCAPED_UNICODE ) . "\n";
			echo '      got:      ' . json_encode( $result, JSON_UNESCAPED_UNICODE ) . "\n";
		}
	}
	echo $failed ? "\n$failed of " . count( $rule['tests'] ) . " failed\n" : "\nall " . count( $rule['tests'] ) . " passed\n";
	return $failed;
}

// ---------------------------------------------------------------------------------------------
// Entry points
// ---------------------------------------------------------------------------------------------

if ( ! defined( 'WP_CLI' ) ) {
	if ( PHP_SAPI === 'cli' && realpath( $argv[0] ?? '' ) === __FILE__ ) {
		if ( 'test' !== ( $argv[2] ?? '' ) ) {
			fwrite( STDERR, "Usage: php cleanup.php <rule> test\n(all other modes run via wp eval-file, see header)\n" );
			exit( 2 );
		}
		try {
			exit( vvp_cleanup_run_tests( vvp_cleanup_load_rule( $argv[1] ?? '' ) ) ? 1 : 0 );
		} catch ( InvalidArgumentException $e ) {
			fwrite( STDERR, $e->getMessage() . "\n" );
			exit( 2 );
		}
	}
	return;
}

global $wpdb;

$args = $args ?? [];
try {
	$rule = vvp_cleanup_load_rule( $args[0] ?? '' );
} catch ( InvalidArgumentException $e ) {
	WP_CLI::error( $e->getMessage() );
}
$mode = $args[1] ?? 'dry';

if ( 'test' === $mode ) {
	vvp_cleanup_run_tests( $rule );
	return;
}

if ( 'show' === $mode ) {
	foreach ( array_slice( $args, 2 ) as $id ) {
		$old = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) );
		try {
			[ $new ] = vvp_cleanup_content( $old, $rule );
		} catch ( RuntimeException $e ) {
			WP_CLI::log( "##### Post $id: would be skipped ({$e->getMessage()})" );
			continue;
		}
		$a = tempnam( sys_get_temp_dir(), 'old' );
		$b = tempnam( sys_get_temp_dir(), 'new' );
		file_put_contents( $a, $old );
		file_put_contents( $b, $new );
		WP_CLI::log( "##### Post $id  " . get_permalink( (int) $id ) );
		WP_CLI::log( (string) shell_exec( 'diff -u ' . escapeshellarg( $a ) . ' ' . escapeshellarg( $b ) ) );
		unlink( $a );
		unlink( $b );
	}
	return;
}

if ( ! $rule['like'] ) {
	WP_CLI::error( "Rule has no 'like' prefilter." );
}

$like  = implode( ' OR ', array_fill( 0, count( $rule['like'] ), 'post_content LIKE %s' ) );
$types = implode( ',', array_fill( 0, count( $rule['post_types'] ), '%s' ) );
$stats = implode( ',', array_fill( 0, count( $rule['statuses'] ), '%s' ) );
$rows  = $wpdb->get_results( $wpdb->prepare(
	"SELECT ID, post_name, post_content FROM {$wpdb->posts}
	 WHERE ($like) AND post_type IN ($types) AND post_status IN ($stats)
	 ORDER BY ID",
	...array_merge( $rule['like'], $rule['post_types'], $rule['statuses'] )
) );

$apply    = 'apply' === $mode;
$survey   = 'survey' === $mode;
$cleaned  = 0;
$total    = 0;
$skipped  = [];
$leftover = [];
$matched  = [];
$left     = [];

foreach ( $rows as $row ) {
	try {
		[ $new, $count, $removed ] = vvp_cleanup_content( $row->post_content, $rule );
	} catch ( RuntimeException $e ) {
		$skipped[] = "{$row->ID}\t{$row->post_name}\t({$e->getMessage()})";
		continue;
	}

	if ( $rule['needle'] && false !== stripos( $new, $rule['needle'] ) ) {
		$leftover[] = "{$row->ID}\t" . get_permalink( (int) $row->ID );
		if ( $survey ) {
			foreach ( vvp_cleanup_needle_contexts( $new, $rule['needle'] ) as $context ) {
				vvp_cleanup_add_group( $left, vvp_cleanup_fingerprint( $context, 300 ), (int) $row->ID );
			}
		}
	}

	if ( 0 === $count ) {
		continue;
	}
	$cleaned++;
	$total += $count;

	if ( $survey ) {
		foreach ( $removed as $snippet ) {
			vvp_cleanup_add_group( $matched, vvp_cleanup_fingerprint( wp_strip_all_tags( $snippet ) ?: $snippet ), (int) $row->ID );
		}
	}
	if ( $apply ) {
		$wpdb->update( $wpdb->posts, [ 'post_content' => $new ], [ 'ID' => $row->ID ] );
		clean_post_cache( (int) $row->ID );
	}
}

WP_CLI::log( sprintf( '%s [%s]: %d posts scanned, %d posts / %d snippets %s.',
	$apply ? 'APPLIED' : ( $survey ? 'SURVEY' : 'DRY RUN' ), $args[0], count( $rows ), $cleaned, $total,
	$apply ? 'removed' : 'would be removed' ) );

if ( $survey ) {
	vvp_cleanup_print_groups( 'Would be removed', $matched );
	if ( $rule['needle'] ) {
		vvp_cleanup_print_groups( "Still containing \"{$rule['needle']}\" afterwards", $left );
	}
	return;
}

if ( $rule['needle'] ) {
	WP_CLI::log( count( $leftover ) . " posts still contain \"{$rule['needle']}\"" . ( $leftover ? ':' : '.' ) );
	foreach ( $leftover as $line ) {
		WP_CLI::log( $line );
	}
}
WP_CLI::log( count( $skipped ) . ' posts skipped' . ( $skipped ? ' (not written):' : '.' ) );
foreach ( $skipped as $line ) {
	WP_CLI::log( $line );
}
