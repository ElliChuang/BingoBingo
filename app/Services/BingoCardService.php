<?php

namespace App\Services;

use App\Repositories\BingoCardRepository;
use Cache;
use Illuminate\Support\Collection;

class BingoCardService
{
    /**
     * lineBot 服務
     *
     * @var LineBotService
     */
    protected $lineBotService;

    /**
     * 賓果卡 Repository
     *
     * @var BingoCardRepository
     */
    protected $bingoCardRepo;

    public function __construct(LineBotService $lineBotService, BingoCardRepository $bingoCardRepo)
    {
        $this->lineBotService = $lineBotService;
        $this->bingoCardRepo = $bingoCardRepo;
    }

    /**
     * 確認是否存在temp賓果卡
     *
     * @param array $event
     * @param string $lineId
     * @return void
     */
    public function checkTempCard(array $event, string $lineId): void
    {
        // 取得使用者目前快取中的賓果卡
        $cacheKey = "bingo_card_temp_{$lineId}";
        $cachedCards = Cache::get($cacheKey, []);

        if (!empty($cachedCards['current'])) {
            $this->lineBotService->replyMessage($event['replyToken'], "您有一張未完成的賓果卡，請選擇：\n\n輸入「繼續」來完成這張賓果卡\n輸入「取消」來放棄新增");
            return;
        }

        // 如果快取為空，開始新的賓果卡輸入
        Cache::put($cacheKey, ['current' => [], 'completed' => []], now()->addMinutes(10));

        $this->lineBotService->replyMessage($event['replyToken'], "請輸入賓果卡的第一排數字\n以空格隔開，例如：1 2 3 4 5 \n\n1.號碼須介於 1 - 75 \n2.號碼不得重複");

        return;
    }

    /**
     * 刪除temp賓果卡
     *
     * @param array $event
     * @param string $lineId
     * @return void
     */
    public function cancelTempCard(array $event, string $lineId): void
    {
        $cacheKey = "bingo_card_temp_{$lineId}";
        Cache::forget($cacheKey);

        $this->lineBotService->replyMessage($event['replyToken'], "賓果卡新增已取消！");

        return;
    }

    /**
     * 繼續編輯temp賓果卡
     *
     * @param array $event
     * @param string $lineId
     * @return void
     */
    public function continueTempCard(array $event, string $lineId): void
    {
        $cacheKey = "bingo_card_temp_{$lineId}";
        $cachedCards = Cache::get($cacheKey, []);

        if (empty($cachedCards['current'])) {
            $this->lineBotService->replyMessage($event['replyToken'], "目前沒有未完成的賓果卡，請輸入「新增賓果卡」開始新增。");
            return;
        }

        // 組裝目前已輸入的排數與內容
        $rowsPreview = "您已輸入的號碼：\n";
        foreach ($cachedCards['current'] as $index => $row) {
            $rowNumber = $index + 1;
            $rowsPreview .= "第 {$rowNumber} 排： " . implode(' ', $row) . "\n";
        }

        // 取得已經輸入的號碼
        $nextRow = count($cachedCards['current']) + 1;
        // 確保數字格式正確
        if (count($cachedCards['current']) == 2) {
            $this->lineBotService->replyMessage($event['replyToken'], "{$rowsPreview}\n\n請輸入第 {$nextRow} 排共 4 個數字，並以空格隔開，例如：1 2 3 4");
            return;
        }

        $this->lineBotService->replyMessage($event['replyToken'], "{$rowsPreview}\n\n請輸入第 {$nextRow} 排數字，並以空格隔開");

        return;
    }

