<?php

namespace App\Integrations;

use RuntimeException;

class IntegrationException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'integration_error')
    {
        parent::__construct($message);
    }
}
