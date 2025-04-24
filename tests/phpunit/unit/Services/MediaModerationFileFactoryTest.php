<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Services;

use InvalidArgumentException;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileFactory;
use MediaWiki\FileRepo\File\LocalFile;
use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationFileFactory
 * @group MediaModeration
 */
class MediaModerationFileFactoryTest extends MediaWikiUnitTestCase {

	use MockServiceDependenciesTrait;

	public function testGetFileObjectForRowThrowsExceptionForUnknownTable() {
		// Tests that a unrecognised $table throws an InvalidArgumentException.
		$this->expectException( InvalidArgumentException::class );
		$objectUnderTest = $this->newServiceInstance( MediaModerationFileFactory::class, [] );
		$objectUnderTest->getFileObjectForRow( (object)[], 'testing-table-does-not-exist' );
	}

	/** @dataProvider provideGetFileObjectForRow */
	public function testGetFileObjectForRow( $row, $table ) {
		// Create a mock LocalFile that is returned by a mock LocalRepo through
		// ::newFileFromRow, expecting that the $row is unmodified by the
		// method under test (::getFileObjectForRow).
		$mockLocalFile = $this->createMock( LocalFile::class );
		$mockLocalRepo = $this->createMock( LocalRepo::class );
		$mockLocalRepo->expects( $this->once() )
			->method( 'newFileFromRow' )
			->with( (object)$row )
			->willReturn( $mockLocalFile );
		// Get the object under test.
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationFileFactory::class,
			[ 'localRepo' => $mockLocalRepo ]
		);
		$this->assertSame(
			$mockLocalFile,
			$objectUnderTest->getFileObjectForRow( (object)$row, $table ),
			'ImportExistingFilesToScanTable::getFileObjectForRow did not return the correct LocalFile object.'
		);
	}

	public static function provideGetFileObjectForRow() {
		return [
			'Row from the image table' => [ [ 'img_sha1' => 'abc' ], 'image' ],
			'Row from the oldimage table' => [ [ 'oi_sha1' => 'abc' ], 'oldimage' ],
		];
	}
}
