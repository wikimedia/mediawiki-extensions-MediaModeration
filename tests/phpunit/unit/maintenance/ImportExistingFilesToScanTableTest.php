<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Maintenance;

use MediaWiki\Extension\MediaModeration\Maintenance\ImportExistingFilesToScanTable;
use MediaWikiUnitTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Maintenance\ImportExistingFilesToScanTable
 * @group MediaModeration
 */
class ImportExistingFilesToScanTableTest extends MediaWikiUnitTestCase {

	public function testGetUpdateKey() {
		// Verifies that the update key does not change without deliberate meaning, as it could
		// cause the script to be unnecessarily re-run on a new call to update.php.
		$objectUnderTest = new ImportExistingFilesToScanTable();
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$this->assertSame(
			'MediaWiki\\Extension\\MediaModeration\\Maintenance\\ImportExistingFilesToScanTable',
			$objectUnderTest->getUpdateKey(),
			'::getUpdateKey did not return the expected key.'
		);
	}
}
