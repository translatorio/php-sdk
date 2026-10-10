<?php

namespace Translatorio;

/**
 * Checking that a delivery is ours.
 *
 * Your webhook URL is public, so a handler that acts on whatever arrives can be written
 * into from outside. Verify every delivery.
 */
class Webhooks
{
    /** The header carrying the signature. */
    public const HEADER = 'X-Translatorio-Signature';

    /** The header naming the event, also inside the body as `event`. */
    public const EVENT_HEADER = 'X-Translatorio-Event';

    /** A batch finished; `data` is its summary. */
    public const BATCH_COMPLETED = 'batch.completed';

    /** Four fifths of the month's allowance are gone. Once a month. */
    public const CREDITS_LOW = 'credits.low';

    /** A call was refused for credits. Once a month. */
    public const QUOTA_EXHAUSTED = 'quota.exhausted';

    /**
     * Whether the body was signed by Translatorio with this secret, recently.
     *
     * @param string $payload The RAW body, exactly as received.
     * @param string $header The value of `X-Translatorio-Signature`.
     * @param string $secret Your endpoint's signing secret.
     * @param int $tolerance Seconds of clock difference to allow.
     * @return bool
     */
    public static function verify(string $payload, string $header, string $secret, int $tolerance = 300): bool
    {
        $parts = [];

        foreach (explode(',', $header) as $piece) {
            [$name, $value] = array_pad(explode('=', trim($piece), 2), 2, null);
            $parts[(string) $name] = (string) $value;
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $signature = (string) ($parts['v1'] ?? '');

        if ($timestamp <= 0 || $signature === '') {
            return false;
        }

        // The timestamp is signed WITH the body, so a delivery captured today cannot be
        // replayed tomorrow: moving it breaks the signature and leaving it stale puts the
        // request outside this window.
        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        // Constant time. A comparison that returns early on the first wrong character
        // tells an attacker how much of their guess was right.
        return hash_equals($expected, $signature);
    }

    /**
     * The event, once it is known to be ours: `id`, `event`, `created_at` and `data`.
     *
     * @param string $payload The RAW body. Re-encoding a parsed array signs a different
     *                        string, since a key order or an escaped slash may differ.
     * @param string $header
     * @param string $secret
     * @param int $tolerance
     * @return array<string, mixed>|null Null when it does not verify.
     */
    public static function event(string $payload, string $header, string $secret, int $tolerance = 300): ?array
    {
        if (! self::verify($payload, $header, $secret, $tolerance)) {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }
}
