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

    /**
     * 各指令應執行的 BingoCardService function
     *
     * @var array
     */
    private $commands = [
        UserStatusService::MODE_CARD => [
            '新增賓果卡' => 'checkTempCard',
            '取消'     => 'cancelTempCard',
            '繼續'     => 'continueTempCard',
            '確認'     => 'confirmAndCreateCard',
            '顯示所有賓果卡' => 'getCards',
        ],
        UserStatusService::MODE_GAME => [
            '開始兌獎' => 'startGame',
            '顯示所有開獎號碼' => 'getDrawNumbers',
            '取消兌獎' => 'cancelDrawNumbers'
        ]
    ];


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
                    $this->handleMessage($event, $lineId);
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

    /**
     * 處理 message 事件
     *
     * @param array $event
     * @param string $lineId
     * @return void
     */
    private function handleMessage(array $event, string $lineId): void
    {
        $oriText = $event['message']['text'] ?? '';
        $messageText = trim(str_replace(' ', '', $oriText));
        $isNumberInput = preg_match('/^\d/', $oriText);

        // 設定使用者模式
        $this->userStatusService->setUserMode($lineId, $messageText);

        // 取得當前模式
        $currentMode = $this->userStatusService->getUserMode($lineId);
        // 確保該模式下有 command 設定，並執行對應 function
        if (isset($this->commands[$currentMode]) && array_key_exists($messageText, $this->commands[$currentMode])) {
            $method = $this->commands[$currentMode][$messageText];
            $this->bingoCardService->$method($event, $lineId);
        }
        // 刪除特定編號的賓果卡 
        elseif (str_starts_with($oriText, '刪除編號')) {
            // 若不是卡片模式，引導切換模式
            if ($this->userStatusService->getUserMode($lineId) !== UserStatusService::MODE_CARD) {
                $this->lineBotService->replyMessage($event['replyToken'], "請先輸入「顯示所有賓果卡」，再執行此操作！");
                return;
            }

            $this->bingoCardService->handleDeleteCommand($event, $lineId, $oriText);
            return;
        }
        // 若這個指令存在於其他模式中，但不是當前模式，則提示切換
        elseif ($this->isCommandInOtherMode($messageText, $currentMode)) {
            $correctMode = $this->getCommandCorrectMode($messageText);
            $guideCommandName = $correctMode === UserStatusService::MODE_CARD ? '顯示所有賓果卡' : '開始兌獎';
            $this->lineBotService->replyMessage($event['replyToken'], "請先輸入：「{$guideCommandName}」，再執行此操作！");
        }
        // 非指令但是數字輸入
        elseif ($isNumberInput) {
            // 在卡片模式時，輸入每排數字
            if ($currentMode === UserStatusService::MODE_CARD) {
                $this->bingoCardService->inputRows($event, $lineId, $oriText);
            }
            // 在遊戲模式時，輸入數字開始兌獎 
            elseif ($currentMode === UserStatusService::MODE_GAME) {
                $this->bingoCardService->inputDrawNumbers($event, $lineId, $oriText);
            }
        }
        // 其他非指定指令
        else {
            $this->lineBotService->replyMessage($event['replyToken'], "請至圖文選單選擇要執行的事項，\n或輸入以下指令：\n- 新增賓果卡\n- 顯示所有賓果卡\n- 開始兌獎\n...等");
        }

        return;
    }

    /**
     * 確認是否為其他模式的指令
     *
     * @param string $text
     * @param integer|null $currentMode
     * @return boolean
     */
    private function isCommandInOtherMode(string $text, ?int $currentMode): bool
    {
        foreach ($this->commands as $mode => $commandSet) {
            if ($currentMode !== $mode && array_key_exists($text, $commandSet)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 取得指令對應的模式
     *
     * @param string $text
     * @return integer|null
     */
    private function getCommandCorrectMode(string $text): ?int
    {
        foreach ($this->commands as $mode => $commandSet) {
            if (array_key_exists($text, $commandSet)) {
                return $mode;
            }
        }
        return null;
    }
}
