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

- **Caption Extraction:** `youtube-transcript-api` via host HTTP proxy (`http://host.docker.internal:9876`). Primary status: `VERIFIED & FUNCTIONAL`.
- **Chunk Segmentation:** `NaturalSpeakingChunkEngineService` enforcing pause splits (350ms), clause markers, and bad ending word avoidances. Status: `VERIFIED & FUNCTIONAL`.
- **Practice Workspace:** SFC Livewire 4 + Alpine.js engine with keyboard shortcuts (`Space`, `R`, `←/→`, `-/+`, `Z`, `M`, `L`), 3 practice modes, 3 loop modes, and per-chunk mastery tracking.

---

## 2. Implemented Features (Verified)

| Feature | State | Verification Method |
|---|---|---|
| Caption Proxy | `VERIFIED` | E2E fetch test on real YouTube videos (`zc87_hodp2g`, `wNJ4r2WsUMI`) |
| Natural Chunking | `VERIFIED` | Pause + clause marker segmentation test |
| Keyboard Shortcuts | `WIRED` | Alpine.js `handleKeydown()` with 8 shortcuts |
| Loop Modes (1x/3x/∞) | `WIRED` | Timer check loop counter in Alpine.js |
| Quick Rewind (-2s) | `WIRED` | `quickRewind(2.0)` timestamp seek |
| Per-Chunk Status | `WIRED` | Migration `000015` (`mastery_status`), Livewire progress tracking, sidebar dot badges |
| Weak Segment Filter | `WIRED` | Livewire `$weakOnlyFilter`, dimming mastered cards, skip navigation |

---

## 3. Pending Work

- [ ] **Phase ⑤ Recording Upload:** WebM `MediaRecorder` blob upload to S3/MinIO & `user_shadowing_attempts.audio_recording_url` persistence.
- [ ] **Phase ⑥ AI Pronunciation Evaluation:** Deepgram / DeepSeek STT & phonetic alignment evaluation.
