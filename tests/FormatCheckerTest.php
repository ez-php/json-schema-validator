<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\JsonSchemaValidator\FormatChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class FormatCheckerTest
 *
 * @package Tests
 */
#[CoversClass(FormatChecker::class)]
final class FormatCheckerTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool|null}>
     */
    public static function formatProvider(): array
    {
        return [
            'date-time utc' => ['date-time', '2026-10-23T12:00:00Z', true],
            'date-time offset + fraction' => ['date-time', '2026-10-23T12:00:00.123456+02:00', true],
            'date-time leap second' => ['date-time', '2016-12-31T23:59:60Z', true],
            'date-time without zone' => ['date-time', '2026-10-23T12:00:00', false],
            'date-time invalid day' => ['date-time', '2026-02-30T12:00:00Z', false],
            'date-time invalid hour' => ['date-time', '2026-10-23T24:00:00Z', false],
            'date-time invalid offset' => ['date-time', '2026-10-23T12:00:00+24:00', false],
            'date' => ['date', '2024-02-29', true],
            'date not leap year' => ['date', '2026-02-29', false],
            'date with time' => ['date', '2026-10-23T00:00:00Z', false],
            'email' => ['email', 'user@example.com', true],
            'email invalid' => ['email', 'user@', false],
            'uri https' => ['uri', 'https://example.com/a?b=c', true],
            'uri urn' => ['uri', 'urn:isbn:0451450523', true],
            'uri relative' => ['uri', '/relative/path', false],
            'uri whitespace' => ['uri', 'https://exa mple.com', false],
            'uuid' => ['uuid', '123e4567-e89b-12d3-a456-426614174000', true],
            'uuid invalid' => ['uuid', '123e4567e89b12d3a456426614174000', false],
            'unknown format' => ['hostname', 'anything', null],
        ];
    }

    #[DataProvider('formatProvider')]
    public function testCheck(string $format, string $value, ?bool $expected): void
    {
        $this->assertSame($expected, (new FormatChecker())->check($format, $value));
    }
}
