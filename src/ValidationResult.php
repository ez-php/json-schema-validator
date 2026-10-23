<?php

declare(strict_types=1);

namespace EzPhp\JsonSchemaValidator;

/**
 * Class ValidationResult
 *
 * All errors found in one validation run (the validator does not stop at the
 * first failure).
 *
 * @package EzPhp\JsonSchemaValidator
 */
final readonly class ValidationResult
{
    /**
     * @param list<ValidationError> $errors
     */
    public function __construct(private array $errors = [])
    {
    }

    /**
     * Whether the data satisfied the schema.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return list<ValidationError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Errors grouped by JSON Pointer — e.g. for an API error response.
     *
     * @return array<string, list<string>> pointer => messages
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->errors as $error) {
            $messages[$error->pointer][] = $error->message;
        }

        return $messages;
    }
}
