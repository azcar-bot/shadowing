# E2E & Browser Verification Report — Round 4 (BUG-004 & BUG-005)

> **Execution Date:** 2026-08-13
> **Branch:** `fix/bug-004-005-round3`
> **Source Commit:** Canonical `azenglish-next` main
> **Result:** ALL 8 INTEGRATION TESTS + REAL PLAYWRIGHT BROWSER E2E PASSED (100% SUCCESS)

---

## 1. Summary of Round 4 Enhancements

1. **`validateSource()` Gate:** Strict rejection of forbidden/fake transcript sources (`ai_generated_fallback`, `mock`, `demo`, `sample`, `fake`, `prototype`).
2. **Deduplication Lookup:** `ShadowingSourceProcessingService::processVideoSource()` strictly ignores stale/fake sources in DB and re-fetches clean transcripts.
3. **Legacy Cleanup Migration:** Created `2026_08_13_170000_cleanup_legacy_shadowing_fake_sources.php` to invalidate fake sources and archive orphaned legacy YouTube lessons (`source_id = NULL`).
4. **`availableLessons()` Classification:** Explicit semantics: YouTube lessons require valid completed source with clean transcript source; Manual lessons must be explicitly official with `total_segments > 0`.
5. **Regression Tests:** 3 new tests added (Fake source reuse, Factory validation rejection, Legacy list exclusion).
6. **Real Playwright Browser E2E:** Headless Chrome browser testing authenticated student session, iframe video binding, transcript card rendering, cross-contamination, and page refresh persistence.

---

## 2. Real Playwright Browser E2E Results

- **Browser Engine:** Playwright Chromium (Headless, 1440x900)
- **Authenticated User:** `student@example.test` (Logged in via `/login`)

```text
[BROWSER E2E] Navigating to local application...
[TEST 1] Opening http://127.0.0.1:8081/shadowing/practice (Empty state)...
  Current URL: http://127.0.0.1:8081/login
  [AUTH] Browser redirected to login. Logging in as student@example.test...
  Post login URL: http://127.0.0.1:8081/shadowing/practice
  After auth URL: http://127.0.0.1:8081/shadowing/practice
  ✅ PASSED: Browser renders empty state without Tekkon fallback.

[TEST 2] Opening Lesson A: http://127.0.0.1:8081/shadowing/practice/test_lesson_a_1786597021...
  ✅ PASSED: Browser renders Lesson A iframe (dbtN9HOOqhk) and Transcript A ('The day, a star fell.').

[TEST 3] Opening Lesson B: http://127.0.0.1:8081/shadowing/practice/test_lesson_b_1786597021...
  ✅ PASSED: Browser renders Lesson B iframe (wNJ4r2WsUMI) and Transcript B ('Oh my god!'). No leakage.

[TEST 4] Switching back to A and Refreshing...
  ✅ PASSED: Browser refresh on Lesson A retains correct iframe and transcript.

=========================================================
REAL BROWSER PLAYWRIGHT E2E SUITE PASSED SUCCESSFULLY! ✅
=========================================================
```

---

## 3. Integration & Regression Test Suite Results (8/8 PASSED)

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
  Lesson A Code: test_lesson_a_1786597021, ID: 23, Source ID: 3, Status: published
  3. Mounting ShadowingPractice with Lesson A Code...
  Assert loaded lesson ID: 23
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
  Lesson B Code: test_lesson_b_1786597021, ID: 24, Source ID: 4, Status: published
  3. Mounting ShadowingPractice with Lesson B Code...
  Assert loaded lesson ID: 24
  Loaded Segments Count: 108
  First 3 Transcripts of Lesson B:
    - Seg #1: Oh my god!
    - Seg #2: Turn it off.
    - Seg #3: Turn it off.
  ✅ PASSED: TEST B successfully bound to Video B and differs from Video A.

[TEST 4: CROSS-CONTAMINATION TEST (A -> B -> A)]
  Switching to Lesson A (test_lesson_a_1786597021)...
  Switching to Lesson B (test_lesson_b_1786597021)...
  Switching back to Lesson A (test_lesson_a_1786597021)...
  ✅ PASSED: Cross-contamination test passed (A -> B -> A complete isolation).

[TEST 5: NULL SCORE TEST (BUG-005)]
  Segment ID: 1200, Segment Index: 1
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

[TEST 6: FAKE SOURCE REUSE REGRESSION TEST]
  Created fake source ID 7 with transcript_source 'ai_generated_fallback'
  ✅ PASSED: Deduplication query correctly ignores stale fake source.

[TEST 7: FACTORY VALIDATION REGRESSION TEST]
  ✅ PASSED: validateSource() rejected fake source with message: ShadowingSource ID 7 uses forbidden or invalid transcript source ('ai_generated_fallback'). Cannot create lesson from fake source.

[TEST 8: LEGACY LIST FILTERING REGRESSION TEST]
  ✅ PASSED: availableLessons correctly excludes legacy YouTube lesson with source_id = null.

=========================================================
ALL 8 E2E & REGRESSION TESTS PASSED SUCCESSFULLY! ✅
=========================================================
```
