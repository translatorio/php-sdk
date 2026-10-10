<?php

namespace Translatorio;

/**
 * A batch: its summary, and, when read with `batchStatus()`, a page of its results.
 *
 * One bad item is an item and not a batch: it fails in its place with an `error`, and the
 * rest are kept. `results()` and `failures()` hand you the two halves separately, both
 * keyed by the position the text was sent in.
 */
class Batch
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(public readonly array $raw)
    {
    }

    /** @return string `bat_` and a ULID. */
    public function id(): string
    {
        return (string) ($this->raw['id'] ?? '');
    }

    /** @return string|null Your own id for the batch. */
    public function reference(): ?string
    {
        $reference = $this->raw['reference'] ?? null;

        return is_string($reference) ? $reference : null;
    }

    /** @return string `queued`, `running`, `completed` or `failed`. */
    public function status(): string
    {
        return (string) ($this->raw['status'] ?? 'queued');
    }

    /** @return bool Whether it will change no more, successfully or not. */
    public function finished(): bool
    {
        return in_array($this->status(), ['completed', 'failed'], true);
    }

    /** @return string|null The source language, or null when it is detected per text. */
    public function source(): ?string
    {
        $source = $this->raw['source'] ?? null;

        return is_string($source) ? $source : null;
    }

    /** @return list<string> */
    public function targets(): array
    {
        return array_values((array) ($this->raw['targets'] ?? []));
    }

    /** @return int Texts in the batch. */
    public function total(): int
    {
        return (int) ($this->raw['total'] ?? 0);
    }

    /** @return int Texts translated so far. */
    public function done(): int
    {
        return (int) ($this->raw['done'] ?? 0);
    }

    /** @return int Texts that failed in their place. */
    public function failed(): int
    {
        return (int) ($this->raw['failed'] ?? 0);
    }

    /** @return int Credits set aside when it was accepted. */
    public function creditsEstimated(): int
    {
        return (int) ($this->raw['credits']['estimated'] ?? 0);
    }

    /** @return int Credits charged so far. */
    public function creditsCharged(): int
    {
        return (int) ($this->raw['credits']['charged'] ?? 0);
    }

    /**
     * When the results are deleted. Read them before then.
     *
     * @return string|null
     */
    public function expiresAt(): ?string
    {
        $at = $this->raw['expires_at'] ?? null;

        return is_string($at) ? $at : null;
    }

    /**
     * The translations on this page, keyed by the position the text was sent in.
     *
     * @return array<int, array<string, string>> Position => language => translation.
     */
    public function results(): array
    {
        $out = [];

        foreach ((array) ($this->raw['results'] ?? []) as $row) {
            if (! is_array($row) || isset($row['error']) || ! isset($row['index'])) {
                continue;
            }

            $out[(int) $row['index']] = (array) ($row['translations'] ?? []);
        }

        return $out;
    }

    /**
     * Whether every placeholder and tag came back in one text's translations.
     *
     * @param int $index
     * @return bool False when that text failed, is not on this page, or the answer does not say.
     */
    public function intact(int $index): bool
    {
        foreach ((array) ($this->raw['results'] ?? []) as $row) {
            if (is_array($row) && (int) ($row['index'] ?? -1) === $index) {
                return ($row['intact'] ?? false) === true;
            }
        }

        return false;
    }

    /**
     * The texts on this page that never became a translation, keyed by position: each one
     * the API's `{code, message}`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function failures(): array
    {
        $out = [];

        foreach ((array) ($this->raw['results'] ?? []) as $row) {
            if (! is_array($row) || ! isset($row['error'], $row['index'])) {
                continue;
            }

            $out[(int) $row['index']] = is_array($row['error']) ? $row['error'] : ['message' => (string) $row['error']];
        }

        return $out;
    }

    /**
     * Where the next page of results starts, to pass back as `batchStatus()`'s `cursor`.
     * Null when that was the last page, and on a summary, which carries no results.
     *
     * @return string|null
     */
    public function next(): ?string
    {
        $after = $this->raw['next_after'] ?? null;

        return is_numeric($after) ? (string) $after : null;
    }
}
