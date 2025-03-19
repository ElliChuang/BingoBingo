<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use LINE\Clients\MessagingApi\Api\MessagingApiApi;
use LINE\Clients\MessagingApi\Configuration;
use LINE\Clients\MessagingApi\Model\ReplyMessageRequest;
use LINE\Clients\MessagingApi\Model\TextMessage;

class LineBotController extends Controller
{
    private $bot;

    public function __construct()
    {
        // 設定 LINE API 客戶端
        $config = Configuration::getDefaultConfiguration()->setAccessToken(env('LINE_CHANNEL_ACCESS_TOKEN'));
        $client = new Client();
        $this->bot = new MessagingApiApi($client, $config);
    }

    /**
     * Webhook 接收 LINE 訊息
     */
    public function webhook(Request $request)
    {
        // 解析 LINE 傳來的 Webhook 事件
        $events = $request->input('events', []);
        print_r($events);

        return response()->json(['status' => 'success']);
    }

    /**
     * 發送回應訊息
     */
    private function replyToUser($replyToken, $messageText)
    {
        $message = new TextMessage([
            'type' => 'text',
            'text' => $messageText
        ]);

        $replyMessageRequest = new ReplyMessageRequest([
            'replyToken' => $replyToken,
            'messages' => [$message]
        ]);

        try {
            $this->bot->replyMessage($replyMessageRequest);
        } catch (\Exception $e) {
            \Log::error("LINE Bot 回應失敗：" . $e->getMessage());
        }
    }
}
