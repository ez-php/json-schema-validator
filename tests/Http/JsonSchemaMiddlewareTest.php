<?php

declare(strict_types=1);

namespace Tests\Http;

use EzPhp\Http\Request;
use EzPhp\Http\RequestInterface;
use EzPhp\Http\Response;
use EzPhp\Http\ResponseInterface;
use EzPhp\JsonSchemaValidator\FormatChecker;
use EzPhp\JsonSchemaValidator\Http\JsonSchemaMiddleware;
use EzPhp\JsonSchemaValidator\ValidationError;
use EzPhp\JsonSchemaValidator\ValidationResult;
use EzPhp\JsonSchemaValidator\Validator;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\ValidatorFixtureCustomer;
use Tests\TestCase;

/**
 * JsonSchemaMiddleware: schema from a #[Property] request class or a fixed
 * schema, the 422 validation envelope with dot-notation keys, invalid JSON.
 */
#[CoversClass(JsonSchemaMiddleware::class)]
#[UsesClass(Validator::class)]
#[UsesClass(ValidationResult::class)]
#[UsesClass(ValidationError::class)]
#[UsesClass(FormatChecker::class)]
final class JsonSchemaMiddlewareTest extends TestCase
{
    private function request(string $body): Request
    {
        return new Request('POST', '/customers', headers: ['content-type' => 'application/json'], rawBody: $body);
    }

    private function next(): callable
    {
        return static fn (RequestInterface $request): ResponseInterface => new Response('created', 201);
    }

    /**
     * The `errors` map of a 422 VALIDATION_FAILED envelope.
     *
     * @return array<string, mixed>
     */
    private function errors(ResponseInterface $response): array
    {
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(422, $response->status());
        $decoded = json_decode($response->body(), true);
        $this->assertIsArray($decoded);
        $error = $decoded['error'] ?? null;
        $this->assertIsArray($error);
        $this->assertSame('VALIDATION_FAILED', $error['code'] ?? null);
        $errors = $error['errors'] ?? null;
        $this->assertIsArray($errors);

        /** @var array<string, mixed> $errors */
        return $errors;
    }

    public function test_a_valid_body_reaches_the_handler(): void
    {
        $body = '{"email":"ada@example.com","age":36,"status":"active","createdAt":"2026-10-23T12:00:00+00:00","address":{"street":"Main 1","city":"Vienna"}}';

        $response = (new JsonSchemaMiddleware())->handle($this->request($body), $this->next(), ValidatorFixtureCustomer::class);

        $this->assertSame(201, $response->status());
    }

    public function test_violations_answer_422_with_dot_notation_fields(): void
    {
        $body = '{"email":"not-an-email","age":12,"status":"active","createdAt":"2026-10-23T12:00:00+00:00","address":{"street":"Main 1"}}';

        $response = (new JsonSchemaMiddleware())->handle($this->request($body), $this->next(), ValidatorFixtureCustomer::class);
        $errors = $this->errors($response);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('application/json', $response->headers()['Content-Type'] ?? null);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('age', $errors);
        $this->assertArrayHasKey('address.city', $errors);
    }

    public function test_invalid_json_and_a_root_type_mismatch_are_reported_on_body(): void
    {
        $middleware = new JsonSchemaMiddleware(schema: ['type' => 'object']);

        $this->assertSame(['body' => ['Request body is not valid JSON.']], $this->errors($middleware->handle($this->request('{"a":'), $this->next())));
        $this->assertArrayHasKey('body', $this->errors($middleware->handle($this->request('[1,2]'), $this->next())));
        $this->assertSame(201, $middleware->handle($this->request('{}'), $this->next())->status());
    }

    public function test_pointer_escapes_are_decoded(): void
    {
        $result = new ValidationResult([
            new ValidationError('/a~1b/0', 'type', 'Expected string.'),
            new ValidationError('/a~1b/0', 'pattern', 'No match.'),
            new ValidationError('', 'type', 'Expected object.'),
        ]);

        $this->assertSame(
            ['a/b.0' => ['Expected string.', 'No match.'], 'body' => ['Expected object.']],
            JsonSchemaMiddleware::errorsByField($result),
        );
    }

    public function test_configuration_errors_are_logic_exceptions(): void
    {
        foreach ([
            fn (): ResponseInterface => (new JsonSchemaMiddleware())->handle($this->request('{}'), $this->next()),
            fn (): ResponseInterface => (new JsonSchemaMiddleware())->handle($this->request('{}'), $this->next(), 'App\\Missing'),
            fn (): ResponseInterface => (new JsonSchemaMiddleware())->handle($this->request('{}'), $this->next(), 'A', 'B'),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected LogicException.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
