<?php

namespace MediaWiki\Extension\MediaModeration\PhotoDNA;

use MediaWiki\FileRepo\File\ArchivedFile;
use MediaWiki\FileRepo\File\File;
use StatusValue;

interface IMediaModerationPhotoDNAServiceProvider {

	/**
	 * @param File|ArchivedFile $file
	 * @return StatusValue
	 *   @see MediaModerationPhotoDNAResponseHandler::createStatusFromResponse() for details on the StatusValue.
	 */
	public function check( $file ): StatusValue;

}
