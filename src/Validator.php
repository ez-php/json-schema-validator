<?php

declare(strict_types=1);

namespace EzPhp\JsonSchemaValidator;

use stdClass;

/**
 * Class Validator
 *
 * Validates decoded JSON against the JSON Schema (draft 2020-12) keyword
 * subset that ez-php/json-schema's SchemaGenerator emits, plus `items`:
 *
 *   type (incl. type lists), enum, properties, required, items, anyOf,
 *   format, pattern, minimum, maximum, $ref (local JSON Pointers into the
 *   root document — "#", "#/$defs/X", "#/components/schemas/X"), boolean schemas.
 *
 * Annotation keywords (description, default, title, examples, ...) and
 * `$defs` are ignored. Any other keyword throws SchemaException — an
 * unsupported constraint must not silently pass.
 *
 *   $result = (new Validator())->validate(json_decode($body, true), $schema);
 *   $result->isValid();   // false
 *   $result->messages();  // ['/email' => ['Value does not match format "email".']]
 *
 * Data may be decoded either way: as associative arrays (`json_decode(..., true)`)
 * or with stdClass objects. With arrays, a list is a JSON array, any other
 * array an object, and `[]` counts as both (json_decode cannot tell them apart).
 *
 * @package EzPhp\JsonSchemaValidator
 */
final class Validator
{
    /**
     * Keywords that never affect validation.
     */
    private const array ANNOTATIONS = [
        '$schema', '$id', '$comment', '$defs', 'definitions', 'title', 'description',
        'default', 'examples', 'deprecated', 'readOnly', 'writeOnly',
    ];

    /**
     * Keywords this validator asserts.
     */
    private const array ASSERTIONS = [
        '$ref', 'type', 'enum', 'anyOf', 'properties', 'required', 'items',
        'format', 'pattern', 'minimum', 'maximum',
    ];

    private const array TYPES = ['null', 'boolean', 'integer', 'number', 'string', 'array', 'object'];

    /**
     * @var array<string, string> pattern => compiled PCRE
     */
    private array $patterns = [];

    /**
     * @param FormatChecker $formats Asserts known `format` values.
     */
    public function __construct(private readonly FormatChecker $formats = new FormatChecker())
    {
    }

    /**
     * Validate decoded JSON data against a schema.
     *
     * @param mixed                $data   Decoded JSON (arrays or stdClass objects).
     * @param array<string, mixed> $schema Decoded root schema; `$ref`s resolve against it.
     *
     * @throws SchemaException When the schema uses an unsupported keyword, an unresolvable or non-local `$ref`, or an invalid keyword value.
     *
     * @return ValidationResult
     */
    public function validate(mixed $data, array $schema): ValidationResult
    {
        $errors = [];
        $this->check($data, $schema, $schema, '', $errors, []);

        return new ValidationResult($errors);
    }

