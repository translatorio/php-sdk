<?php

namespace Translatorio\Exception;

/**
 * The request was malformed and nothing was translated or charged.
 */
class InvalidRequest extends ApiError
{
    /**
     * Which fields were wrong, when the API said: `error.fields`, field => messages.
     *
     * The same map whether a validation failed or one field was refused (`unknown_field`,
     * `unsupported_language`, `unknown_glossary`): the API answers both in this shape.
     *
     * @return array<string, list<string>>
     */
    public function fields(): array
    {
        $error = (array) ($this->payload['error'] ?? []);

        return isset($error['fields']) && is_array($error['fields']) ? $error['fields'] : [];
    }
}
