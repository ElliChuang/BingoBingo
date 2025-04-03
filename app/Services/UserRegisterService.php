<?php

namespace App\Services;

use App\Repositories\BingoCardRepository;
use App\Repositories\UserRepository;
use Log;

class UserRegisterService
{
    /**
     * 使用者 repository
     *
     * @var UserRepository
     */
    protected $userRepo;

    /**
     * 賓果卡 repository
     *
     * @var BingoCardRepository
     */
    protected $bingoCardRepo;

    /**
     * lineBot 服務
     *
     * @var LineBotService
     */
    protected $lineBotService;

    public function __construct(UserRepository $userRepo, BingoCardRepository $bingoCardRepo, LineBotService $lineBotService)
    {
        $this->userRepo = $userRepo;
        $this->bingoCardRepo = $bingoCardRepo;
        $this->lineBotService = $lineBotService;
    }

    /**
     * 建立/更新使用者資訊，並傳送歡迎訊息
     *
     * @param array $event
     * @param string $lineId
     * @return string
     */
    public function registerUser(array $event, string $lineId): void
    {
        // 取得 LINE 使用者資訊
        $profile = $this->lineBotService->fetchLineUserProfile($lineId);
        $user = $this->userRepo->findOrCreateUser($lineId, $profile['displayName'] ?? null);
        // 已經存在的用戶，確認是否有賓果卡的紀錄
        $cardCount = $this->bingoCardRepo->countUserCards($user->line_id);

        try {
            if ($user->wasRecentlyCreated || $cardCount == 0) {
                $this->lineBotService->replyMessage($event['replyToken'], "歡迎加入 Bingo 遊戲！\n請點選下方選單新增賓果卡，完成後就可以開始兌獎囉！");
            } else {
                $this->lineBotService->replyMessage($event['replyToken'], "歡迎回來！\n您目前有{$cardCount}張賓果卡 \n\n1.直接開始兌獎，請點選下方選單『開始兌獎』\n\n2.或點選下方選單『新增賓果卡』");
            }
        } catch (\Exception $e) {
            Log::error('[LineBot 回覆失敗] ' . $e->getMessage());
        }

        return;
    }
}
