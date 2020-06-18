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

use File;
use MailAddress;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Mail\IEmailer;
use Title;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

/**
 * Process moderation check result, update DB and send an email
 *
 * @since 0.1.0
 */
class ProcessModerationCheckResult {

	public const CONSTRUCTOR_OPTIONS = [
		'MediaModerationRecipientList',
		'MediaModerationFrom',
	];

	/**
	 * @var string
	 */
	private $from;

	/**
	 * @var array
	 */
	private $recipientList;

	/**
	 * @var ITextFormatter
	 */
	private $formatter;

	/**
	 * @var IEmailer
	 */
	private $emailer;

	/**
	 * @param ServiceOptions $options
	 * @param ITextFormatter $formatter
	 * @param IEmailer $emailer
	 */
	public function __construct(
		ServiceOptions $options,
		ITextFormatter $formatter,
		IEmailer $emailer
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->recipientList = $options->get( 'MediaModerationRecipientList' );
		$this->from = $options->get( 'MediaModerationFrom' );

		$this->formatter = $formatter;
		$this->emailer = $emailer;
	}

	/**
	 * @param Title $title
	 * @return string
	 */
	private function getMessageBody( Title $title ): string {
		$fullUrl = $title->getFullURL();
		return $this->formatter->format(
			MessageValue::new( 'mediamoderation-email-body' )->plaintextParams( $fullUrl )
		);
	}

	/**
	 * @return string
	 */
	private function getMessageSubject(): string {
		return $this->formatter->format( MessageValue::new( 'mediamoderation-email-subject' ) );
	}

	/**
	 * @since 0.1.0
	 *
	 * @param CheckResultValue $result
	 * @param File $file
	 */
	public function processResult( CheckResultValue $result, File $file ) {
		if ( !$result->isChildExploitationFound() ) {
			return;
		}

		$title = $file->getTitle();
		$body = $this->getMessageBody( $title );
		$subject = $this->getMessageSubject();

		$to = array_map( function ( $address ) {
			return new MailAddress( $address );
		}, $this->recipientList );

		$from = new MailAddress( $this->from );

		$this->emailer->send(
			$to,
			$from,
			$subject,
			$body
		);
	}
}
