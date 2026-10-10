<?php

namespace Translatorio;

/**
 * The answer to `translateImage()`: every block of text in the picture, with its box.
 */
class ImageTranslation extends Result
{
    /**
     * Every block: `text` (as written in the picture), `box` (`x`, `y`, `width`, `height`,
     * fractions of the image from its top-left corner) and `translations` (language => text).
     *
     * @return list<array<string, mixed>>
     */
    public function blocks(): array
    {
        return array_values((array) ($this->raw['blocks'] ?? []));
    }

    /**
     * Every block's translation into one language, in the picture's order.
     *
     * @param string $language
     * @return list<string>
     */
    public function texts(string $language): array
    {
        $out = [];

        foreach ($this->blocks() as $block) {
            $text = $block['translations'][$language] ?? null;

            if (is_string($text)) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function targets(): array
    {
        return array_values((array) ($this->raw['targets'] ?? []));
    }
}
