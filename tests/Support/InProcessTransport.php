<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Services\Sync\Transport;
use App\Services\Sync\TransportException;
use Server\Kernel;

/** Calls the real server Kernel in-process (no HTTP), with optional fault injection. */
final class InProcessTransport implements Transport
{
    public int $calls = 0;
    /** @var callable|null fn(int $callNo, string $path): ?string  return 'before' to fail before server sees it, 'after' to drop the response */
    public $fault = null;
    public array $log = [];

    public function __construct(private Kernel $kernel, private string $deviceId, private string $token)
    {
    }

    public function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $this->calls++;
        $mode = $this->fault ? ($this->fault)($this->calls, $path) : null;
        if ($mode === 'before') {
            throw new TransportException('Simulated network down');
        }
        $raw = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        [$st, $resp] = $this->kernel->handle($method, $path, ['Authorization' => 'Bearer ' . $this->token, 'X-Device-Id' => $this->deviceId], $raw, $query);
        $this->log[] = [$method, $path, $st];
        if ($mode === 'after') {
            throw new TransportException('Simulated connection drop after server processed the request');
        }
        // round-trip through JSON like a real network would
        return [$st, json_decode(json_encode($resp), true)];
    }
}
