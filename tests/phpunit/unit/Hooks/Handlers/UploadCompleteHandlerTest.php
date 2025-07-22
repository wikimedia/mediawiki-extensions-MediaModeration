<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\Extension\MediaModeration\Tests\Unit;

use MediaWiki\Config\HashConfig;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\MediaModeration\Hooks\Handlers\UploadCompleteHandler;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationEmailer;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileProcessor;
use MediaWiki\FileRepo\File\File;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;
use Psr\Log\LoggerInterface;
use UploadBase;
use Wikimedia\Rdbms\LBFactory;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers MediaWiki\Extension\MediaModeration\Hooks\Handlers\UploadCompleteHandler
 * @covers \MediaWiki\Extension\MediaModeration\Deferred\InsertFileOnUploadUpdate
 * @group MediaModeration
 */
class UploadCompleteHandlerTest extends MediaWikiUnitTestCase {
	use MockServiceDependenciesTrait;

	/** @dataProvider provideOnUploadComplete */
	public function testOnUploadComplete( $fileAlreadyExistsInScanTable, $sha1IsAlreadyAMatch, $expectCallToEmailer ) {
		$mockFile = $this->createMock( File::class );
		$mockFile->method( 'getSha1' )
			->willReturn( 'sha1' );
		// Mock that the UploadBase::getLocalFile returns a mock file.
		$uploadBase = $this->createMock( UploadBase::class );
		$uploadBase->method( 'getLocalFile' )
			->willReturn( $mockFile );
		// Expect that the LoggerInterface::warning method is never called.
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->never() )
			->method( 'warning' );
		// Expect that the MediaModerationFileProcessor::insertFile method is called once if the file is not already
		// scanned as a match.
		$mockMediaModerationFileProcessor = $this->createMock( MediaModerationFileProcessor::class );
		$mockMediaModerationFileProcessor->expects( $sha1IsAlreadyAMatch ? $this->never() : $this->once() )
			->method( 'insertFile' )
			->with( $mockFile );
		// Expect that MediaModerationDatabaseLookup::fileExistsInScanTable is called once
		// and ::getMatchStatusForSha1 is called once if ::fileExistsInScanTable returns true.
		$mockMediaModerationDatabaseLookup = $this->createMock( MediaModerationDatabaseLookup::class );
		$mockMediaModerationDatabaseLookup->expects( $this->once() )
			->method( 'fileExistsInScanTable' )
			->with( $mockFile )
			->willReturn( $fileAlreadyExistsInScanTable );
		$mockMediaModerationDatabaseLookup->expects( $fileAlreadyExistsInScanTable ? $this->once() : $this->never() )
			->method( 'getMatchStatusForSha1' )
			->with( 'sha1' )
			->willReturn( $sha1IsAlreadyAMatch );
		// Expect that MediaModerationEmailer::sendEmail is called once if $sha1IsAlreadyAMatch is true.
		$mockMediaModerationEmailer = $this->createMock( MediaModerationEmailer::class );
		$mockMediaModerationEmailer->expects( $expectCallToEmailer ? $this->once() : $this->never() )
			->method( 'sendEmailForSha1' );
		// Get the object under test.
		$objectUnderTest = new UploadCompleteHandler(
			$mockMediaModerationFileProcessor,
			$mockMediaModerationDatabaseLookup,
			$mockMediaModerationEmailer,
			$this->createMock( LBFactory::class ),
			new HashConfig( [ 'MediaModerationAddToScanTableOnUpload' => true ] )
		);
		// As the logger is created in the constructor, re-assign it to the mock
		// logger for the test.
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->logger = $mockLogger;
		// Call the method under test.
		$objectUnderTest->onUploadComplete( $uploadBase );
		// Cause the deferred updates to run, so that the deferred update for the
		// call to MediaModerationFileProcessor::insertFile is run before the test ends.
		DeferredUpdates::doUpdates();
	}

	public static function provideOnUploadComplete() {
		return [
			'File does not exist in scan table' => [ false, false, false ],
			'File exists in scan table, but sha1 has a null match status' => [ true, null, false ],
			'File exists in scan table, but sha1 is not a match' => [ true, false, false ],
			'File exists in scan table, and sha1 is a match' => [ true, true, true ],
		];
	}

	public function testOnUploadCompleteForNullFile() {
		// Mock that UploadBase::getLocalFile will return null.
		$mockUploadBase = $this->createMock( UploadBase::class );
		$mockUploadBase->method( 'getLocalFile' )
			->willReturn( null );
		// Expect that the LoggerInterface::warning is called as the file is null.
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'warning' );
		$objectUnderTest = $this->newServiceInstance( UploadCompleteHandler::class, [] );

		// As the logger is created in the constructor, re-assign it to the mock
		// logger for the test.
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->logger = $mockLogger;
		// Call the method under test.
		$objectUnderTest->onUploadComplete( $mockUploadBase );
		// Cause the deferred updates to run, so that if the method under test is
		// not doing as expected the call to MediaModerationFileProcessor::insertFile
		// will be made before the test ends and then the test would fail.
		DeferredUpdates::doUpdates();
	}
}
