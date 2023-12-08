<?php

namespace MediaWiki\Extension\MediaModeration\PhotoDNA;

use MediaWiki\Language\RawMessage;
use StatusValue;

/**
 * Common methods shared across mock and real implementations of PhotoDNA endpoint.
 */
trait MediaModerationPhotoDNAResponseHandler {

	/**
	 * Return an appropriate status value, given a response from PhotoDNA endpoint.
	 *
	 * @param Response $response
	 * @return StatusValue
	 *   The StatusValue always contains the Response object constructed from the API, which includes
	 *    - IsMatch
	 *    - StatusCode (from PhotoDNA)
	 *    - raw response JSON
	 *
	 *   Fatal status values are used to handle the Response::STATUS_* codes that indicate errors
	 *   on the PhotoDNA API side. RawMessage is used throughout, as we don't anticipate
	 *   showing these errors to users.
	 */
	private function createStatusFromResponse( Response $response ): StatusValue {
		$status = StatusValue::newGood( $response );
		switch ( $response->getStatusCode() ) {
			case Response::STATUS_OK:
				return $status;
			case Response::STATUS_INVALID_MISSING_REQUEST_PARAMS:
				return $status->merge( StatusValue::newFatal(
					new RawMessage(
						$response->getStatusCode() .
						': Invalid or missing request parameter(s)'
					)
				) );
			case Response::STATUS_UNKNOWN_SCENARIO:
				return $status->merge( StatusValue::newFatal(
					new RawMessage(
						$response->getStatusCode() .
						': Unknown scenario or unhandled error occurred while processing request'
					)
				) );
			case Response::STATUS_COULD_NOT_VERIFY_FILE_AS_IMAGE:
				return $status->merge( StatusValue::newFatal(
					new RawMessage(
						$response->getStatusCode() .
						': The given file could not be verified as an image'
					)
				) );
			case Response::STATUS_IMAGE_PIXEL_SIZE_NOT_IN_RANGE:
				return $status->merge( StatusValue::newFatal(
					new RawMessage(
						$response->getStatusCode() .
						': Image size in pixels is not within allowed range'
					)
				) );
			case Response::STATUS_REQUEST_SIZE_EXCEEDED:
				return $status->merge( StatusValue::newFatal(
					new RawMessage(
						$response->getStatusCode() .
						': Request Size Exceeded'
					)
				) );
			default:
				return $status->merge( StatusValue::newFatal(
					new RawMessage(
						$response->getStatusCode() .
						': Unknown status code'
					)
				) );
		}
	}

}
