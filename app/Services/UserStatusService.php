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
    public const MODE_MAPS = [
        '新增賓果卡' => self::MODE_CARD,
        '顯示所有賓果卡' => self::MODE_CARD,
        '開始兌獎' => self::MODE_GAME,
        '顯示所有已開獎號碼' => self::MODE_GAME,
        '取消兌獎' => self::MODE_GAME,
    ];

    public function setUserMode(string $lineId, string $text): void
    {
        if (array_key_exists($text, self::MODE_MAPS)) {
            Cache::put("bingo_mode_{$lineId}", self::MODE_MAPS[$text], now()->addMinutes(10));
        }

        return;
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
