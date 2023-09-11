<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'session_id',
        'event_id',
        'name',
        'desc',
        'type_price',
        'price',
        'quantity',
        'start_date',
        'end_date',
        'deleted',
    ];

    public function session(): BelongsTo{
        return $this->belongsTo(EventSession::class, 'session_id')->where('deleted', 0);
    }

    public function purchases(): HasMany{
        return $this->hasMany(Purchase::class, 'ticket_id');
    }

    public function vouchers(): HasMany{
        return $this->hasMany(Voucher::class, 'ticket_id');
    }
}
