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

namespace MediaWiki\Extension\MediaModeration\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\MediaModeration\Deferred\InsertFileOnUploadUpdate;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationEmailer;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileProcessor;
use MediaWiki\Hook\UploadCompleteHook;
use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\LBFactory;

class UploadCompleteHandler implements UploadCompleteHook {

	private LoggerInterface $logger;

	public function __construct(
		private readonly MediaModerationFileProcessor $mediaModerationFileProcessor,
		private readonly MediaModerationDatabaseLookup $mediaModerationDatabaseLookup,
		private readonly MediaModerationEmailer $mediaModerationEmailer,
		private readonly LBFactory $lbFactory,
		private readonly Config $config
	) {
		$this->logger = LoggerFactory::getInstance( 'mediamoderation' );
	}

	/** @inheritDoc */
	public function onUploadComplete( $uploadBase ) {
		$file = $uploadBase->getLocalFile();
		if ( $file === null ) {
			// This should not happen, but if the $file is null then log this as a warning.
			$this->logger->warning( 'UploadBase::getLocalFile is null on run of UploadComplete hook.' );
			return;
		}

		// Send an email for just this file if the SHA-1 is already marked as a match.
		// Previously uploaded files that match this SHA-1 have already been sent via email,
		// so sending them again is unnecessary.
		if (
			$this->mediaModerationDatabaseLookup->fileExistsInScanTable( $file ) &&
			$this->mediaModerationDatabaseLookup->getMatchStatusForSha1( $file->getSha1() )
		) {
			$this->mediaModerationEmailer->sendEmailForSha1( $file->getSha1(), $file->getTimestamp() );
			return;
		}

		// Add the image to the mediamoderation_scan table if adding to the table on upload is configured.
		if ( $this->config->get( 'MediaModerationAddToScanTableOnUpload' ) ) {
			DeferredUpdates::addUpdate( new InsertFileOnUploadUpdate(
				$this->mediaModerationFileProcessor,
				$this->lbFactory,
				$file
			) );
		}
	}
}
