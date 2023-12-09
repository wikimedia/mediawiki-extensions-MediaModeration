<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Maintenance;

use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MediaWiki\Extension\MediaModeration\Maintenance\UpdateMetrics;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * Test class for the updateMetrics.php maintenance script.
 * This is marked as covering the metric classes and factory too.
 *
 * @covers \MediaWiki\Extension\MediaModeration\Maintenance\UpdateMetrics
 *
 * @covers \MediaWiki\Extension\MediaModeration\PeriodicMetrics\MediaModerationMetricsFactory
 * @covers \MediaWiki\Extension\MediaModeration\PeriodicMetrics\TotalTableCountMetric
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

	/** @dataProvider provideExecute */
	public function testExecute( $expectedGaugeReturnMap ) {
		// Mock the StatsdDataFactoryInterface so we can verify that the correct values are being stored.
		$mockStatsDataFactory = $this->createMock( StatsdDataFactoryInterface::class );
		$mockStatsDataFactory->method( 'gauge' )
			->willReturnMap( $expectedGaugeReturnMap );
		/** @var TestingAccessWrapper $maintenance */
		$maintenance = $this->maintenance;
		$maintenance->statsDataFactory = $mockStatsDataFactory;
		$this->maintenance = $maintenance;
		$this->maintenance->execute();
	}

	public static function provideExecute() {
		return [
			'Expected execute behaviour based on test data' => [ [
				[ 'MediaModeration.ScanTable.TotalCount', 7 ],
				[ 'MediaModeration.ScanTable.Unscanned', 4 ],
				[ 'MediaModeration.ScanTable.UnscannedWithLastCheckedDefined', 1 ],
				[ 'MediaModeration.ScanTable.Scanned', 3 ],
			] ],
		];
	}

	public function addDBData() {
		parent::addDBData();
		$this->db->newInsertQueryBuilder()
			->insertInto( 'mediamoderation_scan' )
			->rows( [
				[ 'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80yu' ],
				[ 'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80ru' ],
				[ 'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j70ru' ],
				[ 'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j800u' ]
			] )
			->execute();
		$this->db->newInsertQueryBuilder()
			->insertInto( 'mediamoderation_scan' )
			->rows( [
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
