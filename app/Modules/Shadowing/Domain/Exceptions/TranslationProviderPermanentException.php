<?php

namespace App\Modules\Shadowing\Domain\Exceptions;

use RuntimeException;

class TranslationProviderPermanentException extends RuntimeException
{
    // Exception thrown for non-retryable permanent errors (missing API key, HTTP 400, 401, 403, 404, 422, malformed JSON)
}
