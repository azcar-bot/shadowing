<?php

namespace App\Modules\Shadowing\Infrastructure\Persistence\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ShadowingRecording extends Model
{
    protected $table = 'shadowing_recordings';

    protected $fillable = [
        'public_id',
        'user_id',
        'shadowing_lesson_id',
        'shadowing_segment_id',
        'disk',
        'object_key',
        'mime_type',
        'size_bytes',
        'duration_ms',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
            if (empty($model->disk)) {
                $model->disk = 'media';
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(ShadowingLesson::class, 'shadowing_lesson_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(ShadowingSegment::class, 'shadowing_segment_id');
    }
}
