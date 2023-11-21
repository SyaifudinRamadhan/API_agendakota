<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AvailableDayTicketSell extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        "event_id",
        "day",
        "max_limit_time"
    ];
}
