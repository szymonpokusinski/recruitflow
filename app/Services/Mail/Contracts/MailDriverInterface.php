<?php

declare(strict_types=1);

namespace App\Services\Mail\Contracts;

interface MailDriverInterface
{
    /**
     * Test connection to mail server with given settings and credentials.
     *
     * @param array{
     *     host: string,
     *     port: int,
     *     encryption: string,
     *     username: string,
     *     password: string,
     *     timeout?: int
     * } $config
     * @return array{
     *     success: bool,
     *     error: string|null,
     *     error_code: string|null
     * }
     */
    public function testConnection(array $config): array;
}
