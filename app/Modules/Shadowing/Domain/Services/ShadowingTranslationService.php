<?php

namespace App\Modules\Shadowing\Domain\Services;

use App\Modules\Shadowing\Domain\Contracts\TranslationProviderContract;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingLesson;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSegment;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSource;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSourceChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Exception;

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
     * Idempotent: Does not call provider if already completed for the given version unless $force = true.
     *
     * @throws InvalidArgumentException if source has invalid/forbidden transcript_source.
     */
    public function translateSource(ShadowingSource $source, string $targetVersion = 'vi-v1', bool $force = false): bool
    {
        // 1. TRANSCRIPT SOURCE VALIDATION GATE
        if (empty($source->transcript_source) || in_array(strtolower($source->transcript_source), $this->forbiddenSources, true)) {
            throw new InvalidArgumentException("Cannot translate ShadowingSource ID {$source->id} with forbidden transcript_source ('{$source->transcript_source}').");
        }

        if ($source->status !== 'completed') {
            throw new InvalidArgumentException("Cannot translate incomplete ShadowingSource ID {$source->id} (status: {$source->status}).");
        }

        // 2. IDEMPOTENCY GATE: Check if already translated for target version
        if (! $force && $source->translation_status === 'completed' && $source->translation_version === $targetVersion) {
            Log::info("ShadowingSource ID {$source->id} already translated for version '{$targetVersion}'. Skipping provider call.");
            return true;
        }

        $chunks = $source->chunks()->orderBy('chunk_index', 'asc')->get();
        if ($chunks->isEmpty()) {
            throw new InvalidArgumentException("ShadowingSource ID {$source->id} has 0 chunks to translate.");
        }

        // 3. NEIGHBORING CHUNK CONTEXT BUILDER (previous + current + next)
        $items = [];
        $count = count($chunks);
        foreach ($chunks as $i => $chunk) {
            $items[] = [
                'chunk_index'     => $chunk->chunk_index,
                'transcript'      => $chunk->transcript,
                'prev_transcript' => $i > 0 ? $chunks[$i - 1]->transcript : null,
                'next_transcript' => $i < $count - 1 ? $chunks[$i + 1]->transcript : null,
            ];
        }

        // 4. PROVIDER EXECUTION WITH ERROR HANDLER
        try {
            $translatedResults = $this->provider->translateChunks($items);

            // Validate structured output count matches
            $resultMap = [];
            foreach ($translatedResults as $res) {
                if (isset($res['chunk_index']) && isset($res['translation_vi'])) {
                    $resultMap[$res['chunk_index']] = $res['translation_vi'];
                }
            }

            foreach ($chunks as $chunk) {
                if (! isset($resultMap[$chunk->chunk_index]) || empty(trim($resultMap[$chunk->chunk_index]))) {
                    throw new Exception("Missing translation for chunk_index {$chunk->chunk_index} from provider output.");
                }
            }

            // 5. ATOMIC PERSISTENCE (Source Chunks + Metadata + Sync to Lessons)
            DB::transaction(function () use ($source, $chunks, $resultMap, $targetVersion) {
                // Update source chunks
                foreach ($chunks as $chunk) {
                    $chunk->update([
                        'translation_vi' => $resultMap[$chunk->chunk_index],
                    ]);
                }

                // Update source translation metadata
                $source->update([
                    'translation_status'   => 'completed',
                    'translation_provider' => $this->provider->getProviderName(),
                    'translation_model'    => $this->provider->getModelName(),
                    'translation_version'  => $targetVersion,
                    'translated_at'        => now(),
                    'error_message'        => null,
                ]);

                // Sync translations to existing linked lesson segments
                $this->syncTranslationsToLessons($source);
            });

            Log::info("Successfully translated ShadowingSource ID {$source->id} with provider {$this->provider->getProviderName()}.");
            return true;

        } catch (Exception $e) {
            Log::error("Translation failed for ShadowingSource ID {$source->id}: {$e->getMessage()}");

            // Provider failure marks status = failed, English lesson remains intact
            $source->update([
                'translation_status' => 'failed',
                'error_message'      => $e->getMessage(),
            ]);

            return false;
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
