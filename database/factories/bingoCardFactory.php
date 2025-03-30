<?php

namespace Database\Factories;

use App\Models\BingoCard;
use Illuminate\Database\Eloquent\Factories\Factory;

class BingoCardFactory extends Factory
{
    protected $model = BingoCard::class;

    public function definition(): array
    {
        return [
            'line_id' => 'U' . $this->faker->unique()->regexify('[A-Z0-9]{10}'),
            'numbers' => [
                [1, 2, 3, 4, 5],
                [6, 7, 8, 9, 10],
                [11, 12, 0, 14, 15],
                [16, 17, 18, 19, 20],
                [21, 22, 23, 24, 25]
            ]
        ];
    }

    /**
     * 套用指定 user 的 line_id 到卡片上
     *
     * @param \App\Models\User $user
     * @return \Database\Factories\BingoCardFactory
     */
    public function forUser($user)
    {
        return $this->state(function () use ($user) {
            return [
                'line_id' => $user->line_id,
            ];
        });
    }
}
