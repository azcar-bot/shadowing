# Shadowing Module — AZEnglish / Pippa

> YouTube-based English Shadowing practice module built with **Laravel 13 + Livewire 4 + Alpine.js**.

## Features

### Lesson Generation Pipeline
- **YouTube URL → Auto Caption Extraction** via `youtube-transcript-api` Python proxy
- **Natural Speaking Chunk Engine** — splits captions into practice-sized segments
- **Segment metadata**: timestamps (ms), IPA transcription, Vietnamese translation

### Practice Workspace (Keyboard-First)
| Key | Action |
|---|---|
| `Space` | Play / Pause |
| `R` | Replay current chunk |
| `←` / `→` | Previous / Next segment |
| `−` / `+` | Speed −0.25x / +0.25x (range 0.25x – 2.00x) |
| `Z` | Quick rewind −2 seconds |
| `M` | Toggle mic recording |
| `L` | Cycle loop mode (1× → 3× → ∞) |

### 3 Practice Modes
- **Luyện có chữ** (Listen & Repeat) — transcript visible
- **Shadowing** — speak along in real-time
- **Không nhìn chữ** (Challenge) — transcript hidden, reveal on demand

### Loop Modes
- `once` — auto-stop at chunk end
- `loop_3` — replay chunk 3 times, then stop
- `loop_infinite` — infinite loop until manual stop

### Per-Chunk Mastery Tracking
| Status | Badge | Trigger |
|---|---|---|
| `unseen` | ⚪ Grey | Default |
| `practicing` | 🟡 Yellow | 1+ attempt |
| `needs_review` | 🟠 Orange | 3+ attempts, score < 75% |
| `mastered` | 🟢 Green | Score ≥ 75% |

- **"Câu yếu" filter**: dims mastered segments, ←/→ skip them
- Practice count displayed per segment

### Audio Recording
- Web Audio `MediaRecorder` API
- Records `audio/webm` blobs for instant client-side playback

## Architecture

```
app/
├── Livewire/
│   └── ShadowingPractice.php          # Main Livewire component
├── Modules/Shadowing/
│   ├── Domain/
│   │   ├── Contracts/                  # Provider interfaces
│   │   └── Services/                   # Business logic
│   │       ├── CaptionNormalizationService.php
│   │       ├── NaturalSpeakingChunkEngineService.php
│   │       ├── ShadowingAttemptService.php
│   │       ├── ShadowingLessonFactoryService.php
│   │       └── YouTubeUrlNormalizerService.php
│   └── Infrastructure/
│       ├── Adapters/                   # External API adapters
│       ├── Persistence/Models/         # Eloquent models
│       └── Scripts/                    # Python caption tools
config/
└── shadowing.php                       # Module configuration
database/migrations/                    # 3 migration files
resources/views/livewire/
└── shadowing-practice.blade.php        # Practice UI (Alpine.js engine)
```

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 13, PHP 8.4 |
| Realtime UI | Livewire 4 |
| Client JS | Alpine.js |
| CSS | Tailwind CSS v4 |
| Database | MySQL 8.x |
| Caption Proxy | Python 3 + youtube-transcript-api |

## YouTube Caption Proxy

YouTube blocks caption requests from cloud/Docker IPs. A lightweight Python HTTP proxy runs on the host machine:

```bash
python3 app/Modules/Shadowing/Infrastructure/Scripts/yt_caption_proxy.py
# Listens on http://localhost:9876/captions?video_id=VIDEO_ID
```

The Laravel app queries this proxy via `YT_CAPTION_PROXY_URL` config.

## License

Proprietary — AZEnglish / HighFlyers Education.
