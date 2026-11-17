<?php
declare(strict_types=1);

namespace App\Services\Sync;

/**
 * HTTPS transport using the curl extension. TLS certificates are verified.
 * Plain http:// is accepted only for localhost (development / mock server).
 */
final class CurlTransport implements Transport
{
    public function __construct(
        private string $baseUrl,
        private string $deviceId,
        private string $token,
        private int $timeout = 30,
    ) {
        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
            throw new \InvalidArgumentException('Sync endpoint must use https:// (http:// is allowed only for localhost testing)');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP curl extension is not enabled');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
            'X-Device-Id: ' . $this->deviceId,
            'X-Request-Id: ' . bin2hex(random_bytes(8)),
            'User-Agent: TextilePOS-Sync/' . APP_VERSION,
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new TransportException('Network error: ' . $err);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            throw new TransportException("Server returned a non-JSON response (HTTP $status). Check the endpoint URL.");
        }
        return [$status, $json];
    }
}
