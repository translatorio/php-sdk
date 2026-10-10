<?php

namespace Translatorio\Tests;

use Translatorio\CurlTransport;
use Translatorio\Exception\ServerError;

/**
 * The timeout covers the whole request, the reading of the answer included.
 *
 * A server that accepts the connection and then says nothing is the case a connect timeout
 * alone never ends. A socket that listens and never answers is exactly that server: the
 * kernel completes the handshake, nothing ever writes back.
 */
final class TimeoutTest extends TestCase
{
    public function test_a_server_that_never_answers_is_given_up_on_in_time(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($server, $error);
        $address = stream_socket_get_name($server, false);

        $started = microtime(true);

        try {
            (new CurlTransport(timeout: 1))->send('GET', "http://{$address}/api/v1/ping", [], null);
            $this->fail('A silent server was waited on for ever.');
        } catch (ServerError $e) {
            $this->assertSame(0, $e->status);
            $this->assertTrue($e->isRetryable());
        } finally {
            fclose($server);
        }

        $this->assertLessThan(3, microtime(true) - $started);
    }

    public function test_the_transport_does_not_follow_a_redirect(): void
    {
        $source = file_get_contents(__DIR__ . '/../src/CurlTransport.php');

        $this->assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $source);
        $this->assertStringNotContainsString('curl_close(', $source);
    }
}
