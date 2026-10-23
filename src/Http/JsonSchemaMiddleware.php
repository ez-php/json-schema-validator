<?php

declare(strict_types=1);

namespace EzPhp\JsonSchemaValidator\Http;

use EzPhp\Contracts\ParameterizedMiddlewareInterface;
use EzPhp\Http\RequestInterface;
use EzPhp\Http\Response;
use EzPhp\Http\ResponseInterface;
use EzPhp\JsonSchema\SchemaGenerator;
use EzPhp\JsonSchemaValidator\SchemaException;
use EzPhp\JsonSchemaValidator\ValidationResult;
use EzPhp\JsonSchemaValidator\Validator;
use JsonException;
use LogicException;

/**
 * Class JsonSchemaMiddleware
 *
 * Validates the JSON request body against a schema before the route handler
 * runs, and answers a failure with the framework's validation envelope (422,
 * the same shape as ApiController::validationError()):
 *
 *   {"error": {"code": "VALIDATION_FAILED", "errors": {"address.city": ["Property \"city\" is required."]}}}
 *
 * Error keys are the JSON Pointers of the failing values in dot notation
 * (`/address/city` → `address.city`, the document root → `body`).
 *
 * The schema is either passed to the constructor, or generated per request
 * class from its #[Property]-annotated public properties by ez-php/json-schema's
 * SchemaGenerator — the same schema ez-php/openapi documents. As route
 * middleware (register an alias, e.g. `json-schema`):
 *
 *   $router->post('/users', [UserController::class, 'store'])
 *       ->middleware('json-schema:App\Requests\CreateUserRequest');
 *
 * Needs ez-php/contracts, ez-php/http and (for class schemas) ez-php/json-schema
 * — all suggests, so the validator itself keeps no dependencies.
 *
 * @package EzPhp\JsonSchemaValidator\Http
 */
final class JsonSchemaMiddleware implements ParameterizedMiddlewareInterface
{
    /**
     * Generated schemas by request class, per middleware instance.
     *
     * @var array<class-string, array<string, mixed>>
     */
    private array $schemas = [];

    /**
     * @param Validator                 $validator
     * @param SchemaGenerator|null      $generator Builds a schema from a request class; null = a default generator.
     * @param array<string, mixed>|null $schema    Fixed schema instead of a class parameter.
     */
    public function __construct(
        private readonly Validator $validator = new Validator(),
        private readonly ?SchemaGenerator $generator = null,
        private readonly ?array $schema = null,
    ) {
    }

    /**
     * @param RequestInterface $request
     * @param callable         $next
     * @param string           ...$parameters At most one: the request class whose schema to enforce.
     *
     * @return ResponseInterface
     */
    public function handle(RequestInterface $request, callable $next, string ...$parameters): ResponseInterface
    {
        $schema = $this->resolveSchema(array_values($parameters));

        try {
            $body = $request->rawBody() === '' ? null : json_decode($request->rawBody(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::failure(['body' => ['Request body is not valid JSON.']]);
        }

        $result = $this->validator->validate($body, $schema);

        if (!$result->isValid()) {
            return self::failure(self::errorsByField($result));
        }

        /** @var ResponseInterface */
        return $next($request);
    }

    /**
     * The JSON Pointer messages of a result keyed in dot notation (`/a/0/b` → `a.0.b`, root → `body`).
     *
     * @param ValidationResult $result
     *
     * @return array<string, list<string>>
     */
    public static function errorsByField(ValidationResult $result): array
    {
        $errors = [];

        foreach ($result->messages() as $pointer => $messages) {
            $segments = array_map(
                static fn (string $s): string => str_replace(['~1', '~0'], ['/', '~'], $s),
                array_values(array_filter(explode('/', (string) $pointer), static fn (string $s): bool => $s !== '')),
            );
            $field = $segments === [] ? 'body' : implode('.', $segments);
            $errors[$field] = [...($errors[$field] ?? []), ...$messages];
        }

        return $errors;
    }

    /**
     * @param list<string> $parameters
     *
     * @throws LogicException  On a configuration error (no schema, unknown class, several parameters).
     * @throws SchemaException When the schema itself is invalid.
     *
     * @return array<string, mixed>
     */
    private function resolveSchema(array $parameters): array
    {
        if (count($parameters) > 1) {
            throw new LogicException('JsonSchemaMiddleware takes at most one parameter: the request class.');
        }

        if ($parameters === []) {
            if ($this->schema === null) {
                throw new LogicException('JsonSchemaMiddleware needs a request class parameter or a schema passed to its constructor.');
            }

            return $this->schema;
        }

        $class = $parameters[0];

        if (!class_exists($class)) {
            throw new LogicException(sprintf('JsonSchemaMiddleware: request class "%s" does not exist.', $class));
        }

        return $this->schemas[$class] ??= ($this->generator ?? new SchemaGenerator())->generate($class);
    }

    /**
     * @param array<string, list<string>> $errors
     *
     * @return Response
     */
    private static function failure(array $errors): Response
    {
        $json = json_encode(['error' => ['code' => 'VALIDATION_FAILED', 'errors' => $errors]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return (new Response($json !== false ? $json : '{}', 422))->withHeader('Content-Type', 'application/json');
    }
}
