<?php
namespace Nowera\ProductFeeds\Catalog;

use Nowera\ProductFeeds\State;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of one batch of parent products.
 */
final class Batch {

	/** @var list<array<string,mixed>> channel fields, one entry per item, in file order */
	public array $rows = array();

	/** @var list<array{item:array,fields:array}> kept only for previews */
	public array $items = array();

	/** @var list<array{0:string,1:int,2:int,3:string}> [reason, id, parent id, detail] */
	public array $skipped = array();

	/** @var list<array{0:string,1:int,2:int,3:string}> [issue, id, parent id, detail] */
	public array $issues = array();

	public array $counts;

	/** Last parent ID handled (the cursor for the next batch). */
	public int $last_id = 0;

	public function __construct() {
		$this->counts = State::zero_counts();
	}

	public function count( string $key, int $by = 1 ): void {
		$this->counts[ $key ] = ( $this->counts[ $key ] ?? 0 ) + $by;
	}
}
