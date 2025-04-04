<?php

namespace MediaWiki\Extension\MediaModeration\Media;

use MediaWiki\FileRepo\File\File;
use ThumbnailImage;

/**
 * Like ThumbnailImage, but can contain the image contents and image content type.
 */
class ThumborThumbnailImage extends ThumbnailImage {

	private string $content;
	private string $contentType;

	public function __construct( File $file, string $url, array $parameters, string $content, string $contentType ) {
		parent::__construct( $file, $url, false, $parameters );

		$this->content = $content;
		$this->contentType = $contentType;
	}

	public function getContentType(): string {
		return $this->contentType;
	}

	public function getContent(): string {
		return $this->content;
	}

}
