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

use DerivativeContext;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\MediaModeration\PeriodicMetrics\MediaModerationMetricsFactory;
use MediaWiki\Extension\MediaModeration\PhotoDNA\IMediaModerationPhotoDNAServiceProvider;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseManager;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationEmailer;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileFactory;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileProcessor;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileScanner;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationImageContentsLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationMockPhotoDNAServiceProvider;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationPhotoDNAServiceProvider;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use RequestContext;

// PHP unit does not understand code coverage for this file
// as the @covers annotation cannot cover a specific file
// This is fully tested in ServiceWiringTest.php
// @codeCoverageIgnoreStart

return [
	'MediaModerationDatabaseLookup' => static function (
		MediaWikiServices $services
	): MediaModerationDatabaseLookup {
		return new MediaModerationDatabaseLookup(
			$services->getDBLoadBalancerFactory()
		);
	},
	'MediaModerationDatabaseManager' => static function (
		MediaWikiServices $services
	): MediaModerationDatabaseManager {
		return new MediaModerationDatabaseManager(
			$services->getDBLoadBalancerFactory()->getPrimaryDatabase( 'virtual-mediamoderation' ),
			$services->getService( 'MediaModerationDatabaseLookup' )
		);
	},
	'MediaModerationFileProcessor' => static function (
		MediaWikiServices $services
	): MediaModerationFileProcessor {
		return new MediaModerationFileProcessor(
			$services->getService( 'MediaModerationDatabaseManager' ),
			$services->getMediaHandlerFactory(),
			LoggerFactory::getInstance( 'mediamoderation' )
		);
	},
	'MediaModerationFileFactory' => static function (
		MediaWikiServices $services
	): MediaModerationFileFactory {
		return new MediaModerationFileFactory(
			$services->getRepoGroup()->getLocalRepo()
		);
	},
	'MediaModerationFileLookup' => static function (
		MediaWikiServices $services
	): MediaModerationFileLookup {
		return new MediaModerationFileLookup(
			$services->getRepoGroup()->getLocalRepo(),
			$services->get( 'MediaModerationFileFactory' )
		);
	},
	'MediaModerationMetricsFactory' => static function (
		MediaWikiServices $services
	): MediaModerationMetricsFactory {
		return new MediaModerationMetricsFactory(
			$services->getDBLoadBalancerFactory()->getReplicaDatabase( 'virtual-mediamoderation' ),
		);
	},
	'MediaModerationPhotoDNAServiceProvider' => static function (
		MediaWikiServices $services
	): IMediaModerationPhotoDNAServiceProvider {
		$config = $services->getConfigFactory()->makeConfig( 'MediaModeration' );
		// If we are in developer mode, and the subscription key or the URL are not
		// configured, then use the mock API.
		if ( $config->get( 'MediaModerationDeveloperMode' ) &&
			(
				!$config->get( 'MediaModerationPhotoDNASubscriptionKey' ) ||
				!$config->get( 'MediaModerationPhotoDNAUrl' )
			)
		) {
			return $services->get( '_MediaModerationMockPhotoDNAServiceProvider' );
		}
		return $services->get( '_MediaModerationPhotoDNAServiceProviderProduction' );
	},
	'_MediaModerationMockPhotoDNAServiceProvider' => static function (
		MediaWikiServices $services
	): MediaModerationMockPhotoDNAServiceProvider {
		$mockFiles = $services->getMainConfig()->get( 'MediaModerationPhotoDNAMockServiceFiles' );
		return new MediaModerationMockPhotoDNAServiceProvider(
			$mockFiles['FilesToIsMatchMap'] ?? [],
			$mockFiles['FilesToStatusCodeMap'] ?? []
		);
	},
	'_MediaModerationPhotoDNAServiceProviderProduction' => static function (
		MediaWikiServices $services
	): MediaModerationPhotoDNAServiceProvider {
		return new MediaModerationPhotoDNAServiceProvider(
			new ServiceOptions(
				MediaModerationPhotoDNAServiceProvider::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig(),
			),
			$services->getHttpRequestFactory(),
			$services->getPerDbNameStatsdDataFactory(),
			$services->get( 'MediaModerationImageContentsLookup' ),
			$services->getFormatterFactory()->getStatusFormatter( RequestContext::getMain() )
		);
	},
	'MediaModerationImageContentsLookup' => static function (
		MediaWikiServices $services
	): MediaModerationImageContentsLookup {
		return new MediaModerationImageContentsLookup(
			new ServiceOptions(
				MediaModerationImageContentsLookup::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig(),
			),
			$services->getRepoGroup()->getLocalRepo()->getBackend(),
			$services->getPerDbNameStatsdDataFactory(),
			$services->getMimeAnalyzer(),
			$services->getRepoGroup()->getLocalRepo(),
			$services->getHttpRequestFactory()
		);
	},
	'MediaModerationFileScanner' => static function (
		MediaWikiServices $services
	): MediaModerationFileScanner {
		return new MediaModerationFileScanner(
			$services->get( 'MediaModerationDatabaseLookup' ),
			$services->get( 'MediaModerationDatabaseManager' ),
			$services->get( 'MediaModerationFileLookup' ),
			$services->get( 'MediaModerationFileProcessor' ),
			$services->get( 'MediaModerationPhotoDNAServiceProvider' ),
			$services->get( 'MediaModerationEmailer' ),
			$services->getFormatterFactory()->getStatusFormatter( RequestContext::getMain() ),
			$services->getPerDbNameStatsdDataFactory(),
			LoggerFactory::getInstance( 'mediamoderation' )
		);
	},
	'MediaModerationEmailer' => static function (
		MediaWikiServices $services
	): MediaModerationEmailer {
		// The emails sent by this service should be in English.
		$messageLocalizer = new DerivativeContext( RequestContext::getMain() );
		$messageLocalizer->setLanguage( 'en' );
		return new MediaModerationEmailer(
			new ServiceOptions(
				MediaModerationEmailer::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig(),
			),
			$services->getEmailer(),
			$services->get( 'MediaModerationFileLookup' ),
			$messageLocalizer,
			$services->getLanguageFactory()->getLanguage( 'en' ),
			LoggerFactory::getInstance( 'mediamoderation' )
		);
	},
];
// @codeCoverageIgnoreEnd
