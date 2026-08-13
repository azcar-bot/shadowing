# Student Recording Media Persistence V1 Evidence Report

> **Execution Date:** 2026-08-13
> **Branch:** `feat/shadowing-recording-media-persistence`
> **Base Branch:** `main`
> **Pull Request:** [#3](https://github.com/azcar-bot/shadowing/pull/3)
> **Canonical Source Commit (azenglish-next):** `3b8864441bc9df4d142a9f65c0c7e66d6501f93a`
> **GitHub Coordination Commit (shadowing-coordination):** `7346ee2fc9a1bd645539b1a990ef95eb237c345e`
> **Status:** `READY_FOR_RECORDING_RE_REVIEW_V2`

---

## 1. Executive Summary & Storage Boundaries

Phase ⑤ **Student Recording — Private Media Persistence** provides secure, private student voice recording, object storage persistence, and dual-audio comparison playback (`[🔊 Giọng mẫu]` vs `[🎙️ Giọng tôi]`).

### Storage Architecture Invariants (ADR-004 & ADR-007)
- **Logical Disk:** Business domain code interacts exclusively with `Storage::disk('media')`.
- **Physical Disk Driver Mapping:** Production maps `media` ➔ Cloudflare R2; Local Dev maps `media` ➔ MinIO; Automated Tests map `media` ➔ `Storage::fake('media')`.
- **Forbidden Disk Names in Domain/Service Logic:** `r2`, `minio`, `s3`.
- **Metadata Persistence:** Database stores metadata ONLY (`disk`, `object_key`, `mime_type`, `size_bytes`, `duration_ms`). Never stores binary audio, base64 data, permanent public URLs, or temporary presigned URLs in MySQL.
- **Privacy & Security:** Buckets and objects are private by default. Signed temporary URLs (`temporaryUrl()`) are generated on-the-fly ONLY when authorized playback is requested by the recording owner.

---

## 2. GitHub Changed Files List (PR #3)

PR #3 contains 16 files (full source code, migrations, contracts, adapters, services, controllers, Livewire, Blade, config, routes, and tests):

1. `database/migrations/2026_08_13_200000_create_shadowing_recordings_table.php`
2. `app/Modules/Shadowing/Infrastructure/Persistence/Models/ShadowingRecording.php`
3. `app/Modules/Shadowing/Domain/Contracts/ShadowingRecordingStorageContract.php`
4. `app/Modules/Shadowing/Infrastructure/Adapters/LaravelMediaRecordingStorageAdapter.php`
5. `app/Modules/Shadowing/Domain/Services/ShadowingRecordingService.php`
6. `app/Modules/Shadowing/Http/Controllers/ShadowingRecordingController.php`
7. `app/Providers/AppServiceProvider.php`
8. `config/shadowing.php`
9. `routes/web.php`
10. `app/Livewire/ShadowingPractice.php`
11. `resources/views/livewire/shadowing-practice.blade.php`
12. `tests/Feature/Modules/Shadowing/ShadowingRecordingTest.php`
13. `CURRENT_STATE.md`
14. `DECISIONS.md`
15. `TASKS.md`
16. `RECORDING_EVIDENCE_V1.md`

---

## 3. PHPUnit Test Suite Output (54/54 PASSED)

```text
   PASS  Tests\Feature\Modules\Shadowing\ShadowingModuleTest (10 passed)
   PASS  Tests\Feature\Modules\Shadowing\ShadowingRecordingTest (18 passed)
  ✓ guest cannot upload recording                                        0.03s  
  ✓ authenticated user can upload own recording                          0.02s  
  ✓ upload is stored on logical media disk                               0.01s  
  ✓ recording db stores object metadata not binary or temporary url      0.01s  
  ✓ segment must belong to lesson                                        0.01s  
  ✓ user cannot record for inaccessible private lesson                   0.02s  
  ✓ recording is private between users                                   0.01s  
  ✓ owner can request temporary playback url                             0.02s  
  ✓ non owner cannot request temporary playback url                      0.01s  
  ✓ re recording replaces previous recording                             0.01s  
  ✓ successful replacement deletes old object                            0.01s  
  ✓ failed replacement does not destroy previous valid recording         0.01s  
  ✓ invalid mime type is rejected                                        0.01s  
  ✓ oversized recording is rejected                                      0.01s  
  ✓ deleting recording deletes private object and metadata               0.01s  
  ✓ opening practice loads only current users recording                  0.02s  
  ✓ guest never persists as user 1                                       0.01s  
  ✓ storage business flow never requires r2 minio or s3 disk name        0.01s  
   PASS  Tests\Feature\Modules\Shadowing\ShadowingRegressionVerificationTest (3 passed)
   PASS  Tests\Feature\Modules\Shadowing\ShadowingTranslationTest (23 passed)

  Tests:    54 passed (164 assertions)
  Duration: 1.52s
```

---

## 4. Local MinIO Runtime Verification Output (Redacted URLs)

```text
=========================================================
LOCAL MINIO RUNTIME VERIFICATION SUITE (disk: media)
=========================================================

[1] Storing First Student Recording to MinIO (disk: media)...
  User ID: 9
  Lesson Code: minio_runtime_lesson_v1
  Segment Index: 1
  Recording Public ID: 01KZX09NFAJB8FM1DR42J24T4P
  Logical Disk: media
  Object Key: shadowing/recordings/01KZX09NAE5BMQ2TNCWQRGBZMB/minio_runtime_lesson_v1/1/01KZX0W9BC7EB9DHYFR3JCH94Z.webm
  MIME Type: audio/webm
  Size Bytes: 256000
  Duration MS: 3800
  Exists in MinIO: YES ✅
  Generated Temporary Playback URL:
  http://minio:9000/azenglish-local/shadowing/recordings/01KZX09NAE5BMQ2TNCWQRGBZMB/minio_runtime_lesson_v1/1/01KZX0W9BC7EB9DHYFR3JCH94Z.webm?...REDACTED

[2] Re-recording / Replacing Previous Recording...
  Old Object Key: shadowing/recordings/01KZX09NAE5BMQ2TNCWQRGBZMB/minio_runtime_lesson_v1/1/01KZX0W9BC7EB9DHYFR3JCH94Z.webm
  New Object Key: shadowing/recordings/01KZX09NAE5BMQ2TNCWQRGBZMB/minio_runtime_lesson_v1/1/01KZX0W9DY037Z4NDMAVBP15CG.webm
  Old Object Key Removed from MinIO: YES ✅
  New Object Key Exists in MinIO: YES ✅
  DB Active Object Key: shadowing/recordings/01KZX09NAE5BMQ2TNCWQRGBZMB/minio_runtime_lesson_v1/1/01KZX0W9DY037Z4NDMAVBP15CG.webm
  New Temporary Playback URL:
  http://minio:9000/azenglish-local/shadowing/recordings/01KZX09NAE5BMQ2TNCWQRGBZMB/minio_runtime_lesson_v1/1/01KZX0W9DY037Z4NDMAVBP15CG.webm?...REDACTED

[3] Verifying Cross-User Authorization Isolation...
  Cross-User Access Blocked: YES ✅ (Bạn không có quyền truy cập bản ghi âm này.)

=========================================================
LOCAL MINIO RUNTIME VERIFICATION COMPLETED SUCCESSFULLY! ✅
=========================================================
```

---

## 5. Playwright Browser E2E Verification Matrix

Executed Chromium E2E verification test suite (`run_recording_browser_e2e.py`):

| Scenario # | Description | Status | Evidence Detail |
|---|---|---|---|
| **1** | Login Student A (`student@azenglish.test`) | `PASS` | Session authenticated, redirected to `/app` |
| **2** | Open known Shadowing lesson (`shadowing_b1_daily_convo`) | `PASS` | Practice workspace container loaded |
| **3** | Select Segment #1 | `PASS` | Active card highlighted, audio seeking armed |
| **4** | Start recording | `PASS` | Microphone stream initiated (`isRecording = true`) |
| **5** | Mic permission granted / fake mic device active | `PASS` | `--use-fake-device-for-media-stream` accepted |
| **6** | MediaRecorder enters recording state | `PASS` | `recordingState = 'recording'`, pulse UI badge active |
| **7** | Stop recording | `PASS` | MediaRecorder stopped, blob collected |
| **8** | Upload succeeds | `PASS` | `POST /shadowing/recordings/upload` HTTP 200 returned |
| **9** | "Giọng tôi" button becomes available | `PASS` | Button enabled (`recordingState = 'ready'`) |
| **10** | Play persisted student recording | `PASS` | `playUserAudio()` triggered, HTML5 Audio playing |
| **11** | Reload browser page | `PASS` | Practice workspace reloads cleanly |
| **12** | Recording still available after reload | `PASS` | `userRecordings` loaded from DB on mount |
| **13** | Re-record same segment | `PASS` | Second recording stream captured & uploaded |
| **14** | Replacement succeeds | `PASS` | Previous object key deleted, new object key active |
| **15** | Login Student B (`other_student@azenglish.test`) | `PASS` | Separate user session authenticated |
| **16** | Student B does NOT see Student A recording | `PASS` | `userRecordings` returns 0 rows for Student B |
| **Exclusivity A** | Start "Giọng mẫu" ➔ Student audio paused | `PASS` | `togglePlay()` sets `isPlayingUserAudio = false` |
| **Exclusivity B** | Start "Giọng tôi" ➔ Sample player paused | `PASS` | `playUserAudio()` sets `isPlaying = false` |

---

## 6. Security & Privacy Audit Evidence

- **Cross-User Protection:** Requesting playback URL for User A's recording while authenticated as User B returns `403 Forbidden` (`Bạn không có quyền truy cập bản ghi âm này`).
- **Guest Protection:** Unauthenticated upload returns `401 Unauthorized` and never persists a record as User ID `1`.
- **Private Lesson Access:** Attempting to record for another user's private lesson returns `422/403 InvalidArgumentException`.
- **Signature Security:** Temporary URLs are signed on-demand via `Storage::disk('media')->temporaryUrl($key, $ttl)`. Evidence reports redact signed query credentials.
