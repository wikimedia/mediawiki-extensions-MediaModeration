<?php

namespace MediaWiki\Extension\MediaModeration\Job;

use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileScanner;
use MediaWiki\JobQueue\Job;

class MediaModerationScanFileJob extends Job {
	public function __construct(
		array $params,
		private readonly MediaModerationFileScanner $mediaModerationFileScanner,
	) {
		parent::__construct( 'mediaModerationScanFileJob', $params );
	}

	/** @inheritDoc */
	public function run(): bool {
		$this->mediaModerationFileScanner->scanSha1( $this->params['sha1'] );
		// Even if the scan fails, return true as we handle the failure using the DB.
		return true;
	}
}
