<?php

namespace MediaWiki\Extension\MediaModeration\Job;

use Job;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileScanner;

class MediaModerationScanFileJob extends Job {
	private MediaModerationFileScanner $mediaModerationFileScanner;

	public function __construct(
		array $params,
		MediaModerationFileScanner $mediaModerationFileScanner
	) {
		parent::__construct( 'mediaModerationScanFileJob', $params );
		$this->mediaModerationFileScanner = $mediaModerationFileScanner;
	}

	/** @inheritDoc */
	public function run(): bool {
		$this->mediaModerationFileScanner->scanSha1( $this->params['sha1'] );
		// Even if the scan fails, return true as we handle the failure using the DB.
		return true;
	}
}
