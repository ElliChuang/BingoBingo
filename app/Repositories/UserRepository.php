<?php

namespace App\Repositories;

use App\Models\User;

class UserRepository
{
    public function findOrCreateUser($lineId, $name)
    {
        return User::firstOrCreate(['line_id' => $lineId, 'name' => $name]);
    }

    public function getUserByLineId($lineId)
    {
        return User::where('line_id', $lineId)->first();
    }
}
