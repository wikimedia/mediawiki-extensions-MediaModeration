<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Maintenance;

use MediaWiki\Extension\MediaModeration\Maintenance\ImportExistingFilesToScanTable;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Maintenance\ImportExistingFilesToScanTable
 * @group MediaModeration
 * @group Database
 */
class ImportExistingFilesToScanTableTest extends MaintenanceBaseTestCase {
	use MockAuthorityTrait;

	protected function getMaintenanceClass() {
		return ImportExistingFilesToScanTable::class;
	}

	public function testExecuteWithNoRowsToImport() {
		/** @var TestingAccessWrapper $maintenance */
		$maintenance = $this->maintenance;
		// Set sleep as 0, otherwise the tests will take ages.
		$maintenance->setOption( 'sleep', 0 );
		// Run the maintenance script
		$maintenance->execute();
		$this->expectOutputString(
			"Now importing rows from the table 'image' in batches of 200.\n" .
			"Batch 1 of ~1.\n" .
			"Now importing rows from the table 'filearchive' in batches of 200.\n" .
			"Batch 1 of ~1.\n" .
			"Now importing rows from the table 'oldimage' in batches of 200.\n" .
			"Batch 1 of ~1.\n" .
			"Script marked as completed (added to updatelog).\n"
		);
		// Expect no rows in mediamoderation_scan, as the import should have added nothing.
		$this->assertSame(
			0,
			(int)$this->getDb()->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->table( 'mediamoderation_scan' )
				->caller( __METHOD__ )
				->fetchField(),
			'The importExistingFilesToScanTable.php maintenance script added rows to mediamoderation_scan ' .
			'when no rows existed in the image, oldimage, or filearchive tables.'
		);
		// Should have a row in the updatelog
		$this->assertSame(
			1,
			(int)$this->getDb()->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->table( 'updatelog' )
				->caller( __METHOD__ )
				->fetchField(),
			'The updatelog table should have a entry as the script completed.'
		);
	}

	public function testExecuteWithInvalidTableProvided() {
		$this->maintenance->setOption( 'sleep', 0 );

		// Execute the maintenance script with an invalid table provided and expect a fatal error
		$this->maintenance->setOption( 'table', [ 'image', 'invalidtable', 'oldimage' ] );
		$this->expectOutputString(
			"The table option value 'invalidtable' is not a valid table to import images from.\n\n"
		);
		$this->expectCallToFatalError();
		$this->maintenance->execute();
	}

	/** @dataProvider provideExecuteWithMarkCompleteSpecified */
	public function testExecuteWithMarkCompleteSpecified( $forceArgumentSpecified, $expectedOutputString ) {
		// Set sleep as 0, otherwise the tests will take ages.
		$this->maintenance->setOption( 'sleep', 0 );
		if ( $forceArgumentSpecified ) {
			$this->maintenance->setOption( 'force', 1 );
		}
		$this->maintenance->setOption( 'mark-complete', 1 );
		// Run the maintenance script
		$this->maintenance->execute();
		$this->expectOutputString( $expectedOutputString );
		// Expect no rows in mediamoderation_scan, as the import should have added nothing.
		$this->assertSame(
			0,
			(int)$this->getDb()->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->table( 'mediamoderation_scan' )
				->caller( __METHOD__ )
				->fetchField(),
			'The importExistingFilesToScanTable.php maintenance script added rows to mediamoderation_scan ' .
			'when no rows existed in the image, oldimage, or filearchive tables.'
		);
		// Should have a row in the updatelog
		$this->assertSame(
			1,
			(int)$this->getDb()->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->table( 'updatelog' )
				->caller( __METHOD__ )
				->fetchField(),
			'The updatelog table should have a entry as mark-complete was provided.'
		);
	}

	public static function provideExecuteWithMarkCompleteSpecified() {
		return [
			'--mark-complete specified' => [
				false,
				"Now importing rows from the table 'image' in batches of 200.\n" .
				"Batch 1 of ~1.\n" .
				"Now importing rows from the table 'filearchive' in batches of 200.\n" .
				"Batch 1 of ~1.\n" .
				"Now importing rows from the table 'oldimage' in batches of 200.\n" .
				"Batch 1 of ~1.\n" .
				"Script marked as completed (added to updatelog).\n",
			],
			'--mark-complete and --force specified' => [
				true,
				"Now importing rows from the table 'image' in batches of 200.\n" .
				"Batch 1 of ~1.\n" .
				"Now importing rows from the table 'filearchive' in batches of 200.\n" .
				"Batch 1 of ~1.\n" .
				"Now importing rows from the table 'oldimage' in batches of 200.\n" .
				"Batch 1 of ~1.\n",
			],
		];
	}

	public function testExecuteWhenInvalidTableProvided() {
		$this->maintenance->setOption( 'sleep', 0 );

		// Execute the maintenance script with an empty tables list and expect a fatal error
		$this->expectCallToFatalError();
		$this->expectOutputRegex( "/The array of tables to have images imported from cannot be empty/" );
		$this->maintenance->setOption( 'table', [] );
		$this->maintenance->execute();
	}

	public function testExecuteWhenStartTimestampInvalid() {
		$this->maintenance->setOption( 'sleep', 0 );

		// Execute the maintenance script with a invalid start-timestamp option value and expect a fatal error
		$this->expectCallToFatalError();
		$this->expectOutputRegex(
			'/start-timestamp could not be parsed as either a valid absolute or relative timestamp/'
		);
		$this->maintenance->setOption( 'start-timestamp', 'invalid timestamp abc' );
		$this->maintenance->execute();
	}
}
