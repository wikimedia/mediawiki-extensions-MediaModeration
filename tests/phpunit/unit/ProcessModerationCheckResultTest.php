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

use MailAddress;
use MediaWiki\Config\ServiceOptions;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @coversDefaultClass MediaWiki\Extension\MediaModeration\ProcessModerationCheckResult
 * @group MediaModeration
 */
class ProcessModerationCheckResultTest extends MediaWikiUnitTestCase {
	use MocksHelperTrait;

	/**
	 * @covers ::processResult
	 * @covers ::__construct
	 */
	public function testProcessResultNegative() {
		$emailer = $this->getMockIEmailer();
		$formatter = $this->getMockTextFormatter();
		$formatter->expects( $this->never() )
			->method( 'format' );

		$options = new ServiceOptions(
			ProcessModerationCheckResult::CONSTRUCTOR_OPTIONS,
			[
				'MediaModerationRecipientList' => [
					'recipient1@example.org',
					'recipient2@example.org'
				],
				'MediaModerationFrom' => 'no-reply@example.org'
			]
		);

		$processor = new ProcessModerationCheckResult(
			$options,
			$formatter,
			$emailer
		);

		$emailer->expects( $this->never() )->method( 'send' );

		$file = $this->getMockLocalFile();
		$result = new CheckResultValue( true, false );
		$processor->processResult( $result, $file );
	}

	/**
	 * @covers ::processResult
	 * @covers ::getMessageBody
	 * @covers ::getMessageSubject
	 * @covers ::__construct
	 */
	public function testProcessResultPositive() {
		$emailer = $this->getMockIEmailer();
		$formatter = $this->getMockTextFormatter();

		$formatter->method( 'format' )
			->withConsecutive( [ $this->anything() ], [ $this->anything() ] )
			->willReturnOnConsecutiveCalls( "Message Body", "Message Subject" );

		$options = new ServiceOptions(
			ProcessModerationCheckResult::CONSTRUCTOR_OPTIONS,
			[
				'MediaModerationRecipientList' => [ 'peter.ovchyn@speedandfunction.com' ],
				'MediaModerationFrom' => 'trustandsafity@mediawiki.com'
			]
		);

		$processor = new ProcessModerationCheckResult(
			$options,
			$formatter,
			$emailer
		);

		$result = new StatusValue();
		$emailer->expects( $this->once() )
			->method( 'send' )
			->with(
				$this->anything(),
				$this->isInstanceOf( MailAddress::class ),
				'Message Subject',
				'Message Body'
			)->willReturn( $result );

		$title = $this->getMockTitle();
		$title->expects( $this->once() )
			->method( 'getFullUrl' )
			->willReturn( 'http://example.org/image.jpg' );
		$file = $this->getMockLocalFile();
		$file->expects( $this->once() )->method( 'getTitle' )->willReturn( $title );

		$result = new CheckResultValue( true, true );
		$processor->processResult( $result, $file );
	}
}
