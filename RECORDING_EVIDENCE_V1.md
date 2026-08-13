# Student Recording Media Persistence V3 Evidence Report

> **Execution Date:** 2026-08-13
> **Branch:** `feat/shadowing-recording-media-persistence`
> **Base Branch:** `main`
> **Pull Request:** [#3](https://github.com/azcar-bot/shadowing/pull/3)
> **Status:** `READY_FOR_RECORDING_RE_REVIEW_V3`
> **EXPOSED_TOKEN_REVOKED:** `YES`

---

## 1. Executive Summary & Storage Boundaries

Phase ⑤ **Student Recording — Private Media Persistence V3** implements all Round 3 Security & Data-Integrity Hardening directives:

1. **P0 Security — Token Purge (`EXPOSED_TOKEN_REVOKED = YES`):** Completely removed all hardcoded `gho_` GitHub tokens from scripts and repository.
2. **P0 Data Integrity — Never Persist Temporary Playback URLs:** Presigned temporary URLs exist strictly in browser memory (`userAudioUrl`) for authorized playback and are NEVER persisted in `user_shadowing_attempts` or any database table. `ShadowingAttemptService::recordAttempt()` receives `score: null, audioUrl: null, durationMs: actualDuration`.
3. **P0 Security — Server-Side Magic Byte MIME Authority:** Magic bytes (`UploadedFile::getMimeType()`) form the primary security boundary. Spoofed audio client headers with non-audio magic bytes (e.g. `text/x-php`, `text/html`, `application/x-executable`) are strictly rejected with `422 Unprocessable Content`.
4. **P0 UI — Dual-Audio Playback Exclusivity:** Handled all sample playback entry points (`togglePlay()`, `playCurrentSegment()`, `replayCurrentSegment()`) to call `this.stopUserAudio()` before playing sample audio. Invariant enforced: `sample_playing XOR student_audio_playing`.
5. **P1 — Real Recording Duration Measurement:** Measured exact recording duration in JS via `performance.now()`.
6. **P1 — Canonical Lesson Access Policy:** Validated `$lesson->status === 'published'`. Rejects uploads to draft or review required lessons.
7. **P1 — Controller Hardening & Kill Switch:** Fixed missing lesson HTTP status to `404 Not Found`. `shadowing.recording.enabled` config controls actual production behavior. Sanitized internal 500 Throwable error responses.
8. **P1 — Concurrency & Storage Delete Failure Semantics:** Audited concurrent uploads with `lockForUpdate()`. Storage object deletion occurs BEFORE DB metadata row deletion.
9. **Browser E2E — Reproducible Playwright Suite:** Created `tests/Browser/ShadowingRecordingE2ETest.py` covering login, recording, presigned URL upload, reload persistence, re-record replacement, dual-audio exclusivity, and Student B isolation.

---

## 2. PHPUnit Test Suite Output (60/60 PASSED)

```text
   PASS  Tests\Feature\Modules\Shadowing\ShadowingModuleTest (10 passed)
   PASS  Tests\Feature\Modules\Shadowing\ShadowingRecordingTest (24 passed)
  ✓ guest cannot upload recording
  ✓ authenticated user can upload own recording
  ✓ upload is stored on logical media disk
  ✓ recording db stores object metadata not binary or temporary url
  ✓ segment must belong to lesson
  ✓ user cannot record for inaccessible private lesson
  ✓ recording is private between users
  ✓ owner can request temporary playback url
  ✓ non owner cannot request temporary playback url
  ✓ re recording replaces previous recording
  ✓ successful replacement deletes old object
  ✓ failed replacement does not destroy previous valid recording
  ✓ invalid mime type is rejected
  ✓ oversized recording is rejected
  ✓ deleting recording deletes private object and metadata
  ✓ opening practice loads only current users recording
  ✓ guest never persists as user 1
  ✓ storage business flow never requires r2 minio or s3 disk name
  ✓ recording upload never persists temporary url in any shadowing attempt
  ✓ spoofed audio client mime with non audio contents is rejected
  ✓ student cannot upload to unpublished lesson
  ✓ student cannot upload to review required lesson
  ✓ disabled recording cannot upload
  ✓ failed storage deletion retains db metadata
   PASS  Tests\Feature\Modules\Shadowing\ShadowingRegressionVerificationTest (3 passed)
   PASS  Tests\Feature\Modules\Shadowing\ShadowingTranslationTest (23 passed)

  Tests:    60 passed (177 assertions)
  Duration: 1.59s
```

---

## 3. Reproducible Playwright Browser E2E Results (10/10 PASSED)

```text
=========================================================
BROWSER E2E RESULTS SUMMARY
=========================================================
 PASS | 1. Login Student A
 PASS | 2. Open known Shadowing lesson
 PASS | 3. Select segment
 PASS | 4. Start recording #1
 PASS | 5. Stop recording #1 & upload
 PASS | 6. Recording persisted after browser reload
 PASS | 7. Re-recording replacement succeeds
 PASS | Exclusivity A: Start 'Giọng tôi' -> Sample Player Paused
 PASS | Exclusivity B: Start 'Giọng mẫu' -> Student Audio Paused
 PASS | 8. Student B clean authorization isolation
=========================================================
```
