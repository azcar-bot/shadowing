<?php

namespace App\Modules\Shadowing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shadowing\Domain\Services\ShadowingRecordingService;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingLesson;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingRecording;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSegment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ShadowingRecordingController extends Controller
{
    public function __construct(
        protected ShadowingRecordingService $service
    ) {}

    /**
     * Upload and store a student voice recording for a lesson segment.
     */
    public function upload(Request $request): JsonResponse
    {
        if (! config('shadowing.recording.enabled', true)) {
            return response()->json(['success' => false, 'message' => 'Chức năng ghi âm hiện đang tạm tắt.'], 403);
        }

        $user = Auth::user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Bạn cần đăng nhập để thực hiện ghi âm.'], 401);
        }

        $request->validate([
            'audio' => 'required|file',
            'lesson_id' => 'required|integer',
            'segment_id' => 'required|integer',
            'duration_ms' => 'nullable|integer|min:0',
        ]);

        $lesson = ShadowingLesson::find($request->input('lesson_id'));
        if (! $lesson) {
            return response()->json(['success' => false, 'message' => 'Bài học không tồn tại.'], 404);
        }

        $segment = ShadowingSegment::find($request->input('segment_id'));
        if (! $segment) {
            return response()->json(['success' => false, 'message' => 'Segment không tồn tại.'], 404);
        }

        try {
            $recording = $this->service->storeRecording(
                user: $user,
                lesson: $lesson,
                segment: $segment,
                file: $request->file('audio'),
                durationMs: (int) $request->input('duration_ms', 0)
            );

            $playbackUrl = $this->service->getTemporaryPlaybackUrl($user, $recording);

            return response()->json([
                'success' => true,
                'public_id' => $recording->public_id,
                'segment_id' => $recording->shadowing_segment_id,
                'playback_url' => $playbackUrl,
                'size_bytes' => $recording->size_bytes,
                'duration_ms' => $recording->duration_ms,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Shadowing recording upload internal error', [
                'user_id' => $user->id,
                'lesson_id' => $lesson->id,
                'segment_id' => $segment->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Lỗi hệ thống khi tải lên file ghi âm. Vui lòng thử lại sau.',
            ], 500);
        }
    }

    /**
     * Get a temporary playback URL for an existing recording.
     */
    public function getPlaybackUrl(string $publicId): JsonResponse
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $recording = ShadowingRecording::where('public_id', $publicId)->first();
        if (! $recording) {
            return response()->json(['success' => false, 'message' => 'Bản ghi âm không tồn tại.'], 404);
        }

        try {
            $playbackUrl = $this->service->getTemporaryPlaybackUrl($user, $recording);
            return response()->json([
                'success' => true,
                'public_id' => $recording->public_id,
                'playback_url' => $playbackUrl,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (Throwable $e) {
            Log::error('Shadowing getPlaybackUrl error', ['public_id' => $publicId, 'exception' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Lỗi hệ thống khi tải phát lại file ghi âm.'], 500);
        }
    }

    /**
     * Delete a student recording.
     */
    public function destroy(string $publicId): JsonResponse
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $recording = ShadowingRecording::where('public_id', $publicId)->first();
        if (! $recording) {
            return response()->json(['success' => false, 'message' => 'Bản ghi âm không tồn tại.'], 404);
        }

        try {
            $this->service->deleteRecording($user, $recording);
            return response()->json(['success' => true, 'message' => 'Bản ghi âm đã được xóa.']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (Throwable $e) {
            Log::error('Shadowing deleteRecording error', ['public_id' => $publicId, 'exception' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Lỗi hệ thống khi xóa file ghi âm.'], 500);
        }
    }
}
