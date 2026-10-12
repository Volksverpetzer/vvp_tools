<?php
/**
 * Old WPBakery [vc_message] donation boxes ("Hey, möchtest du mehr Recherchen …").
 * Applied on production 2026-10-12: 2,357 boxes.
 *
 * Only boxes whose text starts with one of the known donation texts are removed. [vc_message]
 * boxes with editorial content (89 in 45 posts, see Asana) are kept – the theme hides them via
 * add_shortcode('vc_message', '__return_false') in vvp_wp_theme functions.php.
 */

$prefixes = [
	'Hey, möchtest du mehr Recherchen',
	'Da du diesen Artikel zu Ende gelesen hast',
	'Ihr wollt mehr Recherchen',
	'Möchtest du mehr Recherchen',
	'Wir schalten keine Werbung und uns finanziert niemand',
	'Wir schalten keine Werbung und werden von niemandem finanziert',
	'If you want to tip our work',
];

$vc  = '[vc_message message_box_color="green" icon_fontawesome="fa fa-check"]';
$don = 'Da du diesen Artikel zu Ende gelesen hast: Möchtest du mehr … <a href="https://www.volksverpetzer.de/unterstuetzen/">einfach hier entlangschauen. </a>';

return [
	'description' => 'Old [vc_message] donation boxes',
	'like'        => [ '%[vc\_message%' ],
	'needle'      => '[vc_message',

	// One box. The inner part may not contain another [vc_message, so an unclosed box never swallows
	// the next one. Unrolled loop with possessive quantifiers to stay within PCRE's JIT stack on long posts.
	'pattern'     => '\[vc_message\b[^\]]*+\]([^\[]*+(?:\[(?!/?vc_message)[^\[]*+)*+)\[/vc_message\]',

	'remove'      => function ( array $m ) use ( $prefixes ): bool {
		$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) ) );
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $text, $prefix ) ) {
				return true;
			}
		}
		return false;
	},

	'tests'       => [
		'centered <p><em> wrapper (192)' => [
			'in'  => "<em>Artikelbild: Volksverpetzer</em>\n<p style=\"text-align: center;\"><em>{$vc}Hey, möchtest du mehr Recherchen … </em><em>[/vc_message]</em></p>\n<p style=\"text-align: center;\"><a href=\"https://paypal.me/vvpetze\">PayPal</a></p>",
			'out' => "<em>Artikelbild: Volksverpetzer</em>\n\n<p style=\"text-align: center;\"><a href=\"https://paypal.me/vvpetze\">PayPal</a></p>",
		],
		'<em> wrapper at the end of a Divi post (5478)' => [
			'in'  => "<em>Artikelbild: pixabay.com</em>\n\n<em>{$vc}{$don}</em><em>Vielleicht hast du auch Fragen? <a href=\"x\">Twitter </a>[/vc_message]</em>\n\n<a href=\"https://steadyhq.com/\">Steady</a><!-- /wp:divi/placeholder -->",
			'out' => "<em>Artikelbild: pixabay.com</em>\n\n<a href=\"https://steadyhq.com/\">Steady</a><!-- /wp:divi/placeholder -->",
		],
		'shortcode block (36756)' => [
			'in'  => "<!-- wp:paragraph -->\n<p>Text</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n{$vc}Möchtest du mehr Recherchen … </em><em>[/vc_message]\n<!-- /wp:shortcode -->\n\n<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->",
			'out' => "<!-- wp:paragraph -->\n<p>Text</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->",
		],
		'editorial box is kept' => [
			'in'  => "<p>Text</p>\n[vc_message message_box_color=\"info\"]Nicht wirklich![/vc_message]\n<p>mehr</p>\n<em>{$vc}{$don}[/vc_message]</em>",
			'out' => "<p>Text</p>\n[vc_message message_box_color=\"info\"]Nicht wirklich![/vc_message]\n<p>mehr</p>\n\n",
		],
		'unclosed box does not swallow the next one (199)' => [
			'in'  => "[vc_message]Bevor du einen Artikel kommentierst, solltest du ihn lesen.\n\nText\n<em>{$vc}{$don}[/vc_message]</em>",
			'out' => "[vc_message]Bevor du einen Artikel kommentierst, solltest du ihn lesen.\n\nText\n\n",
		],
		'umlaut stored as entity' => [
			'in'  => "<p>Text</p>\n<p>&nbsp;[vc_message]Hey, m&ouml;chtest du mehr Recherchen …[/vc_message]</p>\n<p>Danach</p>",
			'out' => "<p>Text</p>\n\n<p>Danach</p>",
		],
		'box closing inside a different <em> is skipped' => [
			'in'  => "<p>Text</p>\n<em>{$vc}{$don}</em>[/vc_message]\n<p>Danach</p>",
			'out' => 'SKIP',
		],
		'very long post (PCRE JIT stack)' => [
			'in'  => str_repeat( "<p>Quelle: <a href=\"https://example.org/x\">Link</a> [1] Text.</p>\n", 4000 ) . "<em>{$vc}{$don}[/vc_message]</em>",
			'out' => str_repeat( "<p>Quelle: <a href=\"https://example.org/x\">Link</a> [1] Text.</p>\n", 4000 ) . "\n",
		],
	],
];
