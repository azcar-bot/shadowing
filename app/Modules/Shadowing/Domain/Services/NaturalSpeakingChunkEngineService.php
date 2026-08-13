<?php

declare(strict_types=1);

namespace App\Modules\Shadowing\Domain\Services;

class NaturalSpeakingChunkEngineService
{
    private int $preferredMinDurationMs;
    private int $preferredMaxDurationMs;
    private int $shortChunkThresholdMs;
    private int $longChunkThresholdMs;
    private int $pauseSplitThresholdMs;
    private array $clauseMarkers;
    private array $badEndingWords;

    public function __construct(
        ?int $preferredMinDurationMs = null,
        ?int $preferredMaxDurationMs = null,
        ?int $shortChunkThresholdMs = null,
        ?int $longChunkThresholdMs = null,
        ?int $pauseSplitThresholdMs = null
    ) {
        $this->preferredMinDurationMs = $preferredMinDurationMs ?? config('shadowing.segmentation.preferred_min_duration_ms', 2500);
        $this->preferredMaxDurationMs = $preferredMaxDurationMs ?? config('shadowing.segmentation.preferred_max_duration_ms', 6500);
        $this->shortChunkThresholdMs = $shortChunkThresholdMs ?? config('shadowing.segmentation.short_chunk_threshold_ms', 2200);
        $this->longChunkThresholdMs = $longChunkThresholdMs ?? config('shadowing.segmentation.long_chunk_threshold_ms', 8500);
        $this->pauseSplitThresholdMs = $pauseSplitThresholdMs ?? config('shadowing.segmentation.pause_split_threshold_ms', 350);

        $this->clauseMarkers = config('shadowing.clause_markers', [
            'because', 'but', 'so', 'although', 'when', 'while', 'if', 'which',
            'who', 'that', 'to be honest', 'in my opinion', 'for example',
            'especially', 'actually', 'however',
        ]);

        $this->badEndingWords = config('shadowing.bad_ending_words', [
            'to', 'of', 'on', 'in', 'for', 'with', 'by', 'from', 'at', 'about', 'into',
            'and', 'but', 'because', 'although', 'if', 'when', 'while', 'that', 'so', 'or',
            'i', 'you', 'he', 'she', 'they', 'we', 'it', 'this',
            'is', 'are', 'was', 'were', 'have', 'has', 'do', 'does', 'can', 'could', 'will', 'would',
            'a', 'an', 'the',
        ]);
    }

