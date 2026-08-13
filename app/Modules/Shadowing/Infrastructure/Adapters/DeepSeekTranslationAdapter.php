<?php

namespace App\Modules\Shadowing\Infrastructure\Adapters;

use App\Modules\Shadowing\Domain\Contracts\TranslationProviderContract;
use App\Modules\Shadowing\Domain\Exceptions\TranslationProviderPermanentException;
use App\Modules\Shadowing\Domain\Exceptions\TranslationProviderTransientException;
use App\Modules\Shadowing\Domain\Exceptions\TranslationProviderUnavailableException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        // STRENGTHENED SYSTEM PROMPT: Complete semantic translation without omitting any clause
        $systemPrompt = "You are a professional IELTS English-to-Vietnamese translator. " .
            "Translate ALL semantic content, clauses, and sentences in each chunk accurately into fluent, natural conversational Vietnamese. " .
            "Do NOT omit any sentence, clause, or detail. Do NOT summarize or add extra commentary/explanations. " .
            "Preserve proper nouns, names, and social media handles appropriately. " .
            "Use the provided context_prev and context_next to ensure proper tone, style, and sentence flow. " .
            "Return valid JSON matching: {\"translations\": [{\"chunk_index\": 1, \"translation_vi\": \"...\"}]}";

        try {
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
                'temperature'     => 0.2,
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException | \GuzzleHttp\Exception\ConnectException | \GuzzleHttp\Exception\RequestException $e) {
            Log::error('DeepSeek Translation Network/Connection Error', ['error' => $e->getMessage()]);
            throw new TranslationProviderTransientException("Translation provider transport/network failure: " . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            if ($e instanceof TranslationProviderTransientException || $e instanceof TranslationProviderPermanentException) {
                throw $e;
            }
            Log::error('DeepSeek Translation Unexpected Transport Error', ['error' => $e->getMessage()]);
            throw new TranslationProviderTransientException("Translation provider transport failure: " . $e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $status = $response->status();
            $body = $response->body();

            Log::error('DeepSeek Translation API HTTP Error', [
                'status' => $status,
                'body'   => $body,
            ]);

            // TRANSIENT ERRORS (HTTP 408, 429, 5xx) -> Retryable
            if (in_array($status, [408, 429], true) || $status >= 500) {
                throw new TranslationProviderTransientException("Translation provider API HTTP {$status}: {$body}");
            }

            // PERMANENT ERRORS (HTTP 400, 401, 403, 404, 422) -> Non-retryable
            throw new TranslationProviderPermanentException("Translation provider API HTTP {$status}: {$body}");
        }

        $responseData = $response->json();
        $contentStr = $responseData['choices'][0]['message']['content'] ?? '{}';
        $parsed = json_decode($contentStr, true);

        if (! is_array($parsed) || ! isset($parsed['translations']) || ! is_array($parsed['translations'])) {
            throw new TranslationProviderPermanentException("Translation provider returned malformed JSON structure.");
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
                throw new TranslationProviderPermanentException("Missing translation for chunk_index {$idx} in provider output.");
            }
            $final[] = [
                'chunk_index'    => $idx,
                'translation_vi' => $mapByChunk[$idx],
            ];
        }

        return $final;
    }
}
