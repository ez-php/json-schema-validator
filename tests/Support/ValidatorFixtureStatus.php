<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Backed enum fixture → `{"type": "string", "enum": [...]}`.
 */
enum ValidatorFixtureStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
}
