<?php

namespace MediaWiki\Extension\MediaModeration\Job;

use GenericParameterJob;
use Job;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileScanner;
use MediaWiki\MediaWikiServices;

class MediaModerationScanFileJob extends Job implements GenericParameterJob {
	public function __construct( array $params ) {
		parent::__construct( 'mediaModerationScanFileJob', $params );
	}

	/** @inheritDoc */
	public function run(): bool {
		/** @var MediaModerationFileScanner $mediaModerationFileScanner */
		$mediaModerationFileScanner = MediaWikiServices::getInstance()->get( 'MediaModerationFileScanner' );
		$mediaModerationFileScanner->scanSha1( $this->params['sha1'] );
		// Even if the scan fails, return true as we handle the failure using the DB.
		return true;
	}
}
