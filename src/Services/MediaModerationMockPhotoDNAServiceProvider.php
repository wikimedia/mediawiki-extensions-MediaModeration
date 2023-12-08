<?php

namespace MediaWiki\Extension\MediaModeration\Services;

use FormatJson;
use MediaWiki\Extension\MediaModeration\PhotoDNA\IMediaModerationPhotoDNAServiceProvider;
use MediaWiki\Extension\MediaModeration\PhotoDNA\MediaModerationPhotoDNAResponseHandler;
use MediaWiki\Extension\MediaModeration\PhotoDNA\Response;
use StatusValue;

/**
 * Mock implementation of PhotoDNA endpoint, for local development environments and CI.
 */
class MediaModerationMockPhotoDNAServiceProvider implements IMediaModerationPhotoDNAServiceProvider {

	use MediaModerationPhotoDNAResponseHandler;

	private array $filesToIsMatchMap;
	private array $filesToStatusCodeMap;

	/**
	 * @param array $filesToIsMatchMap Map of file names to boolean values where true indicates
	 *   a match with PhotoDNA's database, and false (default) means no match
	 * @param array $filesToStatusCodeMap Map of file names to integer status codes,
	 *   see the Response::STATUS_ constants. Default is STATUS_OK.
	 */
	public function __construct(
		array $filesToIsMatchMap = [],
		array $filesToStatusCodeMap = []
	) {
		$this->filesToIsMatchMap = $filesToIsMatchMap;
		$this->filesToStatusCodeMap = $filesToStatusCodeMap;
	}

	/** @inheritDoc */
	public function check( $file ): StatusValue {
		$statusCode = $this->filesToStatusCodeMap[ $file->getName() ] ?? Response::STATUS_OK;
		$isMatch = $this->filesToIsMatchMap[ $file->getName() ] ?? false;
		$response = new Response(
			$statusCode,
			$isMatch,
			FormatJson::encode( 'Mock endpoint in use!' )
		);
		return $this->createStatusFromResponse( $response );
	}

}
