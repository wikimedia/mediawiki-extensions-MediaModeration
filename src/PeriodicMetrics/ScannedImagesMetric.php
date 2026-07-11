<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\MediaModeration\PeriodicMetrics;

use Wikimedia\Rdbms\IReadableDatabase;

/**
 * A metric that holds the number of scanned images (mms_is_match != NULL) in the
 * mediamoderation_scan table.
 */
class ScannedImagesMetric implements IMetric {

	public function __construct(
		private readonly IReadableDatabase $dbr,
	) {
	}

	/** @inheritDoc */
	public function calculate(): int {
		return (int)$this->dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'mediamoderation_scan' )
			->where( $this->dbr->expr( 'mms_is_match', '!=', null ) )
			->caller( __METHOD__ )
			->fetchField();
	}

	/** @inheritDoc */
	public function getName(): string {
		return 'scan_table_scanned_total';
	}
}
