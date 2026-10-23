<?php

declare(strict_types=1);

namespace EzPhp\JsonSchemaValidator;

/**
 * Class FormatChecker
 *
 * Asserts the `format` values this module knows: `date-time` (the one
 * ez-php/json-schema emits for DateTimeInterface properties), `date`,
 * `email`, `uri` and `uuid`. Any other format is an annotation only, as
 * JSON Schema 2020-12 permits.
 *
 * @package EzPhp\JsonSchemaValidator
 */
final class FormatChecker
{
    /**
     * Check a string against a format.
     *
     * @param string $format
     * @param string $value
     *
     * @return bool|null True/false for a known format, null when the format is unknown (not asserted).
     */
    public function check(string $format, string $value): ?bool
    {
        return match ($format) {
            'date-time' => $this->isDateTime($value),
            'date' => $this->isDate($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'uri' => $this->isUri($value),
            'uuid' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1,
            default => null,
        };
    }

    /**
     * RFC 3339 `date-time`, e.g. 2026-10-23T12:00:00Z or 2026-10-23T12:00:00.5+02:00.
     */
    private function isDateTime(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:[Zz]|[+-](\d{2}):(\d{2}))$/', $value, $m) !== 1) {
            return false;
        }

        $offsetHours = (int) ($m[7] ?? 0);
        $offsetMinutes = (int) ($m[8] ?? 0);

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (int) $m[4] < 24
            && (int) $m[5] < 60
            && (int) $m[6] <= 60 // 60 = leap second
            && $offsetHours < 24
            && $offsetMinutes < 60;
    }

    /**
     * RFC 3339 `full-date`, e.g. 2026-10-23.
     */
    private function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * An absolute URI: a scheme, then a non-empty rest, no whitespace.
     * Accepts non-HTTP schemes (urn:, mailto:) that FILTER_VALIDATE_URL rejects.
     */
    private function isUri(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9+.\-]*:[^\s]+$/i', $value) === 1;
    }
}
