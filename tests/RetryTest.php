<?php

namespace Translatorio\Tests;

use Translatorio\Exception\ApiError;
use Translatorio\Exception\AuthenticationError;
use Translatorio\Exception\InvalidRequest;
use Translatorio\Exception\NotFound;
use Translatorio\Exception\QuotaExhausted;
use Translatorio\Exception\RateLimited;
use Translatorio\Exception\ServerError;

/**
 * What is retried, what never is, and whether a retry is the same request or a second one.
 *
 * The behaviours a caller cannot see and would otherwise find out from an invoice.
 */
final class RetryTest extends TestCase
{
    public function test_a_rate_limit_and_a_server_error_are_retried_honouring_retry_after(): void
    {
        [$tr, $transport] = $this->client([
            [429, ['error' => ['code' => 'rate_limited', 'message' => 'Too many.']], false, ['Retry-After' => '3']],
            [503, ['error' => ['code' => 'translation_unavailable', 'message' => 'Down.']]],
            [200, $this->translation()],
        ]);

        $this->assertSame('Hola mundo', $tr->translate('Hello world', 'es')->translation());
        $this->assertCount(3, $transport->calls);
        $this->assertSame([3, 4], $tr->waited, 'Retry-After says how long; the server error grows.');
    }

    public function test_a_retry_reuses_the_key_and_two_calls_never_share_one(): void
    {
        [$tr, $transport] = $this->client([
            [500, ['error' => ['code' => 'server_error']]],
            [200, $this->translation()],
            [200, $this->translation()],
        ]);

        $tr->translate('Hello', 'es');
        $tr->translate('Hello', 'es');

        $this->assertNotNull($transport->calls[0]['key']);
        $this->assertSame($transport->calls[0]['key'], $transport->calls[1]['key'], 'A retry is the same request.');
        $this->assertNotSame($transport->calls[1]['key'], $transport->calls[2]['key'], 'Two calls are two requests.');
    }

    public function test_a_key_of_your_own_is_used_and_never_sent_in_the_body(): void
    {
        [$tr, $transport] = $this->client([[200, $this->translation()]]);

        $tr->translate('Hello', 'es', ['idempotency_key' => 'release-42-hello']);

        $this->assertSame('release-42-hello', $transport->calls[0]['key']);
        $this->assertArrayNotHasKey('idempotency_key', $transport->calls[0]['body']);
    }

    public function test_a_quota_refusal_is_never_retried(): void
    {
        [$tr, $transport] = $this->client([
            [402, [
                'error' => ['code' => 'quota_exhausted', 'message' => 'Out.'],
                'credits' => ['remaining' => 3, 'required' => 9, 'renews_at' => '2026-11-01T00:00:00+00:00'],
            ]],
        ]);

        try {
            $tr->translate('Hello', 'es');
            $this->fail('A 402 did not throw.');
        } catch (QuotaExhausted $e) {
            $this->assertSame(3, $e->remaining());
            $this->assertSame(9, $e->required());
            $this->assertNull($e->queued());
            $this->assertSame('2026-11-01T00:00:00+00:00', $e->renewsAt());
            $this->assertSame('quota_exhausted', $e->errorCode);
            $this->assertFalse($e->isRetryable());
        }

        $this->assertCount(1, $transport->calls, 'No amount of retrying produces credits.');
        $this->assertSame([], $tr->waited);
    }

    public function test_a_batch_refusal_says_what_running_batches_set_aside(): void
    {
        [$tr] = $this->client([[402, [
            'error' => ['code' => 'quota_exhausted', 'message' => 'Out.'],
            'credits' => ['remaining' => 50, 'required' => 40, 'queued' => 30],
        ]]]);

        try {
            $tr->batch(['a'], 'es');
            $this->fail('A 402 did not throw.');
        } catch (QuotaExhausted $e) {
            $this->assertSame(30, $e->queued(), 'A batch refusal carries its figures beside `error`, like any 402.');
            $this->assertSame(50, $e->remaining());
            $this->assertSame(40, $e->required());
        }
    }

    /**
     * @return array<string, array{0: int, 1: class-string, 2: string}>
     */
    public static function refusals(): array
    {
        return [
            'no key' => [401, AuthenticationError::class, 'invalid_api_key'],
            'not here' => [404, NotFound::class, 'not_found'],
            'malformed' => [422, InvalidRequest::class, 'invalid_request'],
            'a reused key' => [422, InvalidRequest::class, 'idempotency_key_reused'],
            'a bad key header' => [400, ApiError::class, 'invalid_idempotency_key'],
            'not that method' => [405, ApiError::class, 'method_not_allowed'],
        ];
    }

