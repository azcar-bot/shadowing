<?php

namespace App\Modules\Shadowing\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ShadowingSource extends Model
{
    use HasFactory;

    protected $table = 'shadowing_sources';

    protected $fillable = [
        'public_id',
        'youtube_video_id',
        'title',
        'duration_seconds',
        'transcript_source',
        'processing_version',
        'status',
        'raw_payload',
        'error_message',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'duration_seconds' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (ShadowingSource $model): void {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
        });
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(ShadowingSourceChunk::class, 'shadowing_source_id')->orderBy('chunk_index', 'asc');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(ShadowingLesson::class, 'source_id');
    }
}
