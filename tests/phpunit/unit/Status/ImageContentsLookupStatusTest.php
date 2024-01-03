<?php

namespace MediaWiki\Extension\MediaModeration\Status;

use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Status\ImageContentsLookupStatus
 */
class ImageContentsLookupStatusTest extends MediaWikiUnitTestCase {
	public function testMimeType() {
		$objectUnderTest = ImageContentsLookupStatus::newGood();
		$objectUnderTest->setMimeType( 'image/png' );
		$this->assertSame(
			'image/png',
			$objectUnderTest->getMimeType(),
			'::getMimeType did not return the expected mime type.'
		);
	}

	public function testImageContents() {
		$objectUnderTest = ImageContentsLookupStatus::newGood();
		$objectUnderTest->setImageContents( 'testing1234' );
		$this->assertSame(
			'testing1234',
			$objectUnderTest->getImageContents(),
			'::getImageContents did not return the expected image contents.'
		);
	}
}
