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

use JobQueueGroup;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use LocalFile;
use LocalRepo;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Mail\IEmailer;
use MWHttpRequest;
use Psr\Log\LoggerInterface;
use ThumbnailImage;
use Title;
use TitleFactory;
use UploadBase;
use Wikimedia\Message\ITextFormatter;

trait MocksHelperTrait {

	/**
	 * Accessor to TestCase::getMockBuilder
	 * @param string $class
	 */
	abstract public function getMockBuilder( string $class );

	/**
	 * Accessor to TestCase::once
	 */
	abstract public function once();

	/**
	 * Creates mock object for LocalRepo
	 * @return LocalRepo
	 */
	public function getMockLocalRepo(): LocalRepo {
		return $this->getMockBuilder( LocalRepo::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'findFile' ] )
			->getMock();
	}

	/**
	 * Creates mock object for RequestModerationCheck
	 * @return RequestModerationCheck
	 */
	public function getMockRequestModerationCheck(): RequestModerationCheck {
		return $this->getMockBuilder( RequestModerationCheck::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'requestModeration' ] )
			->getMock();
	}

	/**
	 * Creates mock object for RequestModerationHander
	 * @return MediaModerationHandler
	 */
	public function getMockMediaModerationHandler(): MediaModerationHandler {
		return $this->getMockBuilder( MediaModerationHandler::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'handleMedia' ] )
			->getMock();
	}

	/**
	 * Creates mock object for ProcessModerationCheckResult
	 * @return ProcessModerationCheckResult
	 */
	public function getMockProcessModerationCheckResult(): ProcessModerationCheckResult {
		return $this->getMockBuilder( ProcessModerationCheckResult::class )
			->disableOriginalConstructor()
			// ->onlyMethods( [ 'getMediaType', 'getTitle' ] )
			->getMock();
	}

	/**
	 * Creates mock object for LoggerInterface
	 * @return LoggerInterface
	 */
	public function getMockLogger(): LoggerInterface {
		return $this->getMockBuilder( LoggerInterface::class )
			->onlyMethods( [ 'info', 'warning' ] )
			->getMockForAbstractClass();
	}

	/**
	 * Creates mock object for LocalFile
	 * @return LocalFile
	 */
	public function getMockLocalFile(): LocalFile {
		return $this->getMockBuilder( LocalFile::class )
			->disableOriginalConstructor()
			->onlyMethods( [
				'getMediaType',
				'getTitle',
				'getTimestamp',
				'getName',
				'canRender',
				'transform',
				'getUrl'
			] )
			->getMock();
	}

	/**
	 * Creates mock object for LocalFile
	 * @return ThumbnailImage
	 */
	public function getMockThumbnailImage(): ThumbnailImage {
		return $this->getMockBuilder( ThumbnailImage::class )
			->disableOriginalConstructor()
			->onlyMethods( [
				'getUrl'
			] )
			->getMock();
	}

	/**
	 * Creates mock object for TitleFactory
	 *
	 * @param Title|null $title
	 * @return TitleFactory
	 */
	public function getMockTitleFactory( Title $title ) {
		$mock = $this->getMockBuilder( TitleFactory::class )
			->onlyMethods( [ 'newFromText' ] )
			->getMock();

		$mock->expects( $this->once() )
			->method( 'newFromText' )
			->willreturn( $title );

		return $mock;
	}

	/**
	 * Creates mock object for Title
	 * @return Title
	 */
	public function getMockTitle(): Title {
		return $this->getMockBuilder( Title::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'getDBkey', 'getNamespace', 'getFullURL' ] )
			->getMock();
	}

	/**
	 * Creates mock object for HttpRequestFactory
	 * @return HttpRequestFactory
	 */
	public function getMockHttpRequestFactory(): HttpRequestFactory {
		return $this->getMockBuilder( HttpRequestFactory::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'create' ] )
			->getMock();
	}

	/**
	 * Creates mock object for MWHttpRequest
	 * @return MWHttpRequest
	 */
	public function getMockHttpRequest(): MWHttpRequest {
		return $this->getMockBuilder( MWHttpRequest::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'setHeader', 'execute', 'getContent' ] )
			->getMock();
	}

	/**
	 * Creates mock object for UploadBase
	 * @return UploadBase
	 */
	public function getMockUploadBase(): UploadBase {
		return $this->getMockBuilder( UploadBase::class )
			->onlyMethods( [ 'getLocalFile' ] )
			->getMockForAbstractClass();
	}

	/**
	 * Creates mock object for JobQueueGroup
	 * @return JobQueueGroup
	 */
	public function getMockJobQueueGroup(): JobQueueGroup {
		return $this->getMockBuilder( JobQueueGroup::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'push' ] )
			->getMock();
	}

	/**
	 * Creates mock object for JobQueueGroup
	 * @return JobQueueGroup
	 */
	public function getMockIEmailer(): IEmailer {
		return $this->getMockBuilder( IEmailer::class )
			->onlyMethods( [ 'send' ] )
			->getMock();
	}

	/**
	 * Creates mock object for JobQueueGroup
	 * @return StatsdDataFactoryInterface
	 */
	public function getMockStats(): StatsdDataFactoryInterface {
		return $this->getMockForAbstractClass( StatsdDataFactoryInterface::class );
	}

	/**
	 * Creates mock object for TextFormatter
	 * @return ITextFormatter
	 */
	public function getMockTextFormatter(): ITextFormatter {
		return $this->getMockBuilder( ITextFormatter::class )
			->onlyMethods( [ 'format' ] )
			->getMockForAbstractClass();
	}

	/**
	 * Creates mock object for ThumbnailProvider
	 * @return ThumbnailProvider
	 */
	public function getMockThumbnailProvider(): ThumbnailProvider {
		return $this->getMockBuilder( ThumbnailProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'getThumbnailUrl' ] )
			->getMock();
	}
}
