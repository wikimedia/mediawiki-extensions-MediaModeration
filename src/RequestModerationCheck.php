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
use FileBackend;
use FormatJson;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use MWHttpRequest;
use Psr\Log\LoggerInterface;

/**
 * Checks for hash matches against 3rd party service
 */
class RequestModerationCheck {

	public const CONSTRUCTOR_OPTIONS = [
		'MediaModerationPhotoDNAUrl',
		'MediaModerationPhotoDNASubscriptionKey',
	];
	private const PHOTODNA_STATS_PREFIX = 'mediamoderation.photodna';

	/**
	 * @var HttpRequestFactory
	 */
	private $httpRequestFactory;

	/**
	 * @var FileBackend
	 */
	private $fileBackend;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var StatsdDataFactoryInterface
	 */
	private $stats;

	/**
	 * @var string
	 */
	private $photoDNAUrl;

	/**
	 * @var string
	 */
	private $photoDNASubscriptionKey;

	/**
	 * @param ServiceOptions $options
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param FileBackend $fileBackend
	 * @param StatsdDataFactoryInterface $stats
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		ServiceOptions $options,
		HttpRequestFactory $httpRequestFactory,
		FileBackend $fileBackend,
		StatsdDataFactoryInterface $stats,
		LoggerInterface $logger
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->httpRequestFactory = $httpRequestFactory;
		$this->fileBackend = $fileBackend;
		$this->stats = $stats;
		$this->logger = $logger;

		$this->photoDNAUrl = $options->get( 'MediaModerationPhotoDNAUrl' );
		$this->photoDNASubscriptionKey = $options->get( 'MediaModerationPhotoDNASubscriptionKey' );
	}

	/**
	 * @param File $file
	 * @return MWHttpRequest
	 */
	private function createModerationRequest( File $file ): MWHttpRequest {
		$options = [
			'method' => 'POST',
			'postData' => $this->getContents( $file )
		];

		$annotationRequest = $this->httpRequestFactory->create(
			$this->photoDNAUrl,
			$options
		);
		$annotationRequest->setHeader( 'Content-Type', $file->getMimeType() );
		$annotationRequest->setHeader( 'Ocp-Apim-Subscription-Key', $this->photoDNASubscriptionKey );
		return $annotationRequest;
	}

	/**
	 * @param File $file
	 * @return string
	 */
	private function getContents( File $file ): string {
		return $this->fileBackend->getFileContents( [ 'src' => $file->getPath() ] );
	}

	/**
	 * @param string $file
	 * @param string|null $detail
	 * @param string|null $response
	 */
	private function logWarning( $file, $detail, $response = null ) {
		$message = 'Hash check of file ' . $file . ' failed.';
		if ( $detail ) {
			$message .= ' ' . $detail;
		}
		if ( $response ) {
			$message .= ' ' . $response;
		}
		$this->logger->warning( $message );
	}

	/**
	 * @param File $file
	 * @return CheckResultValue
	 */
	public function requestModeration( File $file ): CheckResultValue {
		$start = microtime( true );
		$this->stats->updateCount( self::PHOTODNA_STATS_PREFIX . '.bandwidth', $file->getSize() );

		$this->logger->debug( 'Creating moderation request for file {file}.',
			[ 'file' => $file->getName() ] );
		$moderationInfoRequest = $this->createModerationRequest( $file );
		$status = $moderationInfoRequest->execute();

		$delay = microtime( true ) - $start;
		$this->stats->timing(
			self::PHOTODNA_STATS_PREFIX . ( $status->isOk() ? '.200.' : '.500.' ) . 'latency',
			1000 * $delay
		);

		if ( !$status->isOk() ) {
			$this->logWarning( $file->getName(), 'Request error response: ' . (string)$status . '.' );
			return new CheckResultValue( false, false );
		}

		$this->logger->debug( 'Parsing moderation result for file {file}.',
			[ 'file' => $file->getName() ] );
		$parseRes = FormatJson::parse( $moderationInfoRequest->getContent(), FormatJson::FORCE_ASSOC );
		if ( !$parseRes->isGood() ) {
			$this->logWarning( $file->getName(),
				'Parse error in JSON returned by photoDNA: ' . (string)$parseRes . '.',
				$moderationInfoRequest->getContent() );
			return new CheckResultValue( false, false );
		}

		$responseBody = $parseRes->getValue();
		if ( !isset( $responseBody['Status']['Code'] )
				|| !isset( $responseBody['Status']['Description'] )
				|| !isset( $responseBody['IsMatch'] ) ) {
			$this->logWarning( $file->getName(), 'Missing keys in response.', $moderationInfoRequest->getContent() );
			return new CheckResultValue( false, false );
		}
		if ( $responseBody['Status']['Code'] != 3000 ) {
			$this->logWarning( $file->getName(),
				'Error response from PhotoDNA service: ', $moderationInfoRequest->getContent() );
			return new CheckResultValue( false, false );
		}

		if ( $responseBody['IsMatch'] ) {
			$this->logger->debug( 'Hash match found for file {file}: {content}.',
				[ 'file' => $file->getName(), 'content' => $moderationInfoRequest->getContent() ] );
		} else {
			$this->logger->debug( 'No hash match found for file {file}.', [ 'file' => $file->getName() ] );
		}
		return new CheckResultValue(
			true,
			$responseBody['IsMatch']
		);
	}
}
