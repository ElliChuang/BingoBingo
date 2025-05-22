<?php

namespace Tests\Unit;

use App\Models\BingoCard;
use App\Models\User;
use App\Repositories\BingoCardRepository;
use App\Services\BingoCardService;
use App\Services\LineBotService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class BingoCardServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $lineId = 'U1234567890';
    protected $replyToken = 'testToken';

    public function testBingoCardCanBeCreatedForUser(): void
    {
        $user = User::factory()->create();
        BingoCard::factory()->forUser($user)->create();

        $this->assertDatabaseHas('bingo_cards', [
            'line_id' => $user->line_id,
        ]);
    }

    public function testInputRowsAcceptValidRow(): void
    {
        [$bot, $repo, $service] = $this->getService();

        Cache::shouldReceive('get')
            ->with("bingo_card_temp_{$this->lineId}", ['current' => [], 'completed' => []])
            ->andReturn(['current' => [], 'completed' => []]);

        Cache::shouldReceive('put')->once();

        $bot->shouldReceive('replyMessage')
            ->once()
            ->with($this->replyToken, \Mockery::on(function ($msg) {
                return str_contains($msg, '請輸入第 2 排數字');
            }));

        $service->inputRows(['replyToken' => $this->replyToken], $this->lineId, '1 2 3 4 5');
    }

    public function testInputRowsRejectInvalidFormat(): void
    {
        [$bot, $repo, $service] = $this->getService();

        Cache::shouldReceive('get')
            ->with("bingo_card_temp_{$this->lineId}", ['current' => [], 'completed' => []])
            ->andReturn(['current' => [], 'completed' => []]);

        $bot->shouldReceive('replyMessage')
            ->once()
            ->with($this->replyToken, \Mockery::on(function ($msg) {
                return str_contains($msg, '請輸入有效的數字');
            }));

        $service->inputRows(['replyToken' => $this->replyToken], $this->lineId, '1, 2, 3');
    }

    public function testInputRowsRejectOutOfRange(): void
    {
        [$bot, $repo, $service] = $this->getService();

        Cache::shouldReceive('get')
            ->with("bingo_card_temp_{$this->lineId}", ['current' => [], 'completed' => []])
            ->andReturn(['current' => [], 'completed' => []]);

        $bot->shouldReceive('replyMessage')
            ->once()
            ->with($this->replyToken, \Mockery::on(function ($msg) {
                return str_contains($msg, '數字必須介於 1 到 75');
            }));

        $service->inputRows(['replyToken' => $this->replyToken], $this->lineId, '1 2 3 4 99');
    }

    public function testInputRowsRejectDuplicateInRow(): void
    {
        [$bot, $repo, $service] = $this->getService();

        Cache::shouldReceive('get')
            ->with("bingo_card_temp_{$this->lineId}", ['current' => [], 'completed' => []])
            ->andReturn(['current' => [], 'completed' => []]);

        $bot->shouldReceive('replyMessage')
            ->once()
            ->with($this->replyToken, \Mockery::on(function ($msg) {
                return str_contains($msg, '每排數字不得重複');
            }));

        $service->inputRows(['replyToken' => $this->replyToken], $this->lineId, '1 2 3 3 4');
    }

    public function testInputRowsRejectDuplicateAcrossRows(): void
    {
        [$bot, $repo, $service] = $this->getService();

        Cache::shouldReceive('get')
            ->with("bingo_card_temp_{$this->lineId}", ['current' => [], 'completed' => []])
            ->andReturn(['current' => [[6, 7, 8, 9, 10]], 'completed' => []]);

        $bot->shouldReceive('replyMessage')
            ->once()
            ->with($this->replyToken, \Mockery::on(function ($msg) {
                return str_contains($msg, '已經使用過');
            }));

        $service->inputRows(['replyToken' => $this->replyToken], $this->lineId, '6 11 12 13 14');
    }

    public function testInputRowsRejectWrongCountThirdRow(): void
    {
        [$bot, $repo, $service] = $this->getService();

        Cache::shouldReceive('get')
            ->with("bingo_card_temp_{$this->lineId}", ['current' => [], 'completed' => []])
            ->andReturn(['current' => [[1, 2, 3, 4, 5], [6, 7, 8, 9, 10]], 'completed' => []]);

        $bot->shouldReceive('replyMessage')
            ->once()
            ->with($this->replyToken, \Mockery::on(function ($msg) {
                return str_contains($msg, '請輸入 4 個數字');
            }));

        $service->inputRows(['replyToken' => $this->replyToken], $this->lineId, '11 12 13 14 15');
    }

    public function testInputRowsRejectWrongCountOtherRow(): void
    {
        [$bot, $repo, $service] = $this->getService();

        Cache::shouldReceive('get')
            ->with("bingo_card_temp_{$this->lineId}", ['current' => [], 'completed' => []])
            ->andReturn(['current' => [], 'completed' => []]);

        $bot->shouldReceive('replyMessage')
            ->once()
            ->with($this->replyToken, \Mockery::on(function ($msg) {
                return str_contains($msg, '請輸入 5 個數字');
            }));

        $service->inputRows(['replyToken' => $this->replyToken], $this->lineId, '1 2 3 4');
    }

    public function testInputDrawNumbersSuccess(): void
    {
        [$bot, $repo, $service] = $this->getService();

        $bingoCard = BingoCard::factory()->make(['numbers' => [
            [1, 2, 3, 4, 5],
            [6, 7, 8, 9, 10],
            [11, 12, 0, 14, 15],
            [16, 17, 18, 19, 20],
            [21, 22, 23, 24, 25],
        ]]);

        Cache::shouldReceive('get')
            ->with("bingo_draw_{$this->lineId}", ['drawn' => []])
            ->andReturn(['drawn' => []]);

        Cache::shouldReceive('put')
            ->once();

        $bot->shouldReceive('replyMessage')
            ->once()
            ->with($this->replyToken, \Mockery::on(function ($msg) {
                return str_contains($msg, '已開獎號碼：');
            }))
            ->andReturnNull();

        $repo->shouldReceive('getBingoCards')
            ->with($this->lineId)
            ->andReturn(collect([$bingoCard]));

        $service->inputDrawNumbers(['replyToken' => $this->replyToken], $this->lineId, '1 2 3 4 5');
    }


    public function testInputDrawNumbersWithInvalidFormat(): void
    {
        [$bot, $repo, $service] = $this->getService();

        $bot->shouldReceive('replyMessage')
            ->once()
            ->with($this->replyToken, \Mockery::on(function ($msg) {
                return str_contains($msg, '格式錯誤');
            }))
            ->andReturnNull();

        $repo->shouldReceive('getBingoCards')
            ->andReturn(collect([BingoCard::factory()->make()]));

        $service->inputDrawNumbers(['replyToken' => $this->replyToken], $this->lineId, '1, 二, 3');
    }

    public function testInputDrawNumbersOutOfRange(): void
    {
        [$bot, $repo, $service] = $this->getService();

        $bot->shouldReceive('replyMessage')
            ->once()
            ->with($this->replyToken, \Mockery::on(function ($msg) {
                return str_contains($msg, '號碼必須介於 1 到 75');
            }))
            ->andReturnNull();

        $repo->shouldReceive('getBingoCards')
            ->andReturn(collect([BingoCard::factory()->make()]));

        $service->inputDrawNumbers(['replyToken' => $this->replyToken], $this->lineId, '76 80');
    }


    public function testInputDrawNumbersWithDuplicate(): void
    {
        [$bot, $repo, $service] = $this->getService();

        Cache::shouldReceive('get')
            ->with("bingo_draw_{$this->lineId}", ['drawn' => []])
            ->andReturn(['drawn' => [5, 10]]);

        $bot->shouldReceive('replyMessage')
            ->once()
            ->with($this->replyToken, \Mockery::on(function ($msg) {
                return str_contains($msg, '已經開出過了');
            }))
            ->andReturnNull();

        $repo->shouldReceive('getBingoCards')
            ->andReturn(collect([BingoCard::factory()->make()]));

        $service->inputDrawNumbers(['replyToken' => $this->replyToken], $this->lineId, '5, 10');
    }


    private function getService()
    {
        $mockBot = Mockery::mock(LineBotService::class);
        $mockRepo = Mockery::mock(BingoCardRepository::class);
        $service = new BingoCardService($mockBot, $mockRepo);

        return [$mockBot, $mockRepo, $service];
    }
}
