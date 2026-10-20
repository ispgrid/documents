<?php

namespace Ispgrid\Documents;

use Ispgrid\Documents\Models\NumberMode;

/**
 * ID numbers as typed from the card. Formats differ around the world (PAN ABCDE1234F, RUT 12.345.678-K, RFC with
 * Ñ and &), so any printable line is taken and kept as typed, only trimmed and with repeated spaces collapsed.
 */
final class IdNumber
{
    public const MAX = 40;

    public static function normalise(string $typed): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($typed));
    }

    /**
     * One line of printable characters (any script), 1 to 40 of them, with at least one letter or digit.
     */
    public static function isValid(string $typed): bool
    {
        $number = self::normalise($typed);

        return preg_match('/\p{C}/u', $typed) === 0
            && preg_match('/[\p{L}\p{N}]/u', $number) === 1
            && mb_strlen($number) <= self::MAX;
    }

    /**
     * The last 4 letters or digits; spaces and symbols are skipped.
     */
    public static function lastFour(string $typed): string
    {
        preg_match_all('/[\p{L}\p{N}]/u', $typed, $matches);

        return implode('', array_slice($matches[0], -4));
    }

    /**
     * Every letter and digit but the last 4 shown as x; spaces and symbols as typed: 1234 5678 4821 is
     * xxxx xxxx 4821.
     */
    public static function mask(string $number): string
    {
        $characters = mb_str_split($number);
        $hide = count(array_filter($characters, fn (string $c): bool => preg_match('/[\p{L}\p{N}]/u', $c) === 1)) - 4;

        return implode('', array_map(function (string $c) use (&$hide): string {
            if ($hide > 0 && preg_match('/[\p{L}\p{N}]/u', $c) === 1) {
                $hide--;

                return 'x';
            }

            return $c;
        }, $characters));
    }

    /**
     * A keyed hash of the letters and digits, ignoring case, so the same card typed differently is found again
     * without decrypting any stored number.
     */
    public static function fingerprint(string $number): string
    {
        preg_match_all('/[\p{L}\p{N}]/u', mb_strtolower($number), $matches);

        return hash_hmac('sha256', implode('', $matches[0]), (string) config('app.key'));
    }

    /**
     * What a type with this setting keeps of a typed number; null when it keeps nothing.
     */
    public static function forMode(string $typed, NumberMode $mode): ?string
    {
        $number = self::normalise($typed);

        return match (true) {
            $number === '', $mode === NumberMode::None => null,
            $mode === NumberMode::LastFour => self::lastFour($number),
            default => $number,
        };
    }
}
