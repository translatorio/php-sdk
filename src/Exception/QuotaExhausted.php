<?php

namespace Translatorio\Exception;

/**
 * The account cannot pay for this call.
 *
 * **Never retried, by anything in this library.** 402 means come back with a bigger plan,
 * and a client that retries it hammers for ever and never succeeds. Catch it, stop calling,
 * and tell somebody: no amount of waiting produces credits.
 */
class QuotaExhausted extends ApiError
{
    /**
     * The figures the refusal carried, from `credits` beside `error` (every 402 has it
     * there, a batch refused at acceptance included).
     *
     * @return array<string, mixed>
     */
    public function credits(): array
    {
        $credits = $this->payload['credits'] ?? [];

        return is_array($credits) ? $credits : [];
    }

    /**
     * @return int|null What is left, or null when the answer did not say.
     */
    public function remaining(): ?int
    {
        $remaining = $this->credits()['remaining'] ?? null;

        return is_numeric($remaining) ? (int) $remaining : null;
    }

    /**
     * What this call was estimated at. A 402 can arrive with credits still in the account:
     * the check prices the call, not one credit.
     *
     * @return int|null
     */
    public function required(): ?int
    {
        $required = $this->credits()['required'] ?? null;

        return is_numeric($required) ? (int) $required : null;
    }

    /**
     * What running batches have set aside, which a new batch has to fit beside.
     *
     * @return int|null Null when the refusal was not about a batch.
     */
    public function queued(): ?int
    {
        $queued = $this->credits()['queued'] ?? null;

        return is_numeric($queued) ? (int) $queued : null;
    }

    /**
     * @return string|null When the month's allowance renews, as an ISO 8601 date.
     */
    public function renewsAt(): ?string
    {
        $at = $this->credits()['renews_at'] ?? null;

        return is_string($at) ? $at : null;
    }
}
