<?php

namespace MediaWiki\Extension\MediaModeration\Maintenance\Dev;

use Error;
use MediaWiki\FileRepo\File\LocalFile;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use UploadFromUrl;

// This is a local development only script, no need to count it in code coverage metrics.
// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script for importing some real world images from Commons to a local wiki. A subset
 * of the total will have new versions uploaded and another subset of the total will be deleted, so
 * as to populate various tables that MediaModeration scans.
 */
class PopulateImageTables extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Populate rows in image tables with fake data.
		This cannot be easily undone. Do not run this script in production!' );
		$this->addOption( 'count', 'How many rows to create' );
	}

	public function execute() {
		if ( !$this->getConfig()->get( 'MediaModerationDeveloperMode' ) ||
			!$this->getConfig()->get( 'AllowCopyUploads' ) ) {
			$this->error( 'AllowCopyUploads and MediaModerationDeveloperMode must be set to true.' );
			return;
		}
		if ( $this->getConfig()->get( 'MaxImageArea' ) ) {
			$this->output( 'For this script, the recommended value for $wgMaxImageArea is `false`.' );
		}
		if ( $this->getConfig()->get( 'CheckFileExtensions' ) ) {
			$this->output( 'For this script, the recommended value for $wgCheckFileExtensions is `false`.' );
		}
		$count = $this->getOption( 'count', 100 );
		$user = User::newSystemUser( 'MediaModeration' );

		// TODO: Allow for continuation if the user wants e.g. 1000 images imported.

		$uploadedImages = [];
		$fileRepo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();

		foreach ( $this->getImages( $count ) as $image ) {
			$this->output( "Importing image {$image['title']}\n" );
			$maintenanceUpload = new UploadFromUrl();
			if ( !isset( $image['pageimage'] ) ) {
				// TODO This occurs often. Should find some way to require that the metadata is present.
				$this->output( "... skipping, does not have image metadata\n" );
				continue;
			}
			$maintenanceUpload->initialize( $image['pageimage'], $image['original']['source'] );
			if ( !$maintenanceUpload->getTitle() ) {
				$this->fatalError( 'illegal-filename' );
			}
			$maintenanceUpload->fetchFile();
			try {
				$uploadResult = $maintenanceUpload->performUpload(
					'MediaModeration PopulateImageTables.php',
					'MediaModeration',
					false,
					$user
				);
				if ( $uploadResult->isGood() ) {
					$uploadedImages[] = $fileRepo->newFile(
						Title::newFromText( $image['title'], NS_FILE )
					);
				} else {
					$this->error( "... unable to perform upload.\n" );
				}
			} catch ( Error $error ) {
				$this->error( "... unable to import\n" );
			}
		}
		// Upload new versions of 20% of images.
		$newImageVersions = array_intersect_key( $uploadedImages, array_rand( $uploadedImages, $count / 5 ) );
		// Get new images to use
		$newImages = $this->getImages( $count / 5 );
		/**
		 * @var int $key
		 * @var LocalFile $newImageVersion
		 */
		foreach ( $newImageVersions as $key => $newImageVersion ) {
			// Upload new version of image.
			$maintenanceUpload = new UploadFromUrl();
			$titleText = $newImageVersion->getTitle()->getText();
			$maintenanceUpload->initialize( $titleText, $newImages[$key]['original']['source'] );
			if ( !$maintenanceUpload->getTitle() ) {
				$this->fatalError( 'illegal-filename' );
			}
			$maintenanceUpload->fetchFile();
			$this->output( "Uploading new version of $titleText\n" );
			$maintenanceUpload->performUpload(
				'MediaModeration PopulateImages.php update',
				'MediaModeration',
				false,
				$user
			);
		}
		$deleteImages = array_intersect_key( $uploadedImages, array_rand( $uploadedImages, $count / 5 ) );
		/** @var LocalFile $image */
		foreach ( $deleteImages as $image ) {
			$this->output( 'Deleting ' . $image->getTitle()->getText() . PHP_EOL );
			$image->deleteFile( 'MediaModeration testing', $user );
		}
	}

	private function getImages( int $count ): array {
		$url = wfAppendQuery( 'https://commons.wikimedia.org/w/api.php', [
			'action' => 'query',
			'prop' => 'pageimages',
			'generator' => 'search',
			'piprop' => 'thumbnail|name|original',
			'format' => 'json',
			// Exclude items with .pdf
			// TODO there's probably a nicer way to get just the file types we want.
			'gsrsearch' => '!".pdf"',
			'gsrnamespace' => NS_FILE,
			'gsrsort' => 'random',
			'gsrlimit' => $count,
			'formatversion' => 2
		] );
		$request = $this->getServiceContainer()->getHttpRequestFactory()->create( $url, [], __METHOD__ );
		$result = $request->execute();
		if ( !$result->isOK() ) {
			return [];
		}
		$data = json_decode( $request->getContent(), true );
		if ( !isset( $data['query']['pages'] ) ) {
			$this->error( 'Unable to get images' );
			return [];
		}
		return $data['query']['pages'];
	}
}

$maintClass = PopulateImageTables::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
