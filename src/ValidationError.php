<?php

declare(strict_types=1);

namespace EzPhp\JsonSchemaValidator;

/**
 * Class ValidationError
 *
 * One failed constraint: where in the data (JSON Pointer), which keyword
 * failed, and a human-readable message.
 *
 * @package EzPhp\JsonSchemaValidator
 */
final readonly class ValidationError
{
    /**
     * @param string $pointer JSON Pointer (RFC 6901) to the offending value; "" is the document root.
     * @param string $keyword The schema keyword that failed, e.g. "required" or "type".
     * @param string $message Human-readable description.
     */
    public function __construct(
        public string $pointer,
        public string $keyword,
        public string $message,
    ) {
    }
}
