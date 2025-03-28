<?php

namespace Tests\Feature;

use App\Models\User;
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

        $payload = [
            'events' => [
                [
                    'type' => 'follow',
                    'source' => ['userId' => $fakeLineId],
                    'replyToken' => $replyToken
                ]
            ]
        ];

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

        $payload = [
            'events' => [
                [
                    'type' => 'follow',
                    'source' => ['userId' => $existingUser->line_id],
                    'replyToken' => $replyToken,
                ]
            ]
        ];

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

        $payload = [
            'events' => [[
                'type' => 'follow',
                'source' => ['userId' => $fakeLineId],
                'replyToken' => $replyToken,
            ]]
        ];

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

        $payload = [
            'events' => [[
                'type' => 'follow',
                'source' => ['userId' => $fakeLineId],
                'replyToken' => $replyToken,
            ]]
        ];

        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);

        // 使用者應仍會被建立（name 為 null）
        $this->assertDatabaseHas('users', [
            'line_id' => $fakeLineId,
            'name' => null,
        ]);
    }
}
