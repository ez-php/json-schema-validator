<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use EzPhp\JsonSchema\Attribute\Property;

/**
 * Class fixture exercising every keyword SchemaGenerator emits.
 */
final class ValidatorFixtureCustomer
{
    public function __construct(
        #[Property(format: 'email')]
        public string $email,
        #[Property(minimum: 18, maximum: 130)]
        public int $age,
        public ValidatorFixtureStatus $status,
        public DateTimeImmutable $createdAt,
        public ValidatorFixtureAddress $address,
        #[Property(pattern: '^[A-Z]{2}[0-9]+$')]
        public ?string $reference = null,
        public int|string $externalId = 0,
    ) {
    }
}
