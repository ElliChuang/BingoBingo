<?php

namespace Tests\Feature;

use App\Models\BingoCard;
use App\Models\User;
use App\Repositories\BingoCardRepository;
use App\Services\LineBotService;
use App\Services\UserStatusService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    /*****************************
     *       follow events       *
     *****************************/

    /**
     * 新用戶加入時，是否已建立使用者資訊
     *
     * @return void
     */
    public function testCreatesNewUserAndSendsWelcomeMessage(): void
    {
        $fakeLineId = 'U1234567890';
        $fakeName = '測試新用戶';
        $replyToken = 'testToken';

        $this->mock(LineBotService::class, function ($mock) use ($fakeLineId, $fakeName, $replyToken) {
            $mock->shouldReceive('fetchLineUserProfile')
                ->with($fakeLineId)
                ->andReturn([
                    'displayName' => $fakeName,
                    'userId' => $fakeLineId,
                ]);

            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '歡迎加入');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('follow', '', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);

        // 確保建立使用者
        $this->assertDatabaseHas('users', ['line_id' => $fakeLineId]);
    }

    /**
     * 舊用戶，重新 follow 時，確保不會重複建立使用者資料
     *
     * @return void
     */
    public function testExistingUserSendsWelcomeBackMessage(): void
    {
        $existingUser = User::factory()->create([
            'line_id' => 'U9876543210',
            'name' => '舊用戶',
        ]);

        $replyToken = 'testToken';

        $this->mock(LineBotService::class, function ($mock) use ($existingUser, $replyToken) {
            $mock->shouldReceive('fetchLineUserProfile')
                ->with($existingUser->line_id)
                ->andReturn([
                    'displayName' => $existingUser->name,
                    'userId' => $existingUser->line_id,
                ]);

            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '歡迎回來') &&
                        str_contains($message, '賓果卡');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('follow', '', $existingUser->line_id, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);

        // 確保沒有重複建立使用者
        $this->assertDatabaseCount('users', 1);
    }

    /**
     * 當 replyToken 有誤時，replyMessage 不會直接報錯
     *
     * @return void
     */
    public function testHandlesReplyMessageErrorGracefully(): void
    {
        $fakeLineId = 'U1234567890';
        $fakeName = '測試用戶';
        $replyToken = 'invalidToken';

        $this->mock(LineBotService::class, function ($mock) use ($fakeLineId, $fakeName) {
            $mock->shouldReceive('fetchLineUserProfile')
                ->andReturn([
                    'displayName' => $fakeName,
                    'userId' => $fakeLineId,
                ]);

            // 模擬 throw exception
            $mock->shouldReceive('replyMessage')
                ->andThrow(new \Exception('Invalid reply token'));
        });

        $payload = $this->getMockPayload('follow', '', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);
    }

    /**
     * 當取得 line profile 失敗時，是否仍會建立使用者資訊
     *
     * @return void
     */
    public function testHandlesLineProfileApiFailureGracefully(): void
    {
        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';

        $this->mock(LineBotService::class, function ($mock) use ($fakeLineId, $replyToken) {
            $mock->shouldReceive('fetchLineUserProfile')
                ->with($fakeLineId)
                ->andReturn([]); // 模擬失敗，回傳空陣列

            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '歡迎加入'); // fallback 訊息是否仍正確
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('follow', '', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);

        // 使用者應仍會被建立（name 為 null）
        $this->assertDatabaseHas('users', [
            'line_id' => $fakeLineId,
            'name' => null,
        ]);
    }



    /*****************************
     *       message events      *
     *****************************/


    /**
     * 新增賓果卡，建立暫存卡片
     *
     * @return void
     */
    public function testUserCanStartCreatingBingoCard(): void
    {
        Cache::spy();

        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';

        $this->mock(UserStatusService::class, function ($mock) use ($fakeLineId) {
            $mock->shouldReceive('setUserMode')
                ->with($fakeLineId, UserStatusService::MODE_CARD)
                ->andReturnNull();

            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_CARD);
        });

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '請輸入賓果卡的第一排數字');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('message', '新增賓果卡', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);

        // 驗證快取裡面是否存在該使用者的暫存卡片
        Cache::shouldHaveReceived('put')
            ->with("bingo_card_temp_{$fakeLineId}", \Mockery::type('array'), \Mockery::any())
            ->once();
    }

    /**
     * 顯示賓果卡（已存在賓果卡）
     *
     * @return void
     */
    public function testShowBingoCardsWhenCardsExist(): void
    {
        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';

        $this->mock(UserStatusService::class, function ($mock) use ($fakeLineId) {
            $mock->shouldReceive('setUserMode')
                ->with($fakeLineId, UserStatusService::MODE_CARD)
                ->andReturnNull();

            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_CARD);
        });

        // 模擬有賓果卡
        $this->mock(BingoCardRepository::class, function ($mock) use ($fakeLineId) {
            $mock->shouldReceive('getBingoCards')
                ->with($fakeLineId)
                ->andReturn(BingoCard::factory()->count(1)->make(['line_id' => $fakeLineId]));
        });

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '您目前有') || str_contains($message, '張賓果卡');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('message', '顯示所有賓果卡', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);
    }

    /**
     * 顯示賓果卡（不存在賓果卡）
     *
     * @return void
     */
    public function testShowBingoCardsWhenCardsNotExist(): void
    {
        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';

        $this->mock(UserStatusService::class, function ($mock) use ($fakeLineId) {
            $mock->shouldReceive('setUserMode')
                ->with($fakeLineId, UserStatusService::MODE_CARD)
                ->andReturnNull();

            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_CARD);
        });

        // 模擬沒有賓果卡
        $this->mock(BingoCardRepository::class, function ($mock) use ($fakeLineId) {
            $mock->shouldReceive('getBingoCards')
                ->with($fakeLineId)
                ->andReturn(collect());
        });

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '尚未建立任何賓果卡');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('message', '顯示所有賓果卡', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);
    }

    /**
     * 取消temp賓果卡
     *
     * @return void
     */
    public function testCancelTempBingoCard(): void
    {
        Cache::spy();

        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';

        $this->mock(UserStatusService::class, function ($mock) use ($fakeLineId) {
            $mock->shouldReceive('setUserMode')
                ->with($fakeLineId, UserStatusService::MODE_CARD)
                ->andReturnNull();

            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_CARD);
        });

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '賓果卡新增已取消');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('message', '取消', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);

        //驗證是否真的被呼叫
        Cache::shouldHaveReceived('forget')
            ->with("bingo_card_temp_{$fakeLineId}");
    }

    /**
     * 繼續編輯temp賓果卡（已存在temp賓果卡）
     *
     * @return void
     */
    public function testContinueTempBingoCardWhenTempCardExist(): void
    {
        Cache::spy();

        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';

        $this->mock(UserStatusService::class, function ($mock) use ($fakeLineId) {
            $mock->shouldReceive('setUserMode')
                ->with($fakeLineId, UserStatusService::MODE_CARD)
                ->andReturnNull();

            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_CARD);
        });

        Cache::shouldReceive('get')
            ->once()
            ->with("bingo_card_temp_{$fakeLineId}", [])
            ->andReturn([
                'current' => [
                    [1, 2, 3, 4, 5],
                    [6, 7, 8, 9, 10],
                    [11, 12, 0, 14, 15],
                    [16, 17, 18, 19, 20],
                ],
                'completed' => []
            ]);

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '您已輸入的號碼');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('message', '繼續', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);

        // 驗證是否真的被呼叫
        Cache::shouldHaveReceived('get')
            ->with("bingo_card_temp_{$fakeLineId}", []);
    }

    /**
     * 繼續編輯temp賓果卡（不存在temp賓果卡）
     *
     * @return void
     */
    public function testContinueTempBingoCardWhenTempCardNotExist(): void
    {
        Cache::spy();

        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';

        $this->mock(UserStatusService::class, function ($mock) use ($fakeLineId) {
            $mock->shouldReceive('setUserMode')
                ->with($fakeLineId, UserStatusService::MODE_CARD)
                ->andReturnNull();

            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_CARD);
        });

        Cache::shouldReceive('get')
            ->once()
            ->with("bingo_card_temp_{$fakeLineId}", [])
            ->andReturn([
                'current' => [],
                'completed' => []
            ]);

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '目前沒有未完成的賓果卡');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('message', '繼續', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);

        // 驗證是否真的被呼叫
        Cache::shouldHaveReceived('get')
            ->with("bingo_card_temp_{$fakeLineId}", []);
    }

    /**
     * 確定建立賓果卡
     *
     * @return void
     */
    public function testConfirmAndCreateCard(): void
    {
        Cache::spy();

        // 建立使用者
        $user = User::factory()->create();

        $fakeLineId = $user->line_id;
        $replyToken = 'testToken';
        $completedNumbers = [
            [
                [1, 2, 3, 4, 5],
                [6, 7, 8, 9, 10],
                [11, 12, 0, 14, 15],
                [16, 17, 18, 19, 20],
                [21, 22, 23, 24, 25]
            ]
        ];

        $this->mock(UserStatusService::class, function ($mock) use ($fakeLineId) {
            $mock->shouldReceive('setUserMode')
                ->with($fakeLineId, UserStatusService::MODE_CARD)
                ->andReturnNull();

            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_CARD);
        });

        Cache::shouldReceive('get')
            ->once()
            ->with("bingo_card_temp_{$fakeLineId}", [])
            ->andReturn([
                'current' => [],
                'completed' => $completedNumbers
            ]);

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '賓果卡已成功儲存');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('message', '確認', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);

        // 確認是否成功建立賓果卡
        $this->assertDatabaseHas('bingo_cards', ['line_id' => $fakeLineId]);

        //驗證是否真的被呼叫
        Cache::shouldHaveReceived('forget')
            ->with("bingo_card_temp_{$fakeLineId}");
    }

    /**
     * 刪除賓果卡
     *
     * @return void
     */
    public function testDeleteBingoCardWhenCardExist(): void
    {
        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';

        // 建立賓果卡
        $card = BingoCard::factory()->create(['line_id' => 'U1234567890',]);
        $fakeLineId = $card->line_id;
        $cardId = $card->id;

        $this->mock(UserStatusService::class, function ($mock) {
            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_CARD);
        });

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '成功刪除編號');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('message', "刪除編號 {$cardId}", $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);

        // 確認資料已成功刪除
        $this->assertDatabaseMissing('bingo_cards', [
            'id' => $cardId,
            'line_id' => $fakeLineId,
        ]);
    }

    /**
     * 刪除賓果卡（找不到此張賓果卡）
     *
     * @return void
     */
    public function testDeleteBingoCardWhenCardNotExist(): void
    {
        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';
        $cardId = 100000;

        $this->mock(UserStatusService::class, function ($mock) {
            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_CARD);
        });

        // 模擬沒有賓果卡
        $this->mock(BingoCardRepository::class, function ($mock) use ($fakeLineId, $cardId) {
            $mock->shouldReceive('getBingoCardById')
                ->with($fakeLineId, $cardId)
                ->andReturnNull();
        });

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '找不到編號');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('message', "刪除編號 {$cardId}", $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);
    }

    /**
     * 刪除賓果卡（模式有誤）
     *
     * @return void
     */
    public function testDeleteBingoCardWithWrongMode(): void
    {
        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';

        $this->mock(UserStatusService::class, function ($mock) {
            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_GAME);
        });

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '請先輸入「顯示所有賓果卡」');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('message', '刪除編號 1', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);
    }

    /**
     * 刪除賓果卡（指令錯誤）
     *
     * @return void
     */
    public function testDeleteBingoCardWithWrongCommand(): void
    {
        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';

        $this->mock(UserStatusService::class, function ($mock) {
            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_CARD);
        });

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '格式錯誤');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('message', '刪除編號 1*', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);
    }

    /**
     * 輸入賓果卡號碼
     *
     * @return void
     */
    public function testUserInputRowOfBingoCard(): void
    {
        Cache::spy();

        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';

        // 模擬 user 已處於卡片模式
        $this->mock(UserStatusService::class, function ($mock) use ($fakeLineId) {
            $mock->shouldReceive('setUserMode')
                ->with($fakeLineId, UserStatusService::MODE_CARD)
                ->andReturnNull();

            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_CARD);
        });

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '這張賓果卡數字已填寫完成');
                }))
                ->andReturnNull();
        });

        // 模擬尚未有快取或目前為空
        Cache::shouldReceive('get')
            ->once()
            ->with("bingo_card_temp_{$fakeLineId}", ['current' => [], 'completed' => []])
            ->andReturn(['current' => [[1, 2, 3, 4, 5], [6, 7, 8, 9, 10], [11, 12, 0, 13, 14], [15, 16, 17, 18, 19]], 'completed' => []]);

        $payload = $this->getMockPayload('message', '20 21 22 23 24', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);

        // 驗證快取裡面是否存在該使用者的暫存卡片
        Cache::shouldHaveReceived('put')
            ->with("bingo_card_temp_{$fakeLineId}", \Mockery::type('array'), \Mockery::any())
            ->twice();
    }

    /**
     * 開始兌獎（快取已存在）
     *
     * @return void
     */
    public function testStarGameWhenCacheExist(): void
    {
        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';

        $this->mock(UserStatusService::class, function ($mock) use ($fakeLineId) {
            $mock->shouldReceive('setUserMode')
                ->with($fakeLineId, UserStatusService::MODE_GAME)
                ->andReturnNull();

            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_GAME);
        });

        Cache::shouldReceive('has')
            ->once()
            ->with("bingo_draw_{$fakeLineId}")
            ->andReturn(true);

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '請輸入開獎號碼！');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('message', '開始兌獎', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);
    }

    /**
     * 開始兌獎（快取不存在）
     *
     * @return void
     */
    public function testStarGameWhenCacheNotExist(): void
    {
        Cache::spy();

        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';

        $this->mock(UserStatusService::class, function ($mock) use ($fakeLineId) {
            $mock->shouldReceive('setUserMode')
                ->with($fakeLineId, UserStatusService::MODE_GAME)
                ->andReturnNull();

            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_GAME);
        });

        Cache::shouldReceive('has')
            ->once()
            ->with("bingo_draw_{$fakeLineId}")
            ->andReturn(false);

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '已進入兌獎模式');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('message', '開始兌獎', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);

        // 驗證快取
        Cache::shouldHaveReceived('put')
            ->with("bingo_draw_{$fakeLineId}", \Mockery::type('array'), \Mockery::any())
            ->once();
    }

    /**
     * 顯示所有開獎號碼
     *
     * @return void
     */
    public function testShowDrawNumbersWhenNumberExist(): void
    {
        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';

        $this->mock(UserStatusService::class, function ($mock) use ($fakeLineId) {
            $mock->shouldReceive('setUserMode')
                ->with($fakeLineId, UserStatusService::MODE_GAME)
                ->andReturnNull();

            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_GAME);
        });

        Cache::shouldReceive('get')
            ->once()
            ->with("bingo_draw_{$fakeLineId}", ['drawn' => []])
            ->andReturn(['drawn' => [1, 2, 3, 4, 5]]);

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '已開獎號碼共有');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('message', '顯示所有開獎號碼', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);
    }

    /**
     * 顯示所有開獎號碼（沒有已開獎號碼)
     *
     * @return void
     */
    public function testShowDrawNumbersWhenNumberNotExist(): void
    {
        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';

        $this->mock(UserStatusService::class, function ($mock) use ($fakeLineId) {
            $mock->shouldReceive('setUserMode')
                ->with($fakeLineId, UserStatusService::MODE_GAME)
                ->andReturnNull();

            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_GAME);
        });

        Cache::shouldReceive('get')
            ->once()
            ->with("bingo_draw_{$fakeLineId}", ['drawn' => []])
            ->andReturn(['drawn' => []]);

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '目前尚無已開獎號碼');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('message', '顯示所有開獎號碼', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);
    }

    /**
     * 清除中獎號碼（快取已存在）
     *
     * @return void
     */
    public function testCancelDrawNumbersWhenCacheExist(): void
    {
        Cache::spy();

        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';

        $this->mock(UserStatusService::class, function ($mock) use ($fakeLineId) {
            $mock->shouldReceive('setUserMode')
                ->with($fakeLineId, UserStatusService::MODE_GAME)
                ->andReturnNull();

            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_GAME);
        });

        Cache::shouldReceive('has')
            ->once()
            ->with("bingo_draw_{$fakeLineId}")
            ->andReturn(true);

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '已取消目前所有開獎號碼');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('message', '取消兌獎', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);

        //驗證是否真的被呼叫
        Cache::shouldHaveReceived('forget')
            ->with("bingo_draw_{$fakeLineId}");
    }

    /**
     * 清除中獎號碼（快取不存在）
     *
     * @return void
     */
    public function testCancelDrawNumbersWhenCacheNotExist(): void
    {
        Cache::spy();

        $fakeLineId = 'U1234567890';
        $replyToken = 'testToken';

        $this->mock(UserStatusService::class, function ($mock) use ($fakeLineId) {
            $mock->shouldReceive('setUserMode')
                ->with($fakeLineId, UserStatusService::MODE_GAME)
                ->andReturnNull();

            $mock->shouldReceive('getUserMode')
                ->andReturn(UserStatusService::MODE_GAME);
        });

        Cache::shouldReceive('has')
            ->once()
            ->with("bingo_draw_{$fakeLineId}")
            ->andReturn(false);

        $this->mock(LineBotService::class, function ($mock) use ($replyToken) {
            $mock->shouldReceive('replyMessage')
                ->once()
                ->with($replyToken, \Mockery::on(function ($message) {
                    return str_contains($message, '目前沒有任何開獎號碼可以取消');
                }))
                ->andReturnNull();
        });

        $payload = $this->getMockPayload('message', '取消兌獎', $fakeLineId, $replyToken);
        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);
    }

    /**
     * 模擬 payload
     *
     * @param string $type
     * @param string $text
     * @param string $lineId
     * @param string $replyToken
     * @return array
     */
    private function getMockPayload(string $type, string $text, string $lineId, string $replyToken): array
    {
        return [
            'events' => [[
                'type' => $type,
                'message' => ['type' => 'text', 'text' => $text],
                'source' => ['userId' => $lineId],
                'replyToken' => $replyToken,
            ]]
        ];
    }
}
