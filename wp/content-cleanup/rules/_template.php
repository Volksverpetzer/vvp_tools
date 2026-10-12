<?php
/**
 * Template for a new rule: copy to rules/<name>.php (lowercase, a-z 0-9 - _) and fill in.
 * Files starting with "_" are not offered as rules.
 *
 * What the engine already does for you (cleanup.php):
 * - removes wrappers that only held the snippet (<p>, <em>, <strong>, <b>, <i>, <span>)
 * - drops paragraph/html/shortcode/freeform blocks that only held the snippet
 * - merges pieces that sit next to each other (only whitespace/<br>/<p> between) into one removal
 * - rejoins a word when the snippet was pasted mid-word ("A<snippet>uch" → "Auch"),
 *   otherwise leaves a paragraph break
 * - skips (never writes) a post on regex errors, changed tag balance or empty result
 */

return [
	// Shown in the summary line.
	'description' => 'What this removes',

	// SQL LIKE prefilter(s), combined with OR. Escape _ and % as \_ and \%.
	'like'        => [ '%some-unique-string%' ],

	// Optional: case-insensitive string that should be gone afterwards. Posts that still contain it
	// are listed (dry run) or grouped by context (survey) – that is how you find variants you missed.
	'needle'      => 'some-unique-string',

	// PCRE without delimiters, matching ONE removable occurrence. Flags default to 's'.
	// Prefer possessive quantifiers (*+, ++) and avoid (?:(?!x).)* on long posts – PCRE's JIT stack
	// is small (preg error 6). Use alternation for several piece types; adjacent pieces merge.
	'pattern'     => '<div class="old-widget">[^<]*+</div>',
	// 'flags'    => 's',

	// Optional: decide per match whether to remove it (true) or keep it (false). $m holds the
	// pattern's capture groups, e.g. to keep boxes with editorial content.
	// 'remove'   => fn( array $m ): bool => true,

	// Optional: defaults to published posts and pages.
	// 'post_types' => [ 'post', 'page' ],
	// 'statuses'   => [ 'publish' ],

	// Test cases, run locally with `php cleanup.php <name> test`. Use real snippets from the survey.
	// 'out' => 'SKIP' expects the post to be skipped by a safety check.
	'tests'       => [
		'removed between paragraphs' => [
			'in'  => "<p>Davor.</p>\n<div class=\"old-widget\">x</div>\n<p>Danach.</p>",
			'out' => "<p>Davor.</p>\n\n<p>Danach.</p>",
		],
	],
];
