<?php
namespace Nowera\ProductFeeds\Output;

defined( 'ABSPATH' ) || exit;

/**
 * A field with sub-fields, e.g. <g:shipping><g:country>SK</g:country>…</g:shipping>.
 * Child names carry their own prefix ("g:country", or "label" for Meta).
 */
final class Node {

	/** @param array<string,string> $children name => value, in output order */
	public function __construct( public readonly array $children ) {}
}
