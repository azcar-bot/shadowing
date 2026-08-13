<?php

namespace App\Modules\Shadowing\Domain\Services;

use InvalidArgumentException;

class YouTubeUrlNormalizerService
{
    /**
     * Extracts clean 11-character YouTube video ID from various URL formats.
     *
     * Supported formats:
     * - https://www.youtube.com/watch?v=dbtN9HOOqhk
     * - https://youtu.be/dbtN9HOOqhk
     * - https://www.youtube.com/embed/dbtN9HOOqhk
     * - https://youtube.com/shorts/dbtN9HOOqhk
     * - dbtN9HOOqhk
     */
    public function extractVideoId(string $input): string
    {
        $input = trim($input);

        if (empty($input)) {
            throw new InvalidArgumentException('YouTube URL or Video ID cannot be empty.');
        }

        // If direct 11-character alphanumeric ID
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $input)) {
            return $input;
        }

        $patterns = [
            '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i',
            '/youtube\.com\/shorts\/([^"&?\/\s]{11})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input, $matches)) {
                return $matches[1];
            }
        }

        throw new InvalidArgumentException('Invalid YouTube URL or Video ID format.');
    }
}
