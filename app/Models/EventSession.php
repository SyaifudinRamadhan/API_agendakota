<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventSession extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'event_id','start_rundown_id','end_rundown_id','title','desc',
        'link','deleted'
    ];

    public function event(): BelongsTo{
        return $this->belongsTo(Event::class, 'event_id')->where('deleted', 0);
    }

    public function startRundown(): BelongsTo{
        return $this->belongsTo(Rundown::class, 'start_rundown_id');
    }

    public function endRundown(): BelongsTo{
        return $this->belongsTo(Rundown::class, 'end_rundown_id');
    }

    public function tickets(): HasMany{
        return $this->hasMany(Ticket::class, 'session_id')->where('deleted', 0);
    }
}
