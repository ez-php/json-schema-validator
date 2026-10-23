<?php

declare(strict_types=1);

namespace EzPhp\JsonSchemaValidator;

/**
 * Class SchemaException
 *
 * The schema itself is unusable: an unsupported keyword, a non-local or
 * unresolvable `$ref`, an invalid `pattern`, or a malformed keyword value.
 * Distinct from validation failures, which are reported in ValidationResult.
 *
 * @package EzPhp\JsonSchemaValidator
 */
final class SchemaException extends \InvalidArgumentException
{
}
