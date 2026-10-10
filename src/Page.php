<?php

namespace Translatorio;

/**
 * One page of a listing: its items, and where the next page starts.
 *
 * The same shape for every paged method, whatever the API calls its cursor underneath
 * (`next_cursor` for the history and the corrections). `next()` is opaque: pass it back as
 * the method's `cursor` argument, and null means this was the last page.
 *
 * @template T
 */
final class Page
{
    /**
     * @param list<T> $items
     * @param string|null $next
     * @param array<string, mixed> $raw The decoded answer, in full.
     */
    public function __construct(
        private readonly array $items,
        private readonly ?string $next,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @return list<T>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Where the next page starts, to pass back as `cursor`. Null on the last page.
     *
     * @return string|null
     */
    public function next(): ?string
    {
        return $this->next;
    }
}
