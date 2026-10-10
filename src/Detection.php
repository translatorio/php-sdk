<?php

namespace Translatorio;

/**
 * The answer to `detect()`: which language a text is in.
 */
class Detection extends Result
{
    /**
     * The language's code, or null when it could not be told.
     *
     * @return string|null
     */
    public function code(): ?string
    {
        $code = $this->raw['language']['code'] ?? null;

        return is_string($code) ? $code : null;
    }

    /**
     * The language in full: `code`, `name`, `native` and `rtl`.
     *
     * @return array<string, mixed>|null
     */
    public function language(): ?array
    {
        $language = $this->raw['language'] ?? null;

        return is_array($language) ? $language : null;
    }

    /**
     * @return float|null 0 to 1.
     */
    public function confidence(): ?float
    {
        $confidence = $this->raw['confidence'] ?? null;

        return is_numeric($confidence) ? (float) $confidence : null;
    }

    /**
     * How it was told: `script` (an alphabet only one language uses, free) or `model`.
     *
     * @return string|null
     */
    public function by(): ?string
    {
        $by = $this->raw['by'] ?? null;

        return is_string($by) ? $by : null;
    }
}
