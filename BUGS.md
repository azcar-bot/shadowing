# Active & Resolved Bugs — Shadowing Module

## Open Bugs

*No open critical runtime bugs currently reported.*

---

## Resolved Bugs

### BUG-001: YouTube 429 Too Many Requests on Direct Subprocess Caption Extraction
- **Reported:** 2026-08-12
- **Root Cause:** YouTube IP blocking on cloud/Docker container subnets.
- **Fix:** Implemented `yt_caption_proxy.py` HTTP server on host machine.
- **Status:** RESOLVED (Verified on videos `zc87_hodp2g`, `wNJ4r2WsUMI`).

### BUG-002: Modal Blur / Dim Overlays Blocking Shadowing Creator UI
- **Reported:** 2026-08-12
- **Root Cause:** Backdrop filter blur CSS overlaying active inputs.
- **Fix:** Removed backdrop blur utility classes from modal container.
- **Status:** RESOLVED (Verified via Playwright browser test).
