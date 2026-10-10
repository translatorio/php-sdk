<?php

namespace Translatorio\Tests;

use Translatorio\Webhooks;

/**
 * The check a receiver runs, byte for byte the one the API signs with
 * (`App\Webhooks\Signature`): `t=…,v1=…`, HMAC-SHA256 over `timestamp.body`.
 */
final class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private const BODY = '{"id":"evt_1","event":"batch.completed","created_at":"2026-10-07T10:00:00+00:00","data":{"id":"bat_1","status":"completed"}}';

    /**
     * @param string $body
     * @param string $secret
     * @param int|null $timestamp
     * @return string
     */
    private function sign(string $body, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp . '.' . $body, $secret));
    }

    public function test_a_delivery_signed_with_the_secret_verifies(): void
    {
        $event = Webhooks::event(self::BODY, $this->sign(self::BODY, self::SECRET), self::SECRET);

        $this->assertSame(Webhooks::BATCH_COMPLETED, $event['event']);
        $this->assertSame('bat_1', $event['data']['id']);
    }

    public function test_the_wrong_secret_fails(): void
    {
        $this->assertFalse(Webhooks::verify(self::BODY, $this->sign(self::BODY, 'another'), self::SECRET));
        $this->assertNull(Webhooks::event(self::BODY, $this->sign(self::BODY, 'another'), self::SECRET));
    }

    public function test_a_stale_delivery_fails_even_with_a_good_signature(): void
    {
        $old = $this->sign(self::BODY, self::SECRET, time() - 301);

        $this->assertFalse(Webhooks::verify(self::BODY, $old, self::SECRET));
        $this->assertTrue(Webhooks::verify(self::BODY, $old, self::SECRET, tolerance: 600));
    }

    public function test_a_body_changed_after_signing_fails(): void
    {
        $header = $this->sign(self::BODY, self::SECRET);

        $this->assertFalse(Webhooks::verify(str_replace('completed', 'failed', self::BODY), $header, self::SECRET));
    }

    public function test_a_missing_or_mangled_header_fails_without_a_crash(): void
    {
        foreach (['', 't=', 'v1=abc', 'garbage', 't=abc,v1=def'] as $header) {
            $this->assertFalse(Webhooks::verify(self::BODY, $header, self::SECRET), $header);
        }
    }

    public function test_the_names_are_the_apis(): void
    {
        $this->assertSame('X-Translatorio-Signature', Webhooks::HEADER);
        $this->assertSame('X-Translatorio-Event', Webhooks::EVENT_HEADER);
        $this->assertSame(['batch.completed', 'credits.low', 'quota.exhausted'], [Webhooks::BATCH_COMPLETED, Webhooks::CREDITS_LOW, Webhooks::QUOTA_EXHAUSTED]);
    }
}
