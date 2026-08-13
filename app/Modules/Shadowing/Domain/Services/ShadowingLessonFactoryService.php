<?php

namespace App\Modules\Shadowing\Domain\Services;

use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingLesson;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSegment;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShadowingLessonFactoryService
{
    /**
     * Creates an Official Library Lesson (Admin Flow).
     */
    public function createOfficialLesson(ShadowingSource $source, array $options = []): ShadowingLesson
    {
        // TRANSCRIPT SOURCE VALIDATION GATE
        $this->validateSource($source);

        $code = $options['code'] ?? 'shadowing_official_' . strtolower($source->youtube_video_id) . '_' . Str::random(4);
        $title = $options['title'] ?? $source->title;
        $level = $options['level'] ?? 'B2';
        $topic = $options['topic'] ?? 'General';

        // Check if official lesson for code already exists
        $existing = ShadowingLesson::where('code', $code)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($source, $code, $title, $level, $topic) {
            $hasReviewChunks = $source->chunks()->where('needs_review', true)->exists();
            $status = $hasReviewChunks ? 'review_required' : 'published';

            $lesson = ShadowingLesson::create([
                'source_id' => $source->id,
                'user_id' => null,
                'code' => $code,
                'title' => $title,
                'description' => "Official Shadowing lesson for YouTube video {$source->youtube_video_id}",
                'level' => $level,
                'topic' => $topic,
                'youtube_video_id' => $source->youtube_video_id,
                'media_type' => 'youtube',
                'total_segments' => $source->chunks()->count(),
                'status' => $status,
                'visibility' => 'official',
                'is_official' => true,
                'transcript_source' => $source->transcript_source,
                'published_at' => $status === 'published' ? now() : null,
            ]);

            foreach ($source->chunks as $chunk) {
                ShadowingSegment::create([
                    'shadowing_lesson_id' => $lesson->id,
                    'segment_index' => $chunk->chunk_index,
                    'start_ms' => $chunk->start_ms,
                    'end_ms' => $chunk->end_ms,
                    'transcript' => $chunk->transcript,
                    'translation_vi' => $chunk->translation_vi,
                    'ipa' => $chunk->ipa,
                    'speaker' => $chunk->speaker,
                    'difficulty' => $level,
                    'needs_review' => $chunk->needs_review,
                ]);
            }

            return $lesson;
        });
    }

    /**
     * Creates a Private Lesson for a PRO User (PRO User Flow).
     */
    public function createPrivateLessonForUser(mixed $user, ShadowingSource $source, array $options = []): ShadowingLesson
    {
        // TRANSCRIPT SOURCE VALIDATION GATE
        $this->validateSource($source);

        // If user already created a private lesson for this exact video source, reuse it!
        $existing = ShadowingLesson::where('user_id', $user->id)
            ->where('source_id', $source->id)
            ->where('visibility', 'private')
            ->first();

        if ($existing) {
            return $existing;
        }

        $code = 'shadowing_user_' . $user->id . '_' . strtolower($source->youtube_video_id) . '_' . Str::random(4);
        $title = $options['title'] ?? $source->title;
        $level = $options['level'] ?? 'B2';

        return DB::transaction(function () use ($user, $source, $code, $title, $level) {
            $lesson = ShadowingLesson::create([
                'source_id' => $source->id,
                'user_id' => $user->id,
                'code' => $code,
                'title' => $title,
                'description' => "Bài luyện Shadowing cá nhân từ YouTube",
                'level' => $level,
                'topic' => 'Personal',
                'youtube_video_id' => $source->youtube_video_id,
                'media_type' => 'youtube',
                'total_segments' => $source->chunks()->count(),
                'status' => 'published', // PRO user can learn immediately
                'visibility' => 'private',
                'is_official' => false,
                'transcript_source' => $source->transcript_source,
                'published_at' => now(),
            ]);

            foreach ($source->chunks as $chunk) {
                ShadowingSegment::create([
                    'shadowing_lesson_id' => $lesson->id,
                    'segment_index' => $chunk->chunk_index,
                    'start_ms' => $chunk->start_ms,
                    'end_ms' => $chunk->end_ms,
                    'transcript' => $chunk->transcript,
                    'translation_vi' => $chunk->translation_vi,
                    'ipa' => $chunk->ipa,
                    'speaker' => $chunk->speaker,
                    'difficulty' => $level,
                    'needs_review' => $chunk->needs_review,
                ]);
            }

            return $lesson;
        });
    }

    /**
     * TRANSCRIPT SOURCE VALIDATION GATE
     * Enforces strict integrity between video ID, source ID, and transcript chunks.
     */
    private function validateSource(ShadowingSource $source): void
    {
        if (empty($source->id) || empty($source->youtube_video_id)) {
            throw new \InvalidArgumentException('ShadowingSource contains invalid or empty YouTube video ID.');
        }

        $forbiddenSources = ['ai_generated_fallback', 'mock', 'demo', 'sample', 'fake', 'prototype'];
        if (empty($source->transcript_source) || in_array(strtolower($source->transcript_source), $forbiddenSources, true)) {
            throw new \InvalidArgumentException("ShadowingSource ID {$source->id} uses forbidden or invalid transcript source ('{$source->transcript_source}'). Cannot create lesson from fake source.");
        }

        if ($source->status !== 'completed') {
            throw new \RuntimeException("ShadowingSource ID {$source->id} is not completed (status: {$source->status}).");
        }

        $chunkCount = $source->chunks()->count();
        if ($chunkCount === 0) {
            throw new \RuntimeException("ShadowingSource ID {$source->id} for video {$source->youtube_video_id} has 0 chunks. Cannot create lesson from empty transcript.");
        }
    }
}
