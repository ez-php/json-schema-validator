<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\JsonSchema\SchemaGenerator;
use EzPhp\JsonSchemaValidator\FormatChecker;
use EzPhp\JsonSchemaValidator\ValidationResult;
use EzPhp\JsonSchemaValidator\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\ValidatorFixtureCustomer;
use Tests\Support\ValidatorFixtureTreeNode;

/**
 * End-to-end: schemas produced by ez-php/json-schema's SchemaGenerator are
 * fully supported — every keyword it emits is understood, none throws.
 *
 * @package Tests
 */
#[CoversNothing]
final class GeneratedSchemaValidationTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validCustomer(): array
    {
        return [
            'email' => 'ada@example.com',
            'age' => 36,
            'status' => 'active',
            'createdAt' => '2026-10-23T12:00:00+00:00',
            'address' => ['city' => 'Vienna', 'zip' => null],
            'reference' => 'AT123',
            'externalId' => 'ext-1',
        ];
    }

    public function testValidPayloadPasses(): void
    {
        $schema = (new SchemaGenerator())->generate(ValidatorFixtureCustomer::class);

        $result = (new Validator())->validate($this->validCustomer(), $schema);

        $this->assertSame([], $result->messages());
    }

    public function testOptionalPropertiesMayBeOmitted(): void
    {
        $schema = (new SchemaGenerator())->generate(ValidatorFixtureCustomer::class);
        $payload = $this->validCustomer();
        unset($payload['reference'], $payload['externalId']);
        $payload['address'] = ['city' => 'Vienna'];

        $this->assertTrue((new Validator())->validate($payload, $schema)->isValid());
    }

    public function testEveryGeneratedConstraintIsEnforced(): void
    {
        $schema = (new SchemaGenerator())->generate(ValidatorFixtureCustomer::class);

        $result = (new Validator())->validate([
            'email' => 'not-an-email',
            'age' => 12,
            'status' => 'deleted',
            'createdAt' => 'yesterday',
            'address' => ['zip' => 1010],
            'reference' => 'lowercase',
            'externalId' => 1.5,
        ], $schema);

        $this->assertSame([
            '/email' => ['Value does not match format "email".'],
            '/age' => ['Value must be at least 18.'],
            '/status' => ['Value is not one of the allowed values.'],
            '/createdAt' => ['Value does not match format "date-time".'],
            '/address/city' => ['Property "city" is required.'],
            '/address/zip' => ['Expected string or null, got integer.'],
            '/reference' => ['Value does not match pattern "^[A-Z]{2}[0-9]+$".'],
            '/externalId' => ['Value does not match any of the allowed schemas.'],
        ], $this->sorted($result, ['/email', '/age', '/status', '/createdAt', '/address/city', '/address/zip', '/reference', '/externalId']));
    }

    public function testRecursiveSchemaViaRootRef(): void
    {
        $schema = (new SchemaGenerator())->generate(ValidatorFixtureTreeNode::class);
        $validator = new Validator(new FormatChecker());

        $this->assertTrue($validator->validate(['name' => 'a', 'child' => ['name' => 'b', 'child' => null]], $schema)->isValid());

        $messages = $validator->validate(['name' => 'a', 'child' => ['child' => ['name' => 3]]], $schema)->messages();

        // `child` is nullable, so the generator wraps the root $ref in anyOf with null:
        // a broken nested node surfaces as an anyOf failure at the outermost child.
        $this->assertSame(['/child' => ['Value does not match any of the allowed schemas.']], $messages);
    }

    /**
     * The messages in a fixed key order, so the assertion does not depend on
     * the generator's property order.
     *
     * @param list<string> $order
     *
     * @return array<string, list<string>>
     */
    private function sorted(ValidationResult $result, array $order): array
    {
        $messages = $result->messages();
        $sorted = [];

        foreach ($order as $pointer) {
            if (isset($messages[$pointer])) {
                $sorted[$pointer] = $messages[$pointer];
                unset($messages[$pointer]);
            }
        }

        return $sorted + $messages;
    }
}
