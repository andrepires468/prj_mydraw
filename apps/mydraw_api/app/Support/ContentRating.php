<?php

namespace App\Support;

/**
 * Content ratings aligned with Civitai-style labels (PG … XXX).
 */
final class ContentRating
{
    public const PG = 'PG';

    public const PG_13 = 'PG-13';

    public const R = 'R';

    public const X = 'X';

    public const XXX = 'XXX';

    /** @var list<string> */
    public const ALLOWED = [
        self::PG,
        self::PG_13,
        self::R,
        self::X,
        self::XXX,
    ];

    /**
     * Higher means more restrictive / mature.
     */
    private const RANK = [
        self::PG     => 1,
        self::PG_13  => 2,
        self::R      => 3,
        self::X      => 4,
        self::XXX    => 5,
    ];

    public static function isAllowed(string $value): bool
    {
        return isset(self::RANK[$value]);
    }

    public static function rank(string $value): int
    {
        return self::RANK[$value] ?? 0;
    }

    /**
     * @param  string[]  $ratings
     */
    public static function max(array $ratings): ?string
    {
        $best    = null;
        $bestRank = 0;

        foreach ($ratings as $r) {
            $r = trim($r);
            if ($r === '' || !self::isAllowed($r)) {
                continue;
            }
            $rk = self::rank($r);
            if ($rk > $bestRank) {
                $bestRank = $rk;
                $best     = $r;
            }
        }

        return $best;
    }
}
