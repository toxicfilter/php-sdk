# ToxicFilter for PHP

The official PHP client for [ToxicFilter](https://toxicfilter.com).

```bash
composer require edulazaro/toxicfilter-sdk
```

```php
use ToxicFilter\Client;

$tf = new Client(getenv('TOXICFILTER_KEY'));

$verdict = $tf->text('Check this message', [
    'locales' => ['en'],
    'surface' => 'comment',
    'reference' => 'comment_9931',
]);

if ($verdict->blocked()) {
    return refuse();
}

if ($verdict->needsReview()) {
    return hold($verdict->id(), $verdict->reasons());
}

publish();
```

Three decisions, not two. `review` is where the uncertainty is allowed to live: forced to
choose between publishing and deleting, a threshold set safely deletes real posts and one
set kindly publishes the abuse. There is no `isToxic()` here for the same reason — fifteen
categories collapsed into one boolean is somebody else's policy in your code.

## What it does for you

**Retries the right failures and never the wrong one.** A 429 or a 5xx is asked again with
a growing wait; a `QuotaExhausted` is not, ever. Those two are constantly confused and the
correct behaviour is opposite: retry a rate limit, stop dead on a quota.

**Makes those retries safe.** Every call carries an `Idempotency-Key`, generated per call,
so a request that timed out and is asked again is judged once and billed once.

**Waits as long as the service asked, and no longer.** A 429 carries `retry_after`, and
honouring it beats guessing: the service knows when its own window turns over. It is bounded
by `maxWait` (30 seconds by default) all the same, because a number on the wire should not
decide how long your own request hangs.

```php
use ToxicFilter\Exception\QuotaExhausted;
use ToxicFilter\Exception\RateLimited;

try {
    $verdict = $tf->text($comment);
} catch (QuotaExhausted $e) {
    // Stop calling. No amount of retrying produces credits.
    alert("out of credits, {$e->remaining()} left, renews {$e->renewsAt()}");
} catch (RateLimited $e) {
    // Already retried, and still too many. Back off properly.
}
```

## When the model is down

A verdict reached without the model because the provider was failing comes back with
`degraded` set, and is billed as the cheap call. It is a separate field from `used_ai` on
purpose: one says the cheap detectors were enough, the other says nobody read it, and only
the first is reassuring. Hold or queue what matters to you when you see it.

## Rules without a policy

Send the line you care about and nothing else is acted on. No stored policy is looked up,
and no default of ours is laid underneath.

```php
$verdict = $tf->text($comment, [
    'rules' => ['thresholds' => ['sexual' => ['block' => 0.7]]],
]);
```

A category you did not mention still scores and still appears in `signals`; it just does not
decide anything. `policy` and `rules` in the same call is a `422`, and so is a name that is
not a real category, subject or lead type: a line that acts on nothing looks exactly like a
line that works.

## The rest of the answer

```php
$verdict->redacted();   // the content with the personal data masked, when you asked
$verdict->context();    // repeats, near-duplicates, the actor's record and what it moved
$verdict->shadow();     // what a policy you are trialling would have said. Never what happened
$verdict->facts();      // noticed, not a finding: a language, an age signal, a fingerprint
$verdict->degraded();   // part of the pipeline could not run
```

`redacted` is usually worth more than a refusal: throwing a whole comment away because it
carried one phone number throws away everything else the person wrote.

```php
$verdict = $tf->text($comment, ['redact' => true, 'actor' => 'user_8812']);

publish($verdict->redacted() ?? $comment);
```

## Who is writing

Every verdict also carries `leads`: what kind of lead wrote it, scored per type. Neither a
harm nor a subject, but who is on the other side and what they want.

```php
$verdict = $tf->conversation($messages, [
    'rules' => ['leads' => ['free_work_for_equity' => ['block' => 0.6]]],
]);

$verdict->leads();                       // ['free_work_for_equity' => 0.9, 'no_budget' => 0.7]
$verdict->lead('sales_pitch');           // 0.0 when nothing of that type showed
$verdict->blocked();                     // true: your rule discarded it
```

Types: `free_work_for_equity`, `no_budget`, `unrealistic_expectations`, `free_consulting`,
`scope_creep`, `no_show`, `sales_pitch`, `partnership_offer`, `job_seeker`,
`student_or_survey`, `support_request`. Send the conversation rather than one message and
everything that person said counts.

## Everything else

```php
$tf->email('someone@mailinator.com');
$tf->name('asdkjhasd');
$tf->signup(['name' => 'Ana', 'email' => 'ana@example.com', 'bio' => '...']);
$tf->image('https://cdn.example.com/photo.jpg');
$tf->image($base64OrDataUri);                   // or the bytes, if you have not published it
$tf->imageData($rawFileContents);               // the same thing, said explicitly
$tf->url('https://bit.ly/3xYz');               // one link, judged as a link
$tf->prompt($whatTheUserTypedIntoYourChatbot);   // prompt injection, plus everything else

// A message with what came before it. A pile-on is thirty people each writing one
// ordinary rude sentence, and no classifier reading one of them can see it.
$tf->conversation([
    ['author' => 'u1', 'content' => '...'],
    ['author' => 'u2', 'content' => '...'],
    ['author' => 'u5', 'content' => 'the one being judged'],
], ['locales' => ['es']]);

// Many things in one call. One bad item is an item, not a batch.
$batch = $tf->batch([
    ['kind' => 'text', 'content' => '...', 'reference' => 'c_1'],
    ['kind' => 'image', 'url' => '...', 'reference' => 'p_2'],
], ['ai' => false]);

foreach ($batch->verdicts() as $index => $verdict) { /* ... */ }
foreach ($batch->failures() as $index => $error) { /* ... */ }

// A backfill: queued, answered immediately, polled or webhooked.
$queued = $tf->batchAsync($tenThousandComments);
$tf->batchStatus($queued->id());

// A backfill read back a page at a time. A cursor, not an offset: rows appear as workers
// finish them, so an offset skips whatever was inserted behind it.
$page = $tf->batchStatus($queued->id());

while ($page->hasMore()) {
    $page = $tf->batchStatus($queued->id(), ['after' => $page->nextAfter()]);
}

// The review queue.
$queue = $tf->records(['state' => 'open']);
$tf->resolve($id, 'approved', 'ana@example.com');
$tf->feedback($id, 'false_positive');   // free, and the only honest measure we have

// And what a held verdict has had done to it.
$verdict = $tf->record($id);
$verdict->reviewState();         // open, approved or rejected
$verdict->resolvedBy();          // your own name for whoever decided
$verdict->feedback();            // what you already told us, or null
$verdict->content();             // only when your policy keeps it, and only until it expires

$tf->keys();                  // prefixes, modes, last use. Never a secret.
$tf->revokeKey($id);          // including the one you are calling with. There is no create.

$tf->usage();   // credits, windows, prices. Works at zero credits.
$tf->ping();
```

## Webhooks

Your endpoint URL is public. Verify before you act:

```php
use ToxicFilter\Webhooks;

$event = Webhooks::event(
    file_get_contents('php://input'),          // the RAW body
    $_SERVER['HTTP_X_TOXICFILTER_SIGNATURE'],
    getenv('TOXICFILTER_WEBHOOK_SECRET'),
);

if ($event === null) {
    http_response_code(400);
    exit;
}
```

## Notes

Requires PHP 8.1 and `ext-curl`. No other dependencies: a client for one small API is not
worth a dependency tree, and pinning an HTTP library is how a client gets removed from a
project. Bring your own by implementing `ToxicFilter\Transport`.

## How this is tested

Two suites, answering two different questions.

```bash
composer install
composer test
```

That one runs here, with no network and no framework, over a stub transport. It covers what
a caller cannot see and would otherwise discover from an invoice: what is retried, what
never is, that a retry reuses its idempotency key while two calls do not, and that bytes go
out as bytes.

The second one lives in the ToxicFilter application, which keeps this package as a Composer
`path` repository and implements the `Transport` interface below by dispatching straight
into its own test client. Every method here then runs against the real routes, middleware
and responses, so a change on either side that would break the other fails a build rather
than an install.

The split is deliberate. A stub agrees with whatever it is handed, so it can prove this
client behaves correctly and can never prove it agrees with the API about a field name.
Fixtures alone would match the API on the day they were written and drift silently
afterwards.
