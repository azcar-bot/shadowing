# Translation EN→VI V2 Evidence & Architectural Verification Report

> **Execution Date:** 2026-08-13
> **Branch:** `feat/shadowing-translation-en-vi`
> **Base Branch:** `main`
> **Status:** `READY_FOR_TRANSLATION_RE_REVIEW_V2`

---

## 1. Executive Summary & Round 2 Review Fixes Addressed

All P0 and P1 review requirements from the Architect have been completely implemented and verified:

1. **P0 — REMOVE FAKE TRANSLATION FALLBACK:**
   - Deleted rule-engine fallbacks, canned maps, and `"Bản dịch tiếng Việt: " . $text` prefixes.
   - Missing provider API key throws `TranslationProviderUnavailableException`.
   - Successful `translation_status = completed` ONLY occurs with valid provider responses.
   - Permanent test added: `missing_real_provider_credentials_never_produces_fake_translation`.

2. **P0 — USE THE QUEUED JOB IN REAL PRODUCTION FLOW:**
   - `ShadowingLessonFactoryService` creates validated English lessons immediately and dispatches `ProcessShadowingTranslationJob` asynchronously. Zero synchronous blocking of lesson creation.
   - Permanent test added: `factory_dispatches_translation_job_instead_of_synchronous_provider_call`.

3. **P0 — RETRY / FAILURE SEMANTICS:**
   - `ProcessShadowingTranslationJob` configured with `$tries = 3`, `$timeout = 120`, and `$backoff = [30, 120, 300]`.
   - Permanent validation errors (invalid source format) set `translation_status = failed` with `translation_error` and do not retry endlessly.
   - Transient network/HTTP errors (`RuntimeException`) set `translation_error` and re-throw to allow Queue retries up to 3 times.
   - Permanent test added: `transient_provider_exception_is_retryable`.

4. **P1 — REAL STATE MACHINE & CONCURRENCY LOCK:**
   - State transition: `pending` ➔ `processing` ➔ `completed` / `failed`.
   - Atomic cache lock (`shadowing_translation_lock_{$source->id}`) prevents duplicate parallel translation workers.
   - Permanent test added: `translation_state_moves_through_processing`.

5. **P1 — FIX IDEMPOTENCY:**
   - Skips provider call ONLY IF `translation_status === completed` AND `translation_version === targetVersion` AND EVERY source chunk has a non-empty `translation_vi`.
   - Permanent test added: `completed_metadata_with_missing_chunk_retranslates`.

6. **P1 — TRANSLATION CONFIG:**
   - Comprehensive config added to `config/shadowing.php` (`enabled`, `provider`, `model`, `version`, `batch_size`, `deepseek.api_key`, `deepseek.base_url`).
   - Zero direct `env()` calls inside adapter.

7. **P1 — BATCHING (100+ CHUNKS):**
   - Transcripts processed in bounded batches (`batch_size = 25`).
   - Neighboring chunk context (`prev_transcript`, `next_transcript`) maintained across batch boundaries using global chunk indices.
   - Permanent test added: `hundred_plus_chunks_are_translated_in_bounded_batches`.

8. **P1 — DEDICATED ERROR METADATA:**
   - Added `translation_error` column to `shadowing_sources` via migration `2026_08_13_190000_add_translation_error_column_to_shadowing_sources_table.php`. Does not overwrite transcript extraction `error_message`.

---

## 2. PHPUnit Test Suite Output (29/29 PASSED)

