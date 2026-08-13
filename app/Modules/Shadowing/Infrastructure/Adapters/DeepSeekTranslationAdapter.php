<?php

namespace App\Modules\Shadowing\Infrastructure\Adapters;

use App\Modules\Shadowing\Domain\Contracts\TranslationProviderContract;
use App\Modules\Shadowing\Domain\Exceptions\TranslationProviderUnavailableException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DeepSeekTranslationAdapter implements TranslationProviderContract
{
    protected ?string $apiKey;
    protected string $model;
    protected string $baseUrl;

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?string $baseUrl = null
    ) {
        $this->apiKey = $apiKey ?? config('shadowing.translation.deepseek.api_key');
        $this->model = $model ?? config('shadowing.translation.model', 'deepseek-chat');
        $this->baseUrl = $baseUrl ?? config('shadowing.translation.deepseek.base_url', 'https://api.deepseek.com/v1');
    }

    public function getProviderName(): string
    {
        return 'deepseek';
    }

    public function getModelName(): string
    {
        return $this->model;
    }

    public function translateChunks(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        // P0 REQUIREMENT: NO FAKE TRANSLATION FALLBACK
        if (empty($this->apiKey)) {
            throw new TranslationProviderUnavailableException(
                "DeepSeek API key is missing. Translation provider cannot process chunks without valid credentials."
            );
        }

        $promptItems = array_map(function ($item) {
            return [
                'chunk_index'  => $item['chunk_index'],
                'transcript'   => $item['transcript'],
                'context_prev' => $item['prev_transcript'] ?? null,
                'context_next' => $item['next_transcript'] ?? null,
            ];
        }, $items);

        $systemPrompt = "You are a professional IELTS English-to-Vietnamese translator. " .
            "Translate each natural speaking chunk into fluent, natural conversational Vietnamese. " .
            "Use the provided context_prev and context_next to ensure accurate context, tone, and sentence flow. " .
            "Return valid JSON matching: {\"translations\": [{\"chunk_index\": 1, \"translation_vi\": \"...\"}]}";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
        ])->timeout(45)->post($this->baseUrl . '/chat/completions', [
            'model'           => $this->model,
            'messages'        => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => json_encode(['items' => $promptItems], JSON_UNESCAPED_UNICODE)],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature'     => 0.3,
        ]);

        if (! $response->successful()) {
            Log::error('DeepSeek Translation API HTTP Error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new RuntimeException("Translation provider API error HTTP {$response->status()}: " . $response->body());
        }

        $responseData = $response->json();
        $contentStr = $responseData['choices'][0]['message']['content'] ?? '{}';
        $parsed = json_decode($contentStr, true);

        if (! is_array($parsed) || ! isset($parsed['translations']) || ! is_array($parsed['translations'])) {
            throw new RuntimeException("Translation provider returned malformed JSON structure.");
        }

        return $this->formatAndValidateOutput($items, $parsed['translations']);
    }

    protected function formatAndValidateOutput(array $originalItems, array $translations): array
    {
        $mapByChunk = [];
        foreach ($translations as $trans) {
            if (isset($trans['chunk_index']) && isset($trans['translation_vi'])) {
                $mapByChunk[$trans['chunk_index']] = trim((string) $trans['translation_vi']);
            }
        }

        $final = [];
        foreach ($originalItems as $orig) {
            $idx = $orig['chunk_index'];
            if (! isset($mapByChunk[$idx]) || empty($mapByChunk[$idx])) {
                throw new RuntimeException("Missing translation for chunk_index {$idx} in provider output.");
            }
            $final[] = [
                'chunk_index'    => $idx,
                'translation_vi' => $mapByChunk[$idx],
            ];
        }

        return $final;
    }
}
