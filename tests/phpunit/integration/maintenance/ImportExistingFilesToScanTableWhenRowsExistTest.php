<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Maintenance;

use MediaWiki\Extension\MediaModeration\Maintenance\ImportExistingFilesToScanTable;
use MediaWiki\Extension\MediaModeration\Tests\Integration\InsertMockFileDataTrait;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Maintenance\ImportExistingFilesToScanTable
 * @group MediaModeration
 * @group Database
 */
class ImportExistingFilesToScanTableWhenRowsExistTest extends MaintenanceBaseTestCase {
	use MockAuthorityTrait;
	use InsertMockFileDataTrait;

	protected function getMaintenanceClass() {
		return ImportExistingFilesToScanTable::class;
	}

	/** @dataProvider provideExecuteWithRowsToImport */
	public function testExecuteWithRowsToImport(
		$batchSize, $startTimestamp, $table, $expectedOutput, $expectedScanTableRowCount, $updateLogHasEntry,
		$fileSchemaMigrationStage
	) {
		$this->overrideConfigValue( MainConfigNames::FileSchemaMigrationStage, $fileSchemaMigrationStage );
		/** @var TestingAccessWrapper $maintenance */
		$maintenance = $this->maintenance;
		// Set the batch size and start timestamp
		$maintenance->setBatchSize( $batchSize );
		$maintenance->setOption( 'start-timestamp', $startTimestamp );
		// Set sleep as 0, otherwise the tests will take ages.
		$maintenance->setOption( 'sleep', 0 );
		// If the $table argument is not null, set the 'table' option as this value
		if ( $table !== null ) {
			$maintenance->setOption( 'table', $table );
		}
		// Run the maintenance script.
		$this->expectOutputString( $expectedOutput );
		$maintenance->execute();
		$this->assertSame(
			$expectedScanTableRowCount,
			(int)$this->getDb()->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( 'mediamoderation_scan' )
				->fetchField(),
			'Rows should have been imported as the maintenance script was run.'
		);
		$this->assertSame(
			$updateLogHasEntry,
			(bool)$this->getDb()->newSelectQueryBuilder()
				->select( '1' )
				->from( 'updatelog' )
				->caller( __METHOD__ )
				->fetchField(),
			'The state of the updatelog table was not as expected.'
		);
	}

