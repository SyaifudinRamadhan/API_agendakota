<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rundown extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'event_id',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'duration',
        'name',
        'desc',
        'deleted',
    ];

    public function event(): BelongsTo{
        return $this->belongsTo(Event::class, 'event_id')->where('deleted', 0);
    }
    
    public function sessionsAsStart(): HasMany{
        return $this->hasMany(EventSession::class, 'start_rundown_id')->where('deleted', 0);
    }

    public function sessionsAsEnd(): HasMany{
        return $this->hasMany(EventSession::class, 'end_rundown_id')->where('deleted', 0);
    }

    public function guests(): HasMany{
        return $this->hasMany(SessionGuest::class, 'rundown_id');
    }
}
