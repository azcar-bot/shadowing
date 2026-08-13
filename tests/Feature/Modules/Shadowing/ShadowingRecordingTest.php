<?php

namespace Tests\Feature\Modules\Shadowing;

use App\Livewire\ShadowingPractice;
use App\Models\User;
use App\Modules\Shadowing\Domain\Contracts\ShadowingRecordingStorageContract;
use App\Modules\Shadowing\Domain\Services\ShadowingRecordingService;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingLesson;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingRecording;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ShadowingRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    protected function createLessonAndSegment(mixed $user = null, string $visibility = 'official'): array
    {
        $lesson = ShadowingLesson::create([
            'code' => 'test_rec_lesson_' . time() . '_' . rand(100, 999),
            'user_id' => $user?->id,
            'title' => 'Recording Test Lesson',
            'level' => 'B2',
            'topic' => 'Test',
            'media_type' => 'youtube',
            'total_segments' => 2,
            'status' => 'published',
            'visibility' => $visibility,
            'is_official' => $visibility === 'official',
            'published_at' => now(),
        ]);

        $seg1 = ShadowingSegment::create([
            'shadowing_lesson_id' => $lesson->id,
            'segment_index' => 1,
            'start_ms' => 0,
            'end_ms' => 3000,
            'transcript' => 'First segment transcript',
        ]);

        $seg2 = ShadowingSegment::create([
            'shadowing_lesson_id' => $lesson->id,
            'segment_index' => 2,
            'start_ms' => 3000,
            'end_ms' => 6000,
            'transcript' => 'Second segment transcript',
        ]);

        return [$lesson, $seg1, $seg2];
    }

    #[Test]
    public function guest_cannot_upload_recording(): void
    {
        [$lesson, $seg1] = $this->createLessonAndSegment();

        $file = UploadedFile::fake()->create('recording.webm', 100, 'audio/webm');

        $response = $this->postJson('/shadowing/recordings/upload', [
            'audio' => $file,
            'lesson_id' => $lesson->id,
            'segment_id' => $seg1->id,
        ], ['Accept' => 'application/json']);

        $this->assertTrue(in_array($response->status(), [401, 302], true));
    }

    #[Test]
    public function authenticated_user_can_upload_own_recording(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        [$lesson, $seg1] = $this->createLessonAndSegment();

        $file = UploadedFile::fake()->create('recording.webm', 120, 'audio/webm');

        $response = $this->postJson('/shadowing/recordings/upload', [
            'audio' => $file,
            'lesson_id' => $lesson->id,
            'segment_id' => $seg1->id,
            'duration_ms' => 3500,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertNotNull($response->json('playback_url'));

        $this->assertDatabaseHas('shadowing_recordings', [
            'user_id' => $user->id,
            'shadowing_lesson_id' => $lesson->id,
            'shadowing_segment_id' => $seg1->id,
            'disk' => 'media',
        ]);
    }

    #[Test]
    public function upload_is_stored_on_logical_media_disk(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        [$lesson, $seg1] = $this->createLessonAndSegment();
        $file = UploadedFile::fake()->create('voice.webm', 150, 'audio/webm');

        /** @var ShadowingRecordingService $service */
        $service = app(ShadowingRecordingService::class);
        $recording = $service->storeRecording($user, $lesson, $seg1, $file, 3000);

        $this->assertEquals('media', $recording->disk);
        Storage::disk('media')->assertExists($recording->object_key);
    }

    #[Test]
    public function recording_db_stores_object_metadata_not_binary_or_temporary_url(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        [$lesson, $seg1] = $this->createLessonAndSegment();
        $file = UploadedFile::fake()->create('test.webm', 200, 'audio/webm');

        /** @var ShadowingRecordingService $service */
        $service = app(ShadowingRecordingService::class);
        $recording = $service->storeRecording($user, $lesson, $seg1, $file, 2500);

        $row = ShadowingRecording::find($recording->id);

        $this->assertEquals('media', $row->disk);
        $this->assertNotNull($row->object_key);
        $this->assertStringContainsString('shadowing/recordings/', $row->object_key);
        $this->assertTrue(in_array($row->mime_type, ['audio/webm', 'video/webm'], true));
        $this->assertGreaterThan(0, $row->size_bytes);

        // Ensure metadata table has no temporary_url or binary payload columns
        $this->assertFalse(isset($row->temporary_url));
        $this->assertFalse(isset($row->audio_blob));
    }

    #[Test]
    public function segment_must_belong_to_lesson(): void
    {
        $user = User::factory()->create();
        [$lessonA, $segA] = $this->createLessonAndSegment();
        [$lessonB, $segB] = $this->createLessonAndSegment();

        $file = UploadedFile::fake()->create('test.webm', 100, 'audio/webm');

        /** @var ShadowingRecordingService $service */
        $service = app(ShadowingRecordingService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('không thuộc về bài học');

        $service->storeRecording($user, $lessonA, $segB, $file, 2000);
    }

    #[Test]
    public function user_cannot_record_for_inaccessible_private_lesson(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        [$privateLesson, $seg] = $this->createLessonAndSegment($owner, 'private');
        $file = UploadedFile::fake()->create('test.webm', 100, 'audio/webm');

        /** @var ShadowingRecordingService $service */
        $service = app(ShadowingRecordingService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bạn không có quyền truy cập');

        $service->storeRecording($otherUser, $privateLesson, $seg, $file, 2000);
    }

    #[Test]
    public function recording_is_private_between_users(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        [$lesson, $seg1] = $this->createLessonAndSegment();
        $fileA = UploadedFile::fake()->create('userA.webm', 100, 'audio/webm');

        /** @var ShadowingRecordingService $service */
        $service = app(ShadowingRecordingService::class);
        $recA = $service->storeRecording($userA, $lesson, $seg1, $fileA, 2000);

        // User B cannot request temporary URL for User A's recording
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bạn không có quyền truy cập bản ghi âm này');

        $service->getTemporaryPlaybackUrl($userB, $recA);
    }

    #[Test]
    public function owner_can_request_temporary_playback_url(): void
    {
        $user = User::factory()->create();
        [$lesson, $seg1] = $this->createLessonAndSegment();
        $file = UploadedFile::fake()->create('user.webm', 100, 'audio/webm');

        /** @var ShadowingRecordingService $service */
        $service = app(ShadowingRecordingService::class);
        $rec = $service->storeRecording($user, $lesson, $seg1, $file, 2000);

        $url = $service->getTemporaryPlaybackUrl($user, $rec);

        $this->assertNotEmpty($url);
        $this->assertStringContainsString($rec->object_key, $url);
    }

    #[Test]
    public function non_owner_cannot_request_temporary_playback_url(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        [$lesson, $seg1] = $this->createLessonAndSegment();
        $file = UploadedFile::fake()->create('owner.webm', 100, 'audio/webm');

        /** @var ShadowingRecordingService $service */
        $service = app(ShadowingRecordingService::class);
        $rec = $service->storeRecording($owner, $lesson, $seg1, $file, 2000);

        $this->actingAs($attacker);
        $response = $this->getJson("/shadowing/recordings/{$rec->public_id}/playback-url");

        $response->assertStatus(403);
    }

    #[Test]
    public function re_recording_replaces_previous_recording(): void
    {
        $user = User::factory()->create();
        [$lesson, $seg1] = $this->createLessonAndSegment();

        $file1 = UploadedFile::fake()->create('v1.webm', 100, 'audio/webm');
        $file2 = UploadedFile::fake()->create('v2.webm', 200, 'audio/webm');

        /** @var ShadowingRecordingService $service */
        $service = app(ShadowingRecordingService::class);

        $rec1 = $service->storeRecording($user, $lesson, $seg1, $file1, 2000);
        $key1 = $rec1->object_key;

        $rec2 = $service->storeRecording($user, $lesson, $seg1, $file2, 3000);
        $key2 = $rec2->object_key;

        // DB maintains unique invariant (1 active recording per user+lesson+segment)
        $this->assertEquals(1, ShadowingRecording::where('user_id', $user->id)->where('shadowing_segment_id', $seg1->id)->count());
        $this->assertEquals($key2, $rec2->fresh()->object_key);
        $this->assertNotEquals($key1, $key2);
    }

    #[Test]
    public function successful_replacement_deletes_old_object(): void
    {
        $user = User::factory()->create();
        [$lesson, $seg1] = $this->createLessonAndSegment();

        $file1 = UploadedFile::fake()->create('old.webm', 100, 'audio/webm');
        $file2 = UploadedFile::fake()->create('new.webm', 200, 'audio/webm');

        /** @var ShadowingRecordingService $service */
        $service = app(ShadowingRecordingService::class);

        $rec1 = $service->storeRecording($user, $lesson, $seg1, $file1, 2000);
        $oldKey = $rec1->object_key;
        Storage::disk('media')->assertExists($oldKey);

        $rec2 = $service->storeRecording($user, $lesson, $seg1, $file2, 3000);
        $newKey = $rec2->object_key;

        Storage::disk('media')->assertMissing($oldKey);
        Storage::disk('media')->assertExists($newKey);
    }

    #[Test]
    public function failed_replacement_does_not_destroy_previous_valid_recording(): void
    {
        $user = User::factory()->create();
        [$lesson, $seg1] = $this->createLessonAndSegment();

        $file1 = UploadedFile::fake()->create('valid.webm', 100, 'audio/webm');

        /** @var ShadowingRecordingService $service */
        $service = app(ShadowingRecordingService::class);

        $rec1 = $service->storeRecording($user, $lesson, $seg1, $file1, 2000);
        $validKey = $rec1->object_key;

        // Attempt replacement with an oversized file (> 5MB)
        $invalidFile = UploadedFile::fake()->create('too_large.webm', 6000, 'audio/webm');

        try {
            $service->storeRecording($user, $lesson, $seg1, $invalidFile, 2000);
        } catch (InvalidArgumentException $e) {
            // Expected validation error
        }

        // Existing previous recording remains intact
        Storage::disk('media')->assertExists($validKey);
        $this->assertEquals($validKey, ShadowingRecording::find($rec1->id)->object_key);
    }

    #[Test]
    public function invalid_mime_type_is_rejected(): void
    {
        $user = User::factory()->create();
        [$lesson, $seg1] = $this->createLessonAndSegment();

        $executableFile = UploadedFile::fake()->create('malicious.exe', 50, 'application/vnd.microsoft.portable-executable');

        /** @var ShadowingRecordingService $service */
        $service = app(ShadowingRecordingService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('không được hỗ trợ');

        $service->storeRecording($user, $lesson, $seg1, $executableFile, 2000);
    }

    #[Test]
    public function oversized_recording_is_rejected(): void
    {
        $user = User::factory()->create();
        [$lesson, $seg1] = $this->createLessonAndSegment();

        $hugeFile = UploadedFile::fake()->create('huge.webm', 6000, 'audio/webm'); // 6MB > 5MB limit

        /** @var ShadowingRecordingService $service */
        $service = app(ShadowingRecordingService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('vượt quá giới hạn tối đa');

        $service->storeRecording($user, $lesson, $seg1, $hugeFile, 2000);
    }

    #[Test]
    public function deleting_recording_deletes_private_object_and_metadata(): void
    {
        $user = User::factory()->create();
        [$lesson, $seg1] = $this->createLessonAndSegment();

        $file = UploadedFile::fake()->create('to_delete.webm', 100, 'audio/webm');

        /** @var ShadowingRecordingService $service */
        $service = app(ShadowingRecordingService::class);

        $rec = $service->storeRecording($user, $lesson, $seg1, $file, 2000);
        $objectKey = $rec->object_key;
        Storage::disk('media')->assertExists($objectKey);

        $service->deleteRecording($user, $rec);

        Storage::disk('media')->assertMissing($objectKey);
        $this->assertDatabaseMissing('shadowing_recordings', ['id' => $rec->id]);
    }

    #[Test]
    public function opening_practice_loads_only_current_users_recording(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        [$lesson, $seg1] = $this->createLessonAndSegment();

        $fileA = UploadedFile::fake()->create('voiceA.webm', 100, 'audio/webm');
        $fileB = UploadedFile::fake()->create('voiceB.webm', 100, 'audio/webm');

        /** @var ShadowingRecordingService $service */
        $service = app(ShadowingRecordingService::class);

        $recA = $service->storeRecording($userA, $lesson, $seg1, $fileA, 2000);
        $recB = $service->storeRecording($userB, $lesson, $seg1, $fileB, 2000);

        // User A opens practice component
        $this->actingAs($userA);
        $componentA = Livewire::test(ShadowingPractice::class, ['lessonCode' => $lesson->code]);

        $userRecordingsA = $componentA->get('userRecordings');
        $this->assertArrayHasKey($seg1->id, $userRecordingsA);
        $this->assertEquals($recA->public_id, $userRecordingsA[$seg1->id]['public_id']);

        // User B opens practice component
        $this->actingAs($userB);
        $componentB = Livewire::test(ShadowingPractice::class, ['lessonCode' => $lesson->code]);

        $userRecordingsB = $componentB->get('userRecordings');
        $this->assertArrayHasKey($seg1->id, $userRecordingsB);
        $this->assertEquals($recB->public_id, $userRecordingsB[$seg1->id]['public_id']);
    }

    #[Test]
    public function guest_never_persists_as_user_1(): void
    {
        [$lesson, $seg1] = $this->createLessonAndSegment();
        $file = UploadedFile::fake()->create('guest.webm', 100, 'audio/webm');

        $response = $this->postJson('/shadowing/recordings/upload', [
            'audio' => $file,
            'lesson_id' => $lesson->id,
            'segment_id' => $seg1->id,
        ], ['Accept' => 'application/json']);

        $this->assertTrue(in_array($response->status(), [401, 302], true));
        $this->assertDatabaseMissing('shadowing_recordings', ['user_id' => 1]);
    }

    #[Test]
    public function storage_business_flow_never_requires_r2_minio_or_s3_disk_name(): void
    {
        $mockStorage = $this->createMock(ShadowingRecordingStorageContract::class);
        $mockStorage->expects($this->once())
            ->method('store')
            ->willReturn(true);
        $mockStorage->expects($this->once())
            ->method('temporaryUrl')
            ->willReturn('http://localhost/temp-url');

        $service = new ShadowingRecordingService($mockStorage);

        $user = User::factory()->create();
        [$lesson, $seg1] = $this->createLessonAndSegment();
        $file = UploadedFile::fake()->create('voice.webm', 100, 'audio/webm');

        $recording = $service->storeRecording($user, $lesson, $seg1, $file, 2000);
        $url = $service->getTemporaryPlaybackUrl($user, $recording);

        $this->assertEquals('http://localhost/temp-url', $url);
        $this->assertEquals('media', $recording->disk);
        $this->assertNotEquals('r2', $recording->disk);
        $this->assertNotEquals('minio', $recording->disk);
        $this->assertNotEquals('s3', $recording->disk);
    }
}