    /**
     * @param mixed                $data
     * @param mixed                $schema
     * @param array<string, mixed> $root
     * @param string               $pointer  JSON Pointer of `$data`.
     * @param list<ValidationError> $errors  Collected errors (appended to).
     * @param array<string, true>  $refChain `$ref`s followed at this data position (cycle guard).
     *
     * @return void
     */
    private function check(mixed $data, mixed $schema, array $root, string $pointer, array &$errors, array $refChain): void
    {
        if ($schema === true) {
            return;
        }

        if ($schema === false) {
            $errors[] = new ValidationError($pointer, 'false', 'No value is allowed here.');

            return;
        }

        if (!is_array($schema)) {
            throw new SchemaException(sprintf('Schema at data path "%s" must be an object or a boolean.', $pointer));
        }

        foreach (array_keys($schema) as $keyword) {
            if (!in_array($keyword, self::ASSERTIONS, true) && !in_array($keyword, self::ANNOTATIONS, true)) {
                throw new SchemaException(sprintf('Unsupported schema keyword "%s".', $keyword));
            }
        }

        if (array_key_exists('$ref', $schema)) {
            $ref = $this->string($schema, '$ref');
            $guard = $ref . '@' . $pointer;

            if (isset($refChain[$guard])) {
                throw new SchemaException(sprintf('Circular $ref "%s" that never reaches a value.', $ref));
            }

            $this->check($data, $this->resolve($ref, $root), $root, $pointer, $errors, $refChain + [$guard => true]);
        }

        if (array_key_exists('type', $schema)) {
            $this->checkType($data, $schema['type'], $pointer, $errors);
        }

        if (array_key_exists('enum', $schema)) {
            $this->checkEnum($data, $schema['enum'], $pointer, $errors);
        }

        if (array_key_exists('anyOf', $schema)) {
            $this->checkAnyOf($data, $schema['anyOf'], $root, $pointer, $errors, $refChain);
        }

        if (is_string($data)) {
            $this->checkString($data, $schema, $pointer, $errors);
        }

        if ((is_int($data) || is_float($data)) && !is_nan((float) $data)) {
            $this->checkNumber($data, $schema, $pointer, $errors);
        }

        $members = $this->objectMembers($data);

        if ($members !== null) {
            $this->checkObject($members, $schema, $root, $pointer, $errors);
        }

        if (is_array($data) && array_is_list($data)) {
            $this->checkItems($data, $schema, $root, $pointer, $errors);
        }
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function checkType(mixed $data, mixed $type, string $pointer, array &$errors): void
    {
        $types = is_string($type) ? [$type] : $type;

        if (!is_array($types) || $types === [] || !array_is_list($types)) {
            throw new SchemaException('"type" must be a type name or a non-empty list of type names.');
        }

        foreach ($types as $name) {
            if (!is_string($name) || !in_array($name, self::TYPES, true)) {
                throw new SchemaException(sprintf('Unknown type "%s".', is_scalar($name) ? (string) $name : get_debug_type($name)));
            }

            if ($this->isType($data, $name)) {
                return;
            }
        }

        $errors[] = new ValidationError($pointer, 'type', sprintf('Expected %s, got %s.', implode(' or ', $types), $this->typeName($data)));
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function checkEnum(mixed $data, mixed $enum, string $pointer, array &$errors): void
    {
        if (!is_array($enum) || !array_is_list($enum)) {
            throw new SchemaException('"enum" must be a list.');
        }

        foreach ($enum as $allowed) {
            if ($this->equals($data, $allowed)) {
                return;
            }
        }

        $errors[] = new ValidationError($pointer, 'enum', 'Value is not one of the allowed values.');
    }

    /**
     * @param array<string, mixed>  $root
     * @param list<ValidationError> $errors
     * @param array<string, true>   $refChain
     */
    private function checkAnyOf(mixed $data, mixed $branches, array $root, string $pointer, array &$errors, array $refChain): void
    {
        if (!is_array($branches) || $branches === [] || !array_is_list($branches)) {
            throw new SchemaException('"anyOf" must be a non-empty list of schemas.');
        }

        foreach ($branches as $branch) {
            $branchErrors = [];
            $this->check($data, $branch, $root, $pointer, $branchErrors, $refChain);

            if ($branchErrors === []) {
                return;
            }
        }

        $errors[] = new ValidationError($pointer, 'anyOf', 'Value does not match any of the allowed schemas.');
    }

    /**
     * @param array<string, mixed>  $schema
     * @param list<ValidationError> $errors
     */
    private function checkString(string $data, array $schema, string $pointer, array &$errors): void
    {
        if (array_key_exists('format', $schema)) {
            $format = $this->string($schema, 'format');

            if ($this->formats->check($format, $data) === false) {
                $errors[] = new ValidationError($pointer, 'format', sprintf('Value does not match format "%s".', $format));
            }
        }

        if (array_key_exists('pattern', $schema)) {
            $pattern = $this->string($schema, 'pattern');

            if (preg_match($this->compile($pattern), $data) !== 1) {
                $errors[] = new ValidationError($pointer, 'pattern', sprintf('Value does not match pattern "%s".', $pattern));
            }
        }
    }

    /**
     * @param array<string, mixed>  $schema
     * @param list<ValidationError> $errors
     */
    private function checkNumber(int|float $data, array $schema, string $pointer, array &$errors): void
    {
        if (array_key_exists('minimum', $schema) && $data < $this->number($schema, 'minimum')) {
            $errors[] = new ValidationError($pointer, 'minimum', sprintf('Value must be at least %s.', $this->numberText($schema['minimum'])));
        }

        if (array_key_exists('maximum', $schema) && $data > $this->number($schema, 'maximum')) {
            $errors[] = new ValidationError($pointer, 'maximum', sprintf('Value must be at most %s.', $this->numberText($schema['maximum'])));
        }
    }

    /**
     * @param array<string, mixed>  $members
     * @param array<string, mixed>  $schema
     * @param array<string, mixed>  $root
     * @param list<ValidationError> $errors
     */
    private function checkObject(array $members, array $schema, array $root, string $pointer, array &$errors): void
    {
        if (array_key_exists('required', $schema)) {
            $required = $schema['required'];

            if (!is_array($required) || !array_is_list($required)) {
                throw new SchemaException('"required" must be a list of property names.');
            }

            foreach ($required as $name) {
                if (!is_string($name)) {
                    throw new SchemaException('"required" must be a list of property names.');
                }

                if (!array_key_exists($name, $members)) {
                    $errors[] = new ValidationError($this->child($pointer, $name), 'required', sprintf('Property "%s" is required.', $name));
                }
            }
        }

        if (array_key_exists('properties', $schema)) {
            $properties = $schema['properties'];

            if (!is_array($properties) || ($properties !== [] && array_is_list($properties))) {
                throw new SchemaException('"properties" must map property names to schemas.');
            }

            foreach ($properties as $name => $propertySchema) {
                if (array_key_exists($name, $members)) {
                    $this->check($members[$name], $propertySchema, $root, $this->child($pointer, (string) $name), $errors, []);
                }
            }
        }
    }

    /**
     * @param list<mixed>           $items
     * @param array<string, mixed>  $schema
     * @param array<string, mixed>  $root
     * @param list<ValidationError> $errors
     */
    private function checkItems(array $items, array $schema, array $root, string $pointer, array &$errors): void
    {
        if (!array_key_exists('items', $schema)) {
            return;
        }

        foreach ($items as $index => $item) {
            $this->check($item, $schema['items'], $root, $this->child($pointer, (string) $index), $errors, []);
        }
    }

    /**
     * Resolve a local `$ref` (a JSON Pointer fragment) against the root schema.
     *
     * @param array<string, mixed> $root
     *
     * @throws SchemaException When the ref is not local or does not resolve.
     */
    private function resolve(string $ref, array $root): mixed
    {
        if ($ref === '#') {
            return $root;
        }

        if (!str_starts_with($ref, '#/')) {
            throw new SchemaException(sprintf('Only local $refs ("#" or "#/...") are supported, got "%s".', $ref));
        }

        $target = $root;

        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));

            if (!is_array($target) || !array_key_exists($segment, $target)) {
                throw new SchemaException(sprintf('$ref "%s" does not resolve.', $ref));
            }

            $target = $target[$segment];
        }

