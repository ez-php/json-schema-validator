<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Nested object fixture (inlined by SchemaGenerator).
 */
final class ValidatorFixtureAddress
{
    public function __construct(
        public string $city,
        public ?string $zip = null,
    ) {
    }
}
