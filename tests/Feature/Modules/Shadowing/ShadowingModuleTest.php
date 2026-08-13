<?php

namespace Tests\Feature\Modules\Shadowing;

use App\Livewire\ShadowingPractice;
use App\Models\User;
use App\Modules\Shadowing\Domain\Services\CaptionNormalizationService;
use App\Modules\Shadowing\Domain\Services\NaturalSpeakingChunkEngineService;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingLesson;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShadowingModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--force' => true]);
    }

    #[Test]
    public function free_user_can_access_shadowing_catalog_and_practice(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('shadowing.index'));
        $response->assertOk();
        $response->assertSee('Luyện nói Shadowing');
        $response->assertSee('Giao tiếp hàng ngày');

        $practiceResponse = $this->get(route('shadowing.practice', ['lessonCode' => 'shadowing_b1_daily_convo']));
        $practiceResponse->assertOk();

        Livewire::test(ShadowingPractice::class, ['lessonCode' => 'shadowing_b1_daily_convo'])
            ->assertSee('Shadowing')
            ->assertSee('Mother');
    }

    #[Test]
    public function shadowing_mode_switching_and_attempt_logging(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $lesson = ShadowingLesson::where('code', 'shadowing_b1_daily_convo')->first();
        $segment = ShadowingSegment::where('shadowing_lesson_id', $lesson->id)->first();

        Livewire::test(ShadowingPractice::class, ['lessonCode' => $lesson->code])
            ->assertSet('practiceMode', 'LISTEN_REPEAT')
            ->call('setMode', 'SHADOWING')
            ->assertSet('practiceMode', 'SHADOWING')
            ->call('recordAttempt', 88.5, 'blob:local', 4500)
            ->assertSet('userAttempts.1.score', 88.5);

        $this->assertDatabaseHas('user_shadowing_progress', [
            'user_id' => $user->id,
            'shadowing_segment_id' => $segment->id,
            'best_score' => 88.5,
            'is_completed' => true,
        ]);
    }

    #[Test]
    public function challenge_mode_withholds_transcript_until_revealed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $lesson = ShadowingLesson::where('code', 'shadowing_b1_daily_convo')->first();

        Livewire::test(ShadowingPractice::class, ['lessonCode' => $lesson->code])
            ->call('setMode', 'CHALLENGE')
            ->assertSee('••••••••••••••••••••••••')
            ->call('revealChallengeTranscript')
            ->assertSee('If things don\'t work out');
    }

    #[Test]
    public function teacher_preview_mode_bypasses_progress_logging(): void
    {
        $teacherRole = \Illuminate\Support\Facades\DB::table('roles')->where('name', 'teacher')->first();
        $teacher = User::factory()->create();
        \Illuminate\Support\Facades\DB::table('role_user')->insert([
            'user_id' => $teacher->id,
            'role_id' => $teacherRole->id,
        ]);

        $this->actingAs($teacher);

        $lesson = ShadowingLesson::where('code', 'shadowing_b1_daily_convo')->first();
        $segment = ShadowingSegment::where('shadowing_lesson_id', $lesson->id)->first();

        Livewire::test(ShadowingPractice::class, ['lessonCode' => $lesson->code])
            ->assertSet('isPreviewMode', true)
            ->call('recordAttempt', 95.0, 'blob:test', 4000);

        $this->assertDatabaseMissing('user_shadowing_progress', [
            'user_id' => $teacher->id,
            'shadowing_segment_id' => $segment->id,
        ]);
    }

    #[Test]
    public function natural_speaking_chunk_engine_merges_short_and_splits_long_captions(): void
    {
        $normalizer = new CaptionNormalizationService();
        $engine = new NaturalSpeakingChunkEngineService(
            preferredMinDurationMs: 2500,
            preferredMaxDurationMs: 6000,
            shortChunkThresholdMs: 1800,
            longChunkThresholdMs: 8000
        );

        // Test 1: Merge two short captions
        $shortCaptions = $normalizer->normalizeCaptions([
            ['text' => 'I really like', 'start_ms' => 12000, 'end_ms' => 13100],
            ['text' => 'travelling by car.', 'start_ms' => 13100, 'end_ms' => 15400],
        ]);
        $chunks1 = $engine->generateSpeechChunks($shortCaptions);

        $this->assertCount(1, $chunks1);
        $this->assertEquals('I really like travelling by car.', $chunks1[0]['transcript']);
        $this->assertEquals(12000, $chunks1[0]['start_ms']);
        $this->assertEquals(15400, $chunks1[0]['end_ms']);

        // Test 2: Parse SRT text
        $srtText = "1\n00:00:10,000 --> 00:00:12,500\nSometimes you have to get close\n\n2\n00:00:12,500 --> 00:00:15,000\nto find out what's inside.\n";
        $parsedCaptions = $normalizer->parseSrtOrVtt($srtText);
        $this->assertCount(2, $parsedCaptions);

        $chunks2 = $engine->generateSpeechChunks($parsedCaptions);
        $this->assertCount(1, $chunks2);
        $this->assertEquals("Sometimes you have to get close to find out what's inside.", $chunks2[0]['transcript']);

        // Test 3: Overly long caption without word evidence flags needs_review
        $longCaptions = $normalizer->normalizeCaptions([
            ['text' => 'This is an extremely long continuous speech sentence that spans way over eight seconds without any clear pause boundary.', 'start_ms' => 10000, 'end_ms' => 21000],
        ]);
        $chunks3 = $engine->generateSpeechChunks($longCaptions);

        $this->assertCount(1, $chunks3);
        $this->assertTrue($chunks3[0]['needs_review']);
    }

    #[Test]
    public function word_timing_evidence_splits_long_chunks_accurately(): void
    {
        $normalizer = new CaptionNormalizationService();
        $engine = new NaturalSpeakingChunkEngineService();

        $longCaptionWithWords = $normalizer->normalizeCaptions([
            [
                'text' => 'Another big problem in the speech space. When customers first bring the software on.',
                'start_ms' => 0,
                'end_ms' => 8500,
                'words' => [
                    ['word' => 'Another', 'start_ms' => 0, 'end_ms' => 400],
                    ['word' => 'big', 'start_ms' => 450, 'end_ms' => 800],
                    ['word' => 'problem', 'start_ms' => 850, 'end_ms' => 1200],
                    ['word' => 'in', 'start_ms' => 1250, 'end_ms' => 1400],
                    ['word' => 'the', 'start_ms' => 1450, 'end_ms' => 1600],
                    ['word' => 'speech', 'start_ms' => 1650, 'end_ms' => 2000],
                    ['word' => 'space.', 'start_ms' => 2050, 'end_ms' => 2500],
                    ['word' => 'When', 'start_ms' => 2950, 'end_ms' => 3300],
                    ['word' => 'customers', 'start_ms' => 3350, 'end_ms' => 3800],
                    ['word' => 'first', 'start_ms' => 3850, 'end_ms' => 4200],
                    ['word' => 'bring', 'start_ms' => 4250, 'end_ms' => 4600],
                    ['word' => 'the', 'start_ms' => 4650, 'end_ms' => 4900],
                    ['word' => 'software', 'start_ms' => 4950, 'end_ms' => 5400],
                    ['word' => 'on.', 'start_ms' => 5450, 'end_ms' => 5900],
                ],
            ],
        ]);

        $chunks = $engine->generateSpeechChunks($longCaptionWithWords);

        $this->assertGreaterThanOrEqual(2, count($chunks));
        $this->assertEquals('Another big problem in the speech space.', $chunks[0]['transcript']);
        $this->assertEquals(0, $chunks[0]['start_ms']);
        $this->assertEquals(2500, $chunks[0]['end_ms']);
        $this->assertFalse($chunks[0]['needs_review']);
    }

    #[Test]
    public function real_youtube_video_lesson_creation_and_segment_isolation(): void
    {
        $lesson = ShadowingLesson::updateOrCreate(
            ['code' => 'shadowing_b2_your_name_trailer_test'],
            [
                'title' => 'YOUR NAME English Trailer (2016) Anime Movie',
                'youtube_video_id' => 'dbtN9HOOqhk',
                'media_type' => 'youtube',
                'level' => 'B2',
                'topic' => 'Anime & Cinema',
                'raw_transcript' => json_encode([['text' => 'The day, a star fell.', 'start_ms' => 1160, 'end_ms' => 3479]]),
            'canonical_transcript' => 'The day, a star fell.',
            'transcript_source' => 'youtube_caption',
            'total_segments' => 1,
            'status' => 'published',
        ]);

        $segment = ShadowingSegment::create([
            'shadowing_lesson_id' => $lesson->id,
            'segment_index' => 1,
            'start_ms' => 1160,
            'end_ms' => 3479,
            'transcript' => 'The day, a star fell.',
            'needs_review' => false,
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(ShadowingPractice::class, ['lessonCode' => $lesson->code])
            ->assertSee('The day, a star fell.')
            ->assertSet('currentStudentSegment.start_ms', 1160)
            ->assertSet('currentStudentSegment.end_ms', 3479);
    }

    #[Test]
    public function youtube_url_normalizer_extracts_clean_video_id(): void
    {
        $normalizer = new \App\Modules\Shadowing\Domain\Services\YouTubeUrlNormalizerService();

        $this->assertEquals('dbtN9HOOqhk', $normalizer->extractVideoId('https://www.youtube.com/watch?v=dbtN9HOOqhk'));
        $this->assertEquals('dbtN9HOOqhk', $normalizer->extractVideoId('https://youtu.be/dbtN9HOOqhk'));
        $this->assertEquals('dbtN9HOOqhk', $normalizer->extractVideoId('https://www.youtube.com/embed/dbtN9HOOqhk?start=10'));
        $this->assertEquals('dbtN9HOOqhk', $normalizer->extractVideoId('dbtN9HOOqhk'));
    }

    #[Test]
    public function shared_processed_source_deduplicates_processing_for_same_video_id(): void
    {
        $source = \App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSource::create([
            'youtube_video_id' => 'dbtN9HOOqhk',
            'title' => 'YOUR NAME English Trailer',
            'duration_seconds' => 106,
            'transcript_source' => 'youtube_manual_caption',
            'processing_version' => 'natural-chunk-v1',
            'status' => 'completed',
        ]);

        \App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSourceChunk::create([
            'shadowing_source_id' => $source->id,
            'chunk_index' => 1,
            'start_ms' => 1160,
            'end_ms' => 3479,
            'transcript' => 'The day, a star fell.',
            'quality_score' => 0.9,
            'needs_review' => false,
        ]);

        /** @var \App\Modules\Shadowing\Domain\Services\ShadowingSourceProcessingService $service */
        $service = app(\App\Modules\Shadowing\Domain\Services\ShadowingSourceProcessingService::class);
        $fetchedSource = $service->processVideoSource('https://www.youtube.com/watch?v=dbtN9HOOqhk');

        $this->assertEquals($source->id, $fetchedSource->id);
        $this->assertEquals(1, \App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSource::where('youtube_video_id', 'dbtN9HOOqhk')->count());
    }

    #[Test]
    public function pro_user_private_lesson_ownership_and_privacy_isolation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $source = \App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSource::create([
            'youtube_video_id' => 'dbtN9HOOqhk',
            'title' => 'YOUR NAME English Trailer',
            'duration_seconds' => 106,
            'transcript_source' => 'youtube_manual_caption',
            'processing_version' => 'natural-chunk-v1',
            'status' => 'completed',
        ]);

        \App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSourceChunk::create([
            'shadowing_source_id' => $source->id,
            'chunk_index' => 1,
            'start_ms' => 1160,
            'end_ms' => 3479,
            'transcript' => 'The day, a star fell.',
        ]);

        /** @var \App\Modules\Shadowing\Domain\Services\ShadowingLessonFactoryService $factory */
        $factory = app(\App\Modules\Shadowing\Domain\Services\ShadowingLessonFactoryService::class);

        $lessonA = $factory->createPrivateLessonForUser($userA, $source);

        $this->assertEquals('private', $lessonA->visibility);
        $this->assertEquals($userA->id, $lessonA->user_id);

        // User A can access lessonA
        $this->actingAs($userA);
        Livewire::test(ShadowingPractice::class, ['lessonCode' => $lessonA->code])
            ->assertSee('The day, a star fell.');

        // User B is forbidden from accessing User A's private lesson (403)
        $this->actingAs($userB);
        Livewire::test(ShadowingPractice::class, ['lessonCode' => $lessonA->code])
            ->assertStatus(403);
    }
}

