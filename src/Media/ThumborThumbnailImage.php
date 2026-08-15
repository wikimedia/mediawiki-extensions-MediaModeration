<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\MediaModeration\Media;

use MediaWiki\FileRepo\File\File;
use MediaWiki\Media\ThumbnailImage;

/**
 * Like ThumbnailImage, but can contain the image contents and image content type.
 */
class ThumborThumbnailImage extends ThumbnailImage {

	public function __construct(
		File $file,
		string $url,
		array $parameters,
		private readonly string $content,
		private readonly string $contentType,
	) {
		parent::__construct( $file, $url, false, $parameters );
	}

	public function getContentType(): string {
		return $this->contentType;
	}

	public function getContent(): string {
		return $this->content;
	}

}
