<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Maintenance;

use MediaWiki\Extension\MediaModeration\Maintenance\UpdateMetrics;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use Wikimedia\Stats\StatsFactory;

/**
 * Test class for the updateMetrics.php maintenance script.
 * This is marked as covering the metric classes and factory too.
 *
 * @covers \MediaWiki\Extension\MediaModeration\Maintenance\UpdateMetrics
 *
 * @covers \MediaWiki\Extension\MediaModeration\PeriodicMetrics\MediaModerationMetricsFactory
 * @covers \MediaWiki\Extension\MediaModeration\PeriodicMetrics\TotalTableCountMetric
 * @covers \MediaWiki\Extension\MediaModeration\PeriodicMetrics\ScannedImagesMetric
 * @covers \MediaWiki\Extension\MediaModeration\PeriodicMetrics\UnscannedImagesMetric
 * @covers \MediaWiki\Extension\MediaModeration\PeriodicMetrics\UnscannedImagesWithLastCheckedDefinedMetric
 *
 * @group MediaModeration
 * @group Database
 */
class UpdateMetricsTest extends MaintenanceBaseTestCase {
	protected function getMaintenanceClass() {
		return UpdateMetrics::class;
	}

	public function testExecute() {
		$statsHelper = StatsFactory::newUnitTestingHelper();
		$this->setService( 'StatsFactory', $statsHelper->getStatsFactory() );
		$this->overrideConfigValues( [
			MainConfigNames::DBname => 'example',
			MainConfigNames::DBmwschema => null,
			MainConfigNames::DBprefix => ''
		] );

		$this->maintenance->setOption( 'verbose', 1 );
		$this->maintenance->execute();

		$this->expectOutputString( <<<TEXT
			scan_table_total is 8.
			scan_table_scanned_total is 3.
			scan_table_unscanned_total is 5.
			scan_table_unscanned_with_last_checked_defined_total is 1.

			TEXT
		);
		$this->assertSame(
			[
				'mediawiki.MediaModeration.scan_table_total:8|g|#wiki:example',
				'mediawiki.MediaModeration.scan_table_scanned_total:3|g|#wiki:example',
				'mediawiki.MediaModeration.scan_table_unscanned_total:5|g|#wiki:example',
				'mediawiki.MediaModeration.scan_table_unscanned_with_last_checked_defined_total:1|g|#wiki:example',
			],
			$statsHelper->consumeAllFormatted()
		);
	}

	public function addDBData() {
		parent::addDBData();
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'mediamoderation_scan' )
			->rows( [
				[
					'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80yu',
					'mms_last_checked' => null,
					'mms_is_match' => null,
				],
				[
					'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80ru',
					'mms_last_checked' => null,
					'mms_is_match' => null,
				],
				[
					'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j70ru',
					'mms_last_checked' => null,
					'mms_is_match' => null,
				],
				[
					'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j800u',
					'mms_last_checked' => null,
					'mms_is_match' => null,
				],
				[
					'mms_sha1' => 'sy02psim0bgdh0st4vdltuzoh7j70ru',
					'mms_last_checked' => '20231211',
					// Define match status as negative
					'mms_is_match' => 0,
				],
				[
					'mms_sha1' => 'sy02psim0bgdh0st4vdltuzoh7j60ru',
					'mms_last_checked' => '20231204',
					// Define match status as positive
					'mms_is_match' => 1,
				],
				[
					'mms_sha1' => 'sy02psim0bgdh0st4vdlguzoh7j60ru',
					'mms_last_checked' => '20231210',
					// Define match status as negative
					'mms_is_match' => 0,
				],
				[
					'mms_sha1' => 'ay02psim0bfdh0st4vdlguzoh7j60ru',
					// Define a last checked value, even though the match status is unscanned.
					'mms_last_checked' => '20231208',
					// Define match status as unscanned
					'mms_is_match' => null,
				]
			] )
			->execute();
	}
}
