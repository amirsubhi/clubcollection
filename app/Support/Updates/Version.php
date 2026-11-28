<?php

declare(strict_types=1);

namespace App\Support\Updates;

/** Semantic version comparison, pre-releases included (2.2.0-rc.1 < 2.2.0). `dev` is lower than every release. */
final class Version
{
    public static function isRelease(string $v): bool
    {
        return strtolower(trim($v)) !== 'dev';
    }

    /** -1, 0 or 1. */
    public static function compare(string $a, string $b): int
    {
        $aRelease = self::isRelease($a);
        $bRelease = self::isRelease($b);
        if (! $aRelease || ! $bRelease) {
            return $aRelease <=> $bRelease;
        }

        [$aCore, $aPre] = self::split($a);
        [$bCore, $bPre] = self::split($b);

        for ($i = 0; $i < 3; $i++) {
            $cmp = $aCore[$i] <=> $bCore[$i];
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        // A version without a pre-release is higher than the same version with one.
        if ($aPre === [] || $bPre === []) {
            return ($aPre === []) <=> ($bPre === []);
        }

        $count = max(count($aPre), count($bPre));
        for ($i = 0; $i < $count; $i++) {
            if (! isset($aPre[$i])) {
                return -1;
            }
            if (! isset($bPre[$i])) {
                return 1;
            }
            $x = $aPre[$i];
            $y = $bPre[$i];
            $xNum = ctype_digit($x);
            $yNum = ctype_digit($y);
            if ($xNum && $yNum) {
                $cmp = (int) $x <=> (int) $y;
            } elseif ($xNum !== $yNum) {
                $cmp = $xNum ? -1 : 1; // numeric identifiers sort before alphanumeric ones
            } else {
                $cmp = strcmp($x, $y) <=> 0;
            }
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return 0;
    }

    /** @return array{0: array{int, int, int}, 1: list<string>} */
    private static function split(string $v): array
    {
        $v = ltrim(trim($v), 'vV');
        $v = explode('+', $v, 2)[0]; // build metadata does not count
        $parts = explode('-', $v, 2);
        $core = array_pad(array_map('intval', explode('.', $parts[0])), 3, 0);
        $pre = isset($parts[1]) && $parts[1] !== '' ? explode('.', $parts[1]) : [];

        return [[$core[0], $core[1], $core[2]], $pre];
    }
}
