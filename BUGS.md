# Active & Resolved Bugs — Shadowing Module

## Open Bugs

### BUG-004: Wrong Transcript Source — Hardcoded Legacy Lesson Code [P0 CRITICAL]
- **Reported:** 2026-08-13
- **Status:** OPEN — IN_PROGRESS (NEEDS_FIX after 2 review rounds)
- **Root Cause:** `ShadowingPractice.php` has hardcoded `$lessonCode = 'shadowing_youtube_tekkon'` which loads a legacy prototype lesson by default. The fallback chain (`?? ShadowingLesson::first()`) can load ANY lesson including invalid ones.
- **Impact:** When user creates a new YouTube lesson, the old prototype transcript may still display. Cross-contamination between Video A and Video B.
- **Required Fix:**
  1. Remove hardcoded `$lessonCode = 'shadowing_youtube_tekkon'`.
  2. Replace fallback chain with safe empty-state default.
  3. Update `availableLessons()` to exclude legacy/invalid lessons (missing `source_id`, fake transcript sources).
  4. Fix `loadUserProgress()` — `(float) $prog->best_score` casts null to 0.0.
  5. Add `transcript_source` type validation to `validateSource()`.
  6. Clean up stale legacy lesson data.
- **Reviewer Verdict:** `NEEDS_FIX` — Do not merge until all 6 sub-items are addressed and E2E verified.

### BUG-005: Null Score Cast in loadUserProgress [P0]
- **Reported:** 2026-08-13
- **Status:** OPEN
- **Root Cause:** `'score' => (float) $prog->best_score` in `loadUserProgress()` converts database `NULL` to `0.0`, losing the distinction between "unscored" and "scored 0".
- **Required Fix:** Use `$prog->best_score !== null ? (float) $prog->best_score : null` instead of `(float) $prog->best_score`.

---

## Resolved Bugs

### BUG-001: YouTube 429 Too Many Requests on Direct Subprocess Caption Extraction
- **Reported:** 2026-08-12
- **Root Cause:** YouTube IP blocking on cloud/Docker container subnets.
- **Fix:** Implemented `yt_caption_proxy.py` HTTP server on host machine.
- **Status:** RESOLVED (Verified on videos `zc87_hodp2g`, `wNJ4r2WsUMI`).

### BUG-002: Modal Blur / Dim Overlays Blocking Shadowing Creator UI
- **Reported:** 2026-08-12
- **Root Cause:** Backdrop filter blur CSS overlaying active inputs.
- **Fix:** Removed backdrop blur utility classes from modal container.
- **Status:** RESOLVED (Verified via Playwright browser test).

### BUG-003: Fake Score 100.0 / Auth::id() ?? 1
- **Reported:** 2026-08-13
- **Root Cause:** `ShadowingAttemptService::recordAttempt()` defaulted `$score = 100.0`; `Auth::id() ?? 1` used guest ID 1.
- **Fix:** Changed score to `?float $score = null`; removed `Auth::id() ?? 1` pattern.
- **Status:** RESOLVED (Nullable migration applied, service updated).
