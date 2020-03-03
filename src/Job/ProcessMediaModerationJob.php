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

namespace MediaWiki\Extension\MediaModeration\Job;

use GenericParameterJob;
use IJobSpecification;
use Job;
use JobSpecification;
use MediaWiki\Extension\MediaModeration\MediaModerationHandler;
use MediaWiki\MediaWikiServices;
use Title;

class ProcessMediaModerationJob extends Job implements GenericParameterJob {
	/**
	 * Callers should use the factory methods instead
	 *
	 * @param array $params Job parameters
	 */
	public function __construct( array $params ) {
		parent::__construct( 'processMediaModeration', $params );
	}

	public function run(): bool {
		$handler = MediaWikiServices::getInstance()->getService( MediaModerationHandler::class );
		return $handler->handleMedia( $this->title, $this->params['timestamp'] );
	}

	/**
	 *
	 * @param Title $title
	 * @param string $timestamp
	 * @return IJobSpecification
	 */
	public static function newSpec( Title $title, string $timestamp ): IJobSpecification {
		return new JobSpecification(
			'processMediaModeration',
			[
				'timestamp' => $timestamp,
			],
			[
				'removeDuplicates' => true,
			],
			$title
		);
	}
}
