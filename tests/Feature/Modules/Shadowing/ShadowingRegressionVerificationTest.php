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
        $fakeVideoId = 'fkVid123456';

        // 1. Create a stale fake source in DB with ai_generated_fallback
        $staleFakeSource = ShadowingSource::create([
            'youtube_video_id' => $fakeVideoId,
            'title' => 'Stale Fake Source',
            'status' => 'completed',
            'transcript_source' => 'ai_generated_fallback',
            'processing_version' => config('shadowing.processing_version', 'natural-chunk-v1'),
        ]);

        ShadowingSourceChunk::create([
            'shadowing_source_id' => $staleFakeSource->id,
            'chunk_index' => 1,
            'start_ms' => 0,
            'end_ms' => 3000,
            'transcript' => 'Stale fake transcript text',
        ]);

        // 2. Mock CaptionNormalizationService to return valid caption data without external HTTP calls
        $mockCaptionService = $this->createMock(\App\Modules\Shadowing\Domain\Services\CaptionNormalizationService::class);
        $mockCaptionService->expects($this->once())
            ->method('fetchCaptionsWithFallback')
            ->with($fakeVideoId)
            ->willReturn([
                'source' => 'youtube_official_caption',
                'title' => 'Fresh Valid Video Title',
                'duration_seconds' => 60,
                'items' => [
                    ['text' => 'Fresh valid transcript text for video.', 'start_ms' => 0, 'end_ms' => 3000]
                ]
            ]);

        $this->app->instance(\App\Modules\Shadowing\Domain\Services\CaptionNormalizationService::class, $mockCaptionService);

        // 3. Call actual production service ShadowingSourceProcessingService::processVideoSource()
        /** @var ShadowingSourceProcessingService $processingService */
        $processingService = app(ShadowingSourceProcessingService::class);
        $resultSource = $processingService->processVideoSource($fakeVideoId);

        // 4. Assertions:
        $this->assertNotNull($resultSource);
        $this->assertNotEquals($staleFakeSource->id, $resultSource->id, 'Production processVideoSource() must NOT reuse stale fake source!');
        $this->assertEquals('youtube_official_caption', $resultSource->transcript_source, 'Returned source must have valid transcript_source');
        $this->assertNotEquals('ai_generated_fallback', $resultSource->transcript_source, 'Returned source must NOT use ai_generated_fallback');
        $this->assertEquals('completed', $resultSource->status);
    }

    #[Test]
    public function factory_rejects_forbidden_transcript_sources(): void
    {
        $fakeVideoId = 'fkVid654321';
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
