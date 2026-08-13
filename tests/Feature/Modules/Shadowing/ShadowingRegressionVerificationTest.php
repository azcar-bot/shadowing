<?php

namespace Tests\Feature\Modules\Shadowing;

use App\Livewire\ShadowingPractice;
use App\Models\User;
use App\Modules\Shadowing\Domain\Services\ShadowingLessonFactoryService;
use App\Modules\Shadowing\Domain\Services\ShadowingSourceProcessingService;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingLesson;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSource;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSourceChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShadowingRegressionVerificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function fake_source_reuse_is_rejected_in_deduplication_lookup(): void
    {
        $fakeVideoId = 'fake_vid_dedup_test_' . time();
        $fakeSource = ShadowingSource::create([
            'youtube_video_id' => $fakeVideoId,
            'title' => 'Fake Stale Source',
            'status' => 'completed',
            'transcript_source' => 'ai_generated_fallback',
            'processing_version' => config('shadowing.processing_version', 'natural-chunk-v1'),
        ]);

        ShadowingSourceChunk::create([
            'shadowing_source_id' => $fakeSource->id,
            'chunk_index' => 1,
            'start_ms' => 0,
            'end_ms' => 3000,
            'transcript' => 'Fake text',
        ]);

        $forbiddenSources = ['ai_generated_fallback', 'mock', 'demo', 'sample', 'fake', 'prototype'];

        $existing = ShadowingSource::where('youtube_video_id', $fakeVideoId)
            ->where('processing_version', config('shadowing.processing_version', 'natural-chunk-v1'))
            ->where('status', 'completed')
            ->whereNotNull('transcript_source')
            ->whereNotIn('transcript_source', $forbiddenSources)
            ->first();

        $this->assertNull($existing, 'Deduplication query must NOT return stale fake source with ai_generated_fallback');
    }

    #[Test]
    public function factory_rejects_forbidden_transcript_sources(): void
    {
        $fakeVideoId = 'fake_vid_factory_test_' . time();
        $fakeSource = ShadowingSource::create([
            'youtube_video_id' => $fakeVideoId,
            'title' => 'Fake Stale Source for Factory',
            'status' => 'completed',
            'transcript_source' => 'ai_generated_fallback',
            'processing_version' => config('shadowing.processing_version', 'natural-chunk-v1'),
        ]);

        ShadowingSourceChunk::create([
            'shadowing_source_id' => $fakeSource->id,
            'chunk_index' => 1,
            'start_ms' => 0,
            'end_ms' => 3000,
            'transcript' => 'Fake text',
        ]);

        /** @var ShadowingLessonFactoryService $factoryService */
        $factoryService = app(ShadowingLessonFactoryService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("uses forbidden or invalid transcript source");

        $factoryService->createOfficialLesson($fakeSource);
    }

    #[Test]
    public function legacy_youtube_lesson_without_valid_source_is_excluded_from_available_lessons(): void
    {
        $legacyLesson = ShadowingLesson::create([
            'code' => 'legacy_no_source_test_' . time(),
            'title' => 'Legacy YouTube Lesson Without Source',
            'media_type' => 'youtube',
            'source_id' => null,
            'status' => 'published',
            'visibility' => 'official',
            'is_official' => true,
            'total_segments' => 10,
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);

        $practice = new ShadowingPractice();
        $available = $practice->availableLessons();

        $this->assertFalse(
            $available->contains('id', $legacyLesson->id),
            'availableLessons must NOT include legacy YouTube lesson with source_id = null'
        );
    }
}
