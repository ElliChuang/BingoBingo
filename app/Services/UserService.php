<?php

namespace App\Services;

use App\Repositories\UserRepository;
use GuzzleHttp\Client;
use Log;

class UserService
{
    /**
     * 使用者 repository
     *
     * @var UserRepository
     */
    protected $userRepo;

    public function __construct(UserRepository $userRepo)
    {
        $this->userRepo = $userRepo;
    }

    /**
     * 建立/更新使用者資訊
     *
     * @param string $lineId
     * @return string
     */
    public function registerUser(string $lineId): string
    {
        // 取得 LINE 使用者資訊
        $profile = $this->fetchLineUserProfile($lineId);
        $user = $this->userRepo->findOrCreateUser($lineId, $profile['displayName'] ?? null);

        return $user->wasRecentlyCreated
            ? "歡迎加入 Bingo 遊戲！\n請輸入『開始遊戲』來啟動兌獎"
            : "歡迎回來！\n請輸入『開始遊戲』來啟動兌獎";
    }

    /**
     * 取得 line profile 資訊
     *
     * @param string $lineId
     * @return array
     */
    private function fetchLineUserProfile(string $lineId): array
    {
        try {
            $client = new Client();
            $response = $client->get("https://api.line.me/v2/bot/profile/{$lineId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . env('LINE_CHANNEL_ACCESS_TOKEN'),
                    'Content-Type' => 'application/json',
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data;
        } catch (\Exception $e) {
            Log::error('Failed to fetch LINE user profile: ' . $e->getMessage());
            return [];
        }
    }
}
