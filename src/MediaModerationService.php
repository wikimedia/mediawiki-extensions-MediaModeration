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
use MediaWiki\Extension\MediaModeration\Job\ProcessMediaModerationJob;
use UploadBase;

/**
 * Main entry point for all external calls and hooks.
 *
 * @since 0.1.0
 */
class MediaModerationService {

	/**
	 * @var JobQueueGroup $handler
	 */
	private $jobQueueGroup;

	/**
	 * Constructs class from
	 *
	 * @since 0.1.0
	 * @param JobQueueGroup $jobQueueGroup
	 */
	public function __construct( JobQueueGroup $jobQueueGroup ) {
		$this->jobQueueGroup = $jobQueueGroup;
	}

	/**
	 * Starts processing of uploaded media
	 *
	 * @since 0.1.0
	 * @param UploadBase $uploadBase uploaded media information
	 */
	public function processUploadedMedia( UploadBase $uploadBase ) {
		$file = $uploadBase->getLocalFile();
		if ( !Utils::isMediaTypeAllowed( $file->getMediaType() ) ) {
			return;
		}
		$title = $file->getTitle();
		$timestamp = $file->getTimestamp();
		$this->jobQueueGroup->push( ProcessMediaModerationJob::newSpec( $title, $timestamp, true ) );
	}
}
