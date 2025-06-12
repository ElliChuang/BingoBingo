<?php

namespace App\Repositories;

use App\Models\BingoCard;
use Illuminate\Support\Collection;

class BingoCardRepository
{
    public function countUserCards(string $lineId): int
    {
        return BingoCard::where('line_id', $lineId)->count();
    }

    public function createBingoCard(string $lineId, array $numbers)
    {
        return BingoCard::create([
            'line_id' => $lineId,
            'numbers' => $numbers,
        ]);
    }

    public function getBingoCards(string $lineId): Collection
    {
        return BingoCard::select('id', 'numbers')->where('line_id', $lineId)->get();
    }

    public function getBingoCardById(string $lineId, int $id): ?BingoCard
    {
        return BingoCard::where('line_id', $lineId)->where('id', $id)->first();
    }
}
