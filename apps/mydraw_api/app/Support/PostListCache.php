<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class PostListCache
{
    private const GLOBAL_VERSION_KEY = 'posts:list:version:global';
    private const USER_VERSION_PREFIX = 'posts:list:version:user:';

    public static function globalVersion(): int
    {
        return self::readVersion(self::GLOBAL_VERSION_KEY);
    }

    public static function userVersion(?int $userId): int
    {
        if (!$userId) {
            return 1;
        }

        return self::readVersion(self::USER_VERSION_PREFIX.$userId);
    }

    public static function bump(?int $userId, bool $touchGlobal): void
    {
        if ($touchGlobal) {
            self::writeVersion(self::GLOBAL_VERSION_KEY, self::globalVersion() + 1);
        }

        if ($userId) {
            $key = self::USER_VERSION_PREFIX.$userId;
            self::writeVersion($key, self::readVersion($key) + 1);
        }
    }

    private static function readVersion(string $key): int
    {
        $value = Cache::get($key);
        if (!is_numeric($value)) {
            self::writeVersion($key, 1);
            return 1;
        }

        return max(1, (int) $value);
    }

    private static function writeVersion(string $key, int $value): void
    {
        Cache::forever($key, max(1, $value));
    }
}
