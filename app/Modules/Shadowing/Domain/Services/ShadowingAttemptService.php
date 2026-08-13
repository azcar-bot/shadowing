<?php

namespace App\Modules\Shadowing\Domain\Services;

use App\Modules\Shadowing\Infrastructure\Persistence\Models\UserShadowingAttempt;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\UserShadowingProgress;
use Illuminate\Support\Carbon;

class ShadowingAttemptService
{
    public function recordAttempt(
        int $userId,
        int $segmentId,
        string $mode = 'LISTEN_REPEAT',
        ?string $audioUrl = null,
        int $durationMs = 0,
        float $score = 100.0
    ): UserShadowingAttempt {
        $attempt = UserShadowingAttempt::create([
            'user_id' => $userId,
            'shadowing_segment_id' => $segmentId,
            'mode' => $mode,
            'audio_recording_url' => $audioUrl,
            'duration_ms' => $durationMs,
            'score' => $score,
        ]);

        $progress = UserShadowingProgress::firstOrNew([
            'user_id' => $userId,
            'shadowing_segment_id' => $segmentId,
        ]);

        $progress->best_score = max($progress->best_score ?? 0.0, $score);
        $progress->practice_count = ($progress->practice_count ?? 0) + 1;
        $progress->is_completed = $progress->best_score >= 75.0;
        $progress->last_practiced_at = Carbon::now();
        $progress->save();

        return $attempt;
    }
}
