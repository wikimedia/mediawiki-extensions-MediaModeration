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
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;
use Wikimedia\Assert\Assert;
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
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @param ServiceOptions $options
	 * @param ITextFormatter $formatter
	 * @param IEmailer $emailer
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		ServiceOptions $options,
		ITextFormatter $formatter,
		IEmailer $emailer,
		LoggerInterface $logger
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->recipientList = $options->get( 'MediaModerationRecipientList' );
		Assert::precondition( is_array( $this->recipientList ), 'MediaModerationRecipientList must be an array.' );
		Assert::precondition( $this->recipientList != [], 'MediaModerationRecipientList must not be empty.' );
		foreach ( $this->recipientList as $recipient ) {
			Assert::precondition( filter_var( $recipient, FILTER_VALIDATE_EMAIL ),
				'MediaModerationRecipientList contains an invalid email: ' . $recipient );
		}

		$this->from = $options->get( 'MediaModerationFrom' );
		Assert::precondition( filter_var( $this->from, FILTER_VALIDATE_EMAIL ),
			'MediaModerationFrom contains an invalid email: ' . $this->from );

		$this->formatter = $formatter;
		$this->emailer = $emailer;
		$this->logger = $logger;
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
		$this->logger->debug( 'Processing result of checking file {file}.',
			[ 'file' => $file->getName() ] );
		if ( !$result->isChildExploitationFound() ) {
			$this->logger->debug( 'No hash match for file {file}.',
				[ 'file' => $file->getName() ] );
			return;
		}

		$this->logger->debug( 'Hash match for file {file}.', [ 'file' => $file->getName() ] );
		$title = $file->getTitle();
		$body = $this->getMessageBody( $title );
		$subject = $this->getMessageSubject();

		$to = array_map( static function ( $address ) {
			return new MailAddress( $address );
		}, $this->recipientList );

		$from = new MailAddress( $this->from );

		$this->logger->info( 'Sending email to {to}: {body}',
			[ 'to' => implode( ',', $to ), 'body' => $body ] );
		$status = $this->emailer->send(
			$to,
			$from,
			$subject,
			$body
		);
		if ( !$status->isOK() ) {
			$this->logger->warning( 'Error sending email to report hash match for file {file}: {status}.',
				[ 'file' => $file->getName(), 'status' => (string)$status ] );
		} else {
			$this->logger->debug( 'Email sent to report hash match for file {file}.',
				[ 'file' => $file->getName() ] );
		}
	}
}
