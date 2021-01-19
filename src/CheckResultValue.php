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

/**
 * Keeps information about moderation check results.
 */
class CheckResultValue {
	/** @var bool */
	private $ok;
	/** @var bool */
	private $childExploitationFound;

	/**
	 * @param bool $isOK
	 * @param bool $childExploitationFound
	 */
	public function __construct( bool $isOK, bool $childExploitationFound ) {
		$this->ok = $isOK;
		$this->childExploitationFound = $childExploitationFound;
	}

	/**
	 * Determines whether child exploitation is found in the media
	 * @return bool
	 */
	public function isChildExploitationFound(): bool {
		return $this->childExploitationFound;
	}

	/**
	 * Determines whether the result is good for further processing
	 * @return bool
	 */
	public function isOk(): bool {
		return $this->ok;
	}
}
