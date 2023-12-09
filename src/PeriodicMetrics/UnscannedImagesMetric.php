<?php

namespace MediaWiki\Extension\MediaModeration\PeriodicMetrics;

use Wikimedia\Rdbms\IReadableDatabase;

/**
 * A metric that holds the number of unscanned images (mms_is_match as NULL) in the
 * mediamoderation_scan table.
 */
class UnscannedImagesMetric implements IMetric {

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
			->where( [ 'mms_is_match' => null ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	/** @inheritDoc */
	public function getStatsdKey(): string {
		return 'MediaModeration.ScanTable.Unscanned';
	}
}
