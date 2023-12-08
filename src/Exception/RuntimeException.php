<?php

namespace MediaWiki\Extension\MediaModeration\Exception;

/**
 * Custom exception class for distinguishing between RuntimeExceptions that
 * our code throws, vs RuntimeExceptions that occur elsewhere in the stack.
 */
class RuntimeException extends \RuntimeException {
}
