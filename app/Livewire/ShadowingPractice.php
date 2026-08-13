<?php

namespace App\Livewire;

use App\Modules\Shadowing\Domain\Services\ShadowingAttemptService;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingLesson;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSegment;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\UserShadowingProgress;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ShadowingPractice extends Component
{
    public string $lessonCode = 'shadowing_youtube_tekkon';

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

        return ShadowingLesson::select('id', 'code', 'title', 'level', 'media_type', 'total_segments', 'visibility', 'user_id')
            ->where(function ($q) use ($userId) {
                $q->where('visibility', 'official')->where('status', 'published');
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
        $lesson = ShadowingLesson::where('code', $this->lessonCode)->first()
            ?? ShadowingLesson::where('status', 'published')->first()
            ?? ShadowingLesson::first();

        if ($lesson && $lesson->visibility === 'private') {
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

    public function recordAttempt(float $score = 100.0, ?string $audioUrl = null, int $durationMs = 0): void
    {
        $segment = $this->currentStudentSegment;
        if (!$segment) {
            return;
        }

        if (!$this->isPreviewMode) {
            $userId = Auth::id() ?? 1;
            /** @var ShadowingAttemptService $service */
            $service = app(ShadowingAttemptService::class);
            $service->recordAttempt(
                userId: $userId,
                segmentId: $segment->id,
                mode: $this->practiceMode,
                audioUrl: $audioUrl,
                durationMs: $durationMs,
                score: $score
            );

            // Auto-update mastery_status based on practice_count and best_score
            $progress = UserShadowingProgress::where('user_id', $userId)
                ->where('shadowing_segment_id', $segment->id)
                ->first();

            if ($progress) {
                $newStatus = $this->computeMasteryStatus(
                    (float) $progress->best_score,
                    (int) $progress->practice_count
                );
                if ($progress->mastery_status !== $newStatus) {
                    $progress->update(['mastery_status' => $newStatus]);
                }
            }
        }

        $practiceCount = ($this->userAttempts[$this->currentIndex]['practice_count'] ?? 0) + 1;
        $bestScore = max($score, $this->userAttempts[$this->currentIndex]['score'] ?? 0);

        $this->userAttempts[$this->currentIndex] = [
            'score'          => $bestScore,
            'is_completed'   => $bestScore >= 75.0,
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

    private function computeMasteryStatus(float $bestScore, int $practiceCount): string
    {
        if ($bestScore >= 75.0) {
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

    private function loadUserProgress(): void
    {
        if ($this->isPreviewMode || !$this->lesson) {
            return;
        }

        $userId = Auth::id() ?? 1;
        $segmentIds = ShadowingSegment::where('shadowing_lesson_id', $this->lesson->id)->pluck('id')->toArray();

        $progresses = UserShadowingProgress::where('user_id', $userId)
            ->whereIn('shadowing_segment_id', $segmentIds)
            ->get();

        foreach ($progresses as $prog) {
            $seg = ShadowingSegment::find($prog->shadowing_segment_id);
            if ($seg) {
                $this->userAttempts[$seg->segment_index] = [
                    'score'          => (float) $prog->best_score,
                    'is_completed'   => (bool) $prog->is_completed,
                    'practice_count' => (int) $prog->practice_count,
                    'mastery_status' => $prog->mastery_status ?? 'unseen',
                ];
            }
        }
    }

    public function render()
    {
        return view('livewire.shadowing-practice');
    }
}
