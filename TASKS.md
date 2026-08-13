# Task Registry — Shadowing Module

## Active Queue

### [READY_FOR_TRANSLATION_FINAL_REVIEW] Translation EN→VI V3 Final
- **Priority:** P1
- **Status:** READY_FOR_TRANSLATION_FINAL_REVIEW — PR #2 (`feat/shadowing-translation-en-vi` -> `main`)
- **Scope:** Complete EN->VI translation pipeline with dynamic config control (`shadowing.translation.enabled`/`provider`), exception classification (transient retryable vs permanent non-retryable), network transport error handling (`TranslationProviderTransientException`), queued job (`ProcessShadowingTranslationJob`) with `$timeout = 300s`, `$tries = 3`, backoff `[30, 120, 300]`, Lock TTL `420s`, bounded batching (25 chunks), 23 permanent PHPUnit translation tests (36/36 total pass), runtime verification with real Vietnamese translations, and zero student session AI calls.
- **Evidence:** See `TRANSLATION_EVIDENCE_V3.md` and `DECISIONS.md` (ADR-006).

### [RESOLVED] BUG-004: Wrong Transcript Source
- **Status:** RESOLVED — Merged in PR #1 (Commit `ade665a`)

### [RESOLVED] BUG-005: Null Score Cast in loadUserProgress
- **Status:** RESOLVED — Merged in PR #1 (Commit `ade665a`)

### [BLOCKED] Phase ⑤: Student Recording — Cloudflare R2 / MinIO Persistence
- **Scope:** Upload client-side WebM recording Blobs via `Storage::disk('media')` to private object storage, persist object metadata in DB (NOT presigned URLs), and enable persistent dual-audio playback (`[🔊 Giọng mẫu]` vs `[🎙️ Giọng tôi]`).
- **Storage:** See ADR-004 and ADR-005 in DECISIONS.md.
- **Dependencies:** BLOCKED until Translation PR #2 is ACCEPTED/MERGED.

### [BLOCKED] Phase ⑥: AI Pronunciation Evaluation
- **Scope:** Deepgram / DeepSeek STT & phonetic alignment evaluation.
- **Dependencies:** BLOCKED until Phase ⑤ Recording is complete.

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
