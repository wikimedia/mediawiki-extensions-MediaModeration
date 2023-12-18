<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Maintenance;

use File;
use MediaWiki\Extension\MediaModeration\Maintenance\ScanFilesInScanTable;
use MediaWiki\Extension\MediaModeration\PhotoDNA\Response;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;

/**
 * Test class for the scanFilesInScanTable.php maintenance script.
 *
 * @covers \MediaWiki\Extension\MediaModeration\Maintenance\ScanFilesInScanTable
 *
 * @group MediaModeration
 * @group Database
 */
class ScanFilesInScanTableTest extends MaintenanceBaseTestCase {
	use MockAuthorityTrait;

	protected function getMaintenanceClass() {
		return ScanFilesInScanTable::class;
	}

	/** @dataProvider provideExecute */
	public function testExecute(
		$mockResponsesConfig, $expectedPositiveMatches, $expectedNegativeMatches, $expectedNullMatches
	) {
		// Cause the mock response endpoint to be used and define mock responses
		$this->overrideConfigValues( [
			'MediaModerationDeveloperMode' => true,
			'MediaModerationPhotoDNASubscriptionKey' => '',
			'MediaModerationPhotoDNAMockServiceFiles' => $mockResponsesConfig
		] );
		// Set 'sleep' to 0 to prevent the test taking too long.
		$this->maintenance->setOption( 'sleep', 0 );
		// Run the maintenance script
		$this->maintenance->execute();
		// Assert the state of the DB is as expected.
		$this->assertArrayEquals(
			$expectedPositiveMatches,
			$this->db->newSelectQueryBuilder()
				->select( 'mms_sha1' )
				->from( 'mediamoderation_scan' )
				->where( [ 'mms_is_match' => 1 ] )
				->fetchFieldValues(),
			false,
			false,
			'The maintenance script did not mark the expected rows as having a positive match'
		);
		$this->assertArrayEquals(
			$expectedNegativeMatches,
			$this->db->newSelectQueryBuilder()
				->select( 'mms_sha1' )
				->from( 'mediamoderation_scan' )
				->where( [ 'mms_is_match' => 0 ] )
				->fetchFieldValues(),
			false,
			false,
			'The maintenance script did not mark the expected rows as having a negative match'
		);
		$this->assertArrayEquals(
			$expectedNullMatches,
			$this->db->newSelectQueryBuilder()
				->select( 'mms_sha1' )
				->from( 'mediamoderation_scan' )
				->where( [ 'mms_is_match' => null ] )
				->fetchFieldValues(),
			false,
			false,
			'The maintenance script did not mark the expected rows as having no match'
		);
	}

	public static function provideExecute() {
		return [
			'All files scanned that could be scanned are scanned as negative' => [
				[
					'FilesToIsMatchMap' => [],
					'FilesToStatusCodeMap' => [],
				],
				// One file was already scanned as a match so isn't rescanned.
				[ 'sy02psim0bgdh0st4vdltuzoh7j60ru' ],
				[
					'sy02psim0bgdh0jt4vdltuzoh7j80yu',
					'sy02psim0bgdh0jt4vdltuzoh7j80ru',
					'sy02psim0bgdh0jt4vdltuzoh7j70ru',
					'sy02psim0bgdh0jt4vdltuzoh7j800u',
					'sy02psim0bgdh0st4vdltuzoh7j70ru',
					'sy02psim0bgdh0st4vdlguzoh7j60ru',
				],
				// One file was unscannable.
				[ 'sy02psim0bgdh0jt4vdltuzoh7j80au' ],
			],
			'Some files were positive matches and some failed to scan' => [
				[
					'FilesToIsMatchMap' => [
						'Random-13m.png' => true,
					],
					'FilesToStatusCodeMap' => [
						'Random-112m.png' => Response::STATUS_IMAGE_PIXEL_SIZE_NOT_IN_RANGE
					],
				],
				// One file was already scanned as a match so isn't rescanned plus the
				// one that now matches.
				[
					'sy02psim0bgdh0st4vdltuzoh7j60ru',
					'sy02psim0bgdh0jt4vdltuzoh7j80yu',
				],
				[
					'sy02psim0bgdh0jt4vdltuzoh7j70ru',
					'sy02psim0bgdh0jt4vdltuzoh7j800u',
					'sy02psim0bgdh0st4vdlguzoh7j60ru',
					'sy02psim0bgdh0st4vdltuzoh7j70ru',
				],
				// One file was unscannable and the other failed to scan.
				[
					'sy02psim0bgdh0jt4vdltuzoh7j80au',
					'sy02psim0bgdh0jt4vdltuzoh7j80ru',
				],
			]
		];
	}

	public function addDBData() {
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
					'img_name' => 'Random-112m.png',
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
				],
				[
					'img_name' => 'Random-11m-not-supported.ogg',
					'img_size' => 54321,
					'img_width' => 1000,
					'img_height' => 1800,
					'img_metadata' => '',
					'img_bits' => 16,
					'img_media_type' => MEDIATYPE_AUDIO,
					'img_major_mime' => 'image',
					'img_minor_mime' => 'png',
					'img_description_id' => $commentId,
					'img_actor' => $actorId,
					'img_timestamp' => $this->db->timestamp( '20201105235242' ),
					'img_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80au',
				]
			] )
			->execute();
		$this->db->newInsertQueryBuilder()
			->insertInto( 'oldimage' )
			->row( [
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

		$this->db->newInsertQueryBuilder()
			->insertInto( 'mediamoderation_scan' )
			->rows( [
				[ 'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80yu' ],
				[ 'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80ru' ],
				[ 'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j70ru' ],
				[ 'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80au' ],
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
					'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j800u',
					// Define a last checked value, even though the match status is unscanned.
					'mms_last_checked' => '20231208',
					// Define match status as unscanned
					'mms_is_match' => null,
				]
			] )
			->execute();
	}
}
