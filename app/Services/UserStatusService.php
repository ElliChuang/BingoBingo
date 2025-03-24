<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class UserStatusService
{
    /**
     * 設定使用者模式
     */
    public const MODE_CARD = 1;
    public const MODE_GAME = 2;

    public function setUserMode(string $lineId, int $mode): void
    {
        Cache::put("bingo_mode_{$lineId}", $mode, now()->addMinutes(10));
    }

    public function getUserMode(string $lineId): ?int
    {
        return Cache::get("bingo_mode_{$lineId}");
    }

    public function clearUserMode(string $lineId): void
    {
        Cache::forget("bingo_mode_{$lineId}");
    }
}
