<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use HasFactory;

    protected $fillable = ['line_id', 'name'];

    public function bingoCards()
    {
        return $this->hasMany(BingoCard::class);
    }
}
