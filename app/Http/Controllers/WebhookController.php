<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\UserService;
use App\Services\LineBotService;
use Illuminate\Http\JsonResponse;

class WebhookController extends Controller
{
    /**
     * 使用者服務
     *
     * @var UserService
     */
    protected $userService;

    /**
     * lineBot 服務
     *
     * @var LineBotService
     */
    protected $lineBotService;

    public function __construct(UserService $userService, LineBotService $lineBotService)
    {
        $this->userService = $userService;
        $this->lineBotService = $lineBotService;
    }

    /**
     * 處理 Webhook 事件
     *
     * @param Request $request
     * @return void
     */
    public function handle(Request $request): JsonResponse
    {
        $data = $request->all();

        foreach ($data['events'] as $event) {
            if ($event['type'] == 'follow') {
                $userId = $event['source']['userId'];
                $replyText = $this->userService->registerUser($userId);
                $this->lineBotService->replyMessage($event['replyToken'], $replyText);
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
