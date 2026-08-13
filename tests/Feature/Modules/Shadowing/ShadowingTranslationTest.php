<?php

namespace Tests\Feature\Modules\Shadowing;

use App\Livewire\ShadowingPractice;
use App\Models\User;
use App\Modules\Shadowing\Domain\Contracts\TranslationProviderContract;
use App\Modules\Shadowing\Domain\Services\ShadowingLessonFactoryService;
use App\Modules\Shadowing\Domain\Services\ShadowingTranslationService;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingLesson;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSegment;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSource;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSourceChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShadowingTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function createValidSourceWithChunks(string $videoId = 'dbtN9HOOqhk'): ShadowingSource
    {
        $source = ShadowingSource::create([
            'youtube_video_id' => $videoId,
            'title' => 'Test Video Source',
            'duration_seconds' => 60,
            'transcript_source' => 'youtube_official_caption',
            'processing_version' => 'natural-chunk-v1',
            'status' => 'completed',
        ]);

        ShadowingSourceChunk::create([
            'shadowing_source_id' => $source->id,
            'chunk_index' => 1,
            'start_ms' => 0,
            'end_ms' => 3000,
            'transcript' => 'The day, a star fell.',
        ]);

        ShadowingSourceChunk::create([
            'shadowing_source_id' => $source->id,
            'chunk_index' => 2,
            'start_ms' => 3000,
            'end_ms' => 6000,
            'transcript' => 'It was almost like a dream.',
        ]);

        ShadowingSourceChunk::create([
            'shadowing_source_id' => $source->id,
            'chunk_index' => 3,
            'start_ms' => 6000,
            'end_ms' => 9000,
            'transcript' => 'A breathtaking view.',
        ]);

        return $source;
    }

    #[Test]
    public function invalid_source_is_not_translated(): void
    {
        $invalidSource = ShadowingSource::create([
            'youtube_video_id' => 'fake1234567',
            'title' => 'Fake Source',
            'transcript_source' => 'ai_generated_fallback',
            'status' => 'completed',
        ]);

        ShadowingSourceChunk::create([
            'shadowing_source_id' => $invalidSource->id,
            'chunk_index' => 1,
            'start_ms' => 0,
            'end_ms' => 3000,
            'transcript' => 'Fake text',
        ]);

        /** @var ShadowingTranslationService $translationService */
        $translationService = app(ShadowingTranslationService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('forbidden transcript_source');

        $translationService->translateSource($invalidSource);
    }

    #[Test]
    public function translation_provider_is_called_for_valid_source(): void
    {
        $source = $this->createValidSourceWithChunks();

        $mockProvider = $this->createMock(TranslationProviderContract::class);
        $mockProvider->expects($this->once())
            ->method('translateChunks')
            ->willReturn([
                ['chunk_index' => 1, 'translation_vi' => 'Ngày mà một ngôi sao rơi xuống.'],
                ['chunk_index' => 2, 'translation_vi' => 'Nó gần như thể một giấc mơ.'],
                ['chunk_index' => 3, 'translation_vi' => 'Một khung cảnh ngoạn mục.'],
            ]);
        $mockProvider->method('getProviderName')->willReturn('mock_provider');
        $mockProvider->method('getModelName')->willReturn('mock_model');

        $translationService = new ShadowingTranslationService($mockProvider);
        $result = $translationService->translateSource($source);

        $this->assertTrue($result);
        $this->assertEquals('completed', $source->fresh()->translation_status);
        $this->assertEquals('mock_provider', $source->fresh()->translation_provider);
    }

    #[Test]
    public function completed_translation_same_version_is_not_called_twice(): void
    {
        $source = $this->createValidSourceWithChunks();

        $mockProvider = $this->createMock(TranslationProviderContract::class);
        // Expect provider to be called EXACTLY ONCE across two translateSource invocations
        $mockProvider->expects($this->once())
            ->method('translateChunks')
            ->willReturn([
                ['chunk_index' => 1, 'translation_vi' => 'Dịch 1'],
                ['chunk_index' => 2, 'translation_vi' => 'Dịch 2'],
                ['chunk_index' => 3, 'translation_vi' => 'Dịch 3'],
            ]);
        $mockProvider->method('getProviderName')->willReturn('mock');
        $mockProvider->method('getModelName')->willReturn('mock');

        $service = new ShadowingTranslationService($mockProvider);

        // 1st call -> triggers provider
        $result1 = $service->translateSource($source, 'vi-v1');
        $this->assertTrue($result1);

        // 2nd call (same version) -> skips provider (idempotent)
        $result2 = $service->translateSource($source, 'vi-v1');
        $this->assertTrue($result2);
    }

    #[Test]
    public function translation_preserves_english_transcript(): void
    {
        $source = $this->createValidSourceWithChunks();
        $originalChunk1Text = $source->chunks[0]->transcript;
        $originalChunk2Text = $source->chunks[1]->transcript;

        /** @var ShadowingTranslationService $translationService */
        $translationService = app(ShadowingTranslationService::class);
        $translationService->translateSource($source);

        $freshSource = $source->fresh();
        $this->assertEquals($originalChunk1Text, $freshSource->chunks[0]->transcript);
        $this->assertEquals($originalChunk2Text, $freshSource->chunks[1]->transcript);
    }

    #[Test]
    public function translation_is_persisted_to_source_chunks(): void
    {
        $source = $this->createValidSourceWithChunks();

        /** @var ShadowingTranslationService $translationService */
        $translationService = app(ShadowingTranslationService::class);
        $translationService->translateSource($source);

        $chunk1 = $source->chunks()->where('chunk_index', 1)->first();
        $this->assertNotEmpty($chunk1->translation_vi);
        $this->assertStringContainsString('Ngày', $chunk1->translation_vi);
    }

    #[Test]
    public function translation_is_synced_to_lesson_segments(): void
    {
        $source = $this->createValidSourceWithChunks();

        /** @var ShadowingLessonFactoryService $factoryService */
        $factoryService = app(ShadowingLessonFactoryService::class);
        $lesson = $factoryService->createOfficialLesson($source, ['code' => 'test_sync_lesson_' . time()]);

        /** @var ShadowingTranslationService $translationService */
        $translationService = app(ShadowingTranslationService::class);
        $translationService->translateSource($source);

        $seg1 = ShadowingSegment::where('shadowing_lesson_id', $lesson->id)->where('segment_index', 1)->first();
        $this->assertNotEmpty($seg1->translation_vi);
    }

    #[Test]
    public function malformed_provider_output_fails_without_partial_corruption(): void
    {
        $source = $this->createValidSourceWithChunks();

        $mockProvider = $this->createMock(TranslationProviderContract::class);
        // Return incomplete/malformed translations missing chunk 2 & 3
        $mockProvider->expects($this->once())
            ->method('translateChunks')
            ->willReturn([
                ['chunk_index' => 1, 'translation_vi' => 'Dịch 1'],
            ]);
        $mockProvider->method('getProviderName')->willReturn('mock');
        $mockProvider->method('getModelName')->willReturn('mock');

        $service = new ShadowingTranslationService($mockProvider);
        $result = $service->translateSource($source);

        $this->assertFalse($result);
        $this->assertEquals('failed', $source->fresh()->translation_status);
        $this->assertNotNull($source->fresh()->error_message);
    }

    #[Test]
    public function provider_failure_sets_translation_status_failed(): void
    {
        $source = $this->createValidSourceWithChunks();

        $mockProvider = $this->createMock(TranslationProviderContract::class);
        $mockProvider->expects($this->once())
            ->method('translateChunks')
            ->willThrowException(new \RuntimeException("API Connection Timeout"));
        $mockProvider->method('getProviderName')->willReturn('mock');
        $mockProvider->method('getModelName')->willReturn('mock');

        $service = new ShadowingTranslationService($mockProvider);
        $result = $service->translateSource($source);

        $this->assertFalse($result);
        $this->assertEquals('failed', $source->fresh()->translation_status);
        $this->assertStringContainsString('API Connection Timeout', $source->fresh()->error_message);

        // English transcript is unaffected
        $this->assertEquals('The day, a star fell.', $source->chunks[0]->fresh()->transcript);
    }

    #[Test]
    public function opening_practice_does_not_call_translation_provider(): void
    {
        $source = $this->createValidSourceWithChunks();
        /** @var ShadowingLessonFactoryService $factoryService */
        $factoryService = app(ShadowingLessonFactoryService::class);
        $lesson = $factoryService->createOfficialLesson($source, ['code' => 'test_open_practice_' . time()]);
        $lesson->update(['status' => 'published']);

        $user = User::factory()->create();
        $this->actingAs($user);

        $mockProvider = $this->createMock(TranslationProviderContract::class);
        $mockProvider->expects($this->never())->method('translateChunks');
        $this->app->instance(TranslationProviderContract::class, $mockProvider);

        // Render practice component multiple times
        Livewire::test(ShadowingPractice::class, ['lessonCode' => $lesson->code])
            ->assertSee($lesson->title);
    }

    #[Test]
    public function neighboring_chunk_context_is_supplied_to_translation_provider(): void
    {
        $source = $this->createValidSourceWithChunks();

        $capturedItems = [];
        $mockProvider = $this->createMock(TranslationProviderContract::class);
        $mockProvider->expects($this->once())
            ->method('translateChunks')
            ->willReturnCallback(function (array $items) use (&$capturedItems) {
                $capturedItems = $items;
                return [
                    ['chunk_index' => 1, 'translation_vi' => 'T1'],
                    ['chunk_index' => 2, 'translation_vi' => 'T2'],
                    ['chunk_index' => 3, 'translation_vi' => 'T3'],
                ];
            });
        $mockProvider->method('getProviderName')->willReturn('mock');
        $mockProvider->method('getModelName')->willReturn('mock');

        $service = new ShadowingTranslationService($mockProvider);
        $service->translateSource($source);

        $this->assertCount(3, $capturedItems);
        // Chunk 1 context: prev = null, next = Chunk 2
        $this->assertNull($capturedItems[0]['prev_transcript']);
        $this->assertEquals('It was almost like a dream.', $capturedItems[0]['next_transcript']);

        // Chunk 2 context: prev = Chunk 1, next = Chunk 3
        $this->assertEquals('The day, a star fell.', $capturedItems[1]['prev_transcript']);
        $this->assertEquals('A breathtaking view.', $capturedItems[1]['next_transcript']);

        // Chunk 3 context: prev = Chunk 2, next = null
        $this->assertEquals('It was almost like a dream.', $capturedItems[2]['prev_transcript']);
        $this->assertNull($capturedItems[2]['next_transcript']);
    }
}
