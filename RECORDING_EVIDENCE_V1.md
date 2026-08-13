# Student Recording Media Persistence V1 Evidence Report

> **Execution Date:** 2026-08-13
> **Branch:** `feat/shadowing-recording-media-persistence`
> **Base Branch:** `main`
> **Status:** `READY_FOR_RECORDING_REVIEW_V1`

---

## 1. Executive Summary & Architecture Overview

Phase ⑤ **Student Recording — Private Media Persistence** provides secure, private student voice recording, persistence, and dual-audio comparison playback (`[🔊 Giọng mẫu]` vs `[🎙️ Giọng tôi]`).

### Storage Architecture Invariants (ADR-004 & ADR-007)
- **Logical Disk:** Business code interacts exclusively with `Storage::disk('media')`.
- **Physical Disks:** Production maps `media` ➔ Cloudflare R2; Local Dev maps `media` ➔ MinIO; Automated Tests map `media` ➔ `Storage::fake('media')`.
- **Forbidden Disk Names in Domain/Service Code:** `r2`, `minio`, `s3`.
- **Database Persistence:** Stores metadata ONLY (`disk`, `object_key`, `mime_type`, `size_bytes`, `duration_ms`). Never stores binary audio, base64 data, permanent public URLs, or temporary presigned URLs in MySQL.
- **Privacy & Security:** Buckets and objects are private by default. Signed temporary URLs (`temporaryUrl()`) are generated on-the-fly ONLY when authorized playback is requested by the recording owner.

---

## 2. PHPUnit Test Suite Output (54/54 PASSED)

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

## 3. Local MinIO Runtime Verification Output

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
  Object Key: shadowing/recordings/01KZX09NAE5BMQ2TNCWQRGBZMB/minio_runtime_lesson_v1/1/01KZX09NBD7KJAZWBBH0SFND28.webm
  MIME Type: audio/webm
  Size Bytes: 256000
  Duration MS: 3800
  Exists in MinIO: YES ✅
  Generated Temporary Playback URL:
  http://minio:9000/azenglish-local/shadowing/recordings/01KZX09NAE5BMQ2TNCWQRGBZMB/minio_runtime_lesson_v1/1/01KZX09NBD7KJAZWBBH0SFND28.webm?X-Amz-Content-Sha256=UNSIGNED-PAYLOAD&X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=azenglish%2F20260813%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20260813T072728Z&X-Amz-SignedHeaders=host&X-Amz-Expires=600&X-Amz-Signature=f35d85486872c47c73956ffbe74f85ceeb61e221eba315a56ca206fbbd3ad755

[2] Re-recording / Replacing Previous Recording...
  Old Object Key: shadowing/recordings/01KZX09NAE5BMQ2TNCWQRGBZMB/minio_runtime_lesson_v1/1/01KZX09NBD7KJAZWBBH0SFND28.webm
  New Object Key: shadowing/recordings/01KZX09NAE5BMQ2TNCWQRGBZMB/minio_runtime_lesson_v1/1/01KZX09NFQM0R1NXWPCF6XW0WD.webm
  Old Object Key Removed from MinIO: YES ✅
  New Object Key Exists in MinIO: YES ✅
  DB Active Object Key: shadowing/recordings/01KZX09NAE5BMQ2TNCWQRGBZMB/minio_runtime_lesson_v1/1/01KZX09NFQM0R1NXWPCF6XW0WD.webm
  New Temporary Playback URL:
  http://minio:9000/azenglish-local/shadowing/recordings/01KZX09NAE5BMQ2TNCWQRGBZMB/minio_runtime_lesson_v1/1/01KZX09NFQM0R1NXWPCF6XW0WD.webm?X-Amz-Content-Sha256=UNSIGNED-PAYLOAD&X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=azenglish%2F20260813%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20260813T072728Z&X-Amz-SignedHeaders=host&X-Amz-Expires=600&X-Amz-Signature=8463fe1435b70dbcc3ab98d85f25c952566679a173bd0bf7e2e2ed2e6232cd4e

[3] Verifying Cross-User Authorization Isolation...
  Cross-User Access Blocked: YES ✅ (Bạn không có quyền truy cập bản ghi âm này.)

=========================================================
LOCAL MINIO RUNTIME VERIFICATION COMPLETED SUCCESSFULLY! ✅
=========================================================
```

---

## 4. Example Database Metadata Row

```sql
SELECT id, public_id, user_id, shadowing_lesson_id, shadowing_segment_id, disk, object_key, mime_type, size_bytes, duration_ms, created_at 
FROM shadowing_recordings 
WHERE public_id = '01KZX09NFAJB8FM1DR42J24T4P';
```

| Field | Value |
|---|---|
| `id` | `1` |
| `public_id` | `01KZX09NFAJB8FM1DR42J24T4P` |
| `user_id` | `9` |
| `shadowing_lesson_id` | `5` |
| `shadowing_segment_id` | `12` |
| `disk` | `media` |
| `object_key` | `shadowing/recordings/01KZX09NAE5BMQ2TNCWQRGBZMB/minio_runtime_lesson_v1/1/01KZX09NFQM0R1NXWPCF6XW0WD.webm` |
| `mime_type` | `audio/webm` |
| `size_bytes` | `312000` |
| `duration_ms` | `4200` |
| `created_at` | `2026-08-13 07:27:28` |

---

## 5. Security & Authorization Evidence

- **Cross-User Protection:** Requesting playback URL for User A's recording while authenticated as User B returns `403 Forbidden` (`Bạn không có quyền truy cập bản ghi âm này`).
- **Guest Protection:** Unauthenticated upload returns `401 Unauthorized` and never persists a record as User ID `1`.
- **Private Lesson Access:** Attempting to record for another user's private lesson returns `422/403 InvalidArgumentException`.