    /**
     * 輸入賓果卡號碼
     *
     * @param array $event
     * @param string $lineId
     * @param string $rowInput
     * @return void
     */
    public function inputRows(array $event, string $lineId, string $rowInput): void
    {
        $cacheKey = "bingo_card_temp_{$lineId}";
        $cachedCards = Cache::get($cacheKey, ['current' => [], 'completed' => []]);

        // 如果 completed 有資料
        if (!empty($cachedCards['completed'])) {
            $this->lineBotService->replyMessage($event['replyToken'], "這張賓果卡號碼已填寫完成！\n\n1. 回覆『確認』建立賓果卡 \n2. 回覆『取消』清除號碼");
            return;
        }

        // 確保只允許數字和空格
        if (!preg_match('/^(\d+\s*)+$/', $rowInput)) {
            $this->lineBotService->replyMessage($event['replyToken'], "請輸入有效的數字，並以空格隔開，例如：1 2 3 4 5");
            return;
        }

        // 拆成陣列並轉整數
        $rowInput = trim($rowInput); // 只去除前後空格，不影響中間數字
        $numbers = preg_split('/\s+/', $rowInput); // 保留單獨數字
        $numbers = array_map('intval', $numbers); // 確保轉換為數字

        // 確保範圍在 1-75
        foreach ($numbers as $num) {
            if ($num < 1 || $num > 75) { // 確保數字在範圍內
                $this->lineBotService->replyMessage($event['replyToken'], "數字必須介於 1 到 75 之間，每個數字以空格隔開，請重新輸入！");
                return;
            }
        }

        // 檢查是否有重複數字：同一排數字重複
        if (count($numbers) !== count(array_unique($numbers))) {
            $this->lineBotService->replyMessage($event['replyToken'], "每排數字不得重複，請重新輸入！");
            return;
        }

        // 取得目前已輸入的所有數字
        $existingNumbers = [];
        foreach ($cachedCards['current'] as $row) {
            $existingNumbers = array_merge($existingNumbers, $row);
        }

        // 檢查是否與 current 內的數字重複
        $duplicateNumbers = array_intersect($numbers, $existingNumbers);
        if (!empty($duplicateNumbers)) {
            $this->lineBotService->replyMessage($event['replyToken'], "數字 " . implode(", ", $duplicateNumbers) . " 已經使用過，請重新輸入不同的數字！");
            return;
        }

        // 確保數字格式正確
        if (count($cachedCards['current']) == 2 && count($numbers) != 4) {
            $this->lineBotService->replyMessage($event['replyToken'], "第 3 排，請輸入 4 個數字並以空格隔開，例如：1 2 3 4");
            return;
        } elseif (count($cachedCards['current']) != 2 && count($numbers) != 5) {
            $this->lineBotService->replyMessage($event['replyToken'], "請輸入 5 個數字，並以空格隔開，例如：1 2 3 4 5");
            return;
        }

        // 第 3 排 free space 補零
        if (count($cachedCards['current']) == 2) {
            array_splice($numbers, 2, 0, [0]);
        }

        // 存入目前卡片
        $cachedCards['current'][] = array_map('intval', $numbers);
        Cache::put($cacheKey, $cachedCards, now()->addMinutes(10));

        // 如果賓果卡未完成提示：第 3 排
        if (count($cachedCards['current']) == 2) {
            $this->lineBotService->replyMessage($event['replyToken'], "請輸入第 " . (count($cachedCards['current']) + 1) . " 排數字共 4 個數字，並以空格隔開");
            return;
        }

        // 如果賓果卡未完成提示：少於 5 排）
        if (count($cachedCards['current']) < 5) {
            $this->lineBotService->replyMessage($event['replyToken'], "請輸入第 " . (count($cachedCards['current']) + 1) . " 排數字，並以空格隔開");
            return;
        }

        // 當 5 排輸入完成，詢問是否確認
        $cachedCards['completed'] = $cachedCards['current'];
        $cachedCards['current'] = [];
        Cache::put($cacheKey, $cachedCards, now()->addMinutes(10));

        $this->lineBotService->replyMessage($event['replyToken'], "這張賓果卡數字已填寫完成！\n\n1. 回覆『確認』建立賓果卡 \n2. 回覆『取消』清除號碼");

        return;
    }

    /**
     * 確定建立賓果卡
     *
     * @param array $event
     * @param string $lineId
     * @return void
     */
    public function confirmAndCreateCard(array $event, string $lineId): void
    {
        $cacheKey = "bingo_card_temp_{$lineId}";
        $cachedCards = Cache::get($cacheKey, []);

        if (empty($cachedCards['completed'])) {
            $this->lineBotService->replyMessage($event['replyToken'], "目前沒有任何賓果卡需要儲存。請輸入「新增賓果卡」開始建立新的賓果卡！");
            return;
        }

        // 存入賓果卡
        try {
            $this->bingoCardRepo->createBingoCard($lineId, $cachedCards['completed']);
            $this->lineBotService->replyMessage($event['replyToken'], "賓果卡已成功儲存！");
            // 清除快取
            Cache::forget($cacheKey);
        } catch (\Exception $e) {
            \Log::error('BingoCard 儲存失敗：' . $e->getMessage());
        }

        return;
    }

