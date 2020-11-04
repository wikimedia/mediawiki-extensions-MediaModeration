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

namespace MediaWiki\Extension\MediaModeration;

use JobQueueGroup;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\MediaModeration\Job\ProcessMediaModerationJob;
use Psr\Log\LoggerInterface;
use UploadBase;

/**
 * Main entry point for all external calls and hooks.
 *
 * @since 0.1.0
 */
class MediaModerationService {

	public const CONSTRUCTOR_OPTIONS = [
		'MediaModerationCheckOnUpload'
	];

	/**
	 * @var bool
	 */
	private $checkOnUpload;

	/**
	 * @var JobQueueGroup
	 */
	private $jobQueueGroup;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * Constructs class from
	 *
	 * @since 0.1.0
	 * @param ServiceOptions $options
	 * @param JobQueueGroup $jobQueueGroup
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		ServiceOptions $options,
		JobQueueGroup $jobQueueGroup,
		LoggerInterface $logger
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->jobQueueGroup = $jobQueueGroup;
		$this->logger = $logger;

		$this->checkOnUpload = $options->get( 'MediaModerationCheckOnUpload' );
	}

	/**
	 * Starts processing of uploaded media
	 *
	 * @since 0.1.0
	 * @param UploadBase $uploadBase uploaded media information
	 */
	public function processUploadedMedia( UploadBase $uploadBase ) {
		if ( !$this->checkOnUpload ) {
			$this->logger->debug( 'Checking on upload is disabled.' );
			return;
		}
		$file = $uploadBase->getLocalFile();
		$type = $file->getMediaType();
		if ( !Utils::isMediaTypeAllowed( $type ) ) {
			$this->logger->debug( 'Media type {type} is not allowed.',
				[ 'type' => $type ] );
			return;
		}
		$title = $file->getTitle();
		$timestamp = $file->getTimestamp();
		$this->logger->debug( 'Adding media moderation job for file {file} to job queue',
			[ 'file' => $file->getName() ] );
		$this->jobQueueGroup->push( ProcessMediaModerationJob::newSpec( $title, $timestamp, true ) );
	}
}
