<?php

namespace Translatorio;

use Translatorio\Exception\ServerError;

/**
 * cURL, and nothing else.
 *
 * No Guzzle, no PSR-18 adapter, no dependency at all beyond the extension every PHP
 * installation already has. A client for one small API is not worth a dependency tree, and
 * a library that forces a major version of an HTTP client on its host is a library that
 * gets removed the first time the host disagrees.
 */
class CurlTransport implements Transport
{
    /**
     * @param int $timeout Seconds for the whole request, the reading of the answer included.
     *                     A limit on the connection alone lets a server that accepted the
     *                     request and then went quiet hold the caller for ever.
     * @param int $connectTimeout Seconds to wait for the connection.
     */
    public function __construct(
        private readonly int $timeout = 120,
        private readonly int $connectTimeout = 5,
    ) {
    }

    /**
     * @param string $method
     * @param string $url
     * @param array<string, string> $headers
     * @param string|null $body
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $handle = curl_init($url);

        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $received = [];

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min($this->connectTimeout, $this->timeout),
            // A translation call that follows a redirect has stopped being one: the second
            // request goes somewhere this client never authenticated to, with the key on it.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        // No `curl_close`: a no-op since PHP 8.0, when handles became objects freed with
        // their last reference, and deprecated in 8.5, where it would print a notice into
        // the host application's log on every call.

        if ($response === false) {
            // Reported as a server error rather than its own type, because the client
            // retries both and the caller does the same thing about either: the request
            // did not arrive, so ask again.
            throw new ServerError('Could not reach Translatorio: ' . $error, 0);
        }

        return ['status' => $status, 'body' => (string) $response, 'headers' => $received];
    }
}
