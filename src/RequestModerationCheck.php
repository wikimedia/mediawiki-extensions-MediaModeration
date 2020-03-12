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

use FormatJson;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use Monolog\Utils;
use MWHttpRequest;
use Psr\Log\LoggerInterface;

/**
 * Checks for hash matches against 3rd party service
 */
class RequestModerationCheck {

	public const CONSTRUCTOR_OPTIONS = [
		'MediaModerationPhotoDNAUrl',
		'MediaModerationPhotoDNASubscriptionKey',
		'MediaModerationHttpProxy',
	];

	private const PHOTODNA_STATS_PREFIX = 'mediamoderation.photodna';

	/**
	 * @var HttpRequestFactory
	 */
	private $httpRequestFactory;

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
	 * Outgoing HTTP proxy
	 * @var string|null
	 */
	private $httpProxy;

	/**
	 * @var string
	 */
	private $photoDNASubscriptionKey;

	/**
	 * @param ServiceOptions $options
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param StatsdDataFactoryInterface $stats
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		ServiceOptions $options,
		HttpRequestFactory $httpRequestFactory,
		StatsdDataFactoryInterface $stats,
		LoggerInterface $logger
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->httpRequestFactory = $httpRequestFactory;
		$this->stats = $stats;
		$this->logger = $logger;

		$this->photoDNAUrl = $options->get( 'MediaModerationPhotoDNAUrl' );
		$this->photoDNASubscriptionKey = $options->get( 'MediaModerationPhotoDNASubscriptionKey' );
		$this->httpProxy = $options->get( 'MediaModerationHttpProxy' );
	}

	/**
	 * @param string $url
	 * @return MWHttpRequest
	 */
	private function createModerationRequest( string $url ): MWHttpRequest {
		$options = [
			'method' => 'POST',
			'postData' => Utils::jsonEncode( [
				'DataRepresentation' => 'URL',
				'Value' => $url
			] ),
		];

		if ( $this->httpProxy ) {
			$options['proxy'] = $this->httpProxy;
			$this->logger->debug( 'Using proxy: {proxy}.', [ 'proxy' => $this->httpProxy ] );
		}

		$annotationRequest = $this->httpRequestFactory->create(
			$this->photoDNAUrl,
			$options
		);
		$annotationRequest->setHeader( 'Content-Type', 'application/json' );
		$annotationRequest->setHeader( 'Ocp-Apim-Subscription-Key', $this->photoDNASubscriptionKey );

		return $annotationRequest;
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
	 * @param string $fileUrl
	 * @param string $fileName
	 * @return CheckResultValue
	 */
	public function requestModeration(
		string $fileUrl,
		string $fileName
	): CheckResultValue {
		$start = microtime( true );

		$this->logger->debug( 'Creating moderation request for file {file}.',
			[ 'file' => $fileName ] );

		$moderationInfoRequest = $this->createModerationRequest( $fileUrl );
		$status = $moderationInfoRequest->execute();

		$delay = microtime( true ) - $start;
		$this->stats->timing(
			self::PHOTODNA_STATS_PREFIX . ( $status->isOk() ? '.200.' : '.500.' ) . 'latency',
			1000 * $delay
		);

		if ( !$status->isOk() ) {
			$this->logWarning( $fileName, 'Request error response: ' . (string)$status . '.' );
			return new CheckResultValue( false, false );
		}

		$this->logger->debug( 'Parsing moderation result for file {file}.',
			[ 'file' => $fileName ] );

		$parseRes = FormatJson::parse( $moderationInfoRequest->getContent(), FormatJson::FORCE_ASSOC );
		if ( !$parseRes->isGood() ) {
			$this->logWarning( $fileName,
				'Parse error in JSON returned by photoDNA: ' . (string)$parseRes . '.',
				$moderationInfoRequest->getContent() );
			return new CheckResultValue( false, false );
		}

		$responseBody = $parseRes->getValue();
		if ( !isset( $responseBody['Status']['Code'] )
				|| !isset( $responseBody['Status']['Description'] )
				|| !isset( $responseBody['IsMatch'] ) ) {
			$this->logWarning( $fileName, 'Missing keys in response.', $moderationInfoRequest->getContent() );
			return new CheckResultValue( false, false );
		}
		if ( $responseBody['Status']['Code'] != 3000 ) {
			$this->logWarning( $fileName,
				'Error response from PhotoDNA service: ', $moderationInfoRequest->getContent() );
			return new CheckResultValue( false, false );
		}

		if ( $responseBody['IsMatch'] ) {
			$this->logger->debug( 'Hash match found for file {file}: {content}.',
				[ 'file' => $fileName, 'content' => $moderationInfoRequest->getContent() ] );
		} else {
			$this->logger->debug( 'No hash match found for file {file}.', [ 'file' => $fileName ] );
		}
		return new CheckResultValue(
			true,
			$responseBody['IsMatch']
		);
	}
}
