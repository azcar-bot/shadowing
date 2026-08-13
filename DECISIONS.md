# Architectural Decisions (ADR) — Shadowing Module

## ADR-001: Host Python HTTP Proxy for YouTube Caption Extraction
- **Date:** 2026-08-12
- **Status:** APPROVED
- **Context:** Direct YouTube caption scraping from cloud/Docker container subnets encounters 429 Too Many Requests and IP bans.
- **Decision:** Run a lightweight host-bound Python HTTP proxy (`yt_caption_proxy.py` on port 9876 using `youtube-transcript-api`) and query it from PHP via `caption_proxy_url`.
- **Consequence:** Solves IP blocking cleanly without requiring expensive proxy services.

## ADR-002: Natural Speaking Chunk Engine over Fixed Time Slicing
- **Date:** 2026-08-12
- **Status:** APPROVED
- **Context:** Fixed 5-second or 10-second video slicing splits words mid-sentence, creating unnatural learning chunks.
- **Decision:** Segment captions using boundary pause detection (≥350ms), grammatical clause markers, and bad ending word avoidance (prepositions, conjunctions, pronouns).
- **Target Chunk Size:** 2,500ms to 6,500ms.

## ADR-003: Alpine.js Engine for Client-Side Playback & Media Control
- **Date:** 2026-08-13
- **Status:** APPROVED
- **Context:** High-frequency timer checks (50ms interval) for auto-pause, timeline sync, and media playback must execute without triggering full Livewire network round-trips.
- **Decision:** Keep playback state machine, YouTube iframe API controls, timer loops, and Web Audio MediaRecorder inside Alpine.js `shadowingEngine()`, syncing back to Livewire only on attempt completion or progress updates.

## ADR-004: Storage Architecture — Cloudflare R2 / MinIO / fake(media)
- **Date:** 2026-08-13
- **Status:** APPROVED — LOCKED
- **Context:** Previous references to AWS S3 were incorrect for this project. The canonical storage backend is Cloudflare R2 for production.
- **Decision:**
  - **Production:** Cloudflare R2
  - **Local Development:** MinIO
  - **Automated Tests:** `Storage::fake('media')`
  - Business/domain code MUST only reference logical disk `media`: `Storage::disk('media')`
  - `.env` determines the actual backend driver.
  - **FORBIDDEN:** Hard-coding `Storage::disk('r2')`, `Storage::disk('minio')`, `Storage::disk('s3')` in application code.
- **Consequence:** All previous S3 references in tasks, docs, and code must be corrected to use `media` logical disk.

## ADR-005: Recording Persistence — Object Key, Not Presigned URL
- **Date:** 2026-08-13
- **Status:** APPROVED — LOCKED
- **Context:** Storing presigned/temporary URLs as permanent database values causes playback failures when URLs expire.
- **Decision:** Database stores object metadata, NOT URLs:
  ```
  disk          = media
  object_key    = shadowing/recordings/{user_id}/{segment_id}/{attempt_id}.webm
  mime_type     = audio/webm
  size_bytes    = <integer>
  duration_ms   = <integer>
  ```
  Temporary/signed URLs are generated on-demand at playback time only.
- **Consequence:** `audio_recording_url` column name is misleading; future migration should rename to `audio_object_key` or add proper columns. Recording playback flow:
  ```
  DB object_key → Storage::disk('media') → temporaryUrl() → browser playback
  ```
