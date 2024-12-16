<?php

namespace MediaWiki\Extension\MediaModeration\PeriodicMetrics;

use Wikimedia\Rdbms\IReadableDatabase;

/**
 * A metric that holds the total table count of the mediamoderation_scan table
 * for a given wiki.
 */
class TotalTableCountMetric implements IMetric {

	private IReadableDatabase $dbr;

	/**
	 * @param IReadableDatabase $dbr
	 */
	public function __construct( IReadableDatabase $dbr ) {
		$this->dbr = $dbr;
	}

	/** @inheritDoc */
	public function calculate(): int {
		return $this->dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'mediamoderation_scan' )
			->caller( __METHOD__ )
			->fetchField();
	}

	/** @inheritDoc */
	public function getName(): string {
		return 'scan_table_total';
	}

	/** @inheritDoc */
	public function getStatsdKey(): string {
		return 'MediaModeration.ScanTable.TotalCount';
	}
}
