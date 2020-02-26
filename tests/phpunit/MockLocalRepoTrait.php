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

use LocalFile;
use LocalRepo;
use Psr\Log\LoggerInterface;

trait MockLocalRepoTrait {
	/**
	 * Accessor to TestCase::getMockBuilder
	 * @param string $class
	 */
	abstract public function getMockBuilder( string $class );

	/**
	 * Creates mock object for LocalRepo
	 * @return LocalRepo
	 */
	public function getMockLocalRepo(): LocalRepo {
		$mock = $this->getMockBuilder( LocalRepo::class )
			->disableOriginalConstructor()
			->setMethods( [ 'findFile' ] )
			->getMock();
		return $mock;
	}

	/**
	 * Creates mock object for RequestModerationCheck
	 * @return RequestModerationCheck
	 */
	public function getMockRequestModerationCheck(): RequestModerationCheck {
		$mock = $this->getMockBuilder( RequestModerationCheck::class )
			->setMethods( [ 'requestModeration' ] )
			->getMock();
		return $mock;
	}

	/**
	 * Creates mock object for ProcessModerationCheckResult
	 * @return ProcessModerationCheckResult
	 */
	public function getMockProcessModerationCheckResult(): ProcessModerationCheckResult {
		$mock = $this->getMockBuilder( ProcessModerationCheckResult::class )
			// ->setMethods( [ 'getMediaType', 'getTitle' ] )
			->getMock();
		return $mock;
	}

	/**
	 * Creates mock object for LoggerInterface
	 * @return LoggerInterface
	 */
	public function getMockLogger(): LoggerInterface {
		$mock = $this->getMockBuilder( LoggerInterface::class )
			->setMethods( [ 'info' ] )
			->getMockForAbstractClass();
		return $mock;
	}

	/**
	 * Creates mock object for LocalFile
	 * @return LocalFile
	 */
	public function getMockLocalFile(): LocalFile {
		$file = $this->getMockBuilder( LocalFile::class )
			->disableOriginalConstructor()
			->setMethods( [ 'getMediaType', 'getTitle' ] )
			->getMock();
		return $file;
	}
}
