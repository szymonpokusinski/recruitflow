<?php

namespace App\Integrations\PracujPl;

use RuntimeException;
use Throwable;

final class ImportException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $httpStatus = 422, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
