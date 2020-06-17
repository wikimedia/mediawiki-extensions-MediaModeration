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

use LocalRepo;
use Psr\Log\LoggerInterface;
use Title;

class MediaModerationHandler {

	/**
	 * @var RequestModerationCheck
	 */
	private $requestModerationCheck;
	/**
	 * @var ProcessModerationCheckResult
	 */
	private $processModerationCheckResult;

	/**
	 * @var LocalRepo
	 */
	private $localRepo;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @param LocalRepo $localRepo
	 * @param RequestModerationCheck $requestModerationCheck
	 * @param ProcessModerationCheckResult $processModerationCheckResult
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		LocalRepo $localRepo,
		RequestModerationCheck $requestModerationCheck,
		ProcessModerationCheckResult $processModerationCheckResult,
		LoggerInterface $logger
	) {
		$this->localRepo = $localRepo;
		$this->requestModerationCheck = $requestModerationCheck;
		$this->processModerationCheckResult = $processModerationCheckResult;
		$this->logger = $logger;
	}

	/**
	 * Process files with given name and namespace
	 * @param Title $title
	 * @param string $timestamp
	 * @return bool
	 */
	public function handleMedia( Title $title, string $timestamp ): bool {
		$file = $this->localRepo->findFile( $title, [ 'time' => $timestamp ] );
		if ( !$file ) {
			// File not found. Note that this is an expected scenario. The extension
			// provides delaying this job if it runs from maintenance script
			$this->logger->info( 'Local file {file} not found', [ 'file' => $title->getFullText() ] );
			return true;
		}
		$this->logger->debug( 'Requesting hash check of file {file}.',
			[ 'file' => $file->getName() ] );
		$result = $this->requestModerationCheck->requestModeration( $file );
		if ( $result->isOk() ) {
			$this->processModerationCheckResult->processResult( $result, $file );
		} else {
			$this->logger->debug( 'Hash check request failed for file {file}.',
				[ 'file' => $file->getName() ] );
		}
		return $result->isOk();
	}
}
