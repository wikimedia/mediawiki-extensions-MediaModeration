<?php

namespace MediaWiki\Extension\MediaModeration\Status;

use StatusValue;

/**
 * @template T
 * @inherits StatusValue<T>
 */
class ImageContentsLookupStatus extends StatusValue {
	private string $mimeType;
	private string $imageContents;

	/**
	 * @suppress PhanGenericConstructorTypes
	 */
	public function __construct() {
	}

	/**
	 * @param string $mimeType
	 * @internal
	 * @return $this
	 */
	public function setMimeType( string $mimeType ) {
		$this->mimeType = $mimeType;
		return $this;
	}

	/**
	 * @param string $imageContents
	 * @return $this
	 * @internal
	 */
	public function setImageContents( string $imageContents ) {
		$this->imageContents = $imageContents;
		return $this;
	}

	/**
	 * Gets the mime type for the image contents found by
	 * MediaModerationFileContentsLookup::getImageContents.
	 *
	 * @return string
	 */
	public function getMimeType(): string {
		return $this->mimeType;
	}

	/**
	 * Gets the image contents as found by
	 * MediaModerationFileContentsLookup::getImageContents.
	 *
	 * @return string
	 */
	public function getImageContents(): string {
		return $this->imageContents;
	}
}
