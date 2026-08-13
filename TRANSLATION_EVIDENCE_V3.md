# Translation EN→VI V3 Evidence & Architectural Hardening Report

> **Execution Date:** 2026-08-13
> **Branch:** `feat/shadowing-translation-en-vi`
> **Base Branch:** `main`
> **Status:** `READY_FOR_TRANSLATION_RE_REVIEW_V3`

---

## 1. Executive Summary & Round 3 Hardening Fixes Addressed

All 6 hardening requirements from the Architect have been completely implemented and verified:

1. **MAKE CONFIG ACTUALLY CONTROL PRODUCTION:**
   - Checked `config('shadowing.translation.enabled', true)` in [`ShadowingLessonFactoryService`](file:///z:/home/pc/projects/azenglish-next/app/Modules/Shadowing/Domain/Services/ShadowingLessonFactoryService.php). When disabled, zero background translation jobs are dispatched.
   - Dynamic binding in [`AppServiceProvider`](file:///z:/home/pc/projects/azenglish-next/app/Providers/AppServiceProvider.php): resolves `TranslationProviderContract` dynamically based on `config('shadowing.translation.provider')`. Unknown providers fail with `InvalidArgumentException`.
   - Permanent tests added: `disabled_translation_does_not_dispatch_job`, `configured_provider_is_resolved_correctly`.

2. **CLASSIFY PERMANENT VS TRANSIENT PROVIDER ERRORS:**
   - Exception hierarchy implemented:
     - `TranslationProviderTransientException` (for timeout, network errors, HTTP 408, 429, 5xx).
     - `TranslationProviderPermanentException` / `TranslationProviderUnavailableException` (for missing API key, HTTP 400, 401, 403, 404, 422, malformed JSON).
   - Service behavior:
     - Transient errors set `translation_error` and **RE-THROW** so Laravel Queue retries up to `$tries = 3` with backoff `[30, 120, 300]`.
     - Permanent errors set `translation_status = failed` and `translation_error`, and **DO NOT RE-THROW** (no endless retries).
   - Permanent tests added: `http_401_does_not_retry`, `http_429_is_retryable`, `http_503_is_retryable`.

3. **FIX CONCURRENCY LOCK SEMANTICS:**
   - Lock TTL updated to `240` seconds (safety margin for 120s job timeout).
   - Lock contention throws `TranslationProviderTransientException("Translation lock contention...")` so Queue retries after backoff instead of failing silently.
   - Permanent test added: `concurrent_translation_lock_contention_is_retryable`.

4. **REAL PROVIDER HTTP BATCH CALL COUNT EVIDENCE:**
   - Measures actual provider HTTP/batch calls (`ceil(chunk_count / batch_size)`):
     - **Video A (`dbtN9HOOqhk`):** 19 chunks, batch size 25 ➔ **1 HTTP batch call** (`ceil(19/25) = 1`).
     - **Video B (`wNJ4r2WsUMI`):** 108 chunks, batch size 25 ➔ **5 HTTP batch calls** (`ceil(108/25) = 5`).
   - Re-running translation (same version): **0 HTTP calls (Delta = 0)**.
   - 5x Practice opens: **0 HTTP calls (Delta = 0)**.

5. **TRANSLATION COMPLETENESS QUALITY:**
   - System prompt strengthened in `DeepSeekTranslationAdapter`.
   - Video B Chunk #6 (*"Now I'm feeling a little nervous, but we'll see what's on there. No turning back now."*):
     - **VI:** *"Bây giờ mình cảm thấy hơi hồi hộp một chút, nhưng hãy xem có gì ở đó nhé. Không còn đường lùi nữa rồi."* (Zero clauses omitted).

6. **CLEAN COORDINATION STATE:**
   - Archived stale `[BLOCKED] Translation EN→VI` block in `TASKS.md`.
   - Updated `DECISIONS.md` (`ADR-006`), `CURRENT_STATE.md`, and `TASKS.md`.
   - Updated PR #2 title and body on GitHub.

---

## 2. PHPUnit Test Suite Output (35/35 PASSED)

```text
   PASS  Tests\Feature\Modules\Shadowing\ShadowingModuleTest (10 passed)
   PASS  Tests\Feature\Modules\Shadowing\ShadowingRegressionVerificationTest (3 passed)
   PASS  Tests\Feature\Modules\Shadowing\ShadowingTranslationTest (22 passed)
  ✓ invalid source is not translated                                     0.01s  
  ✓ translation provider is called for valid source                      0.01s  
  ✓ completed translation same version is not called twice               0.01s  
  ✓ translation preserves english transcript                             0.01s  
  ✓ translation is persisted to source chunks                            0.01s  
  ✓ translation is synced to lesson segments                             0.02s  
  ✓ malformed provider output fails without partial corruption           0.01s  
  ✓ provider failure sets translation status failed                      0.01s  
  ✓ opening practice does not call translation provider                  0.02s  
  ✓ neighboring chunk context is supplied to translation provider        0.01s  
  ✓ missing real provider credentials never produces fake translation    0.01s  
  ✓ completed metadata with missing chunk retranslates                   0.01s  
  ✓ factory dispatches translation job instead of synchronous provider…  0.01s  
  ✓ transient provider exception is retryable                            0.01s  
  ✓ translation state moves through processing                           0.01s  
  ✓ hundred plus chunks are translated in bounded batches                0.03s  
  ✓ disabled translation does not dispatch job                           0.01s  
  ✓ configured provider is resolved correctly                            0.01s  
  ✓ http 401 does not retry                                              0.01s  
  ✓ http 429 is retryable                                                0.02s  
  ✓ http 503 is retryable                                                0.01s  
  ✓ concurrent translation lock contention is retryable                  0.01s  

  Tests:    35 passed (112 assertions)
  Duration: 1.54s
```

---

## 3. Real Provider HTTP Batch Call Count & Completeness Evidence

### 🎬 VIDEO A: `dbtN9HOOqhk` (Your Name)
- **Source ID:** `3`
- **Chunk Count:** `19`
- **Batch Size:** `25`
- **Expected HTTP Batches:** `1` (`ceil(19/25)`)
- **Actual Provider HTTP Calls:** `1`
- **Translation Status:** `completed`
- **Provider:** `deepseek` (`deepseek-chat`)
- **Version:** `vi-v1`

#### First 10 EN→VI Pairs:
1. `[#1]` **EN:** *"The day, a star fell."* ➔ **VI:** *"Ngày mà một ngôi sao rơi xuống."*
2. `[#2]` **EN:** *"It was almost like, like seeing something out of a dream."* ➔ **VI:** *"Nó gần như thể, giống như đang nhìn thấy điều gì đó bước ra từ giấc mơ."*
3. `[#3]` **EN:** *"Nothing more or less, than a breathtaking view."* ➔ **VI:** *"Không hơn không kém, chính là một khung cảnh ngoạn mục đến ngạt thở."*
4. `[#4]` **EN:** *"Wanna hit the café later?"* ➔ **VI:** *"Bạn có muốn ghé quán cà phê sau đó không?"*
5. `[#5]` **EN:** *"Thanks, but i got to go to work."* ➔ **VI:** *"Cảm ơn, nhưng tôi phải đi làm rồi."*
6. `[#6]` **EN:** *"I can't stand this place anymore."* ➔ **VI:** *"Tôi không thể chịu đựng nổi nơi này thêm nữa."*
7. `[#7]` **EN:** *"It's too small and townie."* ➔ **VI:** *"Nơi này quá nhỏ bé và hẻo lánh."*
8. `[#8]` **EN:** *"Please make me a handsome Tokyo boy in my next life!"* ➔ **VI:** *"Xin hãy biến tôi thành một chàng trai Tokyo đẹp trai ở kiếp sau!"*
9. `[#9]` **EN:** *"Where...?"* ➔ **VI:** *"Ở đâu...?"*
10. `[#10]` **EN:** *"Now that you've mentioned, I do feel like I've been having weird dreams lately."* ➔ **VI:** *"Bây giờ khi bạn nhắc tới, tôi cảm thấy dạo này mình hay có những giấc mơ kỳ lạ."*

---

### 🎬 VIDEO B: `wNJ4r2WsUMI` (Olivia Rodrigo - 108 Chunks)
- **Source ID:** `4`
- **Chunk Count:** `108`
- **Batch Size:** `25`
- **Expected HTTP Batches:** `5` (`ceil(108/25)`)
- **Actual Provider HTTP Calls:** `5`
- **Translation Status:** `completed`
- **Provider:** `deepseek` (`deepseek-chat`)
- **Version:** `vi-v1`

#### First 10 EN→VI Pairs:
1. `[#1]` **EN:** *"Oh my god!"* ➔ **VI:** *"Ôi trời ơi!"*
2. `[#2]` **EN:** *"Turn it off."* ➔ **VI:** *"Tắt nó đi."*
3. `[#3]` **EN:** *"Turn it off."* ➔ **VI:** *"Tắt nó đi."*
4. `[#4]` **EN:** *"Hi, YouTube. It's Olivia."* ➔ **VI:** *"Xin chào YouTube. Mình là Olivia đây."*
5. `[#5]` **EN:** *"Let's take a look at my Watch History."* ➔ **VI:** *"Hãy cùng xem qua Lịch sử xem của mình nhé."*
6. `[#6]` **EN:** *"Now I'm feeling a little nervous, but we'll see what's on there. No turning back now."* ➔ **VI:** *"Bây giờ mình cảm thấy hơi hồi hộp một chút, nhưng hãy xem có gì ở đó nhé. Không còn đường lùi nữa rồi."*
7. `[#7]` **EN:** *"Let's pull her up."* ➔ **VI:** *"Mở nó lên nào."*
8. `[#8]` **EN:** *"This is a ‘Wuthering Heights’ movie review and I love these girls."* ➔ **VI:** *"Đây là video đánh giá phim Đồi Gió Hú và mình rất thích những cô gái này."*
9. `[#9]` **EN:** *"@flickchicks9649."* ➔ **VI:** *"Kênh Flickchicks9649."*
10. `[#10]` **EN:** *"They're these ladies and they do movie reviews in their car."* ➔ **VI:** *"Họ là những người phụ nữ làm video đánh giá phim ngay trong xe ô tô của họ."*

---

## 4. Cost & Idempotency Proof

```text
[COST / IDEMPOTENCY PROOF]
  Re-running translateSource() on Video A (same version 'vi-v1')...
  Video A Re-run Result: SUCCESS (Skipped Provider Call)
  Video A Provider Call Delta: 0

  Re-running translateSource() on Video B (108 chunks, same version 'vi-v1')...
  Video B Re-run Result: SUCCESS (Skipped Provider Call)
  Video B Provider Call Delta: 0

  ✅ PASSED: Real provider HTTP batch call counting & idempotency verified!
     Video A: 1 batch calls (= ceil(19/25)).
     Video B: 5 batch calls (= ceil(108/25)).
     Re-run Deltas: 0.
```
