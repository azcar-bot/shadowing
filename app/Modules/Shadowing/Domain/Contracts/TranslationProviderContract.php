<?php

namespace App\Modules\Shadowing\Domain\Contracts;

interface TranslationProviderContract
{
    /**
     * Translates natural speaking chunks into Vietnamese with neighboring context.
     *
     * @param array<int, array{chunk_index: int, transcript: string, prev_transcript: ?string, next_transcript: ?string}> $items
     * @return array<int, array{chunk_index: int, translation_vi: string}>
     */
    public function translateChunks(array $items): array;

    public function getProviderName(): string;

    public function getModelName(): string;
}
