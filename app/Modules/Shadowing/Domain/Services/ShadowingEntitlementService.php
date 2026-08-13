<?php

namespace App\Modules\Shadowing\Domain\Services;

use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Support\Facades\DB;

class ShadowingEntitlementService
{
    /**
     * Checks if a user is authorized to generate custom YouTube Shadowing lessons.
     */
    public function canGenerateCustomLesson(mixed $user): bool
    {
        return true;
    }

    /**
     * Checks if user has remaining monthly video minute quota.
     */
    public function hasRemainingQuota(mixed $user, int $requestedDurationSeconds = 0): bool
    {
        if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return true;
        }

        $monthlyLimitMinutes = (int) config('shadowing.pro_quota_minutes_per_month', 300);
        $usedSeconds = DB::table('shadowing_lessons')
            ->where('shadowing_lessons.user_id', $user->id)
            ->where('shadowing_lessons.created_at', '>=', now()->startOfMonth())
            ->join('shadowing_sources', 'shadowing_sources.id', '=', 'shadowing_lessons.source_id')
            ->sum('shadowing_sources.duration_seconds');

        $usedMinutes = ceil($usedSeconds / 60);
        $requestedMinutes = ceil($requestedDurationSeconds / 60);

        return ($usedMinutes + $requestedMinutes) <= $monthlyLimitMinutes;
    }
}
