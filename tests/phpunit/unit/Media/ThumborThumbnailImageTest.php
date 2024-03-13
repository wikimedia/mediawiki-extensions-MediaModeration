<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Media;

use File;
use MediaWiki\Extension\MediaModeration\Media\ThumborThumbnailImage;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Media\ThumborThumbnailImage
 */
class ThumborThumbnailImageTest extends MediaWikiUnitTestCase {
	public function testGetters() {
		$thumborThumbnailImage = new ThumborThumbnailImage(
			$this->createMock( File::class ),
			'thumb-url',
			[ 'width' => 250, 'height' => 250 ],
			'test-content',
			'test-content-type'
		);
		$this->assertSame( 'test-content', $thumborThumbnailImage->getContent() );
		$this->assertSame( 'test-content-type', $thumborThumbnailImage->getContentType() );
	}
}