    /**
     * Generate Natural Speaking Chunks with Semantic Completeness Checks, Cross-Chunk Repair,
     * and Quality Scoring.
     *
     * @param array<int, array{
     *   text: string,
     *   start_ms: int,
     *   end_ms: int,
     *   words?: array<int, array{word: string, start_ms: int, end_ms: int}>
     * }> $captions
     * @return array<int, array{
     *   segment_index: int,
     *   start_ms: int,
     *   end_ms: int,
     *   transcript: string,
     *   quality_score: float,
     *   needs_review: bool
     * }>
     */
    public function generateSpeechChunks(array $captions): array
    {
        if (empty($captions)) {
            return [];
        }

        // Step 1: Pre-process & Split long captions using Word Timing Evidence if available
        $units = $this->preprocessAndSplitLongCaptions($captions);

        // Step 2: Accumulate & Evaluate Boundary Scores with Meaning Boundary Priority
        $rawChunks = [];
        $bufferText = [];
        $bufferStartMs = null;
        $bufferEndMs = null;
        $needsReviewFlag = false;

        $total = count($units);

        for ($i = 0; $i < $total; $i++) {
            $cap = $units[$i];

            if ($bufferStartMs === null) {
                $bufferStartMs = $cap['start_ms'];
            }
            $bufferEndMs = $cap['end_ms'];
            $bufferText[] = $cap['text'];

            if (!empty($cap['needs_review'])) {
                $needsReviewFlag = true;
            }

            $currentText = trim(implode(' ', $bufferText));
            $currentDur = $bufferEndMs - $bufferStartMs;
            $wordCount = count(explode(' ', $currentText));

            $isLast = ($i === $total - 1);
            $nextCap = !$isLast ? $units[$i + 1] : null;
            $pauseMs = $nextCap ? ($nextCap['start_ms'] - $cap['end_ms']) : 0;

            // Boundary Signals
            $hasPause = $pauseMs >= $this->pauseSplitThresholdMs;
            $hasStrongPunctuation = (bool) preg_match('/[.?!]$/u', trim($cap['text']));
            $hasWeakPunctuation = (bool) preg_match('/[,;:]$/u', trim($cap['text']));
            $hasClauseMarker = $this->hasClauseMarker($currentText);
            $hasBadEnding = $this->hasBadPhraseEnding($currentText);

            // Calculate Score
            $score = 0;
            if ($hasPause) {
                $score += 35;
            }
            if ($hasStrongPunctuation) {
                $score += 40;
            }
            if ($hasWeakPunctuation && $currentDur >= 2000) {
                $score += 20;
            }
            if ($hasClauseMarker && $currentDur >= 2500) {
                $score += 25;
            }
            if ($currentDur >= $this->preferredMinDurationMs) {
                $score += 15;
            }

            // CRITICAL DIRECTIVE: Natural meaning boundary > duration.
            // If ending is bad (dangling preposition/conjunction/pronoun), DEFER split unless duration > 9000ms.
            if ($hasBadEnding && $currentDur < 9000 && !$isLast) {
                $score -= 50;
            }

            $tooShortToSplit = ($currentDur < $this->shortChunkThresholdMs) && !$isLast && !$hasStrongPunctuation;
            $shouldSplit = !$tooShortToSplit && ($isLast || $score >= 50 || $currentDur >= $this->longChunkThresholdMs || $wordCount >= 18);

            if ($shouldSplit) {
                $rawChunks[] = [
                    'start_ms' => $bufferStartMs,
                    'end_ms' => $bufferEndMs,
                    'transcript' => $currentText,
                    'needs_review' => $needsReviewFlag || ($currentDur > $this->longChunkThresholdMs) || $hasBadEnding,
                ];

                $bufferText = [];
                $bufferStartMs = null;
                $bufferEndMs = null;
                $needsReviewFlag = false;
            }
        }

        if (!empty($bufferText) && $bufferStartMs !== null && $bufferEndMs !== null) {
            $currentText = trim(implode(' ', $bufferText));
            $rawChunks[] = [
                'start_ms' => $bufferStartMs,
                'end_ms' => $bufferEndMs,
                'transcript' => $currentText,
                'needs_review' => $needsReviewFlag || $this->hasBadPhraseEnding($currentText),
            ];
        }

        // Step 3: Cross-Chunk Repair Pass (Fix Bad Endings & Sentence Bleeds between consecutive chunks)
        $repairedChunks = $this->applyCrossChunkRepair($rawChunks);

        // Step 4: Calculate Quality Score & Finalize
        $finalChunks = [];
        foreach ($repairedChunks as $index => $chunk) {
            $durSec = ($chunk['end_ms'] - $chunk['start_ms']) / 1000.0;
            $hasBadEnd = $this->hasBadPhraseEnding($chunk['transcript']);
            $hasSentenceEnd = (bool) preg_match('/[.?!]$/u', trim($chunk['transcript']));

            // Duration Score
            if ($durSec >= 2.5 && $durSec <= 6.5) {
                $durScore = 1.0;
            } elseif ($durSec >= 1.8 && $durSec <= 8.5) {
                $durScore = 0.7;
            } else {
                $durScore = 0.4;
            }

            // Punctuation Score
            $punctScore = $hasSentenceEnd ? 1.0 : (preg_match('/[,;:]$/u', trim($chunk['transcript'])) ? 0.8 : 0.5);

            // Semantic Score
            $semScore = $hasBadEnd ? 0.2 : ($this->hasClauseMarker($chunk['transcript']) ? 0.9 : 0.8);

            // Weighted Quality Score
            $qualityScore = round(($durScore * 0.3) + ($punctScore * 0.3) + ($semScore * 0.4), 2);
            
            // 3-Tier Classification Status (READY, REVIEW_RECOMMENDED, NEEDS_REPAIR)
            if ($hasBadEnd || $qualityScore < 0.50) {
                $statusTier = 'NEEDS_REPAIR';
                $needsReview = true;
            } elseif ($qualityScore < 0.78 || $durSec > 7.5) {
                $statusTier = 'REVIEW_RECOMMENDED';
                $needsReview = true;
            } else {
                $statusTier = 'READY';
                $needsReview = false;
            }

            $finalChunks[] = [
                'segment_index' => $index + 1,
                'start_ms' => $chunk['start_ms'],
                'end_ms' => $chunk['end_ms'],
                'transcript' => $chunk['transcript'],
                'quality_score' => $qualityScore,
                'status_tier' => $statusTier,
                'needs_review' => $needsReview,
            ];
        }

        return $finalChunks;
    }

