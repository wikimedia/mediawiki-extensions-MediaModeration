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

use MediaWiki\Extension\MediaModeration\CheckResultValue;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass MediaWiki\Extension\MediaModeration\CheckResultValue
 * @group MediaModeration
 */
class CheckResultValueTest extends MediaWikiUnitTestCase {

	/**
	 * @covers ::__construct
	 * @covers ::isOk
	 * @covers ::isChildExploitationFound
	 */
	public function testCheckResultValue() {
		$this->assertTrue( ( new CheckResultValue( true, true ) )->isOk() );
		$this->assertFalse( ( new CheckResultValue( false, false ) )->isOk() );
		$this->assertTrue( ( new CheckResultValue( true, true ) )->isChildExploitationFound() );
		$this->assertFalse( ( new CheckResultValue( true, false ) )->isChildExploitationFound() );
	}
}
