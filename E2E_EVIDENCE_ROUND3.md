# E2E Verification Report — Round 3 (BUG-004 & BUG-005)

> **Execution Date:** 2026-08-13
> **Branch:** `fix/bug-004-005-round3`
> **Source Commit:** `d18649f` (azenglish-next main)
> **Result:** ALL 5 E2E TESTS PASSED (100% SUCCESS)

---

## 1. Test Environment & Runtime Matrix

- **App Container:** `azenglish-next-dev-app-1` (PHP 8.3 / Laravel 13)
- **Database:** MySQL 8.x (`azenglish-next-dev-mysql-1` port 3308)
- **Execution Script:** `scratch_e2e_verification.php`

---

## 2. Test Verification Details

### TEST 1: EMPTY STATE TEST
- **Input:** Mount `ShadowingPractice` with `lessonCode = null` / `''`.
- **Expected:** `$practice->lesson` is `null` (No Tekkon fallback, no random lesson fallback).
- **Result:** `PASSED` (`$lessonEmpty === null`). Controlled empty state with UI prompt.

---

### TEST 2: TEST A — VIDEO A (`dbtN9HOOqhk`)
- **Video ID A:** `dbtN9HOOqhk` (Your Name - Falling Star scene)
- **Source Processing:** Created `ShadowingSource` ID `3`, Status: `completed`, 19 natural speaking chunks.
- **Lesson Creation:** Created Official Lesson `test_lesson_a_1786596766` (ID `21`, `source_id = 3`, `status = published`).
- **Mount Verification:**
  - `loadedLessonA->id` = `21`
  - `loadedLessonA->youtube_video_id` = `dbtN9HOOqhk`
  - `loadedLessonA->source_id` = `3`
  - `source->youtube_video_id` = `dbtN9HOOqhk`
- **First 3 Transcripts (Lesson A):**
  1. `Seg #1`: "The day, a star fell."
  2. `Seg #2`: "It was almost like, like seeing something out of a dream."
  3. `Seg #3`: "Nothing more or less, than a breathtaking view."
- **Result:** `PASSED` (Lesson A cleanly bound to Video A).

---

### TEST 3: TEST B — VIDEO B (`wNJ4r2WsUMI`)
- **Video ID B:** `wNJ4r2WsUMI` (Olivia Rodrigo reacts to Watch History)
- **Source Processing:** Created `ShadowingSource` ID `4`, Status: `completed`, 108 natural speaking chunks.
- **Lesson Creation:** Created Official Lesson `test_lesson_b_1786596766` (ID `22`, `source_id = 4`, `status = published`).
- **Mount Verification:**
  - `loadedLessonB->id` = `22`
  - `loadedLessonB->youtube_video_id` = `wNJ4r2WsUMI`
  - `loadedLessonB->source_id` = `4`
  - `source->youtube_video_id` = `wNJ4r2WsUMI`
- **First 3 Transcripts (Lesson B):**
  1. `Seg #1`: "Oh my god!"
  2. `Seg #2`: "Turn it off."
  3. `Seg #3`: "Turn it off."
- **Isolation Check:** Transcript B[0] (`Oh my god!`) != Transcript A[0] (`The day, a star fell.`). No cross-contamination!
- **Result:** `PASSED` (Lesson B cleanly bound to Video B, completely distinct from Video A).

---

### TEST 4: CROSS-CONTAMINATION TEST (A → B → A)
- **Sequence:** Mount `A` → Mount `B` → Mount `A` again.
- **Verification:**
  - Mount A: Video `dbtN9HOOqhk`, Transcript `The day, a star fell.`
  - Mount B: Video `wNJ4r2WsUMI`, Transcript `Oh my god!`
  - Remount A: Video `dbtN9HOOqhk`, Transcript `The day, a star fell.`
- **Result:** `PASSED` (Complete isolation, zero state bleed across switching).

---

### TEST 5: NULL SCORE TEST (BUG-005)
- **Input:** Created `UserShadowingProgress` record for `student_test@azenglish.dev` on Segment ID `1073` (Index `1`) with `best_score = NULL`.
- **Mount Verification:** Mounted `ShadowingPractice` as `student_test@azenglish.dev`.
- **Loaded State:**
  ```php
  userAttempts[1] => [
    'score' => NULL,
    'is_completed' => false,
    'practice_count' => 1,
    'mastery_status' => 'practicing'
  ]
  ```
- **Result:** `PASSED` (`score` preserved strictly as `NULL`, NOT coerced to `0.0`).

---

## 3. Raw Execution Output Log

```text
=========================================================
E2E VERIFICATION SUITE — BUG-004 & BUG-005
=========================================================

[SETUP] Authenticated User ID: 1

[TEST 1: EMPTY STATE TEST]
  ✅ PASSED: Empty lessonCode returns NULL (No Tekkon fallback, no random lesson).

[TEST 2: TEST A — VIDEO A (dbtN9HOOqhk)]
  1. Processing YouTube Source for Video A (dbtN9HOOqhk)...
  Source A ID: 3, Status: completed, Chunks: 19
  2. Generating Official Lesson A...
  Lesson A Code: test_lesson_a_1786596766, ID: 21, Source ID: 3, Status: published
  3. Mounting ShadowingPractice with Lesson A Code...
  Assert loaded lesson ID: 21
  Loaded Segments Count: 19
  First 3 Transcripts of Lesson A:
    - Seg #1: The day, a star fell.
    - Seg #2: It was almost like, like seeing something out of a dream.
    - Seg #3: Nothing more or less, than a breathtaking view.
  ✅ PASSED: TEST A successfully bound to Video A.

[TEST 3: TEST B — VIDEO B (wNJ4r2WsUMI)]
  1. Processing YouTube Source for Video B (wNJ4r2WsUMI)...
  Source B ID: 4, Status: completed, Chunks: 108
  2. Generating Official Lesson B...
  Lesson B Code: test_lesson_b_1786596766, ID: 22, Source ID: 4, Status: published
  3. Mounting ShadowingPractice with Lesson B Code...
  Assert loaded lesson ID: 22
  Loaded Segments Count: 108
  First 3 Transcripts of Lesson B:
    - Seg #1: Oh my god!
    - Seg #2: Turn it off.
    - Seg #3: Turn it off.
  ✅ PASSED: TEST B successfully bound to Video B and differs from Video A.

[TEST 4: CROSS-CONTAMINATION TEST (A -> B -> A)]
  Switching to Lesson A (test_lesson_a_1786596766)...
  Switching to Lesson B (test_lesson_b_1786596766)...
  Switching back to Lesson A (test_lesson_a_1786596766)...
  ✅ PASSED: Cross-contamination test passed (A -> B -> A complete isolation).

[TEST 5: NULL SCORE TEST (BUG-005)]
  Segment ID: 1073, Segment Index: 1
  userAttempts dump: array (
  1 => 
  array (
    'score' => NULL,
    'is_completed' => false,
    'practice_count' => 1,
    'mastery_status' => 'practicing',
  ),
)
  Progress best_score in DB: NULL
  userAttempts[1]['score'] in Livewire state: NULL
  ✅ PASSED: Null score correctly preserved as NULL (not 0.0).

=========================================================
ALL 5 E2E TESTS PASSED SUCCESSFULLY! ✅
=========================================================
```
