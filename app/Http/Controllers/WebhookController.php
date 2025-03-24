<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\BingoCardService;
use App\Services\LineBotService;
use App\Services\UserRegisterService;
use App\Services\UserStatusService;
use Illuminate\Http\JsonResponse;

class WebhookController extends Controller
{
    /**
     * lineBot 服務
     *
     * @var LineBotService
     */
    protected $lineBotService;

    /**
     * 使用者註冊服務
     *
     * @var UserRegisterService
     */
    protected $userRegisterService;

    /**
     * 使用者狀態管理服務
     *
     * @var UserStatusService
     */
    protected $userStatusService;

    /**
     * 賓果卡服務
     *
     * @var BingoCardService
     */
    protected $bingoCardService;


    public function __construct(LineBotService $lineBotService, UserStatusService $userStatusService, UserRegisterService $userRegisterService, BingoCardService $bingoCardService)
    {
        $this->lineBotService = $lineBotService;
        $this->userStatusService = $userStatusService;
        $this->userRegisterService = $userRegisterService;
        $this->bingoCardService = $bingoCardService;
    }

    /**
     * 處理 Webhook 事件
     *
     * @param Request $request
     * @return void
     */
    public function handle(Request $request): JsonResponse
    {
        $events = $request->input('events', []);

        foreach ($events as $event) {
            $lineId = $event['source']['userId'];

            switch ($event['type']) {
                case 'follow':
                    $this->handleFollow($event, $lineId);
                    break;
                case 'message':
                    //$this->handleMessage($event, $lineId);
                    break;
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * 處理 follow 事件
     *
     * @param array $event
     * @param string $lineId
     * @return void
     */
    private function handleFollow(array $event, string $lineId): void
    {
        $this->userRegisterService->registerUser($event, $lineId);
        return;
    }
}
