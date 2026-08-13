<?php

namespace App\Modules\Shadowing\Domain\Exceptions;

use RuntimeException;

class TranslationProviderUnavailableException extends RuntimeException
{
    // Exception thrown when translation provider credentials or services are missing/unavailable
}
