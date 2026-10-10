<?php

namespace Translatorio;

/**
 * How a request actually leaves the machine.
 *
 * One method, so this package pins no HTTP library and can be pointed at anything:
 * `Http::` in Laravel, or a test that dispatches into a real application.
 */
interface Transport
{
    /**
     * Sends one request and hands back what came back, without judging it.
     *
     * The client decides what is an answer and what is an error; a transport only reports.
     * It must NOT follow redirects, and it should report a request that never arrived (a
     * refused connection, a timeout) as a `ServerError` with status 0, which the client
     * retries.
     *
     * @param string $method
     * @param string $url
     * @param array<string, string> $headers
     * @param string|null $body The body, already encoded (JSON, or a multipart form whose
     *                          boundary is in the `Content-Type` header). Send it as it is.
     * @return array{status: int, body: string, headers?: array<string, string>} Header names
     *         in any case; only `Retry-After` is read.
     */
    public function send(string $method, string $url, array $headers, ?string $body): array;
}
