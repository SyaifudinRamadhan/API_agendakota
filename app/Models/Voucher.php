<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voucher extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'ticket_id',
        'name',
        'discount',
        'quantity',
        'start',
        'end',
    ];

    public function ticket(): BelongsTo{
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }
}
