<?php

namespace MediaWiki\Extension\MediaModeration\Maintenance;

use FormatJson;
use Maintenance;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\MediaModeration\PhotoDNA\Response;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationPhotoDNAServiceProvider;
use MediaWiki\Title\Title;

// This is a developer script, no need to count it in code coverage metrics.
// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Helper script to check how the result from transmitting a local file
 * via the service provider implementation to PhotoDNA. Does not have
 * any side effects in the mediamoderation_scan table.
 */
class DebugPhotoDNA extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'MediaModeration' );

		$this->addDescription( 'Debug sending a single file to the PhotoDNA service endpoint via MediaModeration.' );
		$this->addArg( 'filename', 'Filename (without File: prefix) to use in check.' );
		$this->addOption( 'use-mock-endpoint', 'Use the mock API endpoint. Default is false, and to use production.' );
		$this->addOption( 'raw-json', 'Return only the raw JSON from the endpoint, useful if you want to pipe to jq' );
	}

	public function execute() {
		$services = $this->getServiceContainer();
		$useMock = $this->getOption( 'use-mock-endpoint', false );
		$photoDNAServiceProvider = $useMock ?
				$services->get( '_MediaModerationMockPhotoDNAServiceProvider' ) :
				$services->get( 'MediaModerationPhotoDNAServiceProvider' );
		if ( !$useMock && !( $photoDNAServiceProvider instanceof MediaModerationPhotoDNAServiceProvider ) ) {
			$this->fatalError(
				'Unable to get production service provider. ' .
				 'Check that $wgMediaModerationPhotoDNAUrl and $wgMediaModerationPhotoDNASubscriptionKey are set.'
			);
		}
		$file = $services->getRepoGroup()->getLocalRepo()->findFile(
			Title::newFromText( $this->getArg(), NS_FILE )
		);
		if ( !$file ) {
			$this->fatalError( 'Unable to get file for ' . $this->getArg() );
		}
		$result = $photoDNAServiceProvider->check( $file );
		/** @var Response|null $response */
		$response = $result->getValue();
		$statusFormatter = $services->getFormatterFactory()->getStatusFormatter( RequestContext::getMain() );

		if ( $this->getOption( 'raw-json' ) ) {
			if ( !$response ) {
				$this->fatalError( FormatJson::encode( [ 'error' => $statusFormatter->getWikiText( $result ) ] ) );
			}
			$this->output( $response->getRawResponse() );
			return;
		}

		$this->output( 'StatusValue: ' . ( $result->isGood() ? 'good' : 'bad' ) . PHP_EOL );
		if ( $response ) {
			$this->output( 'Status code: ' . $response->getStatusCode() . PHP_EOL );
		}
		if ( $result->isGood() && $response ) {
			$this->output( 'IsMatch: ' . (int)$response->isMatch() . PHP_EOL );
		} else {
			$this->output(
				'Error: ' . $statusFormatter->getWikiText( $result ) . PHP_EOL
			);
		}
		if ( $response ) {
			$this->output( 'Raw response: ' . PHP_EOL . $response->getRawResponse() . PHP_EOL );
		}
	}
}

$maintClass = DebugPhotoDNA::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
