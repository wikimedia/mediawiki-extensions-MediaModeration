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

use Exception;
use LocalFile;
use Maintenance;
use MediaWiki\MediaWikiServices;
use OldLocalFile;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

// @todo FIXME: Remove this in favour of autoloader via extension.json
require_once __DIR__ . '/includes/ModerateExistingFilesHelper.php';

/**
 * Process existing file(s) against PhotoDNA.
 *
 * @ingroup Maintenance
 */
class ModerateExistingFiles extends Maintenance {

	/**
	 * The script description.
	 */
	private const SCRIPT_DESCRIPTION = 'Script for processing existing file(s) against PhotoDNA';

	/**
	 * The image type, can be "old" or "new".
	 */
	private const OPTION_TYPE = 'type';

	/**
	 * Timestamp of file to start after.
	 */
	private const OPTION_START = 'start';

	/**
	 * Number of batches should be processed in one call.
	 */
	private const OPTION_BATCH_COUNT = 'batch-count';

	/**
	 * File name to scan.
	 */
	private const OPTION_FILE_NAME = 'file-name';

	/**
	 * Type option description.
	 */
	private const OPTION_TYPE_DESCRIPTION = 'Could be either "old" or "new", default is "new"';

	/**
	 * Start option description.
	 */
	private const OPTION_START_DESCRIPTION = 'Timestamp for new images or file name for old images to start ' .
	' after, default ""';

	/**
	 * Batch count option description.
	 */
	private const OPTION_BATCH_COUNT_DESCRIPTION = 'Number of batches should be processed in one call.' .
	'    0 - means work till the end, default: 1';

	/**
	 * File name option description.
	 */
	private const OPTION_FILE_NAME_DESCRIPTION = 'File name to scan (only this file will be scanned).';

	/**
	 * The number of operations to do in a batch
	 */
	private const BATCH_SIZE = 1000;

	/**
	 * Script started - message.
	 */
	private const MESSAGE_SCRIPT_STARTED = 'Script successfully started';

	/**
	 * Script failed - message.
	 */
	private const MESSAGE_SCRIPT_FAILED = 'Script failed';

	public function __construct() {
		parent::__construct();

		$this->addDescription( self::SCRIPT_DESCRIPTION );

		$this->addOption( self::OPTION_START, self::OPTION_START_DESCRIPTION );
		$this->addOption( self::OPTION_TYPE, self::OPTION_TYPE_DESCRIPTION );
		$this->addOption( self::OPTION_BATCH_COUNT, self::OPTION_BATCH_COUNT_DESCRIPTION );
		$this->addOption( self::OPTION_FILE_NAME, self::OPTION_FILE_NAME_DESCRIPTION );

		$this->setBatchSize( self::BATCH_SIZE );
		$this->requireExtension( 'MediaModeration' );
	}

	/**
	 * @return void
	 */
	public function execute() {
		$start = $this->getOption( self::OPTION_START, '' );
		$batchCount = $this->getOption( self::OPTION_BATCH_COUNT, 1 );
		$old = $this->getOption( self::OPTION_TYPE, 'new' ) === 'old';
		$fileName = $this->getOption( self::OPTION_FILE_NAME, null );

		$dbr = $this->getDB( DB_REPLICA );
		$batchSize = $this->getBatchSize();

		$completed = false;
		$error = null;

		$mwServices = MediaWikiServices::getInstance();
		$moderationHelper = new ModerateExistingFilesHelper(
			$mwServices->getRepoGroup()->getLocalRepo(),
			$mwServices->getJobQueueGroup(),
			$mwServices->getService( 'MediaModerationHandler' ),
			$old ? OldLocalFile::getQueryInfo() : LocalFile::getQueryInfo()
		);
		$this->output( self::MESSAGE_SCRIPT_STARTED . PHP_EOL );

		try {
			if ( $fileName === null ) {
				$completed = !$moderationHelper->processSeveral( $start, $dbr, $batchSize, $batchCount, $old );
			} else {
				$completed = $moderationHelper->processSingle( $fileName, $dbr, $old );
			}
		} catch ( Exception $e ) {
			$error = $e;
			$this->output( self::MESSAGE_SCRIPT_FAILED . "\n" );
		}

		if ( $fileName === null ) {
			$this->output( $moderationHelper->getOutputSeveral( $completed, $start, $error, self::OPTION_START ) );
		} else {
			$this->output( $moderationHelper->getOutputSingle( $completed, $fileName, $error ) );
		}
	}
}

$maintClass = ModerateExistingFiles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
