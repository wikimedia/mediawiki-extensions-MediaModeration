<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Hooks\Handlers;

use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileLookup;
use MediaWiki\Extension\MediaModeration\Tests\Integration\InsertMockFileDataTrait;
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
	public function testGetFileObjectsForSha1( $sha1, $batchSize, $expectedReturnCount ) {
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
		return [
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
	}

	public function addDBDataOnce() {
		$this->insertMockFileData();

		// Add an additional oldimage row
		$actorId = $this->getServiceContainer()
			->getActorStore()
			->acquireActorId( $this->mockRegisteredUltimateAuthority()->getUser(), $this->getDb() );
		$commentId = $this->getServiceContainer()
			->getCommentStore()
			->createComment( $this->getDb(), 'test' )->id;
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
			->execute();
	}
}