    /**
     * 取得所有賓果卡
     *
     * @param array $event
     * @param string $lineId
     * @return void
     */
    public function getCards(array $event, string $lineId): void
    {
        $cards = $this->bingoCardRepo->getBingoCards($lineId);

        if ($cards->isEmpty()) {
            $this->lineBotService->replyMessage($event['replyToken'], "您目前尚未建立任何賓果卡");
            return;
        }

        $message = "您目前有 " . $cards->count() . " 張賓果卡： \n";

        foreach ($cards as $card) {
            $rows = $card->numbers;

            $message .= "\n🎯 編號 {$card->id}\n";
            foreach ($rows as $row) {
                $message .= implode(' ', array_map(function ($n) {
                    return str_pad($n, 2, ' ', STR_PAD_LEFT) . "\u{200B}";
                }, $row)) . "\n";
            }
        }

        $message .= "\n如需刪除卡片，請輸入「刪除編號 1」或「刪除編號 2」...\n";

        $this->lineBotService->replyMessage($event['replyToken'], $message);

        return;
    }

    /**
     * 處理刪除的指令
     *
     * @param array $event
     * @param string $lineId
     * @param string $text
     * @return void
     */
    public function handleDeleteCommand(array $event, string $lineId, string $text): void
    {
        // 嘗試從文字中抓出數字
        if (preg_match('/^刪除編號(?:卡片)?[#\s]*(\d+)$/u', $text, $matches)) {
            $cardId = (int) $matches[1];
            $this->deleteCardById($event, $lineId, $cardId);
            return;
        }

        // 若格式錯誤，回覆提示
        $this->lineBotService->replyMessage($event['replyToken'], "格式錯誤！請輸入「刪除編號 1」來刪除指定卡片");

        return;
    }

    /**
     * 開始兌獎
     *
     * @param array $event
     * @param string $lineId
     * @return void
     */
    public function startGame(array $event, string $lineId): void
    {
        // 如果還沒有開獎快取，就初始化
        $cacheKey = "bingo_draw_{$lineId}";
        if (!Cache::has($cacheKey)) {
            Cache::put($cacheKey, ['drawn' => []], now()->addMinutes(30));
            $this->lineBotService->replyMessage($event['replyToken'], "已進入兌獎模式！\n\n請依序輸入開獎號碼，系統將即時更新中獎狀況。\n\n一次輸入多個號碼時，請以空格或逗號分隔，例如：1 2 3 或 1,2,3 \n");
            return;
        }

        // 若已經有快取，不重複初始化
        $this->lineBotService->replyMessage($event['replyToken'], "請輸入開獎號碼！");
    }


    /**
     * 取得所有已開獎號碼
     *
     * @param array $event
     * @param string $lineId
     * @return void
     */
    public function getDrawNumbers(array $event, string $lineId): void
    {
        $draw = Cache::get("bingo_draw_{$lineId}", ['drawn' => []]);

        if (empty($draw['drawn'])) {
            $this->lineBotService->replyMessage($event['replyToken'], "目前尚無已開獎號碼");
            return;
        }

        $drawnNumbers = $draw['drawn'];
        sort($drawnNumbers); // 排序號碼，讓顯示更清楚
        $count = count($drawnNumbers);
        $list = implode(', ', $drawnNumbers);

        $message = "📣 已開獎號碼共有 {$count} 個：\n{$list}";
        $this->lineBotService->replyMessage($event['replyToken'], $message);
    }

    /**
     * 輸入中獎號碼
     *
     * @param array $event
     * @param string $lineId
     * @param string $input
     * @return void
     */
    public function inputDrawNumbers(array $event, string $lineId, string $input): void
    {
        // 是否存在賓果卡
        $cards = $this->bingoCardRepo->getBingoCards($lineId);
        if ($cards->isEmpty()) {
            $this->lineBotService->replyMessage($event['replyToken'], '您尚未建立任何賓果卡，請輸入『新增賓果卡』後，建立完畢後再行兌獎');
            return;
        }

        // 擷取所有數字（允許空格與逗號間隔）
        if (!preg_match_all('/\b\d+\b/', $input, $matches)) {
            $this->lineBotService->replyMessage($event['replyToken'], "請僅輸入 1 到 75 的數字\n一次輸入1個或多個數字\n並空格或逗號分隔，例如：1 2 3 或 1,2,3");
            return;
        }

        $numbers = array_map('intval', $matches[0] ?? []);

        // 檢查是否有非法格式（即原字串中有非數字的部分）
        $cleaned = implode(' ', $numbers);
        if (preg_replace('/[,\s]+/', ' ', trim($input)) !== $cleaned) {
            $this->lineBotService->replyMessage($event['replyToken'], "格式錯誤！請僅輸入數字，並以空格或逗號分隔，例如：1 2 3 或 1,2,3");
            return;
        }

        // 驗證數字範圍
        $invalid = array_filter($numbers, function ($n) {
            return $n < 1 || $n > 75;
        });
        if (!empty($invalid)) {
            $this->lineBotService->replyMessage($event['replyToken'], "號碼必須介於 1 到 75 之間，請重新輸入！");
            return;
        }

        // 快取開獎號碼
        $cacheKey = "bingo_draw_{$lineId}";
        $drawNumbers = Cache::get($cacheKey, ['drawn' => []]);

        // 避免重複輸入
        $duplicateNumbers = array_intersect($numbers, $drawNumbers['drawn']);
        if (!empty($duplicateNumbers)) {
            $this->lineBotService->replyMessage($event['replyToken'], "數字 " . implode(", ", $duplicateNumbers) . " 已經開出過了，請輸入其他號碼！");
            return;
        }

        $drawNumbers['drawn'] = array_merge($drawNumbers['drawn'], $numbers);
        Cache::put($cacheKey, $drawNumbers, now()->addHours(1));

        // 呼叫兌獎流程
        $message = $this->isBingo($cards, $drawNumbers["drawn"]);

        $this->lineBotService->replyMessage($event['replyToken'], $message);
    }