```text
   PASS  Tests\Feature\Modules\Shadowing\ShadowingModuleTest
  ✓ free user can access shadowing catalog and practice                  0.36s  
  ✓ shadowing mode switching and attempt logging                         0.06s  
  ✓ challenge mode withholds transcript until revealed                   0.05s  
  ✓ teacher preview mode bypasses progress logging                       0.05s  
  ✓ natural speaking chunk engine merges short and splits long captions  0.04s  
  ✓ word timing evidence splits long chunks accurately                   0.04s  
  ✓ real youtube video lesson creation and segment isolation             0.05s  
  ✓ youtube url normalizer extracts clean video id                       0.04s  
  ✓ shared processed source deduplicates processing for same video id    0.04s  
  ✓ pro user private lesson ownership and privacy isolation              0.07s  

   PASS  Tests\Feature\Modules\Shadowing\ShadowingRegressionVerificationTest
  ✓ fake source reuse is rejected in deduplication lookup                0.02s  
  ✓ factory rejects forbidden transcript sources                         0.01s  
  ✓ legacy youtube lesson without valid source is excluded from availab… 0.01s  

   PASS  Tests\Feature\Modules\Shadowing\ShadowingTranslationTest
  ✓ invalid source is not translated                                     0.01s  
  ✓ translation provider is called for valid source                      0.01s  
  ✓ completed translation same version is not called twice               0.01s  
  ✓ translation preserves english transcript                             0.02s  
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

  Tests:    29 passed (98 assertions)
  Duration: 1.14s
```

---

## 3. Runtime Verification Evidence (Video A & Video B - First 10 Pairs)

### 🎬 VIDEO A: `dbtN9HOOqhk` (Your Name - Falling Star)
- **Source ID:** `3`
- **Lesson Code:** `translation_v2_a_1786603855`
- **Translation Status:** `completed`
- **Provider:** `deepseek`
- **Model:** `deepseek-chat`
- **Version:** `vi-v1`
- **Translated At:** `2026-08-13 06:50:55`
- **Provider Calls Made:** `1`

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

### 🎬 VIDEO B: `wNJ4r2WsUMI` (Olivia Rodrigo - Watch History)
- **Source ID:** `4`
- **Lesson Code:** `translation_v2_b_1786603856`
- **Translation Status:** `completed`
- **Provider:** `deepseek`
- **Model:** `deepseek-chat`
- **Version:** `vi-v1`
- **Translated At:** `2026-08-13 06:50:56`
- **Provider Calls Made:** `1`

#### First 10 EN→VI Pairs:
1. `[#1]` **EN:** *"Oh my god!"* ➔ **VI:** *"Ôi trời ơi!"*
2. `[#2]` **EN:** *"Turn it off."* ➔ **VI:** *"Tắt nó đi."*
3. `[#3]` **EN:** *"Turn it off."* ➔ **VI:** *"Tắt nó đi."*
4. `[#4]` **EN:** *"Hi, YouTube. It's Olivia."* ➔ **VI:** *"Xin chào YouTube. Mình là Olivia đây."*
5. `[#5]` **EN:** *"Let's take a look at my Watch History."* ➔ **VI:** *"Hãy cùng xem qua Lịch sử xem của mình nhé."*
6. `[#6]` **EN:** *"Now I'm feeling a little nervous, but we'll see what's on there. No turning back now."* ➔ **VI:** *"Bây giờ mình cảm thấy hơi hồi hộp một chút, nhưng hãy xem có gì ở đó nhé."*
7. `[#7]` **EN:** *"Let's pull her up."* ➔ **VI:** *"Mở nó lên nào."*
8. `[#8]` **EN:** *"This is a ‘Wuthering Heights’ movie review and I love these girls."* ➔ **VI:** *"Đây là video đánh giá phim Đồi Gió Hú và mình rất thích những cô gái này."*
9. `[#9]` **EN:** *"@flickchicks9649."* ➔ **VI:** *"Kênh Flickchicks9649."*
10. `[#10]` **EN:** *"They're these ladies and they do movie reviews in their car."* ➔ **VI:** *"Họ là những người phụ nữ làm video đánh giá phim ngay trong xe ô tô của họ."*

---

## 4. Cost & Idempotency Proof

```text
[COST / IDEMPOTENCY PROOF]
  Re-running translateSource() on Video A (same version 'vi-v1')...
  Result: SUCCESS (Skipped Provider Call)
  Provider Call Delta: 0
  ✅ PASSED: Idempotency verified. Same version was NOT re-translated.
```

- **First Translation:** 1 provider batch call (`translation_status = completed`).
- **Student Practice Page Opened 5 Times:** 0 provider calls (Delta = 0).
- **Re-running Translation (Same Version):** 0 provider calls (Delta = 0).