        return $target;
    }

    private function isType(mixed $data, string $type): bool
    {
        return match ($type) {
            'null' => $data === null,
            'boolean' => is_bool($data),
            'integer' => is_int($data) || (is_float($data) && is_finite($data) && floor($data) === $data),
            'number' => is_int($data) || (is_float($data) && is_finite($data)),
            'string' => is_string($data),
            'array' => is_array($data) && array_is_list($data),
            'object' => $data instanceof stdClass || (is_array($data) && ($data === [] || !array_is_list($data))),
            default => false,
        };
    }

    private function typeName(mixed $data): string
    {
        foreach (['null', 'boolean', 'integer', 'number', 'string', 'array', 'object'] as $type) {
            if ($this->isType($data, $type)) {
                return $type;
            }
        }

        return get_debug_type($data);
    }

    /**
     * The members of a JSON object, or null when `$data` is not one.
     *
     * @return array<string, mixed>|null
     */
    private function objectMembers(mixed $data): ?array
    {
        if ($data instanceof stdClass) {
            return get_object_vars($data);
        }

        if (is_array($data) && $data !== [] && !array_is_list($data)) {
            $members = [];

            foreach ($data as $key => $value) {
                $members[(string) $key] = $value;
            }

            return $members;
        }

        return $data === [] ? [] : null;
    }

    /**
     * JSON equality: numbers compare by value (1 == 1.0), containers recursively, everything else strictly.
     */
    private function equals(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }

        if ($a instanceof stdClass) {
            $a = get_object_vars($a);
        }

        if ($b instanceof stdClass) {
            $b = get_object_vars($b);
        }

        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b) || array_is_list($a) !== array_is_list($b)) {
                return false;
            }

            foreach ($a as $key => $value) {
                if (!array_key_exists($key, $b) || !$this->equals($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }

    private function child(string $pointer, string $segment): string
    {
        return $pointer . '/' . str_replace(['~', '/'], ['~0', '~1'], $segment);
    }

    /**
     * Compile an ECMA-262-style `pattern` to PCRE (unanchored, Unicode).
     *
     * @throws SchemaException When the pattern does not compile.
     */
    private function compile(string $pattern): string
    {
        if (isset($this->patterns[$pattern])) {
            return $this->patterns[$pattern];
        }

        $delimiter = null;

        foreach (['/', '#', '~', '%', '@', '!', ';'] as $candidate) {
            if (!str_contains($pattern, $candidate)) {
                $delimiter = $candidate;

                break;
            }
        }

        if ($delimiter === null) {
            throw new SchemaException(sprintf('Cannot compile pattern "%s": no free delimiter.', $pattern));
        }

        $regex = $delimiter . $pattern . $delimiter . 'u';

        set_error_handler(static fn (): bool => true);

        try {
            $valid = preg_match($regex, '') !== false;
        } finally {
            restore_error_handler();
        }

        if (!$valid) {
            throw new SchemaException(sprintf('Invalid pattern "%s".', $pattern));
        }

        return $this->patterns[$pattern] = $regex;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function string(array $schema, string $keyword): string
    {
        $value = $schema[$keyword];

        if (!is_string($value)) {
            throw new SchemaException(sprintf('"%s" must be a string.', $keyword));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function number(array $schema, string $keyword): int|float
    {
        $value = $schema[$keyword];

        if (!is_int($value) && !is_float($value)) {
            throw new SchemaException(sprintf('"%s" must be a number.', $keyword));
        }

        return $value;
    }

    private function numberText(mixed $number): string
    {
        return is_int($number) || is_float($number) ? (string) $number : '?';
    }
}
