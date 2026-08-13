<?php

namespace App\Livewire;

use App\Modules\Shadowing\Domain\Services\ShadowingAttemptService;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingLesson;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSegment;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\UserShadowingProgress;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ShadowingPractice extends Component
{
    public string $lessonCode = '';

    public int $currentIndex = 1;

    public string $practiceMode = 'LISTEN_REPEAT'; // LISTEN_REPEAT, SHADOWING, CHALLENGE

    public bool $showTranslation = true;

    public bool $showIpa = true;

    public bool $revealedInChallenge = false;

    public array $userAttempts = [];

    public bool $isPreviewMode = false;

    public bool $weakOnlyFilter = false;

    public function mount(?string $lessonCode = null): void
    {
        if ($lessonCode) {
            $this->lessonCode = $lessonCode;
        } elseif (request()->has('lesson')) {
            $this->lessonCode = request()->get('lesson');
        }

        $user = Auth::user();
        $this->isPreviewMode = $user && ($user->hasRole('teacher') || $user->hasRole('admin'));

        $this->loadUserProgress();
    }

    public function switchLesson(string $code): void
    {
        $this->lessonCode = $code;
        $this->currentIndex = 1;
        $this->revealedInChallenge = false;
        $this->loadUserProgress();
    }

    #[Computed]
    public function availableLessons()
    {
        $userId = Auth::id();
        $forbidden = ['ai_generated_fallback', 'mock', 'demo', 'sample', 'fake', 'prototype'];

        return ShadowingLesson::select('id', 'code', 'title', 'level', 'media_type', 'total_segments', 'visibility', 'user_id', 'source_id', 'youtube_video_id', 'is_official')
            ->where('status', 'published')
            ->where(function ($q) use ($forbidden) {
                // 1. YouTube lessons MUST have valid completed source with clean transcript_source
                $q->where(function ($yt) use ($forbidden) {
                    $yt->where('media_type', 'youtube')
                       ->whereNotNull('source_id')
                       ->whereHas('source', function ($srcQuery) use ($forbidden) {
                           $srcQuery->where('status', 'completed')
                                    ->whereNotNull('transcript_source')
                                    ->whereNotIn(DB::raw('LOWER(transcript_source)'), $forbidden);
                       });
                })
                // 2. Non-YouTube / Manual lessons MUST be explicitly official with segments
                ->orWhere(function ($manual) {
                    $manual->where('media_type', '!=', 'youtube')
                           ->where('is_official', true)
                           ->where('total_segments', '>', 0);
                });
            })
            ->where(function ($q) use ($userId) {
                $q->where('visibility', 'official');
                if ($userId) {
                    $q->orWhere(function ($sub) use ($userId) {
                        $sub->where('visibility', 'private')->where('user_id', $userId);
                    });
                }
            })
            ->orderBy('id', 'asc')
            ->get();
    }

    #[Computed]
    public function lesson()
    {
        if (empty($this->lessonCode)) {
            return null;
        }

        $lesson = ShadowingLesson::where('code', $this->lessonCode)
            ->where('status', 'published')
            ->first();

        if (!$lesson) {
            return null;
        }

        // TRANSCRIPT SOURCE INTEGRITY CHECK
        if ($lesson->source_id && $lesson->source) {
            if ($lesson->youtube_video_id && $lesson->source->youtube_video_id !== $lesson->youtube_video_id) {
                \Illuminate\Support\Facades\Log::critical('BUG-004 TRANSCRIPT MISMATCH DETECTED', [
                    'lesson_id' => $lesson->id,
                    'lesson_video' => $lesson->youtube_video_id,
                    'source_video' => $lesson->source->youtube_video_id,
                ]);
                throw new \RuntimeException("Phát hiện lỗi không đồng bộ dữ liệu: Video lesson ({$lesson->youtube_video_id}) không khớp với nguồn transcript ({$lesson->source->youtube_video_id}).");
            }
        }

        if ($lesson->visibility === 'private') {
            $user = Auth::user();
            if (!$user || ($lesson->user_id !== $user->id && !$user->isAdmin())) {
                abort(403, 'Bạn không có quyền truy cập bài Shadowing cá nhân này.');
            }
        }

        return $lesson;
    }

    #[Computed]
    public function studentSegments()
    {
        if (!$this->lesson) {
            return collect();
        }

        $segments = ShadowingSegment::where('shadowing_lesson_id', $this->lesson->id)
            ->orderBy('segment_index', 'asc')
            ->get();

        return $segments->map(function ($s) {
            $isChallenge = $this->practiceMode === 'CHALLENGE' && !$this->revealedInChallenge;
            return (object) [
                'id' => $s->id,
                'shadowing_lesson_id' => $s->shadowing_lesson_id,
                'segment_index' => (int) $s->segment_index,
                'start_ms' => (int) $s->start_ms,
                'end_ms' => (int) $s->end_ms,
                'transcript' => $isChallenge ? '••••••••••••••••••••••••' : $s->transcript,
                'translation_vi' => $isChallenge ? '••••••••••••••••' : $s->translation_vi,
                'translation' => $isChallenge ? '••••••••••••••••' : ($s->translation_vi ?? ''),
                'ipa' => $s->ipa,
                'speaker' => $s->speaker,
                'difficulty' => $s->difficulty,
                'loop_default' => (int) $s->loop_default,
            ];
        });
    }

    #[Computed]
    public function currentStudentSegment()
    {
        return $this->studentSegments->firstWhere('segment_index', $this->currentIndex)
            ?? $this->studentSegments->first();
    }

    #[Computed]
    public function completedCount(): int
    {
        return count(array_filter($this->userAttempts, fn ($a) => ($a['is_completed'] ?? false) || ($a['score'] ?? 0) >= 75));
    }

    public function selectSegment(int $index): void
    {
        if ($index < 1 || $index > count($this->studentSegments)) {
            return;
        }

        $this->currentIndex = $index;
        $this->revealedInChallenge = false;
    }

    public function nextSegment(): void
    {
        if ($this->currentIndex < count($this->studentSegments)) {
            $this->selectSegment($this->currentIndex + 1);
        }
    }

    public function prevSegment(): void
    {
        if ($this->currentIndex > 1) {
            $this->selectSegment($this->currentIndex - 1);
        }
    }

    public function setMode(string $mode): void
    {
        if (in_array($mode, ['LISTEN_REPEAT', 'SHADOWING', 'CHALLENGE'])) {
            $this->practiceMode = $mode;
            $this->revealedInChallenge = false;
        }
    }

    public function revealChallengeTranscript(): void
    {
        $this->revealedInChallenge = true;
    }

    public function recordAttempt(?float $score = null, ?string $audioUrl = null, int $durationMs = 0): void
    {
        $segment = $this->currentStudentSegment;
        if (!$segment) {
            return;
        }

        $userId = Auth::id();
        if ($userId && !$this->isPreviewMode) {
            /** @var ShadowingAttemptService $service */
            $service = app(ShadowingAttemptService::class);
            $service->recordAttempt(
                userId: $userId,
                segmentId: $segment->id,
                mode: $this->practiceMode,
                audioUrl: null,
                durationMs: $durationMs,
                score: $score
            );

            // Auto-update mastery_status based on practice_count and best_score
            $progress = UserShadowingProgress::where('user_id', $userId)
                ->where('shadowing_segment_id', $segment->id)
                ->first();

            if ($progress) {
                $newStatus = $this->computeMasteryStatus(
                    $progress->best_score !== null ? (float) $progress->best_score : null,
                    (int) $progress->practice_count
                );
                if ($progress->mastery_status !== $newStatus) {
                    $progress->update(['mastery_status' => $newStatus]);
                }
            }
        }

        $practiceCount = ($this->userAttempts[$this->currentIndex]['practice_count'] ?? 0) + 1;
        $prevScore = $this->userAttempts[$this->currentIndex]['score'] ?? null;
        $bestScore = $score !== null ? ($prevScore !== null ? max($score, $prevScore) : $score) : $prevScore;

        $this->userAttempts[$this->currentIndex] = [
            'score'          => $bestScore,
            'is_completed'   => $bestScore !== null && $bestScore >= 75.0,
            'audio_url'      => $audioUrl,
            'practice_count' => $practiceCount,
            'mastery_status' => $this->computeMasteryStatus($bestScore, $practiceCount),
        ];

        $this->revealedInChallenge = true;
    }

    public function markSegmentStatus(int $segmentIndex, string $status): void
    {
        if (!in_array($status, ['unseen', 'practicing', 'needs_review', 'mastered'])) {
            return;
        }

        $userId = Auth::id();
        if (!$userId || $this->isPreviewMode) {
            return;
        }

        $segment = $this->studentSegments->firstWhere('segment_index', $segmentIndex);
        if (!$segment) {
            return;
        }

        UserShadowingProgress::updateOrCreate(
            ['user_id' => $userId, 'shadowing_segment_id' => $segment->id],
            ['mastery_status' => $status]
        );

        $this->userAttempts[$segmentIndex] = array_merge(
            $this->userAttempts[$segmentIndex] ?? [],
            ['mastery_status' => $status]
        );
    }

    private function computeMasteryStatus(?float $bestScore, int $practiceCount): string
    {
        if ($bestScore !== null && $bestScore >= 75.0) {
            return 'mastered';
        }
        if ($practiceCount >= 3) {
            return 'needs_review';
        }
        if ($practiceCount >= 1) {
            return 'practicing';
        }
        return 'unseen';
    }

    public array $userRecordings = [];

    private function loadUserProgress(): void
    {
        $userId = Auth::id();
        if (!$userId || $this->isPreviewMode || !$this->lesson) {
            return;
        }

        $user = Auth::user();
        $segmentIds = ShadowingSegment::where('shadowing_lesson_id', $this->lesson->id)->pluck('id')->toArray();

        $progresses = UserShadowingProgress::where('user_id', $userId)
            ->whereIn('shadowing_segment_id', $segmentIds)
            ->get();

        foreach ($progresses as $prog) {
            $seg = ShadowingSegment::find($prog->shadowing_segment_id);
            if ($seg) {
                $this->userAttempts[$seg->segment_index] = [
                    'score'          => $prog->best_score !== null ? (float) $prog->best_score : null,
                    'is_completed'   => (bool) $prog->is_completed,
                    'practice_count' => (int) $prog->practice_count,
                    'mastery_status' => $prog->mastery_status ?? 'unseen',
                ];
            }
        }

        // Load active student recordings for this lesson
        $recordings = \App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingRecording::where('user_id', $userId)
            ->where('shadowing_lesson_id', $this->lesson->id)
            ->get();

        /** @var \App\Modules\Shadowing\Domain\Services\ShadowingRecordingService $recordingService */
        $recordingService = app(\App\Modules\Shadowing\Domain\Services\ShadowingRecordingService::class);

        $this->userRecordings = [];
        foreach ($recordings as $rec) {
            try {
                $playbackUrl = $recordingService->getTemporaryPlaybackUrl($user, $rec);
                $this->userRecordings[$rec->shadowing_segment_id] = [
                    'public_id' => $rec->public_id,
                    'playback_url' => $playbackUrl,
                    'duration_ms' => $rec->duration_ms,
                    'size_bytes' => $rec->size_bytes,
                ];
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to generate temporary URL for recording ID {$rec->id}", ['error' => $e->getMessage()]);
            }
        }
    }

    public function render()
    {
        return view('livewire.shadowing-practice');
    }
}
