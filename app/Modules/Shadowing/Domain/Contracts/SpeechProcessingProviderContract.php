<?php

declare(strict_types=1);

namespace App\Modules\Shadowing\Domain\Contracts;

interface SpeechProcessingProviderContract
{
    /**
     * Transcribe media URL / audio stream into raw timestamped caption units.
     *
     * @return array{duration_seconds?: int, items: array<int, array{text: string, start_ms: int, end_ms: int}>}
     */
    public function transcribe(string $mediaUrl, string $language = 'en'): array;

    /**
     * Force align canonical transcript against audio stream.
     */
    public function align(string $mediaUrl, string $canonicalTranscript, string $language = 'en'): array;
}
