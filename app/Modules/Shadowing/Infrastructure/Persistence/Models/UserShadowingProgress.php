<?php

namespace App\Modules\Shadowing\Infrastructure\Persistence\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserShadowingProgress extends Model
{
    use HasFactory;

    protected $table = 'user_shadowing_progress';

    protected $fillable = [
        'user_id',
        'shadowing_segment_id',
        'best_score',
        'practice_count',
        'is_completed',
        'last_practiced_at',
        'mastery_status',
    ];

    protected $casts = [
        'practice_count' => 'integer',
        'is_completed' => 'boolean',
        'last_practiced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(ShadowingSegment::class, 'shadowing_segment_id');
    }
}
