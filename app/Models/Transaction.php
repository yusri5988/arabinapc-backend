<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'description',
        'site_id',
        'receipt_url',
        'date',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
