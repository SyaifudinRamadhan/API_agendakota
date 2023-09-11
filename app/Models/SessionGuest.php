<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionGuest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'rundown_id',
        'guest_id'
    ];

    public function rundown(): BelongsTo{
        return $this->belongsTo(Rundown::class, 'rundown_id');
    }

    public function guest(): BelongsTo{
        return $this->belongsTo(Guest::class, 'guest_id');
    }
}
