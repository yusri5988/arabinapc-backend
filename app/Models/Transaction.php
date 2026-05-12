<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'payment_to',
        'details',
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

    protected function receiptUrl(): Attribute
    {
        return Attribute::make(
            get: function (?string $value) {
                if (empty($value)) {
                    return $value;
                }

                $path = str_replace('/storage/receipts/', '/receipts/', $value);

                if (str_starts_with($path, 'http')) {
                    return $path;
                }

                return rtrim(config('app.api_url', config('app.url')), '/') . $path;
            },
        );
    }
}
