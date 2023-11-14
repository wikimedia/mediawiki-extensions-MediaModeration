<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Services;

use File;
use IDBAccessObject;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup
 * @group MediaModeration
 */
class MediaModerationDatabaseLookupTest extends MediaWikiUnitTestCase {
	/** @dataProvider provideFileExistsInScanTable */
	public function testFileExistsInScanTable( $flags, $methodName ) {
		$mockDb = $this->createMock( IDatabase::class );
		$selectQueryBuilderMock = $this->getMockBuilder( SelectQueryBuilder::class )
			->setConstructorArgs( [ $mockDb ] )
			->onlyMethods( [ 'fetchField' ] )
			->getMock();
		// Expect that fetchField is called. The expected fields, table etc. will
		// be checked after the call to the method.
		$selectQueryBuilderMock->expects( $this->once() )
			->method( 'fetchField' );
		$mockDb->expects( $this->once() )->method( 'newSelectQueryBuilder' )
			->willReturn( $selectQueryBuilderMock );
		$connectionProviderMock = $this->createMock( IConnectionProvider::class );
		// Expect that the getPrimaryDatabase or getReplicaDatabase method
		// is called as expected by $methodName
		$connectionProviderMock->expects( $this->once() )
			->method( $methodName )
			->willReturn( $mockDb );
		$objectUnderTest = new MediaModerationDatabaseLookup(
			$connectionProviderMock
		);
		// Create a mock File object
		$mockFile = $this->createMock( File::class );
		$mockFile->expects( $this->once() )
			->method( 'getSha1' )
			->willReturn( '123456abcdef' );
		// Call the method under test
		$objectUnderTest->fileExistsInScanTable( $mockFile, $flags );
		// Expect that the ::getQueryInfo of the SelectQueryBuilder returns
		// the expected data, which also tests that the correct builder methods
		// are called.
		$this->assertArrayEquals(
			[
				'tables' => [ 'mediamoderation_scan' ],
				'fields' => [ 'COUNT(*)' ],
				'conds' => [ 'mms_sha1' => '123456abcdef' ],
				'join_conds' => [],
				'caller' => 'MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup' .
					'::fileExistsInScanTable',
				'options' => [],
			],
			$selectQueryBuilderMock->getQueryInfo(),
			true,
			true,
			'The result from ::getQueryInfo was not as expected, suggesting the query that was performed ' .
			'was not as expected.'
		);
	}

	public static function provideFileExistsInScanTable() {
		return [
			'Reads from replica with flags as READ_NORMAL' => [ IDBAccessObject::READ_NORMAL, 'getReplicaDatabase' ],
			'Reads from primary with flags as READ_LATEST' => [ IDBAccessObject::READ_LATEST, 'getPrimaryDatabase' ],
		];
	}
}
