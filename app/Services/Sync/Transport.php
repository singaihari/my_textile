<?php
declare(strict_types=1);

namespace App\Services\Sync;

interface Transport
{
    /**
     * @return array{0:int,1:array} HTTP status and decoded JSON body
     * @throws TransportException on network failure (no response received)
     */
    public function request(string $method, string $path, ?array $body = null, array $query = []): array;
}