	public static function provideExecuteWithRowsToImport() {
		$testCases = [
			'Default batch size, no start timestamp' => [
				// Null means use the default of 200 as the batch size
				null,
				// Empty string indicates no start timestamp
				'',
				// Null means no specified 'table' argument
				null,
				// Expect that the output of the script matches the expected output
				"Now importing rows from the table 'image' in batches of 200.\n" .
				"Batch 1 of ~1.\n" .
				"Now importing rows from the table 'filearchive' in batches of 200.\n" .
				"Batch 1 of ~1.\n" .
				"Now importing rows from the table 'oldimage' in batches of 200.\n" .
				"Batch 1 of ~1.\n" .
				"Script marked as completed (added to updatelog).\n",
				// The number of mediamoderation_scan table rows that should exist after the maintenance script is run.
				6,
				// Boolean value indicating whether an entry to the updatelog table should have been added.
				true,
			],
			'Batch size 1, no start timestamp' => [
				1,
				'',
				null,
				"Now importing rows from the table 'image' in batches of 1.\n" .
				"Batch 1 of ~3.\n" .
				"Batch 2 of ~3 with rows starting at timestamp 20201105234242.\n" .
				"Temporarily raised the batch size to 2 due to files with the same upload timestamp. This is done " .
				"to prevent an infinite loop. Consider raising the batch size to avoid this.\n" .
				"Batch 3 of ~3 with rows starting at timestamp 20201105235242.\n" .
				"Temporarily raised the batch size to 2 due to files with the same upload timestamp. This is done " .
				"to prevent an infinite loop. Consider raising the batch size to avoid this.\n" .
				"Now importing rows from the table 'filearchive' in batches of 1.\n" .
				"Batch 1 of ~5.\n" .
				"Batch 2 of ~5 with rows starting at timestamp 20201105235239.\n" .
				"Temporarily raised the batch size to 4 due to files with the same upload timestamp. This is done " .
				"to prevent an infinite loop. Consider raising the batch size to avoid this.\n" .
				"Batch 3 of ~5 with rows starting at timestamp 20231105235239.\n" .
				"Temporarily raised the batch size to 2 due to files with the same upload timestamp. This is done " .
				"to prevent an infinite loop. Consider raising the batch size to avoid this.\n" .
				"Now importing rows from the table 'oldimage' in batches of 1.\n" .
				"Batch 1 of ~2.\n" .
				"Batch 2 of ~2 with rows starting at timestamp 20201105235241.\n" .
				"Temporarily raised the batch size to 2 due to files with the same upload timestamp. This is done " .
				"to prevent an infinite loop. Consider raising the batch size to avoid this.\n" .
				"Script marked as completed (added to updatelog).\n",
				6,
				true,
			],
			'Batch size 2 and start timestamp' => [
				2,
				'20201105235241',
				null,
				"Now importing rows from the table 'image' in batches of 2.\n" .
				"Starting from timestamp 20201105235241 and importing files with a greater timestamp.\n" .
				"Batch 1 of ~1 with rows starting at timestamp 20201105235241.\n" .
				"Now importing rows from the table 'filearchive' in batches of 2.\n" .
				"Starting from timestamp 20201105235241 and importing files with a greater timestamp.\n" .
				"Batch 1 of ~1 with rows starting at timestamp 20201105235241.\n" .
				"Now importing rows from the table 'oldimage' in batches of 2.\n" .
				"Starting from timestamp 20201105235241 and importing files with a greater timestamp.\n" .
				"Batch 1 of ~1 with rows starting at timestamp 20201105235241.\n" .
				'Script not marked as completed (not added to updatelog). The script was marked as not complete ' .
				"because not all the images on the wiki were processed in this run of the script.\n" .
				'To mark the script as complete and not have it run again through update.php, make sure to run the ' .
				"script again with the 'mark-complete' option specified. You should only do this once you are sure " .
				"that all the images on the wiki have been imported.\n",
				3,
				false,
			],
			'Default batch size and start timestamp only including one file' => [
				null,
				'20231105235239',
				null,
				"Now importing rows from the table 'image' in batches of 200.\n" .
				"Starting from timestamp 20231105235239 and importing files with a greater timestamp.\n" .
				"Batch 1 of ~1 with rows starting at timestamp 20231105235239.\n" .
				"Now importing rows from the table 'filearchive' in batches of 200.\n" .
				"Starting from timestamp 20231105235239 and importing files with a greater timestamp.\n" .
				"Batch 1 of ~1 with rows starting at timestamp 20231105235239.\n" .
				"Now importing rows from the table 'oldimage' in batches of 200.\n" .
				"Starting from timestamp 20231105235239 and importing files with a greater timestamp.\n" .
				"Batch 1 of ~1 with rows starting at timestamp 20231105235239.\n" .
				'Script not marked as completed (not added to updatelog). The script was marked as not complete ' .
				"because not all the images on the wiki were processed in this run of the script.\n" .
				'To mark the script as complete and not have it run again through update.php, make sure to run the ' .
				"script again with the 'mark-complete' option specified. You should only do this once you are sure " .
				"that all the images on the wiki have been imported.\n",
				1,
				false,
			],
			'Default batch size, only the image table specified' => [
				null,
				'',
				[ 'image' ],
				"Now importing rows from the table 'image' in batches of 200.\n" .
				"Batch 1 of ~1.\n" .
				'Script not marked as completed (not added to updatelog). The script was marked as not complete ' .
				"because not all the images on the wiki were processed in this run of the script.\n" .
				'To mark the script as complete and not have it run again through update.php, make sure to run the ' .
				"script again with the 'mark-complete' option specified. You should only do this once you are sure " .
				"that all the images on the wiki have been imported.\n",
				2,
				false,
			],
			'Default batch size, the filearchive and oldimage table specified' => [
				null,
				'',
				[ 'filearchive', 'image' ],
				"Now importing rows from the table 'filearchive' in batches of 200.\n" .
				"Batch 1 of ~1.\n" .
				"Now importing rows from the table 'image' in batches of 200.\n" .
				"Batch 1 of ~1.\n" .
				'Script not marked as completed (not added to updatelog). The script was marked as not complete ' .
				"because not all the images on the wiki were processed in this run of the script.\n" .
				'To mark the script as complete and not have it run again through update.php, make sure to run the ' .
				"script again with the 'mark-complete' option specified. You should only do this once you are sure " .
				"that all the images on the wiki have been imported.\n",
				5,
				false,
			],
			'Default batch size, start timestamp defined, only image specified' => [
				null,
				'20201105235242',
				[ 'image' ],
				"Now importing rows from the table 'image' in batches of 200.\n" .
				"Starting from timestamp 20201105235242 and importing files with a greater timestamp.\n" .
				"Batch 1 of ~1 with rows starting at timestamp 20201105235242.\n" .
				'Script not marked as completed (not added to updatelog). The script was marked as not complete ' .
				"because not all the images on the wiki were processed in this run of the script.\n" .
				'To mark the script as complete and not have it run again through update.php, make sure to run the ' .
				"script again with the 'mark-complete' option specified. You should only do this once you are sure " .
				"that all the images on the wiki have been imported.\n",
				1,
				false,
			],
		];

		foreach ( $testCases as $testName => $testData ) {
			foreach ( self::provideFileSchemaMigrationStageValues() as $name => $schemaStageValue ) {
				yield $testName . ', ' . strtolower( $name ) => array_merge( $testData, $schemaStageValue );
			}
		}
	}

	/** @dataProvider provideFileSchemaMigrationStageValues */
	public function testExecuteWithRowsAlreadyExistingInScanTable( $fileSchemaMigrationStage ) {
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'mediamoderation_scan' )
			->rows( [
				[ 'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80yu' ],
				[ 'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80gu' ],
			] )
			->execute();
		$this->testExecuteWithRowsToImport(
			null,
			'',
			null,
			"Now importing rows from the table 'image' in batches of 200.\n" .
			"Batch 1 of ~1.\n" .
			"Now importing rows from the table 'filearchive' in batches of 200.\n" .
			"Batch 1 of ~1.\n" .
			"Now importing rows from the table 'oldimage' in batches of 200.\n" .
			"Batch 1 of ~1.\n" .
			"Script marked as completed (added to updatelog).\n",
			7,
			true,
			$fileSchemaMigrationStage
		);
	}

	public static function provideFileSchemaMigrationStageValues() {
		return [
			'Reading new for file schema migration' => [ SCHEMA_COMPAT_NEW | SCHEMA_COMPAT_WRITE_OLD ],
			'Reading old for file schema migration' => [ SCHEMA_COMPAT_OLD | SCHEMA_COMPAT_WRITE_NEW ],
		];
	}

	public function addDBDataOnce() {
		$this->insertMockFileData();
	}
}
