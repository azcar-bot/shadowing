# Task Registry — Shadowing Module

## Active Queue

### [BLOCKER] BUG-004: Wrong Transcript Source — Fix Required Before Any New Phase
- **Priority:** P0 CRITICAL
- **Status:** NEEDS_FIX (Review Round 2)
- **Scope:** See BUGS.md BUG-004 for full details. All 6 sub-items must be fixed.
- **Reviewer Verdict:** Do NOT proceed to Phase ⑤ until BUG-004 receives `ACCEPT`.

### [BLOCKER] BUG-005: Null Score Cast in loadUserProgress
- **Priority:** P0
- **Status:** OPEN
- **Scope:** Fix `(float) $prog->best_score` → preserve null. See BUGS.md.

### [BLOCKED] Phase ⑤: Student Recording — Cloudflare R2 / MinIO Persistence
- **Scope:** Upload client-side WebM recording Blobs via `Storage::disk('media')` to private object storage, persist object metadata in DB (NOT presigned URLs), and enable persistent dual-audio playback (`[🔊 Giọng mẫu]` vs `[🎙️ Giọng tôi]`).
- **Storage:** See ADR-004 and ADR-005 in DECISIONS.md.
- **Dependencies:** BUG-004 must be `ACCEPT` first. Storage config for `media` disk.
- **⚠️ CORRECTION:** Previous references to "S3/MinIO" and "presigned URL persistence" are INVALID. See ADR-004 for canonical storage architecture.

### [BLOCKED] Phase ⑥: AI Pronunciation Evaluation
- **Scope:** Deepgram / DeepSeek STT & phonetic alignment evaluation.
- **Dependencies:** Phase ⑤ must be complete.

---

## Completed Tasks

### [COMPLETED] Phase ①: Keyboard-First UX & Auto-Follow Centering
- **Commit:** `3ae99a0`
- **Changes:** Extended `handleKeydown()` with `-/+`, `Z`, `M`, `L`; centered transcript scroll offset to 50% viewport; updated speed controls.

### [COMPLETED] Phase ③: Segment Loop Modes & Quick Rewind (-2s)
- **Commit:** `3ae99a0`
- **Changes:** Added 3 loop modes (`once`, `loop_3`, `loop_infinite`), `quickRewind(2.0)` timestamp seek, UI cycle button & ↶2s button.

### [COMPLETED] Phase ④: Per-Chunk Mastery Tracking & Weak Segment Filter
- **Commit:** `3ae99a0`
- **Migration:** `2026_08_13_150000_add_mastery_status_to_shadowing_progress.php`
- **Changes:** Added `mastery_status` column, auto-computation in `ShadowingPractice`, sidebar status dot badges (⚪🟡🟠🟢), practice count labels, `$weakOnlyFilter` toggle & skip logic.

### [COMPLETED] BUG-003: Nullable Score Migration
- **Commit:** `9a34a72`
- **Migration:** `2026_08_13_160000_make_shadowing_scores_nullable.php`
- **Changes:** `score` and `best_score` columns now nullable. `ShadowingAttemptService` handles null correctly.
