<?php

namespace App\Modules\Shadowing\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ShadowingSourceChunk extends Model
{
    use HasFactory;

    protected $table = 'shadowing_source_chunks';

    protected $fillable = [
        'public_id',
        'shadowing_source_id',
        'chunk_index',
        'start_ms',
        'end_ms',
        'transcript',
        'translation_vi',
        'ipa',
        'speaker',
        'quality_score',
        'needs_review',
        'reason',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'start_ms' => 'integer',
        'end_ms' => 'integer',
        'quality_score' => 'float',
        'needs_review' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (ShadowingSourceChunk $model): void {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ShadowingSource::class, 'shadowing_source_id');
    }
}
