<?php

namespace App\Support;

/**
 * Tyre size parsing and formatting.
 *
 * Accepts the many ways staff type sizes – "205/55 R16", "205/55R16", "205 55 16", "205-55-16",
 * "2055516", "205/55ZR16", "LT265/65R17", "185R14C", "185 R14" – and produces a canonical display
 * ("205/55 R16") and a normalised key ("205/55/16") used for exact, indexed matching.
 */
final class TyreSize
{
    public const MIN_WIDTH = 100;

    public const MAX_WIDTH = 455;

    public const MIN_PROFILE = 20;

    public const MAX_PROFILE = 95;

    public const MIN_RIM = 8;

    public const MAX_RIM = 30;

    public static function format(int $width, ?int $profile, string|float|int $rim): string
    {
        $rimText = self::rimText($rim);

        return $profile ? "{$width}/{$profile} R{$rimText}" : "{$width} R{$rimText}";
    }

    public static function key(int $width, ?int $profile, string|float|int $rim): string
    {
        return $width.'/'.($profile ?? 0).'/'.self::rimText($rim);
    }

    /** "16.0" → "16", "17.5" → "17.5" */
    public static function rimText(string|float|int $rim): string
    {
        $text = rtrim(rtrim(number_format((float) $rim, 1, '.', ''), '0'), '.');

        return $text === '' ? '0' : $text;
    }

    /**
     * Parse a complete size. Returns null when the input is not a complete, plausible tyre size.
     *
     * @return array{width:int, profile:int|null, rim:string}|null
     */
    public static function parse(string $input): ?array
    {
        $s = strtoupper(trim($input));
        $s = preg_replace('/^(P|LT|ST)\s*/', '', $s) ?? $s;
        $s = str_replace(',', '.', $s);

        // width / profile [Z]R rim  — separators optional
        if (preg_match('/^(\d{3})\s*[\/\-\s]?\s*(\d{2})\s*[\/\-\s]?\s*(?:Z?R|-|D|B)?\s*[\/\-\s]?\s*(\d{2}(?:\.\d)?)\s*C?$/', $s, $m)) {
            return self::validated((int) $m[1], (int) $m[2], $m[3]);
        }
        // width R rim, no profile (e.g. 185R14C, commercial sizes)
        if (preg_match('/^(\d{3})\s*[\/\-]?\s*R\s*(\d{2}(?:\.\d)?)\s*C?$/', $s, $m)) {
            return self::validated((int) $m[1], null, $m[2]);
        }

        return null;
    }

    /**
     * Extract size criteria from free-text search input. Supports complete sizes, rim-only ("R16")
     * and width/profile-only ("205/55") fragments. Returns the criteria plus the remaining text.
     *
     * @return array{width:?int, profile:?int, rim:?string, rest:string}
     */
    public static function extract(string $input): array
    {
        $s = ' '.strtoupper(str_replace(',', '.', $input)).' ';
        $result = ['width' => null, 'profile' => null, 'rim' => null, 'rest' => ''];

        $patterns = [
            // complete size inside free text
            'full' => '/(?<![\d.])(?:P|LT)?(\d{3})\s*[\/\-\s]?\s*(\d{2})\s*[\/\-\s]?\s*(?:Z?R)?\s*[\/\-\s]?\s*(\d{2}(?:\.\d)?)C?(?![\d.])/',
            'noprofile' => '/(?<![\d.])(\d{3})\s*R\s*(\d{2}(?:\.\d)?)C?(?![\d.])/',
            'rim' => '/(?<![A-Z\d.])Z?R\s*(\d{2}(?:\.\d)?)(?![\d.])/',
            'partial' => '/(?<![\d.])(\d{3})\s*\/\s*(\d{2})(?![\d.])/',
        ];

        foreach ($patterns as $name => $pattern) {
            if (! preg_match($pattern, $s, $m)) {
                continue;
            }
            $candidate = match ($name) {
                'full' => self::validated((int) $m[1], (int) $m[2], $m[3]),
                'noprofile' => self::validated((int) $m[1], null, $m[2]),
                'rim' => self::validRim($m[1]) ? ['width' => null, 'profile' => null, 'rim' => self::rimText($m[1])] : null,
                'partial' => (self::validWidth((int) $m[1]) && self::validProfile((int) $m[2]))
                    ? ['width' => (int) $m[1], 'profile' => (int) $m[2], 'rim' => null] : null,
            };
            if ($candidate) {
                $result = array_merge($result, $candidate);
                $s = str_replace($m[0], ' ', $s);
                break;
            }
        }

        $result['rest'] = trim(preg_replace('/\s+/', ' ', $s) ?? '');

        return $result;
    }

    /** @return array{width:int, profile:int|null, rim:string}|null */
    private static function validated(int $width, ?int $profile, string $rim): ?array
    {
        if (! self::validWidth($width) || ($profile !== null && ! self::validProfile($profile)) || ! self::validRim($rim)) {
            return null;
        }

        return ['width' => $width, 'profile' => $profile, 'rim' => self::rimText($rim)];
    }

    public static function validWidth(int $w): bool
    {
        return $w >= self::MIN_WIDTH && $w <= self::MAX_WIDTH;
    }

    public static function validProfile(int $p): bool
    {
        return $p >= self::MIN_PROFILE && $p <= self::MAX_PROFILE;
    }

    public static function validRim(string|float $r): bool
    {
        $r = (float) $r;

        return $r >= self::MIN_RIM && $r <= self::MAX_RIM;
    }
}
