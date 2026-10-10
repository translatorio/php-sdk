<?php

namespace Translatorio;

/**
 * What every translation call answers with beside its translation: the id, the cost, what
 * the model did, and how long it took.
 *
 * Thin on purpose: every accessor reads a field the API sends, and `raw` holds the rest.
 */
abstract class Result
{
    /**
     * @param array<string, mixed> $raw The decoded answer, in full.
     */
    public function __construct(public readonly array $raw)
    {
    }

    /**
     * The call's own id, `tr_` and a ULID. It names this call in the history.
     *
     * @return string|null
     */
    public function id(): ?string
    {
        $id = $this->raw['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * Your own id for the call, as you sent it.
     *
     * @return string|null
     */
    public function reference(): ?string
    {
        $reference = $this->raw['reference'] ?? null;

        return is_string($reference) ? $reference : null;
    }

    /**
     * The source language: the one you gave, or the one detected.
     *
     * @return string|null
     */
    public function source(): ?string
    {
        $language = $this->raw['source']['language'] ?? null;

        return is_string($language) ? $language : null;
    }

    /**
     * @return array<string, mixed> Characters, segments, cached, model and tokens, as sent.
     */
    public function usage(): array
    {
        return (array) ($this->raw['usage'] ?? []);
    }

    /**
     * @return int Characters this call was counted as.
     */
    public function characters(): int
    {
        return (int) ($this->raw['usage']['characters'] ?? 0);
    }

    /**
     * Whether the model read this call. False for a test key, and for a call answered
     * entirely from memory or corrections.
     *
     * @return bool
     */
    public function modelRead(): bool
    {
        return (bool) ($this->raw['usage']['model']['read'] ?? false);
    }

    /**
     * Why the model did not read it, said out loud: `test_key` (a marked copy, never a
     * translation) or `memory`. Null when it read it.
     *
     * @return string|null
     */
    public function modelWhy(): ?string
    {
        $why = $this->raw['usage']['model']['why'] ?? null;

        return is_string($why) ? $why : null;
    }

    /**
     * @return string|null The model that read it, when one did.
     */
    public function modelName(): ?string
    {
        $name = $this->raw['usage']['model']['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * The effort the call was made at (`low`, `medium` or `high`), which picked the model.
     *
     * @return string|null
     */
    public function modelEffort(): ?string
    {
        $effort = $this->raw['usage']['model']['effort'] ?? null;

        return is_string($effort) ? $effort : null;
    }

    /**
     * @return int Credits this call cost.
     */
    public function charged(): int
    {
        return (int) ($this->raw['credits']['charged'] ?? 0);
    }

    /**
     * @return int|null Credits left after it, or null when the answer did not say.
     */
    public function creditsRemaining(): ?int
    {
        $remaining = $this->raw['credits']['remaining'] ?? null;

        return is_numeric($remaining) ? (int) $remaining : null;
    }

    /**
     * Whether a test key made the call. A test answer is a marked copy, never a translation:
     * do not publish it.
     *
     * @return bool
     */
    public function isTest(): bool
    {
        return ($this->raw['mode'] ?? null) === 'test';
    }

    /**
     * @return int|null Milliseconds the call took on our side.
     */
    public function tookMs(): ?int
    {
        $took = $this->raw['took_ms'] ?? null;

        return is_numeric($took) ? (int) $took : null;
    }
}