    private function applyCrossChunkRepair(array $chunks): array
    {
        if (count($chunks) <= 1) {
            return $chunks;
        }

        $repaired = [];
        $curr = null;

        foreach ($chunks as $chunk) {
            if ($curr === null) {
                $curr = $chunk;
                continue;
            }

            $currHasBadEnd = $this->hasBadPhraseEnding($curr['transcript']);
            $currEndsWithComma = (bool) preg_match('/,$/u', trim($curr['transcript']));
            $combinedDur = ($chunk['end_ms'] - $curr['start_ms']);

            // 1. Merge if current chunk ends on a true dangling bad ending word
            // 2. Merge if current chunk ends on a dependent clause with comma and combined duration <= 7500ms
            if ($currHasBadEnd || ($currEndsWithComma && $combinedDur <= 7500)) {
                $curr['end_ms'] = $chunk['end_ms'];
                $curr['transcript'] = trim($curr['transcript'] . ' ' . $chunk['transcript']);
                $curr['needs_review'] = $curr['needs_review'] || $chunk['needs_review'];
            } else {
                $repaired[] = $curr;
                $curr = $chunk;
            }
        }

        if ($curr !== null) {
            $repaired[] = $curr;
        }

        // Secondary Pass: Split mid-chunk sentence bleeds (e.g. "...aptitudes. There are hundreds...")
        $finalRepaired = [];
        foreach ($repaired as $chunk) {
            // Check if chunk contains a sentence boundary ". " in the middle
            if (preg_match('/^(.+[.?!])\s+([A-Z].+)$/u', $chunk['transcript'], $matches)) {
                $firstPart = trim($matches[1]);
                $secondPart = trim($matches[2]);
                $totalDur = $chunk['end_ms'] - $chunk['start_ms'];

                // Estimate split boundary proportionally based on word count
                $wordsFirst = count(explode(' ', $firstPart));
                $wordsSecond = count(explode(' ', $secondPart));
                $totalWords = max(1, $wordsFirst + $wordsSecond);
                $splitMs = (int) ($chunk['start_ms'] + ($totalDur * ($wordsFirst / $totalWords)));

                $finalRepaired[] = [
                    'start_ms' => $chunk['start_ms'],
                    'end_ms' => $splitMs,
                    'transcript' => $firstPart,
                    'needs_review' => false,
                ];

                $finalRepaired[] = [
                    'start_ms' => $splitMs,
                    'end_ms' => $chunk['end_ms'],
                    'transcript' => $secondPart,
                    'needs_review' => false,
                ];
            } else {
                $finalRepaired[] = $chunk;
            }
        }

        return $finalRepaired;
    }

