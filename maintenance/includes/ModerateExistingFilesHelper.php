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
use JobQueueGroup;
use LocalFile;
use LocalRepo;
use MediaWiki\Extension\MediaModeration\Job\ProcessMediaModerationJob;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IResultWrapper;

/**
 * Helper class for existing files moderation script against PhotoDNA.
 *
 * @ingroup Maintenance
 */
class ModerateExistingFilesHelper {

	/**
	 * Script processed all files - message.
	 */
	private const MESSAGE_SCRIPT_PROCESSED_ALL_FILES = 'Script processed all files. Nothing left!';

	/**
	 * Script finished file - message.
	 */
	private const MESSAGE_SCRIPT_FINISHED_FILE = 'Script finished file:';

	/**
	 * Error pointer - message.
	 */
	private const MESSAGE_ERROR_POINTER = 'due to error:';

	/**
	 * No file error - message.
	 */
	private const MESSAGE_ERROR_NO_FILE = 'There is no file with the name:';

	/**
	 * Continue script - message.
	 */
	private const MESSAGE_CONTINUE_SCRIPT = 'To continue script from this point, ' .
	'run ModerateExistingFiles.php adding argument --';

	/**
	 * @var LocalRepo
	 */
	private $repository = null;

	/**
	 * @var array
	 */
	private $fileQuery = [];

	private JobQueueGroup $jobQueueGroup;
	private MediaModerationHandler $mediaModerationHandler;

	/**
	 * ModerateExistingFilesHelper constructor.
	 * @param LocalRepo $repository
	 * @param JobQueueGroup $jobQueueGroup
	 * @param MediaModerationHandler $mediaModerationHandler
	 * @param array $fileQuery
	 */
	public function __construct(
		LocalRepo $repository,
		JobQueueGroup $jobQueueGroup,
		MediaModerationHandler $mediaModerationHandler,
		array $fileQuery = []
	) {
		$this->repository = $repository;
		$this->fileQuery = $fileQuery;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->mediaModerationHandler = $mediaModerationHandler;
	}

	/**
	 * @param LocalFile $file
	 * @param bool $useJobQueue
	 * @return bool
	 */
	private function processFile( LocalFile $file, $useJobQueue = false ): bool {
		if ( !$useJobQueue ) {
			return $this->mediaModerationHandler->handleMedia( $file->getTitle(), $file->getTimestamp() );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			try {
				$this->jobQueueGroup->push(
					ProcessMediaModerationJob::newSpec( $file->getTitle(), $file->getTimestamp(), false )
				);
				print( '.' );
				break;
			} catch ( Exception $e ) {
				print( PHP_EOL . '#_EXCEPTION' . $e->getMessage() . '  ' . __FILE__ . ', ' . __LINE__ . PHP_EOL );
				continue;
			}
		}
		return true;
	}

	/**
	 * @param string &$start
	 * @param IResultWrapper $rows
	 * @param bool $old
	 */
	private function processBatch( string &$start, IResultWrapper $rows, bool $old ) {
		foreach ( $rows as $row ) {
			$file = $this->repository->newFileFromRow( $row );
			$this->processFile( $file );
			$start = $old ? $row->oi_name : $row->img_timestamp;
		}
		print( PHP_EOL );
	}

	/**
	 * @param string &$start
	 * @param IDatabase $db
	 * @param int $batchSize
	 * @param int $batchCount
	 * @param bool $old
	 * @return bool
	 */
	public function processSeveral(
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
	 * @param string $fileName
	 * @param IDatabase $db
	 * @param bool $old
	 * @return bool
	 */
	public function processSingle(
		string $fileName,
		IDatabase $db,
		bool $old
	): bool {
		$result = $this->selectFile( $fileName, $db, $old );

		if ( $result->numRows() ) {
			$file = $this->repository->newFileFromRow( $result->current() );
			$this->processFile( $file );

			return true;
		}

		return false;
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
		$condition = '';

		if ( $start ) {
			$condition = [ ( $old ? 'oi_name > ' : 'img_timestamp > ' ) .
				$db->addQuotes( $old ? $start : $db->timestamp( $start ) ) ];
		}

		return $db->select(
			$this->fileQuery['tables'],
			$this->fileQuery['fields'],
			$condition,
			__METHOD__,
			[
				'LIMIT' => $batchSize,
				'ORDER BY' => ( $old ? 'oi_name' : 'img_timestamp' )
			],
			$this->fileQuery['joins']
		);
	}

	/**
	 * @param string $fileName
	 * @param IDatabase $db
	 * @param bool $old
	 * @return IResultWrapper
	 */
	private function selectFile(
		string $fileName,
		IDatabase $db,
		bool $old
	): IResultWrapper {
		return $db->select(
			$this->fileQuery['tables'],
			$this->fileQuery['fields'],
			[ ( $old ? 'oi_name = ' : 'img_name = ' ) . $db->addQuotes( $fileName ) ],
			__METHOD__,
			[
				'LIMIT' => 1,
			],
			$this->fileQuery['joins']
		);
	}

	/**
	 * @param bool $completed
	 * @param string $start
	 * @param string|null $error
	 * @param string $optionName
	 * @return string
	 */
	public function getOutputSeveral(
		bool $completed,
		string $start,
		?string $error,
		string $optionName
	): string {
		$output = '';

		if ( $completed ) {
			$output .= self::MESSAGE_SCRIPT_PROCESSED_ALL_FILES . "\n";
		} else {
			$output .= self::MESSAGE_SCRIPT_FINISHED_FILE . ' ' . $start . "\n";

			if ( $error ) {
				$output .= self::MESSAGE_ERROR_POINTER . ' ' . $error . "\n";
			}

			$output .= self::MESSAGE_CONTINUE_SCRIPT . $optionName . '=' . $start . "\n\n";
		}

		return $output;
	}

	/**
	 * @param bool $completed
	 * @param string $fileName
	 * @param string|null $error
	 * @return string
	 */
	public function getOutputSingle(
		bool $completed,
		string $fileName,
		?string $error
	): string {
		$output = '';

		if ( $completed ) {
			$output .= self::MESSAGE_SCRIPT_FINISHED_FILE . ' ' . $fileName . "\n";
		} else {
			$output .= self::MESSAGE_ERROR_NO_FILE . ' ' . $fileName . "\n";

			if ( $error ) {
				$output .= self::MESSAGE_ERROR_POINTER . ' ' . $error . "\n";
			}
		}

		return $output;
	}
}
