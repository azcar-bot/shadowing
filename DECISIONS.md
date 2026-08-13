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
