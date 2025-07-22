<?php

namespace MediaWiki\Extension\MediaModeration\Deferred;

use MediaWiki\Deferred\DeferrableUpdate;
use MediaWiki\Deferred\EnqueueableDataUpdate;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileProcessor;
use MediaWiki\FileRepo\File\File;
use MediaWiki\JobQueue\JobSpecification;
use Wikimedia\Rdbms\LBFactory;

/**
 * A deferred update which handles inserting a file to the mediamoderation_scan table on upload
 * and re-queues the insert as a job if it fails.
 */
class InsertFileOnUploadUpdate implements DeferrableUpdate, EnqueueableDataUpdate {

	public function __construct(
		private readonly MediaModerationFileProcessor $mediaModerationFileProcessor,
		private readonly LBFactory $lbFactory,
		private readonly File $file
	) {
	}

	/** @inheritDoc */
	public function doUpdate(): void {
		$this->mediaModerationFileProcessor->insertFile( $this->file );
	}

	/** @inheritDoc */
	public function getAsJobSpecification(): array {
		return [
			'domain' => $this->lbFactory->getLocalDomainID(),
			'job' => new JobSpecification(
				'mediaModerationInsertFileOnUploadJob',
				[
					'sha1' => $this->file->getSha1(),
					'canScanFile' => $this->mediaModerationFileProcessor->canScanFile( $this->file ),
				]
			),
		];
	}
}
