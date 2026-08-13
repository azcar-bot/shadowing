<?php

declare(strict_types=1);

namespace App\Modules\Shadowing\Domain\Services;

use App\Modules\Shadowing\Infrastructure\Adapters\DeepgramNova3Provider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class CaptionNormalizationService
{
    /**
     * Fetch captions for a YouTube video.
     *
     * Strategy order:
     *   1. Python youtube-transcript-api (most reliable – bypasses YouTube bot protection)
     *   2. PHP HTTP scraping (ytInitialPlayerResponse regex)
     *   3. Deepgram Nova-3 STT (requires DEEPGRAM_API_KEY)
     *   4. RuntimeException – no fake/mock data allowed
     *
     * @return array{source: string, title: string, duration_seconds: int, items: array<int, array{text: string, start_ms: int, end_ms: int}>}
     */
    public function fetchCaptionsWithFallback(string $videoId): array
    {
        // 1. PRIMARY: Python youtube-transcript-api (most reliable)
        $pythonResult = $this->fetchViaPython($videoId);
        if (!empty($pythonResult['items'])) {
            Log::info('YouTube captions fetched via Python youtube-transcript-api', [
                'videoId' => $videoId,
                'source'  => $pythonResult['source'],
                'count'   => count($pythonResult['items']),
            ]);
            return $pythonResult;
        }

        // 2. FALLBACK: PHP HTTP scraping (works when YouTube doesn't block)
        $captionData = $this->fetchYouTubeCaptionsViaPhp($videoId);
        if (!empty($captionData['items'])) {
            Log::info('YouTube captions fetched via PHP HTTP scraping', [
                'videoId' => $videoId,
                'source'  => $captionData['source'],
                'count'   => count($captionData['items']),
            ]);
            return $captionData;
        }

        // 3. FALLBACK: STT Provider (Deepgram Nova-3)
        /** @var DeepgramNova3Provider $sttProvider */
        $sttProvider = app(DeepgramNova3Provider::class);
        $sttResult = $sttProvider->transcribe("https://www.youtube.com/watch?v={$videoId}");

        if (!empty($sttResult['items'])) {
            return [
                'source' => 'deepgram_nova3',
                'title' => "YouTube Video ({$videoId})",
                'duration_seconds' => (int) ($sttResult['duration_seconds'] ?? 180),
                'items' => $this->normalizeCaptions($sttResult['items']),
            ];
        }

        // 4. HARD STOP – no fake/mock data allowed
        throw new \RuntimeException("Không thể tự động tải phụ đề tiếng Anh cho video YouTube (ID: {$videoId}). Vui lòng chọn video YouTube khác có phụ đề tiếng Anh.");
    }

    // Strategy 1: Caption Proxy HTTP Service (host.docker.internal:9876)
    //
    // The proxy runs on the HOST machine with a residential IP, bypassing
    // YouTube's cloud/container IP blocking that causes 429/IpBlocked errors.
    // ─────────────────────────────────────────────────────────────────────────

    private function fetchViaPython(string $videoId): array
    {
        // 1a. Try the Caption Proxy HTTP Service first (most reliable from Docker)
        $proxyResult = $this->fetchViaCaptionProxy($videoId);
        if (!empty($proxyResult)) {
            return $proxyResult;
        }

        // 1b. Fallback to local Python subprocess (works outside Docker / host with residential IP)
        return $this->fetchViaPythonSubprocess($videoId);
    }

    private function fetchViaCaptionProxy(string $videoId): array
    {
        $proxyUrl = config('shadowing.caption_proxy_url', 'http://host.docker.internal:9876');

        try {
            $response = Http::timeout(20)->get("{$proxyUrl}/captions", [
                'video_id' => $videoId,
                'lang'     => 'en',
            ]);

            if (!$response->successful()) {
                $json = $response->json();
                Log::info('Caption proxy returned no captions', [
                    'videoId' => $videoId,
                    'status'  => $response->status(),
                    'error'   => $json['error'] ?? 'Unknown',
                ]);
                return [];
            }

            $json = $response->json();

            if (empty($json) || !($json['success'] ?? false) || empty($json['items'])) {
                return [];
            }

            $title = $this->fetchVideoTitle($videoId);

            return [
                'source'           => $json['source'] ?? 'youtube_auto_caption',
                'title'            => $title,
                'duration_seconds' => $this->estimateDuration($json['items']),
                'items'            => $this->normalizeCaptions($json['items']),
            ];
        } catch (\Throwable $e) {
            Log::info('Caption proxy unreachable, falling back to Python subprocess', [
                'videoId' => $videoId,
                'error'   => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function fetchViaPythonSubprocess(string $videoId): array
    {
        $scriptPath = base_path('app/Modules/Shadowing/Infrastructure/Scripts/fetch_youtube_captions.py');

        if (!file_exists($scriptPath)) {
            Log::warning('Python caption script not found', ['path' => $scriptPath]);
            return [];
        }

        try {
            $result = Process::timeout(30)->run([
                'python3', $scriptPath, $videoId, 'en',
            ]);

            if (!$result->successful()) {
                $output = trim($result->output());
                if ($output) {
                    $json = json_decode($output, true);
                    $errorMsg = $json['error'] ?? $result->errorOutput();
                    Log::info('Python caption fetch returned no captions', [
                        'videoId' => $videoId,
                        'error'   => $errorMsg,
                    ]);
                }
                return [];
            }

            $json = json_decode(trim($result->output()), true);

            if (empty($json) || !($json['success'] ?? false) || empty($json['items'])) {
                return [];
            }

            $title = $this->fetchVideoTitle($videoId);

            return [
                'source'           => $json['source'] ?? 'youtube_auto_caption',
                'title'            => $title,
                'duration_seconds' => $this->estimateDuration($json['items']),
                'items'            => $this->normalizeCaptions($json['items']),
            ];
        } catch (\Throwable $e) {
            Log::warning('Python caption fetch exception', [
                'videoId' => $videoId,
                'error'   => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Fetch video title via YouTube oEmbed API (lightweight, no scraping).
     */
    private function fetchVideoTitle(string $videoId): string
    {
        try {
            $res = Http::timeout(5)->get("https://www.youtube.com/oembed", [
                'url'    => "https://www.youtube.com/watch?v={$videoId}",
                'format' => 'json',
            ]);
            if ($res->successful()) {
                return $res->json('title') ?? "YouTube Video ({$videoId})";
            }
        } catch (\Throwable) {
            // Silently fall through
        }
        return "YouTube Video ({$videoId})";
    }

    /**
     * Estimate total video duration from the last caption item's end_ms.
     */
    private function estimateDuration(array $items): int
    {
        if (empty($items)) {
            return 180;
        }
        $lastItem = end($items);
        $endMs = $lastItem['end_ms'] ?? 0;
        return max(30, (int) ceil($endMs / 1000));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Strategy 2: PHP HTTP Scraping (ytInitialPlayerResponse)
    // ─────────────────────────────────────────────────────────────────────────

    private function fetchYouTubeCaptionsViaPhp(string $videoId): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])->get("https://www.youtube.com/watch?v={$videoId}");

            if (!$response->successful()) {
                return [];
            }

            $html = $response->body();

            $title = "YouTube Video ({$videoId})";
            if (preg_match('/<title>(.*?)<\/title>/i', $html, $m)) {
                $title = trim(str_replace('- YouTube', '', html_entity_decode($m[1])));
            }

            if (preg_match('/var ytInitialPlayerResponse\s*=\s*({.*?});/s', $html, $m)) {
                $playerData = json_decode($m[1], true);
                $captionTracks = $playerData['captions']['playerCaptionsTracklistRenderer']['captionTracks'] ?? [];

                $targetTrack = null;
                foreach ($captionTracks as $track) {
                    $langCode = $track['languageCode'] ?? '';
                    if (str_starts_with($langCode, 'en')) {
                        $targetTrack = $track;
                        if (($track['kind'] ?? '') !== 'asr') {
                            break;
                        }
                    }
                }

                if ($targetTrack && !empty($targetTrack['baseUrl'])) {
                    $captionUrl = $targetTrack['baseUrl'] . '&fmt=json3';
                    $captionResponse = Http::get($captionUrl);
                    if ($captionResponse->successful()) {
                        $json = $captionResponse->json();
                        $events = $json['events'] ?? [];
                        $rawItems = [];
                        foreach ($events as $ev) {
                            if (empty($ev['segs'])) continue;
                            $text = '';
                            foreach ($ev['segs'] as $s) {
                                $text .= $s['utf8'] ?? '';
                            }
                            $text = trim($text);
                            if ($text === '' || $text === "\n") continue;
                            $startMs = (int) ($ev['tStartMs'] ?? 0);
                            $durationMs = (int) ($ev['dDurationMs'] ?? 2500);
                            $rawItems[] = [
                                'text' => $text,
                                'start_ms' => $startMs,
                                'end_ms' => $startMs + $durationMs,
                            ];
                        }

                        if (!empty($rawItems)) {
                            $isManual = ($targetTrack['kind'] ?? '') !== 'asr';
                            return [
                                'source' => $isManual ? 'youtube_manual_caption' : 'youtube_auto_caption',
                                'title' => $title,
                                'duration_seconds' => (int) ($playerData['videoDetails']['lengthSeconds'] ?? 180),
                                'items' => $this->normalizeCaptions($rawItems),
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('PHP YouTube caption fetch failed', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Normalize YouTube caption items, SRT/VTT parser outputs, or STT arrays
     * into a standardized array of CaptionUnit items.
     *
     * @param array<int, array{text: string, start_ms?: int, end_ms?: int, start?: float, duration?: float}> $rawCaptions
     * @return array<int, array{text: string, start_ms: int, end_ms: int}>
     */
    public function normalizeCaptions(array $rawCaptions): array
    {
        $normalized = [];

        foreach ($rawCaptions as $item) {
            $text = trim($item['text'] ?? '');
            if ($text === '') {
                continue;
            }

            // Clean backticks into apostrophes
            $text = str_replace('`', "'", $text);
            // Clean double/multiple ellipses
            $text = preg_replace('/(\.\.\.\s*){2,}/u', '...', $text);
            $text = preg_replace('/\s+\.\.\./u', '...', $text);
            // Clean spaces before commas, periods, question marks
            $text = preg_replace('/\s+([,\.!\?])/u', '$1', $text);
            $text = trim($text);

            $startMs = isset($item['start_ms'])
                ? (int) $item['start_ms']
                : (int) (($item['start'] ?? 0.0) * 1000);

            $endMs = isset($item['end_ms'])
                ? (int) $item['end_ms']
                : (int) ($startMs + (($item['duration'] ?? 2.5) * 1000));

            $unit = [
                'text' => $text,
                'start_ms' => $startMs,
                'end_ms' => max($startMs + 200, $endMs),
            ];

            if (!empty($item['words'])) {
                $unit['words'] = $item['words'];
            }

            $normalized[] = $unit;
        }

        // Sort chronologically by start_ms
        usort($normalized, fn ($a, $b) => $a['start_ms'] <=> $b['start_ms']);

        return $normalized;
    }

    /**
     * Parse raw WebVTT / SRT text string into normalized caption units.
     *
     * @return array<int, array{text: string, start_ms: int, end_ms: int}>
     */
    public function parseSrtOrVtt(string $subtitleContent): array
    {
        $lines = explode("\n", str_replace("\r", "", $subtitleContent));
        $captions = [];
        $currentStart = null;
        $currentEnd = null;
        $textBuffer = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/(\d{2}:\d{2}:\d{2}[.,]\d{3}|\d{2}:\d{2}[.,]\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2}[.,]\d{3}|\d{2}:\d{2}[.,]\d{3})/', $trimmed, $matches)) {
                if (!empty($textBuffer) && $currentStart !== null && $currentEnd !== null) {
                    $captions[] = [
                        'text' => implode(' ', $textBuffer),
                        'start_ms' => $currentStart,
                        'end_ms' => $currentEnd,
                    ];
                    $textBuffer = [];
                }

                $currentStart = $this->timestampToMs($matches[1]);
                $currentEnd = $this->timestampToMs($matches[2]);
                continue;
            }

            if ($trimmed === '' || is_numeric($trimmed) || str_starts_with($trimmed, 'WEBVTT')) {
                if (!empty($textBuffer) && $currentStart !== null && $currentEnd !== null) {
                    $captions[] = [
                        'text' => implode(' ', $textBuffer),
                        'start_ms' => $currentStart,
                        'end_ms' => $currentEnd,
                    ];
                    $textBuffer = [];
                    $currentStart = null;
                    $currentEnd = null;
                }
                continue;
            }

            $textBuffer[] = strip_tags($trimmed);
        }

        if (!empty($textBuffer) && $currentStart !== null && $currentEnd !== null) {
            $captions[] = [
                'text' => implode(' ', $textBuffer),
                'start_ms' => $currentStart,
                'end_ms' => $currentEnd,
            ];
        }

        return $this->normalizeCaptions($captions);
    }

    private function timestampToMs(string $timestampStr): int
    {
        $parts = explode(':', str_replace(',', '.', $timestampStr));
        if (count($parts) === 3) {
            $hours = (int) $parts[0];
            $minutes = (int) $parts[1];
            $seconds = (float) $parts[2];
            return (int) (($hours * 3600 + $minutes * 60 + $seconds) * 1000);
        } elseif (count($parts) === 2) {
            $minutes = (int) $parts[0];
            $seconds = (float) $parts[1];
            return (int) (($minutes * 60 + $seconds) * 1000);
        }
        return 0;
    }
}
