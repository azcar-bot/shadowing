# Current State — Shadowing Module

> **Last Updated:** 2026-08-13
> **Active Environment:** Laravel 13 + Livewire 4 + Alpine.js + MySQL 8.x + Python Caption Proxy

---

## 1. System Architecture & Status

```
[YouTube Video] ──► [yt_caption_proxy.py (Port 9876)]
                             │ (raw transcript JSON)
                             ▼
             [CaptionNormalizationService]
                             │ (timed cues)
                             ▼
         [NaturalSpeakingChunkEngineService]
                             │ (natural chunks 2.5s-6.5s)
                             ▼
                  [ShadowingLessonFactory]
                             │ (DB models & segments)
                             ▼
                 [ShadowingPractice (Livewire 4)]
```

### Storage Architecture (ADR-004 — LOCKED)

```
Shadowing Recording
        ↓
Laravel Storage
        ↓
logical disk: media
        ↓
 ┌─────────────────────────────┐
 │ Production → Cloudflare R2  │
 │ Local      → MinIO          │
 │ Tests      → fake(media)    │
 └─────────────────────────────┘
```

- Business code: `Storage::disk('media')` ONLY.
- **FORBIDDEN:** `Storage::disk('r2')`, `Storage::disk('minio')`, `Storage::disk('s3')`.
- DB stores `object_key`, NOT presigned URL. See ADR-005.

---

## 2. Current Blockers & Review Status

| Bug | Priority | Status | Reviewer | PR Branch |
|---|---|---|---|---|
| **BUG-004** Wrong Transcript Source | P0 CRITICAL | `READY_FOR_RE_REVIEW_ROUND_3` | Architect | `fix/bug-004-005-round3` |
| **BUG-005** Null Score Cast | P0 | `READY_FOR_RE_REVIEW_ROUND_3` | Architect | `fix/bug-004-005-round3` |

**E2E Evidence:** 5/5 Automated E2E Tests Passed. See `E2E_EVIDENCE_ROUND3.md`.

---

## 3. Implemented Features (Verified)

| Feature | State | Verification Method |
|---|---|---|
| Caption Proxy | `VERIFIED` | E2E fetch test on real YouTube videos (`zc87_hodp2g`, `wNJ4r2WsUMI`) |
| Natural Chunking | `VERIFIED` | Pause + clause marker segmentation test |
| Keyboard Shortcuts | `WIRED` | Alpine.js `handleKeydown()` with 8 shortcuts |
| Loop Modes (1x/3x/∞) | `WIRED` | Timer check loop counter in Alpine.js |
| Quick Rewind (-2s) | `WIRED` | `quickRewind(2.0)` timestamp seek |
| Per-Chunk Status | `WIRED` | Migration `000015` (`mastery_status`), Livewire progress tracking, sidebar dot badges |
| Weak Segment Filter | `WIRED` | Livewire `$weakOnlyFilter`, dimming mastered cards, skip navigation |
| Nullable Scores | `VERIFIED` | Migration applied, service handles null correctly |

---

## 4. Pending Work (Ordered)

1. ⬜ **FIX BUG-004** — Remove hardcoded lesson code, safe fallback, legacy cleanup
2. ⬜ **FIX BUG-005** — Preserve null score in loadUserProgress
3. ⬜ **E2E Verification** — YouTube A → Lesson A, YouTube B → Lesson B, no cross-contamination
4. ⬜ **ACCEPT gate** — Architect reviews and accepts BUG-004 + BUG-005 fixes
5. ⬜ **Translation EN→VI** — One-time AI translation per lesson at creation time, store `translation_vi` in DB
6. ⬜ **Phase ⑤ Recording** — MediaRecorder WebM → `Storage::disk('media')` → object metadata persistence
7. ⬜ **Phase ⑥ AI Pronunciation** — STT + phonetic alignment evaluation
