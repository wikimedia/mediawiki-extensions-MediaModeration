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

use File;
use MediaWiki\Config\ServiceOptions;
use Psr\Log\LoggerInterface;

class ThumbnailProvider {

	public const CONSTRUCTOR_OPTIONS = [
		'MediaModerationSendThumbnails',
		'MediaModerationThumbnailSize',
	];

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var bool
	 */
	private $sendThumbnails = true;

	/**
	 * @var int
	 */
	private $thumbnailWidth = 160;

	/**
	 * @var int
	 */
	private $thumbnailHeight = 160;

	/**
	 * @param ServiceOptions $options
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		ServiceOptions $options,
		LoggerInterface $logger
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->logger = $logger;

		$this->sendThumbnails = $options->get( 'MediaModerationSendThumbnails' );
		$this->thumbnailWidth = $options->get( 'MediaModerationThumbnailSize' )['width'];
		$this->thumbnailHeight = $options->get( 'MediaModerationThumbnailSize' )['height'];
	}

	/**
	 * @param File $file
	 * @return string
	 */
	public function getThumbnailUrl( File $file ): string {
		$fileCanRender = $file->canRender();
		$warningMessage = 'File can\'t be rendered into thumbnail. Full file will be sent to photoDNA.';

		if ( !$this->sendThumbnails || !$fileCanRender ) {
			if ( !$fileCanRender ) {
				$this->logger->warning(
					$warningMessage,
					[
						'file' => $file->getName()
					]
				);
			}

			return $file->getUrl();
		}

		$thumbnail = $file->transform(
			[ 'width' => $this->thumbnailWidth, 'height' => $this->thumbnailHeight ]
		);

		return $thumbnail->getUrl();
	}
}
