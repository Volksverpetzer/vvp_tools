<?php
/**
 * Old Google AdSense in-article ads (2014–2020), pasted into post content by hand.
 * Applied on production 2026-10-12: 1,493 posts / 1,534 ads.
 *
 * Removes each piece separately; pieces next to each other merge into one removal:
 * loader script, <ins class="adsbygoogle"> slot (also with AdSense's injected aswift_N wrappers,
 * when copied from the live DOM), push script, and the bare push line left after <script> was stripped.
 *
 * Slots are only removed for our old publisher ID; the slot IDs varied, the publisher ID did not.
 * Any other AdSense slot stays and shows up as a leftover in the dry run / survey.
 */

$client = 'ca-pub-5940101755599596';

$space = '(?:\s|&nbsp;|<br\s*/?>)*';
$push  = '\(adsbygoogle\s*=\s*window\.adsbygoogle\s*\|\|\s*\[\]\)\.push\(\{\}\);?';
$ins   = '<ins class="adsbygoogle" style="text-align: center" data-ad-layout="in-article" data-ad-format="fluid" data-ad-client="ca-pub-5940101755599596" data-ad-slot="9775095125"></ins>';
$insB  = '<ins class="adsbygoogle" style="display: block; text-align: center;" data-ad-layout="in-article" data-ad-format="fluid" data-ad-client="ca-pub-5940101755599596" data-ad-slot="9775095125"></ins>';
$pre   = "<!-- wp:paragraph -->\n<p>Davor.</p>\n<!-- /wp:paragraph -->\n\n";
$post  = "<!-- wp:paragraph -->\n<p>Danach.</p>\n<!-- /wp:paragraph -->";

return [
	'description' => 'Old Google AdSense in-article ads',
	'like'        => [ '%adsbygoogle%' ],
	'needle'      => 'adsbygoogle',
	'pattern'     => '<script[^>]*adsbygoogle\.js[^>]*>\s*</script>'
		. '|<ins class="adsbygoogle"[^>]*\bdata-ad-client="' . preg_quote( $client, '#' ) . '"[^>]*>(?:<ins id="aswift_\d+_expand"[^>]*><ins id="aswift_\d+_anchor"[^>]*></ins></ins>)?\s*</ins>'
		. "|<script[^>]*>{$space}{$push}{$space}</script>"
		. "|{$push}",

	'tests'       => [
		'2020 paragraph block' => [
			'in'  => $pre . "<!-- wp:paragraph -->\n<p><br>\n$ins<br>\n\n     (adsbygoogle = window.adsbygoogle || []).push({});\n</p>\n<!-- /wp:paragraph -->\n\n" . $post,
			'out' => $pre . $post,
		],
		'broken p structure (161x)' => [
			'in'  => $pre . "<!-- wp:paragraph -->\n</p>\n<p>\n$ins</p>\n<p>\n(adsbygoogle = window.adsbygoogle || []).push({});\n</p>\n<p>\n<!-- /wp:paragraph -->\n\n" . $post,
			'out' => $pre . $post,
		],
		'script tags with <br /> (6x)' => [
			'in'  => $pre . "<!-- wp:paragraph -->\n<p><script async=\"\" src=\"//pagead2.googlesyndication.com/pagead/js/adsbygoogle.js\"></script><br>$insB<br><script><br />\n(adsbygoogle = window.adsbygoogle || []).push({});<br />\n</script></p>\n<!-- /wp:paragraph -->\n\n" . $post,
			'out' => $pre . $post,
		],
		'bare push line' => [
			'in'  => $pre . "<!-- wp:paragraph -->\n<p>(adsbygoogle = window.adsbygoogle || []).push({});</p>\n<!-- /wp:paragraph -->\n\n" . $post,
			'out' => $pre . $post,
		],
		'classic content in Divi wrapper (181)' => [
			'in'  => "<!-- wp:divi/placeholder --><h2>Fakten.</h2>\n<script async src=\"//pagead2.googlesyndication.com/pagead/js/adsbygoogle.js\"></script>\n$insB\n<script>\n     (adsbygoogle = window.adsbygoogle || []).push({});\n</script>\nSie untersuchten folgende Behauptungen:<!-- /wp:divi/placeholder -->",
			'out' => "<!-- wp:divi/placeholder --><h2>Fakten.</h2>\n\nSie untersuchten folgende Behauptungen:<!-- /wp:divi/placeholder -->",
		],
		'slot between two paragraphs (1287)' => [
			'in'  => "“noch weniger, medienwirksam.”\n\n{$insB}Der höchste gemessene Wert",
			'out' => "“noch weniger, medienwirksam.”\n\nDer höchste gemessene Wert",
		],
		'slot copied from live DOM, mid-word (3624)' => [
			'in'  => "Proteste sein.\n\nA<ins class=\"adsbygoogle\" data-ad-layout=\"in-article\" data-ad-format=\"fluid\" data-ad-client=\"ca-pub-5940101755599596\" data-ad-slot=\"9775095125\" data-adsbygoogle-status=\"done\"><ins id=\"aswift_1_expand\"><ins id=\"aswift_1_anchor\"></ins></ins></ins>uch andere rechte Blogs",
			'out' => "Proteste sein.\n\nAuch andere rechte Blogs",
		],
		'slot of another publisher is kept' => [
			'in'  => "<p>Text.</p>\n<ins class=\"adsbygoogle\" data-ad-client=\"ca-pub-0000000000000000\" data-ad-slot=\"1\"></ins>\n<p>Mehr.</p>",
			'out' => "<p>Text.</p>\n<ins class=\"adsbygoogle\" data-ad-client=\"ca-pub-0000000000000000\" data-ad-slot=\"1\"></ins>\n<p>Mehr.</p>",
		],
		'paragraph with real text keeps the text' => [
			'in'  => "<!-- wp:paragraph -->\n<p>Echter Text. $insB</p>\n<!-- /wp:paragraph -->",
			'out' => "<!-- wp:paragraph -->\n<p>Echter Text.\n\n</p>\n<!-- /wp:paragraph -->",
		],
	],
];
