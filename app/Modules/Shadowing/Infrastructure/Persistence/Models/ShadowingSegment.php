<?php

namespace App\Modules\Shadowing\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ShadowingSegment extends Model
{
    use HasFactory;

    protected $table = 'shadowing_segments';

    protected $fillable = [
        'public_id',
        'shadowing_lesson_id',
        'segment_index',
        'start_ms',
        'end_ms',
        'transcript',
        'translation_vi',
        'ipa',
        'speaker',
        'difficulty',
        'loop_default',
        'needs_review',
    ];

    protected $casts = [
        'segment_index' => 'integer',
        'start_ms' => 'integer',
        'end_ms' => 'integer',
        'loop_default' => 'integer',
        'needs_review' => 'boolean',
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

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(ShadowingLesson::class, 'shadowing_lesson_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(UserShadowingAttempt::class, 'shadowing_segment_id');
    }
}
