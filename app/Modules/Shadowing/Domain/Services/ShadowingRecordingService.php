<?php

namespace App\Modules\Shadowing\Domain\Services;

use App\Models\User;
use App\Modules\Shadowing\Domain\Contracts\ShadowingRecordingStorageContract;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingLesson;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingRecording;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSegment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ShadowingRecordingService
{
    public function __construct(
        protected ShadowingRecordingStorageContract $storage
    ) {}

    /**
     * Store or replace a student voice recording for a specific lesson segment.
     */
    public function storeRecording(
        User $user,
        ShadowingLesson $lesson,
        ShadowingSegment $segment,
        UploadedFile $file,
        int $durationMs = 0
    ): ShadowingRecording {
        // 1. VALIDATION GATES
        $this->authorizeLessonAccess($user, $lesson);
        $this->validateSegmentBelongsToLesson($segment, $lesson);
        $this->validateUploadedFile($file);

        $maxDuration = (int) config('shadowing.recording.max_duration_ms', 120000);
        if ($durationMs > $maxDuration) {
            throw new InvalidArgumentException("Độ dài file ghi âm ({$durationMs}ms) vượt quá giới hạn tối đa ({$maxDuration}ms).");
        }

        // Determine file extension
        $mimeType = $file->getClientMimeType() ?: $file->getMimeType() ?: 'audio/webm';
        $extension = match (true) {
            str_contains($mimeType, 'mp4') || str_contains($mimeType, 'm4a') => 'm4a',
            str_contains($mimeType, 'ogg') => 'ogg',
            str_contains($mimeType, 'wav') => 'wav',
            default => 'webm',
        };

        $userPublicId = $user->public_id ?? $user->id;
        $ulid = (string) Str::ulid();

        // 2. SERVER-GENERATED OBJECT KEY DESIGN
        $objectKey = sprintf(
            'shadowing/recordings/%s/%s/%d/%s.%s',
            $userPublicId,
            strtolower($lesson->code),
            $segment->segment_index,
            $ulid,
            $extension
        );

        $fileContents = file_get_contents($file->getRealPath());
        if ($fileContents === false) {
            throw new RuntimeException("Không thể đọc nội dung file ghi âm tải lên.");
        }

        $sizeBytes = $file->getSize();

        // 3. SAFE RE-RECORDING / REPLACEMENT FLOW
        // Step A: Store NEW object to storage first
        $stored = $this->storage->store($objectKey, $fileContents, $mimeType);
        if (! $stored) {
            throw new RuntimeException("Không thể lưu trữ file ghi âm vào hệ thống lưu trữ media.");
        }

        $oldObjectKey = null;
        $recording = null;

        try {
            // Step B: DB Transaction to update/create recording record
            $recording = DB::transaction(function () use ($user, $lesson, $segment, $objectKey, $mimeType, $sizeBytes, $durationMs, &$oldObjectKey) {
                $existing = ShadowingRecording::where('user_id', $user->id)
                    ->where('shadowing_lesson_id', $lesson->id)
                    ->where('shadowing_segment_id', $segment->id)
                    ->first();

                if ($existing) {
                    $oldObjectKey = $existing->object_key;
                    $existing->update([
                        'disk' => 'media',
                        'object_key' => $objectKey,
                        'mime_type' => $mimeType,
                        'size_bytes' => $sizeBytes,
                        'duration_ms' => $durationMs,
                    ]);
                    return $existing->fresh();
                }

                return ShadowingRecording::create([
                    'user_id' => $user->id,
                    'shadowing_lesson_id' => $lesson->id,
                    'shadowing_segment_id' => $segment->id,
                    'disk' => 'media',
                    'object_key' => $objectKey,
                    'mime_type' => $mimeType,
                    'size_bytes' => $sizeBytes,
                    'duration_ms' => $durationMs,
                ]);
            });
        } catch (Throwable $e) {
            // Step C: If DB fails, delete newly uploaded object to prevent orphan
            Log::error("DB transaction failed while saving recording metadata. Cleaning up new object: {$objectKey}", ['error' => $e->getMessage()]);
            $this->storage->delete($objectKey);
            throw new RuntimeException("Lỗi lưu trữ dữ liệu ghi âm: " . $e->getMessage(), 0, $e);
        }

        // Step D: After DB transaction succeeds, delete old object if replaced
        if ($oldObjectKey && $oldObjectKey !== $objectKey) {
            try {
                $this->storage->delete($oldObjectKey);
            } catch (Throwable $e) {
                Log::warning("Failed to delete replaced recording old object: {$oldObjectKey}", ['error' => $e->getMessage()]);
            }
        }

        return $recording;
    }

    /**
     * Generate an authorized temporary playback URL for a student recording.
     */
    public function getTemporaryPlaybackUrl(User $user, ShadowingRecording $recording): string
    {
        $this->authorizeRecordingAccess($user, $recording);

        $ttlMinutes = (int) config('shadowing.recording.temporary_url_ttl_minutes', 10);
        return $this->storage->temporaryUrl($recording->object_key, $ttlMinutes);
    }

    /**
     * Delete a student recording and its private media object.
     */
    public function deleteRecording(User $user, ShadowingRecording $recording): bool
    {
        $this->authorizeRecordingAccess($user, $recording);

        $objectKey = $recording->object_key;

        DB::transaction(function () use ($recording) {
            $recording->delete();
        });

        if ($objectKey) {
            try {
                $this->storage->delete($objectKey);
            } catch (Throwable $e) {
                Log::warning("Failed to delete object from media storage: {$objectKey}", ['error' => $e->getMessage()]);
            }
        }

        return true;
    }

    /**
     * Authorize lesson access for user.
     */
    public function authorizeLessonAccess(User $user, ShadowingLesson $lesson): void
    {
        if ($lesson->visibility === 'private' && $lesson->user_id !== $user->id && ! $user->isAdmin()) {
            throw new InvalidArgumentException("Bạn không có quyền truy cập bài học cá nhân này.");
        }
    }

    /**
     * Authorize recording access for user.
     */
    public function authorizeRecordingAccess(User $user, ShadowingRecording $recording): void
    {
        if ($recording->user_id !== $user->id && ! $user->isAdmin()) {
            throw new InvalidArgumentException("Bạn không có quyền truy cập bản ghi âm này.");
        }
    }

    /**
     * Validate that segment belongs to lesson.
     */
    public function validateSegmentBelongsToLesson(ShadowingSegment $segment, ShadowingLesson $lesson): void
    {
        if ($segment->shadowing_lesson_id !== $lesson->id) {
            throw new InvalidArgumentException("Segment ID {$segment->id} không thuộc về bài học ID {$lesson->id}.");
        }
    }

    /**
     * Validate uploaded file.
     */
    public function validateUploadedFile(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new InvalidArgumentException("File ghi âm không hợp lệ hoặc đã bị lỗi khi tải lên.");
        }

        $maxSizeKb = (int) config('shadowing.recording.max_size_kb', 5120);
        if ($file->getSize() > $maxSizeKb * 1024) {
            throw new InvalidArgumentException("Kích thước file ghi âm vượt quá giới hạn tối đa ({$maxSizeKb} KB).");
        }

        $allowedMimes = config('shadowing.recording.allowed_mime_types', [
            'audio/webm', 'audio/webm;codecs=opus', 'audio/mp4', 'audio/m4a', 'audio/ogg', 'audio/wav', 'audio/x-matroska'
        ]);

        $mimeType = strtolower($file->getClientMimeType() ?: $file->getMimeType());
        $cleanMime = explode(';', $mimeType)[0];

        $matched = false;
        foreach ($allowedMimes as $allowed) {
            $cleanAllowed = explode(';', strtolower($allowed))[0];
            if ($cleanMime === $cleanAllowed || $mimeType === strtolower($allowed)) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            throw new InvalidArgumentException("Định dạng file ghi âm ('{$mimeType}') không được hỗ trợ.");
        }
    }
}
