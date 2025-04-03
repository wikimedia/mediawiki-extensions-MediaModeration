<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration;

use File;
use MediaWiki\MediaWikiServices;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use Wikimedia\Rdbms\IDatabase;

trait InsertMockFileDataTrait {
	use MockAuthorityTrait;

	/**
	 * Inserts testing data to the image, oldimage, filearchive, file, and filerevision tables for use in
	 * testing MediaModeration code.
	 *
	 * @return void
	 */
	protected function insertMockFileData() {
		$actorId = $this->getServiceContainer()
			->getActorStore()
			->acquireActorId( $this->mockRegisteredUltimateAuthority()->getUser(), $this->getDb() );
		$commentId = $this->getServiceContainer()
			->getCommentStore()
			->createComment( $this->getDb(), 'test' )->id;

		// Insert data using the old table structure
		$this->getDb()->newInsertQueryBuilder()
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
					'img_timestamp' => $this->getDb()->timestamp( '20201105234242' ),
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
					'img_timestamp' => $this->getDb()->timestamp( '20201105235242' ),
					'img_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80ru',
				]
			] )
			->execute();
		$this->getDb()->newInsertQueryBuilder()
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
				'oi_timestamp' => $this->getDb()->timestamp( '20201105235241' ),
				'oi_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j800u',
				'oi_deleted' => File::DELETED_FILE | File::DELETED_COMMENT | File::DELETED_USER,
			] )
			->execute();
		$this->getDb()->newInsertQueryBuilder()
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
					'fa_timestamp' => $this->getDb()->timestamp( '20201105235239' ),
					'fa_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j70ru',
					'fa_deleted' => 0,
					'fa_deleted_timestamp' => $this->getDb()->timestamp( '20210506070809' ),
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
					'fa_timestamp' => $this->getDb()->timestamp( '20201105235239' ),
					'fa_sha1' => 'sy02psim0bgdh0st4vdltuzoh7j70ru',
					'fa_deleted' => 0,
					'fa_deleted_timestamp' => $this->getDb()->timestamp( '20210506070810' ),
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
					'fa_timestamp' => $this->getDb()->timestamp( '20201105235239' ),
					'fa_sha1' => 'sy02psim0bgdh0st4vdltuzoh7j70ru',
					'fa_deleted' => 0,
					'fa_deleted_timestamp' => $this->getDb()->timestamp( '20210506070808' ),
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
					'fa_timestamp' => $this->getDb()->timestamp( '20231105235239' ),
					'fa_sha1' => 'sy02psim0bgdh0st4vdltuzoh7j60ru',
					'fa_deleted' => 0,
					'fa_deleted_timestamp' => $this->getDb()->timestamp( '20231205235239' ),
					'fa_deleted_reason_id' => $commentId,
				]
			] )
			->execute();

		// Now insert the test file data to the new file schema
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'filetypes' )
			->rows( [
				[
					'ft_id' => 1,
					'ft_media_type' => MEDIATYPE_BITMAP,
					'ft_major_mime' => 'image',
					'ft_minor_mime' => 'png',
				],
				[
					'ft_id' => 2,
					'ft_media_type' => MEDIATYPE_BITMAP,
					'ft_major_mime' => 'image',
					'ft_minor_mime' => 'jpeg',
				],
			] )
			->caller( __METHOD__ )
			->execute();

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'file' )
			->rows( [
				[
					'file_id' => 1,
					'file_name' => 'Random-13m.png',
					'file_latest' => 1,
					'file_type' => 1,
					'file_deleted' => 0,
				],
				[
					'file_id' => 2,
					'file_name' => 'Random-11m.png',
					'file_latest' => 2,
					'file_type' => 1,
					'file_deleted' => 0,
				],
				[
					'file_id' => 3,
					'file_name' => 'Random-12m.png',
					'file_latest' => 0,
					'file_type' => 2,
					'file_deleted' => 1,
				],
				[
					'file_id' => 4,
					'file_name' => 'Random-15m.png',
					'file_latest' => 0,
					'file_type' => 2,
					'file_deleted' => 1,
				],
				[
					'file_id' => 5,
					'file_name' => 'Random-20m.png',
					'file_latest' => 0,
					'file_type' => 2,
					'file_deleted' => 1,
				],
			] )
			->caller( __METHOD__ )
			->execute();

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'filerevision' )
			->rows( [
				[
					'fr_file' => 1,
					'fr_size' => 54321,
					'fr_width' => 1000,
					'fr_height' => 1800,
					'fr_metadata' => '',
					'fr_bits' => 16,
					'fr_description_id' => $commentId,
					'fr_actor' => $actorId,
					'fr_timestamp' => $this->getDb()->timestamp( '20201105234242' ),
					'fr_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80yu',
					'fr_archive_name' => '',
					'fr_deleted' => 0,
				],
				[
					'fr_file' => 2,
					'fr_size' => 54321,
					'fr_width' => 1000,
					'fr_height' => 1800,
					'fr_metadata' => '',
					'fr_bits' => 16,
					'fr_description_id' => $commentId,
					'fr_actor' => $actorId,
					'fr_timestamp' => $this->getDb()->timestamp( '20201105235242' ),
					'fr_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80ru',
					'fr_archive_name' => '',
					'fr_deleted' => 0,
				],
				[
					'fr_file' => 2,
					'fr_size' => 54321,
					'fr_width' => 1000,
					'fr_height' => 1800,
					'fr_metadata' => '',
					'fr_bits' => 16,
					'fr_description_id' => $commentId,
					'fr_actor' => $actorId,
					'fr_timestamp' => $this->getDb()->timestamp( '20201105235241' ),
					'fr_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j800u',
					'fr_archive_name' => '20201105235241' . 'Random-11m.png',
					'fr_deleted' => File::DELETED_FILE | File::DELETED_COMMENT | File::DELETED_USER,
				],
			] )
			->caller( __METHOD__ )
			->execute();
	}

	public static function provideFileSchemaMigrationStageValues(): array {
		return [
			'Reading new for file schema migration' => [ SCHEMA_COMPAT_NEW | SCHEMA_COMPAT_WRITE_OLD ],
			'Reading old for file schema migration' => [ SCHEMA_COMPAT_OLD | SCHEMA_COMPAT_WRITE_NEW ],
		];
	}

	/** @return IDatabase */
	abstract protected function getDb();

	/** @return MediaWikiServices */
	abstract protected function getServiceContainer();
}
