<?php

namespace App\Services;

use GuzzleHttp\Client;
use LINE\Clients\MessagingApi\Configuration;
use LINE\Clients\MessagingApi\Api\MessagingApiApi;

class LineBotService
{
    /**
     * line bot 訊息 api
     *
     * @var MessagingApiApi
     */
    protected $bot;

    public function __construct()
    {
        // 只有在需要發送訊息時才初始化 LINE API 連線
        $config = Configuration::getDefaultConfiguration()->setAccessToken(config('services.line.channel_access_token'));
        $client = new Client();
        $this->bot = new MessagingApiApi($client, $config);
    }

    /**
     * 回傳訊息
     *
     * @param string $replyToken
     * @param string $text
     * @return void
     */
    public function replyMessage(string $replyToken, string $text): void
    {
        $this->bot->replyMessage([
            'replyToken' => $replyToken,
            'messages' => [['type' => 'text', 'text' => $text]]
        ]);
    }
}
