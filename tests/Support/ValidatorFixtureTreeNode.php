<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Self-referencing fixture → the root `$ref: "#"`.
 */
final class ValidatorFixtureTreeNode
{
    public function __construct(
        public string $name,
        public ?ValidatorFixtureTreeNode $child = null,
    ) {
    }
}
