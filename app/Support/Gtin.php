<?php

namespace App\Support;

/**
 * GTIN helpers. Every barcode is stored in one canonical form: GTIN-13 (13 digits).
 *
 * A UPC-A is an EAN-13 with a leading zero, and different scanners report one or the other,
 * so every code is normalised before it is compared, looked up or stored.
 * UPC-E (compressed) codes are not expanded here; reject them and ask for the full number.
 */
final class Gtin
{
    /** Returns the canonical 13-digit GTIN, or null if the input is not a valid 8/12/13/14-digit GTIN. */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $code = preg_replace('/[\s\-]/', '', $input);

        if ($code === null || ! preg_match('/^\d+$/', $code)) {
            return null;
        }

        if (! in_array(strlen($code), [8, 12, 13, 14], true) || ! self::hasValidCheckDigit($code)) {
            return null;
        }

        // GTIN-14 is only representable as GTIN-13 when it starts with a zero.
        if (strlen($code) === 14) {
            if ($code[0] !== '0') {
                return null;
            }

            return substr($code, 1);
        }

        // Left-padding with zeros keeps the check digit valid (weights run from the right).
        return str_pad($code, 13, '0', STR_PAD_LEFT);
    }

    public static function isValid(?string $input): bool
    {
        return self::normalize($input) !== null;
    }

    /** The 12-digit UPC-A form, when the GTIN-13 has a leading zero. */
    public static function toUpcA(string $gtin13): ?string
    {
        return ($gtin13[0] ?? null) === '0' ? substr($gtin13, 1) : null;
    }

    public static function checkDigitFor(string $digitsWithoutCheck): int
    {
        $sum = 0;
        $weight = 3;
        for ($i = strlen($digitsWithoutCheck) - 1; $i >= 0; $i--) {
            $sum += ((int) $digitsWithoutCheck[$i]) * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - ($sum % 10)) % 10;
    }

    public static function hasValidCheckDigit(string $code): bool
    {
        $body = substr($code, 0, -1);

        return self::checkDigitFor($body) === (int) substr($code, -1);
    }
}
