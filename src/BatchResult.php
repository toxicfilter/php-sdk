<?php

namespace ToxicFilter;

/**
 * What came back from a batch: a verdict per item, or an error in its place.
 *
 * One bad item is an item and not a batch: the API answers 200 with the failure filed
 * where that item was, so this object never throws for a single bad element and gives you
 * both
 * halves separately.
 */
class BatchResult
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(public readonly array $raw)
    {
    }

    /** @return string */
    public function id(): string
    {
        return (string) ($this->raw['batch_id'] ?? '');
    }

    /** @return string `queued`, `running` or `completed`. */
    public function status(): string
    {
        return (string) ($this->raw['status'] ?? 'queued');
    }

    /** @return bool */
    public function finished(): bool
    {
        return $this->status() === 'completed';
    }

    /**
     * The verdicts, keyed by the position they were sent in.
     *
     * @return array<int, Verdict>
     */
    public function verdicts(): array
    {
        $out = [];

        foreach ((array) ($this->raw['results'] ?? []) as $row) {
            if (isset($row['error'])) {
                continue;
            }

            $out[(int) ($row['index'] ?? count($out))] = new Verdict((array) $row);
        }

        return $out;
    }

    /**
     * The items that never became a verdict, by position.
     *
     * @return array<int, array<string, mixed>>
     */
    public function failures(): array
    {
        $out = [];

        foreach ((array) ($this->raw['results'] ?? $this->raw['errors'] ?? []) as $row) {
            if (! isset($row['error'])) {
                continue;
            }

            $out[(int) ($row['index'] ?? count($out))] = (array) $row['error'];
        }

        return $out;
    }

    /** @return int */
    public function count(): int
    {
        return (int) ($this->raw['count'] ?? 0);
    }

    /** @return int */
    public function processed(): int
    {
        return (int) ($this->raw['processed'] ?? 0);
    }

    /** @return int */
    public function failed(): int
    {
        return (int) ($this->raw['failed'] ?? 0);
    }

    /** @return int */
    public function creditsCharged(): int
    {
        return (int) ($this->raw['credits_charged'] ?? 0);
    }

    /**
     * The cursor for the next page, or null when that was the last one. Pass it back as
     * `after`: an offset would skip rows that workers filed behind it.
     *
     * @return int|null
     */
    public function nextAfter(): ?int
    {
        $after = $this->raw['next_after'] ?? null;

        return $after === null ? null : (int) $after;
    }

    /** @return bool Whether there is another page to ask for. */
    public function hasMore(): bool
    {
        return $this->nextAfter() !== null;
    }
}
