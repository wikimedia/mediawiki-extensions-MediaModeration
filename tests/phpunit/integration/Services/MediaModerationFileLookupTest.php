<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Hooks\Handlers;

use File;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileLookup;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationFileLookup
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationFileFactory
 * @group MediaModeration
 * @group Database
 */
class MediaModerationFileLookupTest extends MediaWikiIntegrationTestCase {
	use MockAuthorityTrait;

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

	public function addDBData() {
		// Copied from ImportExistingFilesToScanTableWhenRowsExist::addDBData.
		parent::addDBData();

		$actorId = $this->getServiceContainer()
			->getActorStore()
			->acquireActorId( $this->mockRegisteredUltimateAuthority()->getUser(), $this->db );
		$commentId = $this->getServiceContainer()
			->getCommentStore()
			->createComment( $this->db, 'test' )->id;
		$this->db->newInsertQueryBuilder()
			->insertInto( 'image' )
			->rows( [
				[
					'img_name' => 'Random-13m.png',
					'img_size' => 54321,
					'img_width' => 1000,
					'img_height' => 1800,
					'img_metadata' => '',
					'img_bits' => 16,
					'img_media_type' => MEDIATYPE_BITMAP,
					'img_major_mime' => 'image',
					'img_minor_mime' => 'png',
					'img_description_id' => $commentId,
					'img_actor' => $actorId,
					'img_timestamp' => $this->db->timestamp( '20201105234242' ),
					'img_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80yu',
				],
				[
					'img_name' => 'Random-11m.png',
					'img_size' => 54321,
					'img_width' => 1000,
					'img_height' => 1800,
					'img_metadata' => '',
					'img_bits' => 16,
					'img_media_type' => MEDIATYPE_BITMAP,
					'img_major_mime' => 'image',
					'img_minor_mime' => 'png',
					'img_description_id' => $commentId,
					'img_actor' => $actorId,
					'img_timestamp' => $this->db->timestamp( '20201105235242' ),
					'img_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80ru',
				]
			] )
			->execute();
		$this->db->newInsertQueryBuilder()
			->insertInto( 'oldimage' )
			->rows( [
				[
					'oi_name' => 'Random-11m.png',
					'oi_archive_name' => '20201105235241' . 'Random-11m.png',
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
					'oi_timestamp' => $this->db->timestamp( '20201105235241' ),
					'oi_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j800u',
					'oi_deleted' => File::DELETED_FILE | File::DELETED_COMMENT | File::DELETED_USER,
				],
				[
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
					'oi_timestamp' => $this->db->timestamp( '20201105235241' ),
					'oi_sha1' => 'sy02psim0bgdh0st4vdltuzoh7j70ru',
					'oi_deleted' => 0,
				],
			] )
			->execute();
		$this->db->newInsertQueryBuilder()
			->insertInto( 'filearchive' )
			->rows( [
				[
					'fa_name' => 'Random-11m.png',
					'fa_archive_name' => '20201105235239' . 'Random-11m.png',
					'fa_size' => 1234,
					'fa_width' => 1000,
					'fa_height' => 1800,
					'fa_metadata' => '',
					'fa_bits' => 16,
					'fa_media_type' => MEDIATYPE_BITMAP,
					'fa_major_mime' => 'image',
					'fa_minor_mime' => 'png',
					'fa_description_id' => $commentId,
					'fa_actor' => $actorId,
					'fa_timestamp' => $this->db->timestamp( '20201105235239' ),
					'fa_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j70ru',
					'fa_deleted' => 0,
					'fa_deleted_timestamp' => '20210506070809',
					'fa_deleted_reason_id' => $commentId,
				],
				// Has same timestamp as the above file, but different SHA-1
				[
					'fa_name' => 'Random-12m.png',
					'fa_archive_name' => '20201105235239' . 'Random-12m.png',
					'fa_size' => 1234,
					'fa_width' => 1000,
					'fa_height' => 1800,
					'fa_metadata' => '',
					'fa_bits' => 16,
					'fa_media_type' => MEDIATYPE_BITMAP,
					'fa_major_mime' => 'image',
					'fa_minor_mime' => 'jpeg',
					'fa_description_id' => $commentId,
					'fa_actor' => $actorId,
					'fa_timestamp' => $this->db->timestamp( '20201105235239' ),
					'fa_sha1' => 'sy02psim0bgdh0st4vdltuzoh7j70ru',
					'fa_deleted' => 0,
					'fa_deleted_timestamp' => '20210506070810',
					'fa_deleted_reason_id' => $commentId,
				],
				// Has same timestamp and SHA-1 as the above file
				[
					'fa_name' => 'Random-15m.png',
					'fa_archive_name' => '20201105235239' . 'Random-15m.png',
					'fa_size' => 1234,
					'fa_width' => 1000,
					'fa_height' => 1800,
					'fa_metadata' => '',
					'fa_bits' => 16,
					'fa_media_type' => MEDIATYPE_BITMAP,
					'fa_major_mime' => 'image',
					'fa_minor_mime' => 'jpeg',
					'fa_description_id' => $commentId,
					'fa_actor' => $actorId,
					'fa_timestamp' => $this->db->timestamp( '20201105235239' ),
					'fa_sha1' => 'sy02psim0bgdh0st4vdltuzoh7j70ru',
					'fa_deleted' => 0,
					'fa_deleted_timestamp' => '20210506070808',
					'fa_deleted_reason_id' => $commentId,
				],
				// Has a different SHA-1 and greater timestamp than any other filearchive row.
				[
					'fa_name' => 'Random-20m.png',
					'fa_archive_name' => '20231105235239' . 'Random-20m.png',
					'fa_size' => 1234,
					'fa_width' => 1000,
					'fa_height' => 1800,
					'fa_metadata' => '',
					'fa_bits' => 16,
					'fa_media_type' => MEDIATYPE_BITMAP,
					'fa_major_mime' => 'image',
					'fa_minor_mime' => 'jpeg',
					'fa_description_id' => $commentId,
					'fa_actor' => $actorId,
					'fa_timestamp' => $this->db->timestamp( '20231105235239' ),
					'fa_sha1' => 'sy02psim0bgdh0st4vdltuzoh7j60ru',
					'fa_deleted' => 0,
					'fa_deleted_timestamp' => '20231205235239',
					'fa_deleted_reason_id' => $commentId,
				]
			] )
			->execute();
	}
}
