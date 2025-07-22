<?php

namespace MediaWiki\Extension\MediaModeration\Job;

use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseManager;
use MediaWiki\JobQueue\Job;

/**
 * A job which handles inserting a file to the mediamoderation_scan table on upload.
 *
 * This job is run if the {@link InsertFileOnUploadUpdate} failed to execute, which can happen with temporary
 * issues such as a short read only window, deadlock, or database overload.
 */
class MediaModerationInsertFileOnUploadJob extends Job {
	public function __construct(
		array $params,
		private readonly MediaModerationDatabaseManager $mediaModerationDatabaseManager
	) {
		parent::__construct( 'mediaModerationInsertFileOnUploadJob', $params );
	}

	/** @inheritDoc */
	public function run(): bool {
		// Because we could not convert the File object to JSON, we need to re-produce what is in
		// MediaModerationFileProcessor::insertFile but without having a File object.
		// To do that, we have MediaModerationFileProcessor::canScanFile called for us by the code
		// that created the job definition and then we can insert using the SHA-1.
		if ( $this->params['canScanFile'] ) {
			$this->mediaModerationDatabaseManager->insertSha1ToScanTable( $this->params['sha1'] );
		}

		return true;
	}
}
