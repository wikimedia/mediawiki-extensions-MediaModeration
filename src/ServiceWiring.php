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

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	MediaModerationService::class =>
		function ( MediaWikiServices $services ): MediaModerationService {
			return new MediaModerationService( $services->getService( MediaModerationHandler::class ) );
		},
	MediaModerationHandler::class =>
		function ( MediaWikiServices $services ): MediaModerationHandler {
			return new MediaModerationHandler(
				$services->getTitleFactory(),
				$services->getRepoGroup()->getLocalRepo(),
				$services->getService( RequestModerationCheck::class ),
				$services->getService( ProcessModerationCheckResult::class ),
				LoggerFactory::getInstance( 'mediamoderation' )
			);
		},
	RequestModerationCheck::class =>
		function ( MediaWikiServices $services ): RequestModerationCheck {
			return new RequestModerationCheck();
		},
	ProcessModerationCheckResult::class =>
		function ( MediaWikiServices $services ): ProcessModerationCheckResult {
			return new ProcessModerationCheckResult();
		},
];
