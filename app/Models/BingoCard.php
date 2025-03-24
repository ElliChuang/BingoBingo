<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BingoCard extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'numbers'];

    protected $casts = [
        'numbers' => 'array', // 將 JSON 轉換成陣列
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
