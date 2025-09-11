<?php

namespace MediaWiki\Extension\MediaModeration\PeriodicMetrics;

use Wikimedia\Rdbms\IReadableDatabase;

/**
 * A metric defining how many unscanned images (mms_is_match as NULL) which also
 * have been previously attempted to be scanned (mms_last_checked as not NULL)
 * are present for a wiki.
 */
class UnscannedImagesWithLastCheckedDefinedMetric implements IMetric {

	public function __construct(
		private readonly IReadableDatabase $dbr,
	) {
	}

	/** @inheritDoc */
	public function calculate(): int {
		return $this->dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'mediamoderation_scan' )
			->where(
				$this->dbr->expr( 'mms_is_match', '=', null )
					->and( 'mms_last_checked', '!=', null )
			)
			->caller( __METHOD__ )
			->fetchField();
	}

	/** @inheritDoc */
	public function getName(): string {
		return 'scan_table_unscanned_with_last_checked_defined_total';
	}

	/** @inheritDoc */
	public function getStatsdKey(): string {
		return 'MediaModeration.ScanTable.UnscannedWithLastCheckedDefined';
	}
}
