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
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'MediaModerationService' =>
		function ( MediaWikiServices $services ): MediaModerationService {
			return new MediaModerationService(
				new ServiceOptions(
					MediaModerationService::CONSTRUCTOR_OPTIONS,
					$services->getConfigFactory()->makeConfig( 'MediaModeration' )
				),
				JobQueueGroup::singleton(),
				LoggerFactory::getInstance( 'mediamoderation' )
			);
		},
	'MediaModerationHandler' =>
		function ( MediaWikiServices $services ): MediaModerationHandler {
			return new MediaModerationHandler(
				$services->getRepoGroup()->getLocalRepo(),
				$services->getService( 'ThumbnailProvider' ),
				$services->getService( 'RequestModerationCheck' ),
				$services->getService( 'ProcessModerationCheckResult' ),
				LoggerFactory::getInstance( 'mediamoderation' )
			);
		},
	'RequestModerationCheck' =>
		function ( MediaWikiServices $services ): RequestModerationCheck {
			return new RequestModerationCheck(
				new ServiceOptions(
					RequestModerationCheck::CONSTRUCTOR_OPTIONS,
					$services->getConfigFactory()->makeConfig( 'MediaModeration' )
				),
				$services->getHttpRequestFactory(),
				MediaWikiServices::getInstance()->getStatsdDataFactory(),
				LoggerFactory::getInstance( 'mediamoderation' )
			);
		},
	'ProcessModerationCheckResult' =>
		function ( MediaWikiServices $services ): ProcessModerationCheckResult {
			return new ProcessModerationCheckResult(
				new ServiceOptions(
					ProcessModerationCheckResult::CONSTRUCTOR_OPTIONS,
					$services->getConfigFactory()->makeConfig( 'MediaModeration' )
				),
				/**
				 * The purpose of the formatter is to create a message to notify
				 * a very the limited group of moderators, so that no need to use
				 * other language than en.
				 */
				$services->getMessageFormatterFactory()->getTextFormatter( 'en' ),
				$services->getEmailer(),
				LoggerFactory::getInstance( 'mediamoderation' )
			);
		},
	'ThumbnailProvider' =>
		function ( MediaWikiServices $services ): ThumbnailProvider {
			$configFactory = $services->getConfigFactory();
			return new ThumbnailProvider(
				new ServiceOptions(
					ThumbnailProvider::CONSTRUCTOR_OPTIONS,
					$configFactory->makeConfig( 'MediaModeration' )
				),
				LoggerFactory::getInstance( 'mediamoderation' )
			);
		},
];
