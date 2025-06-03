<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    /** @use HasFactory<\Database\Factories\BookingFactory> */
    use HasFactory;

    protected $fillable = [
        //"user_id",
        "resource_id",
        "start_time",
        "end_time",
        "status",
        "purpose"
    ];
    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];
    public function user(): BelongsTo{
        return $this -> belongsTo(User::class);
    }
    public function resource(): BelongsTo{
        return $this -> belongsTo(Resource::class);
    }
    
}
