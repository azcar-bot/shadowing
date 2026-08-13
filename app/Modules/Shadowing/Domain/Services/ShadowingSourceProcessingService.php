<?php

namespace App\Modules\Shadowing\Domain\Services;

use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSource;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSourceChunk;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ShadowingSourceProcessingService
{
    public function __construct(
        protected YouTubeUrlNormalizerService $urlNormalizer,
        protected CaptionNormalizationService $captionService,
        protected NaturalSpeakingChunkEngineService $chunkEngine
    ) {}

    /**
     * Deduplicated processing of YouTube video source.
     * If source already exists for the given version, returns it without reprocessing.
     */
    public function processVideoSource(string $youtubeInput, ?string $processingVersion = null): ShadowingSource
    {
        $videoId = $this->urlNormalizer->extractVideoId($youtubeInput);
        $version = $processingVersion ?? config('shadowing.processing_version', 'natural-chunk-v1');

        $forbiddenSources = ['ai_generated_fallback', 'mock', 'demo', 'sample', 'fake', 'prototype'];

        // 1. DEDUPLICATION LOOKUP: Check if already processed with valid transcript source
        $existing = ShadowingSource::where('youtube_video_id', $videoId)
            ->where('processing_version', $version)
            ->where('status', 'completed')
            ->whereNotNull('transcript_source')
            ->whereNotIn('transcript_source', $forbiddenSources)
            ->first();

        if ($existing) {
            return $existing;
        }

        // 2. CONCURRENT LOCKING: Prevent duplicate jobs for same video ID
        $lockKey = "shadowing_process_{$videoId}_{$version}";
        $lock = Cache::lock($lockKey, 120);

        if (! $lock->get()) {
            // Wait up to 10 seconds for concurrent job to finish
            for ($i = 0; $i < 20; $i++) {
                usleep(500000);
                $existing = ShadowingSource::where('youtube_video_id', $videoId)
                    ->where('processing_version', $version)
                    ->where('status', 'completed')
                    ->whereNotNull('transcript_source')
                    ->whereNotIn('transcript_source', $forbiddenSources)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }
            throw new RuntimeException("Process timeout waiting for concurrent video processing on ID: {$videoId}");
        }

        try {
            // Re-check inside lock
            $existing = ShadowingSource::where('youtube_video_id', $videoId)
                ->where('processing_version', $version)
                ->where('status', 'completed')
                ->whereNotNull('transcript_source')
                ->whereNotIn('transcript_source', $forbiddenSources)
                ->first();

            if ($existing) {
                return $existing;
            }

            // 3. FETCH CAPTIONS / STT FALLBACK
            $captionResult = $this->captionService->fetchCaptionsWithFallback($videoId);
            $captionUnits = $captionResult['items'] ?? [];
            $transcriptSource = $captionResult['source'] ?? 'youtube_manual_caption';
            $videoTitle = $captionResult['title'] ?? "YouTube Video ({$videoId})";
            $durationSeconds = (int) ($captionResult['duration_seconds'] ?? 0);

            if (empty($captionUnits)) {
                throw new RuntimeException("No speech transcript or audio available for YouTube video ID: {$videoId}");
            }

            // 4. SPEECH CONTENT FILTER
            $speechUnits = array_values(array_filter($captionUnits, function ($item) {
                $text = trim($item['text'] ?? '');
                if (empty($text)) return false;
                // Ignore non-speech markers like [Music], [Applause], [Laughter], [Silence], ♪
                if (preg_match('/^\[(music|applause|laughter|laughter|noise|silence|dog barking)\]$/i', $text)) {
                    return false;
                }
                if (preg_match('/^[\s♪♫\*\(\)]+$/u', $text)) {
                    return false;
                }
                return true;
            }));

            if (empty($speechUnits)) {
                throw new RuntimeException("No human linguistic speech found in video ID: {$videoId}");
            }

            // 5. NATURAL SPEAKING CHUNK ENGINE
            $chunks = $this->chunkEngine->generateSpeechChunks($speechUnits);

            if (empty($chunks)) {
                throw new RuntimeException("Could not generate valid Shadowing chunks for video ID: {$videoId}");
            }

            // 6. PERSIST SHARED PROCESSED SOURCE & CHUNKS
            return DB::transaction(function () use ($videoId, $videoTitle, $durationSeconds, $transcriptSource, $version, $chunks, $captionResult) {
                $source = ShadowingSource::create([
                    'youtube_video_id' => $videoId,
                    'title' => $videoTitle,
                    'duration_seconds' => $durationSeconds,
                    'transcript_source' => $transcriptSource,
                    'processing_version' => $version,
                    'status' => 'completed',
                    'raw_payload' => $captionResult,
                ]);

                foreach ($chunks as $idx => $chunk) {
                    ShadowingSourceChunk::create([
                        'shadowing_source_id' => $source->id,
                        'chunk_index' => $idx + 1,
                        'start_ms' => $chunk['start_ms'],
                        'end_ms' => $chunk['end_ms'],
                        'transcript' => $chunk['transcript'],
                        'translation_vi' => $chunk['translation_vi'] ?? null,
                        'ipa' => $chunk['ipa'] ?? null,
                        'speaker' => $chunk['speaker'] ?? null,
                        'quality_score' => $chunk['quality_score'] ?? 1.0,
                        'needs_review' => $chunk['needs_review'] ?? false,
                        'reason' => $chunk['reason'] ?? 'NATURAL_BOUNDARY',
                    ]);
                }

                return $source;
            });
        } finally {
            $lock->release();
        }
    }
}
