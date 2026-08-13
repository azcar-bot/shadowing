<?php

namespace App\Modules\Shadowing\Domain\Exceptions;

use RuntimeException;

class TranslationProviderTransientException extends RuntimeException
{
    // Exception thrown for retryable transient errors (network timeout, HTTP 408, 429, 5xx)
}
