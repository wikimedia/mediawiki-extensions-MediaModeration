<?php

namespace MediaWiki\Extension\MediaModeration\PhotoDNA;

/**
 * Plain value object for modeling responses from the PhotoDNA endpoint.
 *
 * Not all fields from the response are modeled as properties; however, `rawResponse`
 * is set from the response content.
 *
 * @see https://developer.microsoftmoderator.com/docs/services/57c7426e2703740ec4c9f4c3/operations/57c7426f27037407c8cc69e6
 */
class Response {

	// Not a status code used by PhotoDNA. This code indicates invalid JSON returned by the API.
	public const INVALID_JSON_STATUS_CODE = -1;
	// The following statuses are documented as status codes by PhotoDNA,
	// check @see link above in class-level documentation block
	public const STATUS_OK = 3000;
	public const STATUS_INVALID_MISSING_REQUEST_PARAMS = 3002;
	public const STATUS_UNKNOWN_SCENARIO = 3004;
	public const STATUS_COULD_NOT_VERIFY_FILE_AS_IMAGE = 3206;
	public const STATUS_IMAGE_PIXEL_SIZE_NOT_IN_RANGE = 3208;
	// Not listed in API documentation, but seen in practice if one sends a file that is too large.
	public const STATUS_REQUEST_SIZE_EXCEEDED = 3209;
	private int $statusCode;
	private bool $isMatch;
	private string $rawResponse;

	/**
	 * @param int $statusCode
	 * @param bool $isMatch
	 * @param string $rawResponse
	 */
	public function __construct(
		int $statusCode,
		bool $isMatch = false,
		string $rawResponse = ''
	) {
		$this->statusCode = $statusCode;
		$this->isMatch = $isMatch;
		$this->rawResponse = $rawResponse;
	}

	/**
	 * @param array $responseJson
	 * @param string $rawResponse
	 * @return self
	 */
	public static function newFromArray( array $responseJson, string $rawResponse = '' ): self {
		return new self(
			$responseJson['Status']['Code'] ?? self::INVALID_JSON_STATUS_CODE,
			$responseJson['IsMatch'] ?? false,
			$rawResponse
		);
	}

	public function getStatusCode(): int {
		return $this->statusCode;
	}

	public function isMatch(): bool {
		return $this->isMatch;
	}

	public function getRawResponse(): string {
		return $this->rawResponse;
	}

}
