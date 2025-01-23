<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Hooks\Handlers;

use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileLookup;
use MediaWiki\Extension\MediaModeration\Tests\Integration\InsertMockFileDataTrait;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationFileLookup
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationFileFactory
 * @group MediaModeration
 * @group Database
 */
class MediaModerationFileLookupTest extends MediaWikiIntegrationTestCase {
	use InsertMockFileDataTrait;

	/** @dataProvider provideGetFileObjectsForSha1 */
	public function testGetFileObjectsForSha1( $sha1, $batchSize, $expectedReturnCount, $fileSchemaMigrationStage ) {
		$this->overrideConfigValue( MainConfigNames::FileSchemaMigrationStage, $fileSchemaMigrationStage );
		/** @var MediaModerationFileLookup $objectUnderTest */
		$objectUnderTest = $this->getServiceContainer()->get( 'MediaModerationFileLookup' );
		$returnedObjects = iterator_to_array( $objectUnderTest->getFileObjectsForSha1( $sha1, $batchSize ) );
		$this->assertCount(
			$expectedReturnCount,
			$returnedObjects,
			'::getFileObjectsForSha1 did not return the expected number of LocalFile/ArchivedFile objects.'
		);
	}

	public static function provideGetFileObjectsForSha1() {
		$testCases = [
			'One row from the image table' => [
				// SHA-1 parameter
				'sy02psim0bgdh0jt4vdltuzoh7j80ru',
				// Batch size parameter
				5,
				// Expected count of the returned generator when converted to an array
				1,
			],
			'One row from the oldimage table' => [
				'sy02psim0bgdh0jt4vdltuzoh7j800u',
				5,
				1,
			],
			'One row from the filearchive table' => [
				'sy02psim0bgdh0jt4vdltuzoh7j70ru',
				5,
				1,
			],
			'Three rows from filearchive and oldimage' => [
				'sy02psim0bgdh0st4vdltuzoh7j70ru',
				5,
				3,
			],
			'Three rows from filearchive and oldimage with batch size as 1' => [
				'sy02psim0bgdh0st4vdltuzoh7j70ru',
				1,
				3,
			],
			'No rows' => [
				'test',
				5,
				0,
			]
		];

		foreach ( $testCases as $testName => $testData ) {
			foreach ( self::provideFileSchemaMigrationStageValues() as $name => $schemaStageValue ) {
				yield $testName . ', ' . strtolower( $name ) => array_merge( $testData, $schemaStageValue );
			}
		}
	}

	public function addDBDataOnce() {
		$this->insertMockFileData();

		// Add an additional file revision to both the oldimage (for read old) and file / filerevision tables
		// (for read new)
		$actorId = $this->getServiceContainer()
			->getActorStore()
			->acquireActorId( $this->mockRegisteredUltimateAuthority()->getUser(), $this->getDb() );
		$commentId = $this->getServiceContainer()
			->getCommentStore()
			->createComment( $this->getDb(), 'test' )->id;

		// Add the additional file revision to the old schema
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'oldimage' )
			->row( [
				'oi_name' => 'Randoma-11m.png',
				'oi_archive_name' => '20201105235241' . 'Randoma-11m.png',
				'oi_size' => 12345,
				'oi_width' => 1000,
				'oi_height' => 1800,
				'oi_metadata' => '',
				'oi_bits' => 16,
				'oi_media_type' => MEDIATYPE_BITMAP,
				'oi_major_mime' => 'image',
				'oi_minor_mime' => 'png',
				'oi_description_id' => $commentId,
				'oi_actor' => $actorId,
				'oi_timestamp' => $this->getDb()->timestamp( '20201105235241' ),
				'oi_sha1' => 'sy02psim0bgdh0st4vdltuzoh7j70ru',
				'oi_deleted' => 0,
			] )
			->caller( __METHOD__ )
			->execute();

		// Add the additional file revision to the new schema
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'file' )
			->row( [
				'file_name' => 'Randoma-11m.png',
				'file_latest' => 0,
				'file_type' => 1,
				'file_deleted' => 0,
			] )
			->caller( __METHOD__ )
			->execute();
		$newlyInsertedFileId = $this->getDb()->insertId();

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'filerevision' )
			->row( [
				'fr_file' => $newlyInsertedFileId,
				'fr_size' => 12345,
				'fr_width' => 1000,
				'fr_height' => 1800,
				'fr_metadata' => '',
				'fr_bits' => 16,
				'fr_description_id' => $commentId,
				'fr_actor' => $actorId,
				'fr_timestamp' => $this->getDb()->timestamp( '20201105234242' ),
				'fr_sha1' => 'sy02psim0bgdh0st4vdltuzoh7j70ru',
				'fr_archive_name' => '20201105235241' . 'Randoma-11m.png',
				'fr_deleted' => 0,
			] )
			->caller( __METHOD__ )
			->execute();
	}
}
