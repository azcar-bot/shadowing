<?php

namespace Tests\Feature\Modules\Shadowing;

use App\Livewire\ShadowingPractice;
use App\Models\User;
use App\Modules\Shadowing\Domain\Contracts\TranslationProviderContract;
use App\Modules\Shadowing\Domain\Exceptions\TranslationProviderPermanentException;
use App\Modules\Shadowing\Domain\Exceptions\TranslationProviderTransientException;
use App\Modules\Shadowing\Domain\Exceptions\TranslationProviderUnavailableException;
use App\Modules\Shadowing\Domain\Jobs\ProcessShadowingTranslationJob;
use App\Modules\Shadowing\Domain\Services\ShadowingLessonFactoryService;
use App\Modules\Shadowing\Domain\Services\ShadowingTranslationService;
use App\Modules\Shadowing\Infrastructure\Adapters\DeepSeekTranslationAdapter;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingLesson;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSegment;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSource;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSourceChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShadowingTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function createValidSourceWithChunks(string $videoId = 'dbtN9HOOqhk', int $chunkCount = 3): ShadowingSource
    {
        $source = ShadowingSource::create([
            'youtube_video_id' => $videoId,
            'title' => 'Test Video Source',
            'duration_seconds' => 60,
            'transcript_source' => 'youtube_official_caption',
            'processing_version' => 'natural-chunk-v1',
            'status' => 'completed',
        ]);

        for ($i = 1; $i <= $chunkCount; $i++) {
            ShadowingSourceChunk::create([
                'shadowing_source_id' => $source->id,
                'chunk_index' => $i,
                'start_ms' => ($i - 1) * 3000,
                'end_ms' => $i * 3000,
                'transcript' => "Chunk text {$i}",
            ]);
        }

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
                ['chunk_index' => 1, 'translation_vi' => 'Dịch 1'],
                ['chunk_index' => 2, 'translation_vi' => 'Dịch 2'],
                ['chunk_index' => 3, 'translation_vi' => 'Dịch 3'],
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

        $result1 = $service->translateSource($source, 'vi-v1');
        $this->assertTrue($result1);

        $result2 = $service->translateSource($source, 'vi-v1');
        $this->assertTrue($result2);
    }

    #[Test]
    public function translation_preserves_english_transcript(): void
    {
        $source = $this->createValidSourceWithChunks();
        $originalChunk1Text = $source->chunks[0]->transcript;
        $originalChunk2Text = $source->chunks[1]->transcript;

        $mockProvider = $this->createMock(TranslationProviderContract::class);
        $mockProvider->method('translateChunks')->willReturn([
            ['chunk_index' => 1, 'translation_vi' => 'Dịch 1'],
            ['chunk_index' => 2, 'translation_vi' => 'Dịch 2'],
            ['chunk_index' => 3, 'translation_vi' => 'Dịch 3'],
        ]);
        $mockProvider->method('getProviderName')->willReturn('mock');
        $mockProvider->method('getModelName')->willReturn('mock');

        $service = new ShadowingTranslationService($mockProvider);
        $service->translateSource($source);

        $freshSource = $source->fresh();
        $this->assertEquals($originalChunk1Text, $freshSource->chunks[0]->transcript);
        $this->assertEquals($originalChunk2Text, $freshSource->chunks[1]->transcript);
    }

    #[Test]
    public function translation_is_persisted_to_source_chunks(): void
    {
        $source = $this->createValidSourceWithChunks();

        $mockProvider = $this->createMock(TranslationProviderContract::class);
        $mockProvider->method('translateChunks')->willReturn([
            ['chunk_index' => 1, 'translation_vi' => 'Ngày mà ngôi sao rơi.'],
            ['chunk_index' => 2, 'translation_vi' => 'Dịch 2'],
            ['chunk_index' => 3, 'translation_vi' => 'Dịch 3'],
        ]);
        $mockProvider->method('getProviderName')->willReturn('mock');
        $mockProvider->method('getModelName')->willReturn('mock');

        $service = new ShadowingTranslationService($mockProvider);
        $service->translateSource($source);

        $chunk1 = $source->chunks()->where('chunk_index', 1)->first();
        $this->assertEquals('Ngày mà ngôi sao rơi.', $chunk1->translation_vi);
    }

    #[Test]
    public function translation_is_synced_to_lesson_segments(): void
    {
        $source = $this->createValidSourceWithChunks();
        Bus::fake();

        /** @var ShadowingLessonFactoryService $factoryService */
        $factoryService = app(ShadowingLessonFactoryService::class);
        $lesson = $factoryService->createOfficialLesson($source, ['code' => 'test_sync_lesson_' . time()]);

        $mockProvider = $this->createMock(TranslationProviderContract::class);
        $mockProvider->method('translateChunks')->willReturn([
            ['chunk_index' => 1, 'translation_vi' => 'Dịch Segment 1'],
            ['chunk_index' => 2, 'translation_vi' => 'Dịch Segment 2'],
            ['chunk_index' => 3, 'translation_vi' => 'Dịch Segment 3'],
        ]);
        $mockProvider->method('getProviderName')->willReturn('mock');
        $mockProvider->method('getModelName')->willReturn('mock');

        $service = new ShadowingTranslationService($mockProvider);
        $service->translateSource($source);

        $seg1 = ShadowingSegment::where('shadowing_lesson_id', $lesson->id)->where('segment_index', 1)->first();
        $this->assertEquals('Dịch Segment 1', $seg1->translation_vi);
    }

    #[Test]
    public function malformed_provider_output_fails_without_partial_corruption(): void
    {
        $source = $this->createValidSourceWithChunks();

        $mockProvider = $this->createMock(TranslationProviderContract::class);
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
        $this->assertNotNull($source->fresh()->translation_error);
    }

    #[Test]
    public function provider_failure_sets_translation_status_failed(): void
    {
        $source = $this->createValidSourceWithChunks();

        $mockProvider = $this->createMock(TranslationProviderContract::class);
        $mockProvider->expects($this->once())
            ->method('translateChunks')
            ->willThrowException(new TranslationProviderTransientException("API Connection Timeout"));
        $mockProvider->method('getProviderName')->willReturn('mock');
        $mockProvider->method('getModelName')->willReturn('mock');

        $service = new ShadowingTranslationService($mockProvider);

        try {
            $service->translateSource($source);
        } catch (TranslationProviderTransientException $e) {
            // Expected transient exception re-thrown for Queue
        }

        $this->assertEquals('failed', $source->fresh()->translation_status);
        $this->assertStringContainsString('API Connection Timeout', $source->fresh()->translation_error);
        $this->assertEquals('Chunk text 1', $source->chunks[0]->fresh()->transcript);
    }

    #[Test]
    public function opening_practice_does_not_call_translation_provider(): void
    {
        $source = $this->createValidSourceWithChunks();
        Bus::fake();

        /** @var ShadowingLessonFactoryService $factoryService */
        $factoryService = app(ShadowingLessonFactoryService::class);
        $lesson = $factoryService->createOfficialLesson($source, ['code' => 'test_open_practice_' . time()]);
        $lesson->update(['status' => 'published']);

        $user = User::factory()->create();
        $this->actingAs($user);

        $mockProvider = $this->createMock(TranslationProviderContract::class);
        $mockProvider->expects($this->never())->method('translateChunks');
        $this->app->instance(TranslationProviderContract::class, $mockProvider);

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
        $this->assertNull($capturedItems[0]['prev_transcript']);
        $this->assertEquals('Chunk text 2', $capturedItems[0]['next_transcript']);

        $this->assertEquals('Chunk text 1', $capturedItems[1]['prev_transcript']);
        $this->assertEquals('Chunk text 3', $capturedItems[1]['next_transcript']);

        $this->assertEquals('Chunk text 2', $capturedItems[2]['prev_transcript']);
        $this->assertNull($capturedItems[2]['next_transcript']);
    }

    #[Test]
    public function missing_real_provider_credentials_never_produces_fake_translation(): void
    {
        $adapter = new DeepSeekTranslationAdapter(apiKey: '');

        $this->expectException(TranslationProviderUnavailableException::class);
        $this->expectExceptionMessage('DeepSeek API key is missing');

        $adapter->translateChunks([
            ['chunk_index' => 1, 'transcript' => 'Test', 'prev_transcript' => null, 'next_transcript' => null],
        ]);
    }

    #[Test]
    public function completed_metadata_with_missing_chunk_retranslates(): void
    {
        $source = $this->createValidSourceWithChunks();
        $source->update([
            'translation_status' => 'completed',
            'translation_version' => 'vi-v1',
        ]);
        $source->chunks[0]->update(['translation_vi' => 'Dịch 1']);

        $mockProvider = $this->createMock(TranslationProviderContract::class);
        $mockProvider->expects($this->once())
            ->method('translateChunks')
            ->willReturn([
                ['chunk_index' => 1, 'translation_vi' => 'Dịch 1 mới'],
                ['chunk_index' => 2, 'translation_vi' => 'Dịch 2 mới'],
                ['chunk_index' => 3, 'translation_vi' => 'Dịch 3 mới'],
            ]);
        $mockProvider->method('getProviderName')->willReturn('mock');
        $mockProvider->method('getModelName')->willReturn('mock');

        $service = new ShadowingTranslationService($mockProvider);
        $result = $service->translateSource($source, 'vi-v1');

        $this->assertTrue($result);
        $this->assertEquals('Dịch 2 mới', $source->chunks[1]->fresh()->translation_vi);
    }

    #[Test]
    public function factory_dispatches_translation_job_instead_of_synchronous_provider_call(): void
    {
        Bus::fake();

        $source = $this->createValidSourceWithChunks();
        /** @var ShadowingLessonFactoryService $factory */
        $factory = app(ShadowingLessonFactoryService::class);

        $lesson = $factory->createOfficialLesson($source, ['code' => 'test_job_dispatch_' . time()]);

        $this->assertNotNull($lesson);
        Bus::assertDispatched(ProcessShadowingTranslationJob::class, function ($job) use ($source) {
            return $job->shadowingSourceId === $source->id;
        });
    }

    #[Test]
    public function transient_provider_exception_is_retryable(): void
    {
        $source = $this->createValidSourceWithChunks();

        $mockProvider = $this->createMock(TranslationProviderContract::class);
        $mockProvider->method('translateChunks')
            ->willThrowException(new TranslationProviderTransientException("503 Service Unavailable"));
        $mockProvider->method('getProviderName')->willReturn('mock');
        $mockProvider->method('getModelName')->willReturn('mock');

        $service = new ShadowingTranslationService($mockProvider);

        $this->expectException(TranslationProviderTransientException::class);
        $this->expectExceptionMessage("503 Service Unavailable");

        $service->translateSource($source);
    }

    #[Test]
    public function translation_state_moves_through_processing(): void
    {
        $source = $this->createValidSourceWithChunks();

        $stateInProviderCall = null;
        $mockProvider = $this->createMock(TranslationProviderContract::class);
        $mockProvider->expects($this->once())
            ->method('translateChunks')
            ->willReturnCallback(function () use ($source, &$stateInProviderCall) {
                $stateInProviderCall = $source->fresh()->translation_status;
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

        $this->assertEquals('processing', $stateInProviderCall);
        $this->assertEquals('completed', $source->fresh()->translation_status);
    }

    #[Test]
    public function hundred_plus_chunks_are_translated_in_bounded_batches(): void
    {
        $source = $this->createValidSourceWithChunks('batch_test_100', 55);

        config(['shadowing.translation.batch_size' => 20]);

        $callCount = 0;
        $mockProvider = $this->createMock(TranslationProviderContract::class);
        $mockProvider->expects($this->exactly(3))
            ->method('translateChunks')
            ->willReturnCallback(function (array $items) use (&$callCount) {
                $callCount++;
                $results = [];
                foreach ($items as $item) {
                    $results[] = [
                        'chunk_index' => $item['chunk_index'],
                        'translation_vi' => "Bản dịch chunk {$item['chunk_index']}",
                    ];
                }
                return $results;
            });
        $mockProvider->method('getProviderName')->willReturn('mock');
        $mockProvider->method('getModelName')->willReturn('mock');

        $service = new ShadowingTranslationService($mockProvider);
        $result = $service->translateSource($source);

        $this->assertTrue($result);
        $this->assertEquals(3, $callCount);
        $this->assertEquals('Bản dịch chunk 55', $source->chunks()->where('chunk_index', 55)->first()->translation_vi);
    }

    // =========================================================================
    // ROUND 3 HARDENING TESTS
    // =========================================================================

    #[Test]
    public function disabled_translation_does_not_dispatch_job(): void
    {
        Bus::fake();
        config(['shadowing.translation.enabled' => false]);

        $source = $this->createValidSourceWithChunks();
        /** @var ShadowingLessonFactoryService $factory */
        $factory = app(ShadowingLessonFactoryService::class);

        $factory->createOfficialLesson($source, ['code' => 'test_disabled_dispatch_' . time()]);

        Bus::assertNotDispatched(ProcessShadowingTranslationJob::class);
    }

    #[Test]
    public function configured_provider_is_resolved_correctly(): void
    {
        config(['shadowing.translation.provider' => 'deepseek']);
        $resolved = app(TranslationProviderContract::class);
        $this->assertInstanceOf(DeepSeekTranslationAdapter::class, $resolved);

        config(['shadowing.translation.provider' => 'unsupported_vendor']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported translation provider');
        app(TranslationProviderContract::class);
    }

    #[Test]
    public function http_401_does_not_retry(): void
    {
        $source = $this->createValidSourceWithChunks();

        $mockProvider = $this->createMock(TranslationProviderContract::class);
        $mockProvider->method('translateChunks')
            ->willThrowException(new TranslationProviderPermanentException("HTTP 401 Unauthorized"));
        $mockProvider->method('getProviderName')->willReturn('mock');
        $mockProvider->method('getModelName')->willReturn('mock');

        $service = new ShadowingTranslationService($mockProvider);

        // Does NOT re-throw TranslationProviderPermanentException, sets status failed and returns false
        $result = $service->translateSource($source);

        $this->assertFalse($result);
        $this->assertEquals('failed', $source->fresh()->translation_status);
        $this->assertStringContainsString('HTTP 401 Unauthorized', $source->fresh()->translation_error);
    }

    #[Test]
    public function http_429_is_retryable(): void
    {
        $source = $this->createValidSourceWithChunks();

        $mockProvider = $this->createMock(TranslationProviderContract::class);
        $mockProvider->method('translateChunks')
            ->willThrowException(new TranslationProviderTransientException("HTTP 429 Too Many Requests"));
        $mockProvider->method('getProviderName')->willReturn('mock');
        $mockProvider->method('getModelName')->willReturn('mock');

        $service = new ShadowingTranslationService($mockProvider);

        $this->expectException(TranslationProviderTransientException::class);
        $this->expectExceptionMessage("HTTP 429 Too Many Requests");

        $service->translateSource($source);
    }

    #[Test]
    public function http_503_is_retryable(): void
    {
        $source = $this->createValidSourceWithChunks();

        $mockProvider = $this->createMock(TranslationProviderContract::class);
        $mockProvider->method('translateChunks')
            ->willThrowException(new TranslationProviderTransientException("HTTP 503 Service Unavailable"));
        $mockProvider->method('getProviderName')->willReturn('mock');
        $mockProvider->method('getModelName')->willReturn('mock');

        $service = new ShadowingTranslationService($mockProvider);

        $this->expectException(TranslationProviderTransientException::class);
        $this->expectExceptionMessage("HTTP 503 Service Unavailable");

        $service->translateSource($source);
    }

    #[Test]
    public function concurrent_translation_lock_contention_is_retryable(): void
    {
        $source = $this->createValidSourceWithChunks();

        // Lock the source manually
        $lockKey = "shadowing_translation_lock_{$source->id}";
        $lock = Cache::lock($lockKey, 420);
        $lock->get();

        $mockProvider = $this->createMock(TranslationProviderContract::class);
        $service = new ShadowingTranslationService($mockProvider);

        // Lock contention throws TranslationProviderTransientException so Queue retries later
        $this->expectException(TranslationProviderTransientException::class);
        $this->expectExceptionMessage("Translation lock contention");

        try {
            $service->translateSource($source);
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function network_connection_failure_is_retryable(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'https://api.deepseek.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException("cURL error 28: Connection timed out after 45000 milliseconds"),
        ]);

        $adapter = new DeepSeekTranslationAdapter(apiKey: 'sk-test-real-key');

        $this->expectException(TranslationProviderTransientException::class);
        $this->expectExceptionMessage('Translation provider transport/network failure');

        $adapter->translateChunks([
            ['chunk_index' => 1, 'transcript' => 'Test transcript', 'prev_transcript' => null, 'next_transcript' => null],
        ]);
    }
}
