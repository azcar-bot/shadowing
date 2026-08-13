# Task Registry — Shadowing Module

## Active Queue

### [IN_PROGRESS] Phase ⑤: Student Recording S3/MinIO Persistence
- **Scope:** Upload client-side WebM recording Blobs to MinIO/S3 private submissions bucket, store presigned URL in `user_shadowing_attempts.audio_recording_url`, and enable persistent dual-audio playback (`[🔊 Giọng mẫu]` vs `[🎙️ Giọng tôi]`).
- **Dependencies:** `ShadowingPractice.php`, S3 MinIO storage config.

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
