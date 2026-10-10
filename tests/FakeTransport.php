<?php

namespace Translatorio\Tests;

use Translatorio\Transport;

/**
 * Answers with whatever it was handed, and remembers what it was asked.
 *
 * A queued response may be an exception instead of a body, which is how a transport that
 * cannot reach the service is simulated. A third element, `true`, sends the payload as the
 * body verbatim (a proxy's HTML page, an empty 301); a fourth carries response headers.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{0: int, 1: mixed, 2?: bool, 3?: array<string, string>}> */
    private array $responses;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /**
     * @param list<array{0: int, 1: mixed, 2?: bool, 3?: array<string, string>}> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    /**
     * Records the call and hands back the next queued answer.
     *
     * @param string $method
     * @param string $url
     * @param array<string, string> $headers
     * @param string|null $body
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            // Decoded when it is JSON, so a test reads fields; `raw` keeps the bytes as sent,
            // which is what a multipart upload is checked on.
            'body' => $body === null ? null : json_decode($body, true),
            'raw' => $body,
            'key' => $headers['Idempotency-Key'] ?? null,
        ];

        [$status, $payload, $raw, $responseHeaders] = array_pad(array_shift($this->responses), 4, null);

        if ($payload instanceof \Throwable) {
            throw $payload;
        }

        return [
            'status' => $status,
            'body' => $raw ? (string) $payload : (string) json_encode($payload),
            'headers' => (array) $responseHeaders,
        ];
    }
}
