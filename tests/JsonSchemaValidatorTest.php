<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\JsonSchemaValidator\FormatChecker;
use EzPhp\JsonSchemaValidator\SchemaException;
use EzPhp\JsonSchemaValidator\ValidationError;
use EzPhp\JsonSchemaValidator\ValidationResult;
use EzPhp\JsonSchemaValidator\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Class JsonSchemaValidatorTest
 *
 * Keyword-level behaviour against hand-written schemas.
 *
 * @package Tests
 */
#[CoversClass(Validator::class)]
#[CoversClass(ValidationResult::class)]
#[CoversClass(ValidationError::class)]
#[UsesClass(FormatChecker::class)]
#[UsesClass(SchemaException::class)]
final class JsonSchemaValidatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, list<string>>
     */
    private function messages(mixed $data, array $schema): array
    {
        return (new Validator())->validate($data, $schema)->messages();
    }

    /**
     * @return array<string, array{mixed, string|list<string>, bool}>
     */
    public static function typeProvider(): array
    {
        return [
            'string' => ['x', 'string', true],
            'int is integer' => [3, 'integer', true],
            'integral float is integer' => [3.0, 'integer', true],
            'fraction is not integer' => [3.5, 'integer', false],
            'int is number' => [3, 'number', true],
            'bool is not integer' => [true, 'integer', false],
            'null' => [null, 'null', true],
            'list is array' => [[1, 2], 'array', true],
            'map is object' => [['a' => 1], 'object', true],
            'map is not array' => [['a' => 1], 'array', false],
            'stdClass is object' => [(object) ['a' => 1], 'object', true],
            'empty array is array' => [[], 'array', true],
            'empty array is object' => [[], 'object', true],
            'type list' => [null, ['string', 'null'], true],
            'type list mismatch' => [1, ['string', 'null'], false],
        ];
    }

    /**
     * @param string|list<string> $type
     */
    #[DataProvider('typeProvider')]
    public function testType(mixed $data, string|array $type, bool $valid): void
    {
        $this->assertSame($valid, (new Validator())->validate($data, ['type' => $type])->isValid());
    }

    public function testTypeErrorMessageNamesExpectedAndActual(): void
    {
        $this->assertSame(['' => ['Expected string or null, got integer.']], $this->messages(5, ['type' => ['string', 'null']]));
    }

    public function testEnumUsesJsonEquality(): void
    {
        $validator = new Validator();

        $this->assertTrue($validator->validate(1.0, ['enum' => [1, 2]])->isValid());
        $this->assertTrue($validator->validate(['a' => [1]], ['enum' => [(object) ['a' => [1]]]])->isValid());
        $this->assertFalse($validator->validate('1', ['enum' => [1, 2]])->isValid());
        $this->assertFalse($validator->validate([1, 2], ['enum' => [[2, 1]]])->isValid());
        $this->assertFalse($validator->validate([1], ['enum' => [['a' => 1]]])->isValid());
        $this->assertFalse($validator->validate([1], ['enum' => [[1, 2]]])->isValid());
        $this->assertSame(['' => ['Value is not one of the allowed values.']], $this->messages('c', ['enum' => ['a', 'b']]));
    }

    public function testRequiredAndPropertiesReportJsonPointers(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'a/b' => ['type' => 'integer'],
                'tilde~' => ['type' => 'integer'],
            ],
            'required' => ['name', 'email'],
        ];

        $this->assertSame([
            '/email' => ['Property "email" is required.'],
            '/name' => ['Expected string, got integer.'],
            '/a~1b' => ['Expected integer, got string.'],
            '/tilde~0' => ['Expected integer, got string.'],
        ], $this->messages(['name' => 1, 'a/b' => 'x', 'tilde~' => 'y'], $schema));
    }

    public function testUndeclaredPropertiesAreAllowed(): void
    {
        $this->assertSame([], $this->messages(['extra' => true], ['type' => 'object', 'properties' => []]));
    }

    public function testItemsValidateEveryElement(): void
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id']]];

        $this->assertSame(['/1/id' => ['Property "id" is required.']], $this->messages([['id' => 1], ['x' => 2]], $schema));
    }

    public function testAnyOf(): void
    {
        $schema = ['anyOf' => [['type' => 'integer'], ['type' => 'string', 'format' => 'date']]];

        $this->assertSame([], $this->messages(5, $schema));
        $this->assertSame([], $this->messages('2026-10-23', $schema));
        $this->assertSame(['' => ['Value does not match any of the allowed schemas.']], $this->messages('tomorrow', $schema));
    }

    public function testStringAndNumberConstraints(): void
    {
        $this->assertSame(['' => ['Value does not match pattern "^[A-Z]+$".']], $this->messages('abc', ['pattern' => '^[A-Z]+$']));
        $this->assertSame([], $this->messages('a/b#c', ['pattern' => '/b#']));
        $this->assertSame(['' => ['Value must be at least 18.']], $this->messages(17, ['minimum' => 18]));
        $this->assertSame(['' => ['Value must be at most 1.5.']], $this->messages(2, ['maximum' => 1.5]));
        $this->assertSame(['' => ['Value does not match format "email".']], $this->messages('nope', ['format' => 'email']));
        $this->assertSame([], $this->messages('anything', ['format' => 'hostname']));
        // keywords for other types do not apply
        $this->assertSame([], $this->messages(5, ['pattern' => '^x$', 'format' => 'email']));
        $this->assertSame([], $this->messages('5', ['minimum' => 10]));
    }

    public function testLocalRefsResolveAgainstTheRoot(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'parent' => ['$ref' => '#'],
                'tag' => ['$ref' => '#/$defs/Tag'],
                'escaped' => ['$ref' => '#/$defs/a~1b'],
            ],
            'required' => ['name'],
            '$defs' => [
                'Tag' => ['type' => 'string', 'enum' => ['x', 'y']],
                'a/b' => ['type' => 'integer'],
            ],
        ];

        $this->assertSame([
            '/parent/name' => ['Property "name" is required.'],
            '/tag' => ['Value is not one of the allowed values.'],
            '/escaped' => ['Expected integer, got string.'],
        ], $this->messages(['name' => 'a', 'parent' => ['parent' => ['name' => 'c']], 'tag' => 'z', 'escaped' => 's'], $schema));
    }

    public function testBooleanSchemas(): void
    {
        $this->assertSame([], $this->messages(['x' => 1], ['properties' => ['x' => true]]));
        $this->assertSame(['/x' => ['No value is allowed here.']], $this->messages(['x' => 1], ['properties' => ['x' => false]]));
    }

    public function testCollectsAllErrorsAndAnnotationsAreIgnored(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'T',
            'description' => 'd',
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string', 'default' => 'x'], 'b' => ['type' => 'string']],
        ];

        $result = (new Validator())->validate(['a' => 1, 'b' => 2], $schema);

        $this->assertFalse($result->isValid());
        $this->assertCount(2, $result->errors());
        $this->assertSame('type', $result->errors()[0]->keyword);
        $this->assertSame('/a', $result->errors()[0]->pointer);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidSchemaProvider(): array
    {
        return [
            'unsupported keyword' => [['minLength' => 3], 'Unsupported schema keyword "minLength"'],
            'remote ref' => [['$ref' => 'https://example.com/s.json'], 'Only local $refs'],
            'unresolvable ref' => [['$ref' => '#/$defs/Missing'], 'does not resolve'],
            'circular ref' => [['$ref' => '#'], 'Circular $ref'],
            'unknown type' => [['type' => 'date'], 'Unknown type "date"'],
            'empty type list' => [['type' => []], '"type" must be'],
            'enum not a list' => [['enum' => 'a'], '"enum" must be a list'],
            'empty anyOf' => [['anyOf' => []], '"anyOf" must be'],
            'invalid pattern' => [['pattern' => '(unclosed'], 'Invalid pattern'],
            'non-numeric minimum' => [['minimum' => '1'], '"minimum" must be a number'],
            'non-string format' => [['format' => 1], '"format" must be a string'],
            'required not a list' => [['required' => 'a'], '"required" must be a list'],
            'properties as list' => [['properties' => [['type' => 'string']]], '"properties" must map'],
            'schema not an object' => [['items' => 'string'], 'must be an object or a boolean'],
        ];
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('invalidSchemaProvider')]
    public function testInvalidSchemasThrow(array $schema, string $message): void
    {
        $data = match (true) {
            isset($schema['minimum']) => 5,
            isset($schema['pattern']), isset($schema['format']) => 'x',
            isset($schema['required']), isset($schema['properties']) => ['a' => 1],
            isset($schema['items']) => [1],
            default => null,
        };

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage($message);

        (new Validator())->validate($data, $schema);
    }

    public function testPatternWithoutFreeDelimiterIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('no free delimiter');

        (new Validator())->validate('x', ['pattern' => '/#~%@!;']);
    }

    public function testRequiredEntriesMustBeStrings(): void
    {
        $this->expectException(SchemaException::class);

        (new Validator())->validate(['a' => 1], ['required' => [1]]);
    }
}
