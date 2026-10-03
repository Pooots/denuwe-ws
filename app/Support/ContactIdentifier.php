<?php

namespace App\Support;

/**
 * A single "mobile number or email" input, split into the column it belongs to.
 */
final class ContactIdentifier
{
    public const PHONE_PATTERN = '/^\+?[0-9]{7,15}$/';

    public static function isEmail(string $value): bool
    {
        return str_contains($value, '@');
    }

    public static function normalizeEmail(string $value): string
    {
        return strtolower(trim($value));
    }

    public static function normalizePhone(string $value): string
    {
        return preg_replace('/[\s\-().]/', '', trim($value)) ?? '';
    }

    /**
     * @return array{column: 'email'|'phone', value: string}
     */
    public static function parse(string $value): array
    {
        return self::isEmail($value)
            ? ['column' => 'email', 'value' => self::normalizeEmail($value)]
            : ['column' => 'phone', 'value' => self::normalizePhone($value)];
    }
}
