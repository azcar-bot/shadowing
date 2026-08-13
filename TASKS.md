# Task Registry — Shadowing Module

## Active Queue

### [READY_FOR_RE_REVIEW_ROUND_3] BUG-004: Wrong Transcript Source
- **Priority:** P0 CRITICAL
- **Status:** READY_FOR_RE_REVIEW_ROUND_3 — PR `fix/bug-004-005-round3`
- **Source Sync:** 24 canonical files pushed to PR branch `fix/bug-004-005-round3`
- **E2E Evidence:** Verified via 5 automated tests. See `E2E_EVIDENCE_ROUND3.md`.

### [READY_FOR_RE_REVIEW_ROUND_3] BUG-005: Null Score Cast in loadUserProgress
- **Priority:** P0
- **Status:** READY_FOR_RE_REVIEW_ROUND_3 — PR `fix/bug-004-005-round3`
- **Scope:** Fixed `(float) $prog->best_score` → null preserved. Model casts updated. Verified in Test 5.

### [BLOCKED] Translation EN→VI (After BUG-004 ACCEPT)
- **Scope:** Add a one-time Translation Provider that runs AI translation (EN→VI) when a lesson is created. Store `translation_vi` per chunk/segment in DB. Do NOT re-translate on every student session.
- **Dependencies:** ACCEPT gate completed (BUG-004 + BUG-005 fixed, E2E verified, Architect ACCEPT).
- **Rationale:** If transcript is wrong, AI will translate the wrong content accurately — wasting resources and creating confusing Vietnamese text.

### [BLOCKED] Phase ⑤: Student Recording — Cloudflare R2 / MinIO Persistence
- **Scope:** Upload client-side WebM recording Blobs via `Storage::disk('media')` to private object storage, persist object metadata in DB (NOT presigned URLs), and enable persistent dual-audio playback (`[🔊 Giọng mẫu]` vs `[🎙️ Giọng tôi]`).
- **Storage:** See ADR-004 and ADR-005 in DECISIONS.md.
- **Dependencies:** Translation phase should be complete or at least functional.
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
