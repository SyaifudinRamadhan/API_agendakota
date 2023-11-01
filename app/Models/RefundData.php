<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundData extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id',
        'user_id',
        'message',
        'account_number',
        'phone_number',
        'nominal',
        'ticket_name',
        'event_name'
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
