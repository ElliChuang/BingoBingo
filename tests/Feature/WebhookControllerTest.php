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