    /**
     * 清除已紀錄的中獎號碼
     *
     * @param array $event
     * @param string $lineId
     * @return void
     */
    public function cancelDrawNumbers(array $event, string $lineId): void
    {
        $cacheKey = "bingo_draw_{$lineId}";

        if (!Cache::has($cacheKey)) {
            $this->lineBotService->replyMessage($event['replyToken'], "目前沒有任何開獎號碼可以取消。");
            return;
        }

        Cache::forget($cacheKey);
        $this->lineBotService->replyMessage($event['replyToken'], "已取消目前所有開獎號碼！");
    }

    /**
     * 進行賓果核對
     *
     * @param Collection $cards
     * @param array $drawNumbers
     * @return string
     */
    private function isBingo(Collection $cards, array $drawNumbers): string
    {
        $reply = "已開獎號碼：" . implode(', ', $drawNumbers) . "\n";

        foreach ($cards as $card) {
            $grid = $card->numbers;
            $bingoLines = 0;

            // 檢查橫線
            foreach ($grid as $row) {
                if (collect($row)->every(function ($num) use ($drawNumbers) {
                    return $num === 0 || in_array($num, $drawNumbers);
                })) {
                    $bingoLines++;
                }
            }

            // 檢查直線
            for ($col = 0; $col < 5; $col++) {
                $column = array_column($grid, $col);
                if (collect($column)->every(function ($num) use ($drawNumbers) {
                    return $num === 0 || in_array($num, $drawNumbers);
                })) {
                    $bingoLines++;
                }
            }

            // 檢查對角線
            $diag1 = $diag2 = true;
            for ($i = 0; $i < 5; $i++) {
                $diag1 &= ($grid[$i][$i] === 0 || in_array($grid[$i][$i], $drawNumbers));
                $diag2 &= ($grid[$i][4 - $i] === 0 || in_array($grid[$i][4 - $i], $drawNumbers));
            }
            $bingoLines += ($diag1 ? 1 : 0) + ($diag2 ? 1 : 0);

            // 撈出中獎號碼（排除 free space 0）
            $matchedNumbers = [];
            foreach ($drawNumbers as $num) {
                foreach ($grid as $row) {
                    if (in_array($num, $row)) {
                        $matchedNumbers[] = $num;
                        break; // 找到就跳出，不必重複
                    }
                }
            }

            $matchedStr = empty($matchedNumbers) ? '無' : implode(', ', $matchedNumbers);
            $reply .= "\n🎯 編號 {$card->id}\n已連線：{$bingoLines} 條\n已中獎號碼：{$matchedStr}\n";
        }

        return $reply;
    }

    /**
     * 刪除指定編號的賓果卡
     *
     * @param array $event
     * @param string $lineId
     * @param integer $cardId
     * @return void
     */
    private function deleteCardById(array $event, string $lineId, int $cardId): void
    {
        $card = $this->bingoCardRepo->getBingoCardById($lineId, $cardId);

        if (!$card) {
            $this->lineBotService->replyMessage($event['replyToken'], "找不到編號為 {$cardId} 的賓果卡！");
            return;
        }

        $card->delete();
        $this->lineBotService->replyMessage($event['replyToken'], "成功刪除編號為 {$cardId} 的賓果卡！");

        return;
    }
}
