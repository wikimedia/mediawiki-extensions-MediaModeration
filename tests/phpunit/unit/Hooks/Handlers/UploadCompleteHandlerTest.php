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

use MediaWiki\Extension\MediaModeration\Hooks\Handlers\UploadCompleteHandler;
use MediaWiki\Extension\MediaModeration\MediaModerationService;
use MediaWikiUnitTestCase;
use UploadBase;

/**
 * @covers MediaWiki\Extension\MediaModeration\Hooks\Handlers\UploadCompleteHandler
 * @group MediaModeration
 */
class UploadCompleteHandlerTest extends MediaWikiUnitTestCase {

	public function testOnUploadComplete() {
		$uploadBase = $this->createMock( UploadBase::class );
		$mockMediaModerationService = $this->createMock( MediaModerationService::class );

		$mockMediaModerationService
			->expects( $this->once() )
			->method( 'processUploadedMedia' )
			->with( $this->equalTo( $uploadBase ) );

		( new UploadCompleteHandler( $mockMediaModerationService ) )->onUploadComplete( $uploadBase );
	}
}
