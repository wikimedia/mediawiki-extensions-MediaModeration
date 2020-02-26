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

namespace MediaWiki\Extension\MediaModeration;

use Title;
use TitleFactory;

trait MockTitleFactoryTrait {
	/**
	 * Accessor to TestCase::getMockBuilder
	 * @param string $class
	 */
	abstract public function getMockBuilder( string $class );

	/**
	 * Creates mock object for TitleFactory
	 *
	 * @param Title|null $title
	 * @return TitleFactory
	 */
	public function getMockTitleFactory( Title $title = null ) {
		$mock = $this->getMockBuilder( TitleFactory::class )
			->setMethods( [ 'newFromText' ] )
			->getMock();

		$mock->expects( $this->once() )
			->method( 'newFromText' )
			->willreturn( $title );

		return $mock;
	}

	/**
	 * Creates mock object for Title
	 * @return Title
	 */
	public function getMockTitle(): Title {
		$title = $this->getMockBuilder( Title::class )
			->setMethods( [ 'getDBkey', 'getNamespace' ] )
			->getMock();

		// $title
		// 	->expects( $this->once() )
		// 	->method( 'getDBkey' )
		// 	->willReturn( 'File:Foom.png' );

		// $title
		// 	->expects( $this->once() )
		// 	->method( 'getNamespace' )
		// 	->willReturn( NS_FILE );
		return $title;
	}
}
