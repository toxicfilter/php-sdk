<?php

namespace ToxicFilter;

use ToxicFilter\Exception\ServerError;

/**
 * One answer from the API.
 *
 * There is no `isToxic()`: three outcomes and fifteen categories do not collapse into one
 * boolean. `needsReview()` is as easy to reach as `blocked()`.
 */
class Verdict
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(public readonly array $raw)
    {
    }

    /** The three answers the API gives. Anything else is not an answer. */
    private const DECISIONS = ['allow', 'review', 'block'];

    /**
     * What to do with it: `allow`, `review` or `block`.
     *
     * Never guessed. 1.0.0 read a missing decision as `allow`, so anything that reached a
     * `Verdict` without one (a proxy's page, a body cut short, a field renamed) meant
     * "publish it". A value outside the three is refused too: `allowed()`, `needsReview()`
     * and `blocked()` would all be false, and code written the obvious way (refuse if
     * blocked, hold if review, otherwise publish) would publish it.
     *
     * @return string
     * @throws ServerError When the answer carries no decision, or one this client does not know.
     */
    public function decision(): string
    {
        $decision = $this->raw['decision'] ?? null;

        if (! is_string($decision) || ! in_array($decision, self::DECISIONS, true)) {
            throw new ServerError(
                $decision === null
                    ? 'The answer carried no decision, so nothing can be said about this content. It is not an allow.'
                    : sprintf('The answer carried a decision this client does not know (%s). It is not an allow.', is_scalar($decision) ? (string) $decision : gettype($decision)),
                0,
                null,
                $this->raw,
            );
        }

        return $decision;
    }

    /** @return bool Nothing crossed a line. Publish it. */
    public function allowed(): bool
    {
        return $this->decision() === 'allow';
    }

    /** @return bool A person should look. Hold it, do not delete it. */
    public function needsReview(): bool
    {
        return $this->decision() === 'review';
    }

    /** @return bool Refuse it. */
    public function blocked(): bool
    {
        return $this->decision() === 'block';
    }

    /**
     * The verdict's own id. Keep it: it names this decision in support, feedback and webhooks.
     *
     * @return string|null
     */
    public function id(): ?string
    {
        $id = $this->raw['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /** @return string|null Your own id for the thing, handed back. */
    public function reference(): ?string
    {
        $reference = $this->raw['reference'] ?? null;

        return is_string($reference) ? $reference : null;
    }

    /**
     * Everything that crossed a line, worst first.
     *
     * A category by its own name; a subject or lead type prefixed `topic:` or `lead:`,
     * since its score is in `topics()` or `leads()` and not in `scores()`.
     *
     * @return list<string>
     */
    public function flagged(): array
    {
        return array_values((array) ($this->raw['flagged'] ?? []));
    }

    /**
     * @param string $category
     * @return float 0.0 when the category did not score at all.
     */
    public function score(string $category): float
    {
        return (float) ($this->raw['scores'][$category] ?? 0.0);
    }

    /** @return array<string, float> */
    public function scores(): array
    {
        return (array) ($this->raw['scores'] ?? []);
    }

    /**
     * Why, in sentences you can show the person whose content it was.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        return array_values(array_map(
            static fn (array $signal) => (string) ($signal['reason'] ?? ''),
            (array) ($this->raw['signals'] ?? []),
        ));
    }

    /** @return list<array<string, mixed>> Every finding, with its evidence. */
    public function signals(): array
    {
        return array_values((array) ($this->raw['signals'] ?? []));
    }

    /** @return bool Whether a model read it, or the cheap detectors settled it. */
    public function usedAi(): bool
    {
        return (bool) ($this->raw['used_ai'] ?? false);
    }

    /** @return bool Whether this content had been judged before. */
    public function cached(): bool
    {
        return (bool) ($this->raw['cached'] ?? false);
    }

    /** @return int What it cost, in credits. */
    public function charged(): int
    {
        return (int) ($this->raw['credits']['charged'] ?? $this->raw['charged'] ?? 0);
    }

    /** @return int Credits left after this call. */
    public function creditsRemaining(): int
    {
        return (int) ($this->raw['credits']['remaining'] ?? 0);
    }

    /**
     * How much this is ABOUT a subject, 0 to 1 per topic. Centrality, not presence.
     *
     * A separate axis from the categories: a subject is not a harm, and nothing acts on one
     * until your rules give it a line.
     *
     * @return array<string, float>
     */
    public function topics(): array
    {
        return (array) ($this->raw['topics'] ?? []);
    }

    /**
     * @param string $topic
     * @return float 0.0 when the subject is not there at all.
     */
    public function topic(string $topic): float
    {
        return (float) ($this->raw['topics'][$topic] ?? 0.0);
    }

    /**
     * Who is writing, 0 to 1 per lead type (`free_work_for_equity`, `no_budget`, `no_show`…).
     *
     * One person is often several types at once, so each keeps its own score, and none acts
     * until your rules give a type a line.
     *
     * @return array<string, float>
     */
    public function leads(): array
    {
        return (array) ($this->raw['leads'] ?? []);
    }

    /**
     * @param string $type
     * @return float 0.0 when nothing of that type showed.
     */
    public function lead(string $type): float
    {
        return (float) ($this->raw['leads'][$type] ?? 0.0);
    }

    /**
     * What was noticed but is not a finding: `age_signal`, the language, an image fingerprint.
     *
     * @return array<string, mixed>
     */
    public function facts(): array
    {
        return (array) ($this->raw['facts'] ?? []);
    }

    /**
     * Part of the pipeline could not run, usually the model. The verdict is real, reached
     * with less. Not the same as `usedAi()` being false, which means it was not needed.
     *
     * @return bool
     */
    public function degraded(): bool
    {
        return (bool) ($this->raw['degraded'] ?? false);
    }

    /**
     * Why the model did not read this although it was asked to, or null when it was not
     * skipped.
     *
     * Today the one reason is `conversation_sampling`: in a conversation the model reads a
     * message when the free detectors found something, a lead type is half there, or every
     * few messages. Without this, a message deliberately left unread looks exactly like one
     * the cheap detectors settled, and `degraded()` (nobody COULD read it) is a different
     * statement again.
     *
     * @return string|null
     */
    public function modelSkipped(): ?string
    {
        $model = $this->raw['model'] ?? null;

        if (! is_array($model) || ($model['read'] ?? null) !== false) {
            return null;
        }

        return is_string($model['why'] ?? null) ? $model['why'] : 'unknown';
    }

    /**
     * Which rules produced this, by slug and version. Worth logging: only a versioned
     * verdict can be argued about six months later.
     *
     * @return array{slug: string, version: int}
     */
    public function policy(): array
    {
        return [
            'slug' => (string) ($this->raw['policy']['slug'] ?? 'default'),
            'version' => (int) ($this->raw['policy']['version'] ?? 0),
        ];
    }

    /**
     * The content with personal data masked, when you sent `redact: true` and there was any.
     * Null when you did not ask, or there was nothing to mask.
     *
     * @return string|null
     */
    public function redacted(): ?string
    {
        $redacted = $this->raw['redacted'] ?? null;

        return is_string($redacted) ? $redacted : null;
    }

    /**
     * What was known beyond the content: `repeats`, `similar`, and the actor's `history`
     * with the `adjustment` it earned. Present whenever it was not empty, even when it
     * moved the line by nothing.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return (array) ($this->raw['context'] ?? []);
    }

    /**
     * What the policy being TRIALLED would have said. Reported, never acted on. Null when
     * no shadow policy is set.
     *
     * @return array{slug: string|null, version: int, decision: string|null}|null
     */
    public function shadow(): ?array
    {
        $shadow = $this->raw['shadow'] ?? null;

        if (is_array($shadow)) {
            return [
                'slug' => isset($shadow['slug']) ? (string) $shadow['slug'] : null,
                'version' => (int) ($shadow['version'] ?? 0),
                'decision' => isset($shadow['decision']) ? (string) $shadow['decision'] : null,
            ];
        }

        // A stored verdict spells the same two facts flat, the way its columns are named.
        if (isset($this->raw['shadow_slug']) || isset($this->raw['shadow_decision'])) {
            return [
                'slug' => isset($this->raw['shadow_slug']) ? (string) $this->raw['shadow_slug'] : null,
                'version' => (int) ($this->raw['shadow_version'] ?? 0),
                'decision' => isset($this->raw['shadow_decision']) ? (string) $this->raw['shadow_decision'] : null,
            ];
        }

        return null;
    }

    /**
     * Where this verdict stands in the queue: the state, who decided and when.
     *
     * Only `review` opens an entry, so a verdict that was allowed outright has nothing
     * here. Filled only by `record()`, `resolve()` and `feedback()`: neither the call that
     * produced the verdict nor the rows of `records()` carry it, so an empty array there
     * means "not asked", not "not reviewed".
     *
     * @return array<string, mixed>
     */
    public function review(): array
    {
        return (array) ($this->raw['review'] ?? []);
    }

    /** @return string|null `open`, `approved` or `rejected`. */
    public function reviewState(): ?string
    {
        $state = $this->review()['state'] ?? null;

        return is_string($state) ? $state : null;
    }

    /** @return bool Whether a person has already dealt with it. */
    public function resolved(): bool
    {
        return in_array($this->reviewState(), ['approved', 'rejected'], true);
    }

    /** @return string|null Your own name for whoever decided. We never invent one. */
    public function resolvedBy(): ?string
    {
        $by = $this->review()['resolved_by'] ?? null;

        return is_string($by) ? $by : null;
    }

    /** @return string|null When they decided, as an ISO 8601 string. */
    public function resolvedAt(): ?string
    {
        $at = $this->review()['resolved_at'] ?? null;

        return is_string($at) ? $at : null;
    }

    /**
     * What you have already told us about this verdict: `correct`, `false_positive` or
     * `false_negative`, with its note and the time it was sent. Null when nobody has said.
     *
     * One answer per verdict, so sending a second replaces the first.
     *
     * @return array<string, mixed>|null
     */
    public function feedback(): ?array
    {
        $feedback = $this->raw['feedback'] ?? null;

        return is_array($feedback) ? $feedback : null;
    }

    /**
     * The content, when your policy kept it and it has not expired. Null almost everywhere:
     * `retain_hours` is 0 by default, and only `record()` ever fills this.
     *
     * @return string|null
     */
    public function content(): ?string
    {
        $content = $this->raw['content'] ?? null;

        return is_string($content) ? $content : null;
    }

    /** @return string|null When the kept content goes, as an ISO 8601 string. */
    public function contentExpiresAt(): ?string
    {
        $at = $this->raw['content_expires_at'] ?? null;

        return is_string($at) ? $at : null;
    }

    /** @return string|null What was judged: `text`, `email`, `name`, `image`, ... */
    public function kind(): ?string
    {
        $kind = $this->raw['kind'] ?? null;

        return is_string($kind) ? $kind : null;
    }

    /** @return string|null When the verdict was reached, as an ISO 8601 string. */
    public function createdAt(): ?string
    {
        $at = $this->raw['created_at'] ?? null;

        return is_string($at) ? $at : null;
    }

    /** @return string|null The batch it arrived in, when it arrived in one. */
    public function batchId(): ?string
    {
        $id = $this->raw['batch_id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /** @return int How long it took us, in milliseconds. */
    public function tookMs(): int
    {
        return (int) ($this->raw['took_ms'] ?? 0);
    }
}
