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

use UploadBase;

/**
 * Main entry point for all external calls and hooks.
 *
 * @since 0.1.0
 */
class MediaModerationService {

	/**
	 * @var MediaModerationHandler $handler
	 */
	private $handler;

	/**
	 * Constructs class from
	 *
	 * @since 0.1.0
	 * @param MediaModerationHandler $handler Handler which, implements all media processing
	 */
	public function __construct( MediaModerationHandler $handler ) {
		$this->handler = $handler;
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
		$this->handler->handleMedia( $title->getDBkey(), $title->getNamespace() );
	}
}