    /**
     * @dataProvider refusals
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_a_refusal_is_typed_and_asked_once(int $status, string $class, string $code): void
    {
        [$tr, $transport] = $this->client([[$status, ['error' => ['code' => $code, 'message' => 'No.']]]]);

        try {
            $tr->translate('Hello', 'es');
            $this->fail("A {$status} did not throw.");
        } catch (ApiError $e) {
            $this->assertSame($class, $e::class);
            $this->assertSame($status, $e->status);
            $this->assertSame($code, $e->errorCode);
            $this->assertSame('No.', $e->getMessage());
        }

        $this->assertCount(1, $transport->calls);
    }

    public function test_a_validation_failure_names_its_fields(): void
    {
        $unknown = '`targetz` is not a field of this endpoint. Did you mean `targets`?';

        [$tr] = $this->client([
            [422, ['error' => ['code' => 'invalid_request', 'message' => 'The text field is required.', 'fields' => ['text' => ['The text field is required.']]]]],
            [422, ['error' => ['code' => 'unknown_field', 'message' => $unknown, 'fields' => ['targetz' => [$unknown]]]]],
            // The shape the API no longer sends is no longer read: `fields` is the only one.
            [422, ['error' => ['code' => 'unknown_field', 'message' => $unknown, 'field' => 'targetz']]],
        ]);

        foreach ([['text' => ['The text field is required.']], ['targetz' => [$unknown]], []] as $expected) {
            try {
                $tr->translate('Hello', 'es');
                $this->fail('A 422 did not throw.');
            } catch (InvalidRequest $e) {
                $this->assertSame($expected, $e->fields());
            }
        }
    }

    public function test_the_retries_run_out_and_the_last_error_is_thrown(): void
    {
        [$tr, $transport] = $this->client([
            [429, ['error' => ['code' => 'rate_limited']], false, ['retry-after' => '2']],
            [429, ['error' => ['code' => 'rate_limited']], false, ['retry-after' => '2']],
            [429, ['error' => ['code' => 'rate_limited']], false, ['retry-after' => '2']],
        ]);

        try {
            $tr->ping();
            $this->fail('Ran out of retries without saying so.');
        } catch (RateLimited $e) {
            $this->assertSame(2, $e->retryAfter());
        }

        $this->assertCount(3, $transport->calls);
    }

    public function test_the_wait_in_the_body_comes_before_the_header(): void
    {
        [$tr] = $this->client([
            [429, ['error' => ['code' => 'rate_limited', 'message' => 'Too many.', 'retry_after' => 7]], false, ['Retry-After' => '3']],
            [429, ['error' => ['code' => 'too_many_attempts', 'message' => 'Wait.', 'retry_after' => 5]]],
            [200, $this->translation()],
        ]);

        $tr->translate('Hello', 'es');

        $this->assertSame([7, 5], $tr->waited, '`error.retry_after` first; the header when the body has none.');
    }

    public function test_a_wait_the_server_asks_for_is_capped(): void
    {
        $transport = new FakeTransport([
            [429, ['error' => ['code' => 'rate_limited']], false, ['Retry-After' => '3600']],
            [200, ['ok' => true]],
        ]);
        $tr = new SleeplessClient('tr_test_key', 'https://example.test/api', 2, $transport, maxWait: 30);

        $tr->ping();

        $this->assertSame([30], $tr->waited);
    }

    public function test_an_unreachable_service_is_retried(): void
    {
        [$tr, $transport] = $this->client([
            [0, new ServerError('Could not reach Translatorio: refused', 0)],
            [200, ['ok' => true]],
        ]);

        $this->assertTrue($tr->ping()['ok']);
        $this->assertCount(2, $transport->calls);
    }

    public function test_a_call_still_in_flight_is_waited_for_and_asked_again(): void
    {
        [$tr, $transport] = $this->client([
            [409, ['error' => ['code' => 'idempotency_in_flight']], false, ['Retry-After' => '1']],
            [200, $this->translation()],
        ]);

        $tr->translate('Hello', 'es');

        $this->assertSame([1], $tr->waited);
        $this->assertSame($transport->calls[0]['key'], $transport->calls[1]['key']);
    }

    public function test_no_retries_means_one_attempt(): void
    {
        [$tr, $transport] = $this->client([[500, ['error' => ['code' => 'server_error']]]], retries: 0);

        $this->expectException(ServerError::class);

        try {
            $tr->ping();
        } finally {
            $this->assertCount(1, $transport->calls);
        }
    }
}
