<?php

namespace Translatorio;

/**
 * The answer to `translate()`: every text, in every language asked for.
 */
class Translation extends Result
{
    /**
     * The translation, when ONE text went into ONE language, which is most calls.
     *
     * Null otherwise: with several texts or several languages there is no single answer,
     * and `get()` or `results()` is how to read them.
     *
     * @return string|null
     */
    public function translation(): ?string
    {
        $translation = $this->raw['translation'] ?? null;

        return is_string($translation) ? $translation : null;
    }

    /**
     * One text in one language.
     *
     * @param string $language A code as the answer spells it: `es`, `pt-BR`.
     * @param int $index The text's position in what was sent, from 0.
     * @return string|null Null when that text or that language is not in the answer.
     */
    public function get(string $language, int $index = 0): ?string
    {
        $text = $this->translations($index)[$language] ?? null;

        return is_string($text) ? $text : null;
    }

    /**
     * One text in every language asked for.
     *
     * @param int $index The text's position in what was sent, from 0.
     * @return array<string, string> Language => translation.
     */
    public function translations(int $index = 0): array
    {
        foreach ($this->results() as $result) {
            if ((int) ($result['index'] ?? -1) === $index) {
                return (array) ($result['translations'] ?? []);
            }
        }

        return [];
    }

    /**
     * One entry per text, in the order sent: `index`, `translations` and `intact`.
     *
     * @return list<array<string, mixed>>
     */
    public function results(): array
    {
        return array_values((array) ($this->raw['results'] ?? []));
    }

    /**
     * Whether every placeholder, tag and masked value came back exactly once.
     *
     * False when the answer does not say, rather than true: this is the field that tells you
     * to look before publishing, and a missing one must not read as "safe to publish".
     *
     * @return bool
     */
    public function intact(): bool
    {
        return ($this->raw['intact'] ?? false) === true;
    }

    /**
     * @return bool Whether the source language was detected rather than given.
     */
    public function detected(): bool
    {
        return (bool) ($this->raw['source']['detected'] ?? false);
    }

    /**
     * @return list<string> The target languages, normalised as the API reads them.
     */
    public function targets(): array
    {
        return array_values((array) ($this->raw['targets'] ?? []));
    }

    /**
     * @return int Text and language pairs translated.
     */
    public function segments(): int
    {
        return (int) ($this->raw['usage']['segments'] ?? 0);
    }

    /**
     * @return int Pairs answered from your translation memory, at no model cost.
     */
    public function cached(): int
    {
        return (int) ($this->raw['usage']['cached'] ?? 0);
    }

    /**
     * @return int Pairs answered from your corrections.
     */
    public function corrected(): int
    {
        return (int) ($this->raw['usage']['corrected'] ?? 0);
    }

    /**
     * @return int Pieces of personal data masked before the model saw the text.
     */
    public function anonymized(): int
    {
        return (int) ($this->raw['usage']['anonymized'] ?? 0);
    }
}
