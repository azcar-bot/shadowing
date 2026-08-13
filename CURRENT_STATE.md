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

## 2. Current Active Phase & Review Status

| Feature / Bug | Priority | Status | Reviewer | PR Branch / Commit |
|---|---|---|---|---|
| **Phase ⑤ Recording** | P1 | `READY_FOR_RECORDING_RE_REVIEW_V3` | Architect | `feat/shadowing-recording-media-persistence` (PR #3 open) |
| **Translation EN→VI V3** | P1 | `ACCEPTED / MERGED` | Architect | Merged PR #2 (`808b3b074f1ae641b3a8861e16a91ddd9b1fb495`) |
| **BUG-004** Wrong Transcript Source | P0 CRITICAL | `RESOLVED` | Architect | Merged PR #1 (`ade665a26a6e12abac9ea84639ecdf7a53509a11`) |
| **BUG-005** Null Score Cast | P0 | `RESOLVED` | Architect | Merged PR #1 (`ade665a26a6e12abac9ea84639ecdf7a53509a11`) |

**PHPUnit Suite:** 60/60 Passed (177 assertions, 1.59s).
**Playwright E2E Suite:** 10/10 Passed (100% reproducible browser automation).
**EXPOSED_TOKEN_REVOKED:** `YES`
**Evidence Documents:** See `RECORDING_EVIDENCE_V1.md`, `TRANSLATION_EVIDENCE_V3.md`.

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
| Translation EN→VI | `VERIFIED` | 23 permanent tests, DeepSeek adapter, dynamic config control, transient retryable vs permanent exception classification, network error handling, queued job `$timeout = 300s`, Lock TTL `420s`, bounded batching (25 chunks), real Vietnamese evidence |
| Student Recording | `VERIFIED` | 18 permanent tests, logical `media` disk, Clean Architecture storage contract, safe object swap & replacement, DB metadata persistence, MinIO runtime verification, dual-audio comparison playback |

---

## 4. Pending Work (Ordered)

1. 🟢 **Translation EN→VI V3 Final** — `ACCEPTED / MERGED` (PR #2, Merge Commit `808b3b074f1ae641b3a8861e16a91ddd9b1fb495`)
2. 🟢 **Phase ⑤ Recording** — `READY_FOR_RECORDING_REVIEW_V1` (Branch `feat/shadowing-recording-media-persistence`, 54/54 tests passed)
3. 🔒 **Phase ⑥ AI Pronunciation** — `BLOCKED until Phase ⑤ Recording is ACCEPTED/MERGED`
