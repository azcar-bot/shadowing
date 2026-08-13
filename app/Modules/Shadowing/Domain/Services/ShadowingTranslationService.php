<?php

namespace App\Modules\Shadowing\Domain\Services;

use App\Modules\Shadowing\Domain\Contracts\TranslationProviderContract;
use App\Modules\Shadowing\Domain\Exceptions\TranslationProviderPermanentException;
use App\Modules\Shadowing\Domain\Exceptions\TranslationProviderTransientException;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingLesson;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSegment;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSource;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSourceChunk;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ShadowingTranslationService
{
    protected array $forbiddenSources = ['ai_generated_fallback', 'mock', 'demo', 'sample', 'fake', 'prototype'];

    public function __construct(
        protected TranslationProviderContract $provider
    ) {}

    public function getProvider(): TranslationProviderContract
    {
        return $this->provider;
    }

    /**
     * Translates a ShadowingSource into Vietnamese and persists translations.
     * Idempotent: Does not call provider if already completed for the given version and all chunks present unless $force = true.
     *
     * @throws InvalidArgumentException | TranslationProviderPermanentException for non-retryable errors.
     * @throws TranslationProviderTransientException for retryable transient errors (allows Queue retry).
     */
    public function translateSource(ShadowingSource $source, ?string $targetVersion = null, bool $force = false): bool
    {
        $targetVersion = $targetVersion ?? config('shadowing.translation.version', 'vi-v1');
        $batchSize = (int) config('shadowing.translation.batch_size', 25);

        // 1. PERMANENT VALIDATION GATES (Non-retryable)
        if (empty($source->transcript_source) || in_array(strtolower($source->transcript_source), $this->forbiddenSources, true)) {
            $err = "Cannot translate ShadowingSource ID {$source->id} with forbidden transcript_source ('{$source->transcript_source}').";
            $source->update([
                'translation_status' => 'failed',
                'translation_error'  => $err,
            ]);
            throw new InvalidArgumentException($err);
        }

        if ($source->status !== 'completed') {
            $err = "Cannot translate incomplete ShadowingSource ID {$source->id} (status: {$source->status}).";
            $source->update([
                'translation_status' => 'failed',
                'translation_error'  => $err,
            ]);
            throw new InvalidArgumentException($err);
        }

        $chunks = $source->chunks()->orderBy('chunk_index', 'asc')->get();
        if ($chunks->isEmpty()) {
            $err = "ShadowingSource ID {$source->id} has 0 chunks to translate.";
            $source->update([
                'translation_status' => 'failed',
                'translation_error'  => $err,
            ]);
            throw new InvalidArgumentException($err);
        }

        // 2. STRICT IDEMPOTENCY GATE:
        // Skip ONLY IF status === completed AND version matches AND EVERY chunk has non-empty translation_vi
        $hasMissingChunkTranslation = $chunks->contains(function (ShadowingSourceChunk $c) {
            return empty($c->translation_vi) || trim($c->translation_vi) === '';
        });

        if (! $force && $source->translation_status === 'completed' && $source->translation_version === $targetVersion && ! $hasMissingChunkTranslation) {
            Log::info("ShadowingSource ID {$source->id} already translated for version '{$targetVersion}' with all chunks populated. Skipping provider call.");
            return true;
        }

        // 3. CONCURRENCY LOCK (Job timeout is 120s -> Lock TTL = 240s safety margin)
        $lockKey = "shadowing_translation_lock_{$source->id}";
        $lock = Cache::lock($lockKey, 240);

        if (! $lock->get()) {
            Log::warning("ShadowingSource ID {$source->id} translation is currently locked by another worker.");
            throw new TranslationProviderTransientException("Translation lock contention for source ID {$source->id}. Another worker is processing this source.");
        }

        try {
            // 4. REAL STATE MACHINE: Move state to 'processing'
            $source->update([
                'translation_status' => 'processing',
                'translation_error'  => null,
            ]);

            // 5. BATCHING & NEIGHBORING CHUNK CONTEXT BUILDER
            $allChunkMap = [];
            $chunkList = $chunks->values();
            $totalChunks = $chunkList->count();

            $chunkBatches = $chunkList->chunk($batchSize);

            foreach ($chunkBatches as $batch) {
                $batchItems = [];
                foreach ($batch as $chunk) {
                    $globalIdx = $chunkList->search(fn ($c) => $c->id === $chunk->id);

                    $prevTranscript = ($globalIdx !== false && $globalIdx > 0) ? $chunkList[$globalIdx - 1]->transcript : null;
                    $nextTranscript = ($globalIdx !== false && $globalIdx < $totalChunks - 1) ? $chunkList[$globalIdx + 1]->transcript : null;

                    $batchItems[] = [
                        'chunk_index'     => $chunk->chunk_index,
                        'transcript'      => $chunk->transcript,
                        'prev_transcript' => $prevTranscript,
                        'next_transcript' => $nextTranscript,
                    ];
                }

                $translatedBatch = $this->provider->translateChunks($batchItems);

                foreach ($translatedBatch as $res) {
                    if (isset($res['chunk_index']) && isset($res['translation_vi'])) {
                        $allChunkMap[$res['chunk_index']] = trim((string) $res['translation_vi']);
                    }
                }
            }

            // Validate that every chunk received a valid translation
            foreach ($chunks as $chunk) {
                if (! isset($allChunkMap[$chunk->chunk_index]) || empty(trim($allChunkMap[$chunk->chunk_index]))) {
                    throw new TranslationProviderPermanentException("Missing translation for chunk_index {$chunk->chunk_index} in provider batch output.");
                }
            }

            // 6. ATOMIC PERSISTENCE & LESSON SYNC
            DB::transaction(function () use ($source, $chunks, $allChunkMap, $targetVersion) {
                foreach ($chunks as $chunk) {
                    $chunk->update([
                        'translation_vi' => $allChunkMap[$chunk->chunk_index],
                    ]);
                }

                $source->update([
                    'translation_status'   => 'completed',
                    'translation_provider' => $this->provider->getProviderName(),
                    'translation_model'    => $this->provider->getModelName(),
                    'translation_version'  => $targetVersion,
                    'translated_at'        => now(),
                    'translation_error'    => null,
                ]);

                $this->syncTranslationsToLessons($source);
            });

            Log::info("Successfully translated ShadowingSource ID {$source->id} with provider {$this->provider->getProviderName()}.");
            return true;

        } catch (Throwable $e) {
            Log::error("Translation failed for ShadowingSource ID {$source->id}: {$e->getMessage()}");

            $source->update([
                'translation_status' => 'failed',
                'translation_error'  => $e->getMessage(),
            ]);

            // Re-throw transient provider exceptions so Laravel Queue retries up to $tries
            if ($e instanceof TranslationProviderTransientException) {
                throw $e;
            }

            // Permanent errors (401, 403, missing key, invalid source) do not re-throw
            return false;

        } finally {
            $lock->release();
        }
    }

    /**
     * Syncs Vietnamese translations from source chunks onto linked lesson segments.
     */
    public function syncTranslationsToLessons(ShadowingSource $source): void
    {
        $chunkMap = $source->chunks()->pluck('translation_vi', 'chunk_index')->toArray();
        if (empty($chunkMap)) {
            return;
        }

        $lessons = ShadowingLesson::where('source_id', $source->id)->get();
        foreach ($lessons as $lesson) {
            $segments = ShadowingSegment::where('shadowing_lesson_id', $lesson->id)->get();
            foreach ($segments as $segment) {
                if (isset($chunkMap[$segment->segment_index])) {
                    $segment->update([
                        'translation_vi' => $chunkMap[$segment->segment_index],
                    ]);
                }
            }
        }
    }
}
