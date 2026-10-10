<?php

namespace Translatorio;

/**
 * The answer to `translateFile()`: one file per language, in the shape of the one sent.
 */
class FileTranslation extends Result
{
    /**
     * @return string|null The format the file was read as: `json`, `po`, `xliff`, `csv`…
     */
    public function format(): ?string
    {
        $format = $this->raw['format'] ?? null;

        return is_string($format) ? $format : null;
    }

    /**
     * @return int Strings found in the file.
     */
    public function strings(): int
    {
        return (int) ($this->raw['strings'] ?? 0);
    }

    /**
     * Every translated file: `language`, `name`, `content` and `intact`.
     *
     * @return list<array<string, mixed>>
     */
    public function files(): array
    {
        return array_values((array) ($this->raw['files'] ?? []));
    }

    /**
     * The file for one language.
     *
     * @param string $language
     * @return array<string, mixed>|null
     */
    public function file(string $language): ?array
    {
        foreach ($this->files() as $file) {
            if (($file['language'] ?? null) === $language) {
                return (array) $file;
            }
        }

        return null;
    }

    /**
     * The content of the file for one language, ready to write to disk.
     *
     * @param string $language
     * @return string|null
     */
    public function content(string $language): ?string
    {
        $content = $this->file($language)['content'] ?? null;

        return is_string($content) ? $content : null;
    }

    /**
     * @return bool Whether every placeholder and tag came back in every file. False when unsaid.
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
     * @return int String and language pairs translated.
     */
    public function segments(): int
    {
        return (int) ($this->raw['usage']['segments'] ?? 0);
    }

    /**
     * @return int Pairs answered from your translation memory.
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
     * @return int Pieces of personal data masked before the model saw the strings.
     */
    public function anonymized(): int
    {
        return (int) ($this->raw['usage']['anonymized'] ?? 0);
    }
}
