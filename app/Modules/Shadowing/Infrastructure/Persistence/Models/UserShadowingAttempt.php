<?php

namespace App\Modules\Shadowing\Infrastructure\Persistence\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserShadowingAttempt extends Model
{
    use HasFactory;

    protected $table = 'user_shadowing_attempts';

    protected $fillable = [
        'public_id',
        'user_id',
        'shadowing_segment_id',
        'mode',
        'audio_recording_url',
        'duration_ms',
        'score',
    ];

    protected $casts = [
        'duration_ms' => 'integer',
        'score' => 'float',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(ShadowingSegment::class, 'shadowing_segment_id');
    }
}