    private function preprocessAndSplitLongCaptions(array $captions): array
    {
        $result = [];

        foreach ($captions as $cap) {
            $dur = $cap['end_ms'] - $cap['start_ms'];
            $words = $cap['words'] ?? [];

            if ($dur > $this->longChunkThresholdMs && !empty($words)) {
                $splits = $this->splitCaptionUsingWordEvidence($cap, $words);
                foreach ($splits as $s) {
                    $result[] = $s;
                }
            } else {
                $result[] = [
                    'text' => $cap['text'],
                    'start_ms' => $cap['start_ms'],
                    'end_ms' => $cap['end_ms'],
                    'needs_review' => $dur > $this->longChunkThresholdMs && empty($words),
                ];
            }
        }

        return $result;
    }

    private function splitCaptionUsingWordEvidence(array $cap, array $words): array
    {
        $splitUnits = [];
        $bufferWords = [];
        $startMs = $cap['start_ms'];

        $wordCount = count($words);
        for ($k = 0; $k < $wordCount; $k++) {
            $w = $words[$k];
            $bufferWords[] = $w['word'];
            $wEnd = $w['end_ms'];

            $isLastWord = ($k === $wordCount - 1);
            $nextW = !$isLastWord ? $words[$k + 1] : null;
            $pauseMs = $nextW ? ($nextW['start_ms'] - $wEnd) : 0;

            $hasPunctuation = (bool) preg_match('/[.?!,]$/u', $w['word']);
            $currentDur = $wEnd - $startMs;

            $shouldSplit = !$isLastWord && (
                ($hasPunctuation && $currentDur >= $this->preferredMinDurationMs) ||
                ($pauseMs >= $this->pauseSplitThresholdMs && $currentDur >= 2000) ||
                ($currentDur >= $this->preferredMaxDurationMs)
            );

            if ($shouldSplit) {
                $splitUnits[] = [
                    'text' => trim(implode(' ', $bufferWords)),
                    'start_ms' => $startMs,
                    'end_ms' => $wEnd,
                    'needs_review' => false,
                ];
                $bufferWords = [];
                $startMs = $nextW ? $nextW['start_ms'] : $wEnd;
            }
        }

        if (!empty($bufferWords)) {
            $splitUnits[] = [
                'text' => trim(implode(' ', $bufferWords)),
                'start_ms' => $startMs,
                'end_ms' => $cap['end_ms'],
                'needs_review' => false,
            ];
        }

        return $splitUnits;
    }

    private function hasClauseMarker(string $text): bool
    {
        $lower = strtolower($text);
        foreach ($this->clauseMarkers as $marker) {
            if (str_contains($lower, " {$marker} ") || str_ends_with($lower, " {$marker}")) {
                return true;
            }
        }
        return false;
    }

    private function hasBadPhraseEnding(string $text): bool
    {
        $trimmed = trim($text);
        $hasTerminalPunctuation = (bool) preg_match('/[.?!]$|\.\.\.$/u', $trimmed);

        $tokens = explode(' ', strtolower(trim(preg_replace('/[^\w\s]/u', '', $trimmed))));
        $lastToken = end($tokens);

        if (!in_array($lastToken, $this->badEndingWords, true)) {
            return false;
        }

        // EXCEPTION 1: Phrasal verbs / complete predicates with terminal punctuation (e.g. "certain of...", "thinking of.", "listen to!")
        if ($hasTerminalPunctuation && in_array($lastToken, ['of', 'in', 'for', 'about', 'at', 'on', 'to'], true)) {
            $lower = strtolower($trimmed);
            $validPhrasalPatterns = ['certain of', 'thinking of', 'thought of', 'proud of', 'tired of', 'aware of', 'take care of', 'listen to', 'talking about', 'looking at', 'going on'];
            foreach ($validPhrasalPatterns as $pattern) {
                if (str_contains($lower, $pattern)) {
                    return false; // NOT a bad ending! It's a complete predicate phrase!
                }
            }
        }

        return true;
    }
}
