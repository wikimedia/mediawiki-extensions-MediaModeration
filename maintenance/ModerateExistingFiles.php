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
 * @ingroup Maintenance
 */
namespace MediaWiki\Extension\MediaModeration\Maintenance;

use Exception;
use JobQueueGroup;
use LocalFile;
use Maintenance;
use MediaWiki\Extension\MediaModeration\Job\ProcessMediaModerationJob;
use MediaWiki\MediaWikiServices;
use MWException;
use OldLocalFile;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IResultWrapper;

// Security: Disable all stream wrappers and reenable individually as needed
foreach ( stream_get_wrappers() as $wrapper ) {
	stream_wrapper_unregister( $wrapper );
}

stream_wrapper_restore( 'file' );
$basePath = getenv( 'MW_INSTALL_PATH' );
if ( $basePath ) {
	if ( !is_dir( $basePath )
		|| strpos( $basePath, '..' ) !== false
		|| strpos( $basePath, '~' ) !== false
	) {
		throw new MWException( "Bad MediaWiki install path: $basePath" );
	}
} else {
	$basePath = __DIR__ . '/../../..';
}

require_once "$basePath/maintenance/Maintenance.php";

/**
 * Maintenance script that fixes double redirects.
 *
 * @ingroup Maintenance
 */
class ModerateExistingFiles extends Maintenance {

	/**
	 * @param LocalFile $file
	 */
	private function processFile( LocalFile $file ) {
		$title = $file->getTitle();
		$timestamp = $file->getTimestamp();

		JobQueueGroup::singleton()->push(
			ProcessMediaModerationJob::newSpec( $title, $timestamp, false )
		);
	}

	/**
	 * @param string &$start
	 * @param IResultWrapper $rows
	 * @param bool $old
	 */
	private function processBatch( string &$start, IResultWrapper $rows, bool $old ) {
		$repo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		foreach ( $rows as $id => $row ) {
			$file = $repo->newFileFromRow( $row );
			$this->processFile( $file );
			$start = $old ? $row->oi_name : $row->img_name;
		}
	}

	/**
	 * @param string &$start
	 * @param IDatabase $db
	 * @param int $batchSize
	 * @param int $batchCount
	 * @param bool $old
	 * @return bool
	 */
	private function processAll(
			string &$start,
			IDatabase $db,
			int $batchSize,
			int $batchCount,
			bool $old
		): bool {
		$i = 0;
		do {
			$rows = $this->selectFiles( $start, $db, $batchSize, $old );
			$this->processBatch( $start, $rows, $old );
			$i++;
		} while ( ( $batchCount <= 0 || $i < $batchCount ) && $rows->numRows() );
		return (bool)$rows->numRows();
	}

	/**
	 * @param string $start
	 * @param IDatabase $db
	 * @param int $batchSize
	 * @param bool $old
	 * @return IResultWrapper
	 */
	private function selectFiles(
		string $start,
		IDatabase $db,
		int $batchSize,
		bool $old
	): IResultWrapper {
		$fileQuery = $old ? OldLocalFile::getQueryInfo() : LocalFile::getQueryInfo();

		return $db->select(
			$fileQuery['tables'],
			$fileQuery['fields'],
			[ ( $old ? 'oi_name > ' : 'img_name > ' ) .
			$db->addQuotes( $start ) ],
			__METHOD__,
			[
				'LIMIT' => $batchSize,
				'ORDER BY' => ( $old ? 'oi_name' : 'img_name' )
			],
			$fileQuery['joins']
		);
	}

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Script for processing all existing files against PhotoDNA' );
		$this->addOption( 'start', 'Name of file to start after, default ""' );
		$this->addOption( 'type', 'Could be either "old" or "new", default is "new"' );
		$this->addOption(
			'batch-count',
			"Number of batches should be processed in one call." .
				'    0 - means work till the end, default 1'
		);
		$this->setBatchSize( 1000 );
	}

	public function execute() {
		$start = $this->getOption( 'start', '' );
		$dbr = $this->getDB( DB_REPLICA );
		$batchSize = $this->getBatchSize();
		$batchCount = $this->getOption( 'batch-count', 1 );
		$old = $this->getOption( 'type', 'new' ) === 'old';
		$completed = false;
		$error = null;
		try {
			$completed = !$this->processAll( $start, $dbr, $batchSize, $batchCount, $old );
		} catch ( Exception $e ) {
			$error = $e;
			$this->output( "Script failed\n" );
		}
		if ( $completed ) {
			$this->output( "Script processed all files. Nothing left!\n" );
			return true;
		} else {
			$this->output( "Script finished file: '$start'\n" );
			if ( $error ) {
				$this->output( "due to error: '$error'\n" );
			}
			$this->output( "To continue script from this point, " .
				"run ModerateExistingFiles.php adding argument --start='$start'\n\n" );
				return false;
		}
	}
}

$maintClass = ModerateExistingFiles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
