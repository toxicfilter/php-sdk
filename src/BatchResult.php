<?php

namespace ToxicFilter;

/**
 * What came back from a batch: a verdict per item, or an error in its place.
 *
 * One bad item is an item and not a batch: the API answers 200 with the failure filed
 * where that item was, so this object never throws for a single bad element and gives you
 * both halves separately.
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

    /** @return string|null The project the batch was filed under. */
    public function project(): ?string
    {
        $project = $this->raw['project'] ?? null;

        return is_string($project) ? $project : null;
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
     * The items that never became a verdict, by the position they were sent in.
     *
     * Read from BOTH lists the API uses. A batch read back with `batchStatus()` always has
     * `results` (the verdicts filed so far) and keeps its item errors in `errors`, so
     * reading `errors` only when `results` was missing reported every failure of an async
     * batch as none. A sync batch carries its rejected items in both, and the position is
     * what makes them one failure rather than two.
     *
     * An error that belongs to no single item, such as a whole chunk the workers could not
     * run (`chunk_failed`), has no position and sits under a NEGATIVE key, -1, -2, and so
     * on, in the order it arrived. A real position is never negative, so it can never
     * overwrite one, and `$index >= 0` tells the two apart.
     *
     * @return array<int, array<string, mixed>>
     */
    public function failures(): array
    {
        $out = [];
        $unplaced = 0;

        foreach ([$this->raw['results'] ?? [], $this->raw['errors'] ?? []] as $rows) {
            foreach ((array) $rows as $row) {
                if (! is_array($row) || ! isset($row['error'])) {
                    continue;
                }

                $error = is_array($row['error']) ? $row['error'] : ['message' => (string) $row['error']];

                if (! isset($row['index']) || ! is_numeric($row['index'])) {
                    $out[-(++$unplaced)] = $error;

                    continue;
                }

                // First one wins: the same item in both lists is the same failure.
                $out[(int) $row['index']] ??= $error;
            }
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
