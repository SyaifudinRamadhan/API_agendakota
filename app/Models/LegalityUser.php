<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalityUser extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        "user_id",
        "name",
        "personal_tax_id_number",
        "tax_image",
        "nic",
        "nic_images"
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
