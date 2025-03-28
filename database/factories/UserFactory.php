<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'line_id' => 'U' . Str::random(10), // 模擬 LINE userId 格式
            'name' => $this->faker->name,
        ];
    }
}
