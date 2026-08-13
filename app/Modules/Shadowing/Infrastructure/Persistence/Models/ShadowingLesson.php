<?php

namespace App\Modules\Shadowing\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ShadowingLesson extends Model
{
    use HasFactory;

    protected $table = 'shadowing_lessons';

    protected $fillable = [
        'public_id',
        'source_id',
        'user_id',
        'code',
        'title',
        'description',
        'level',
        'topic',
        'audio_url',
        'youtube_video_id',
        'media_type',
        'total_segments',
        'status',
        'visibility',
        'is_official',
        'raw_transcript',
        'canonical_transcript',
        'transcript_source',
        'content_version',
        'checksum',
        'published_at',
    ];

    protected $casts = [
        'total_segments' => 'integer',
        'content_version' => 'integer',
        'is_official' => 'boolean',
        'published_at' => 'datetime',
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

    public function source(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ShadowingSource::class, 'source_id');
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Identity\Infrastructure\Persistence\Models\User::class, 'user_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(ShadowingSegment::class, 'shadowing_lesson_id')->orderBy('segment_index', 'asc');
    }

    public function scopeOfficial($query)
    {
        return $query->where('visibility', 'official');
    }

    public function scopePrivateForUser($query, int $userId)
    {
        return $query->where('visibility', 'private')->where('user_id', $userId);
    }
}
