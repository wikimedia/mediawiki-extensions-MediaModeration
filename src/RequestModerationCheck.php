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
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use MWHttpRequest;
use Psr\Log\LoggerInterface;

/**
 * Provides a strategy for recieving moderation from 3d party services
 */
class RequestModerationCheck {

	public const CONSTRUCTOR_OPTIONS = [
		'MediaModerationPhotoDNAUrl',
		'MediaModerationPhotoDNASubscriptionKey',
	];

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
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		ServiceOptions $options,
		HttpRequestFactory $httpRequestFactory,
		FileBackend $fileBackend,
		LoggerInterface $logger
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->httpRequestFactory = $httpRequestFactory;
		$this->fileBackend = $fileBackend;
		$this->logger = $logger;

		$this->photoDNAUrl = $options->get( 'MediaModerationPhotoDNAUrl' );
		$this->photoDNASubscriptionKey = $options->get( 'MediaModerationPhotoDNASubscriptionKey' );
	}

	/**
	 * @param File $file
	 * @return MWHttpRequest
	 */
	private function fetchModerationInfo( File $file ): MWHttpRequest {
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
	 * @param File $file
	 * @return CheckResultValue
	 */
	public function requestModeration( File $file ): CheckResultValue {
		$moderationInfoRequest = $this->fetchModerationInfo( $file );
		$status = $moderationInfoRequest->execute();

		if ( !$status->isOk() ) {
			return new CheckResultValue( false, false );
		}

		$parseRes = FormatJson::parse( $moderationInfoRequest->getContent(), FormatJson::FORCE_ASSOC );
		if ( !$parseRes->isGood() ) {
			$this->logger->warning(
				'JSON provided by photoDNA is wrong',
				[
					'error' => (string)$parseRes,
					'caller' => __METHOD__,
					'content' => $moderationInfoRequest->getContent()
				]
			);
			return new CheckResultValue( false, false );
		}
		$responseBody = $parseRes->getValue();
		if ( !isset( $responseBody['Status']['Code'] )
				|| !isset( $responseBody['Status']['Description'] ) ) {
			$this->logger->warning(
				'Status or Code keys are not found in response',
				[
					'caller' => __METHOD__,
					'content' => $moderationInfoRequest->getContent()
				]
			);
			return new CheckResultValue( false, false );
		}

		if ( $responseBody['Status']['Code'] != 3000 ) {
			$this->logger->warning(
				'Error on photoDNA service',
				[
					'error' => $responseBody['Status']['Description'],
					'caller' => __METHOD__,
					'content' => $moderationInfoRequest->getContent()
				]
			);
			return new CheckResultValue( false, false );
		}

		if ( !isset( $responseBody['IsMatch'] ) ) {
			$this->logger->warning(
				'EvaluateResponse or it\'s keys are not found in response',
				[
					'caller' => __METHOD__,
					'content' => $moderationInfoRequest->getContent()
				]
			);
			return new CheckResultValue( false, false );
		}

		return new CheckResultValue(
			true,
			$responseBody['IsMatch']
		);
	}
}
