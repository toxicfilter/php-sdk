![ToxicFilter PHP SDK](https://raw.githubusercontent.com/toxicfilter/php-sdk/main/art/banner.png)

# ToxicFilter PHP SDK

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
    return refuse($verdict->reason());   // the first reason; reasons() has them all
}

if ($verdict->needsReview()) {
    return hold($verdict->id(), $verdict->reasons());
}

publish();
```

## Reading a verdict

```php
$verdict->decision();         // 'allow', 'review' or 'block'
$verdict->allowed();          // and needsReview(), blocked()
$verdict->reason();           // the first reason, or null
$verdict->reasons();          // every reason, in words you can show the author
$verdict->flagged();          // the categories that crossed a line: ['spam']
$verdict->scores();           // every category that scored, 0 to 1
$verdict->score('spam');      // one of them, 0.0 when it did not score
$verdict->signals();          // every finding, with its evidence
$verdict->topics();           // what it is ABOUT, per subject: ['gambling' => 0.82]
$verdict->topic('crypto');
$verdict->id();               // mod_..., the name of this decision
$verdict->reference();        // your own id, as you sent it
$verdict->project();          // the project it was filed under
$verdict->usedAi();           // whether the model read it
$verdict->cached();           // answered from a verdict already reached
$verdict->charged();          // credits this call cost
$verdict->creditsRemaining();
$verdict->policy();           // the rules it was judged under: slug, version, overridden
$verdict->tookMs();
```

Three decisions, not two. `review` is where the uncertainty is allowed to live: forced to
choose between publishing and deleting, a threshold set safely deletes real posts and one
set kindly publishes the abuse. There is no `isToxic()` here for the same reason: fifteen
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

**Never reads anything but an answer as `allow`.** A redirect (a base URL on `http://`,
which this client deliberately does not follow), an empty body, or a proxy's HTML page is a
`ServerError`, retried like one, and never a verdict. A verdict with no decision, or one the
client does not know, throws from `decision()`, `allowed()`, `needsReview()` and `blocked()`
rather than defaulting to anything.

Every failure is a type you can branch on:

| Status | Exception | Retried |
|---|---|---|
| 401 | `AuthenticationError` | no |
| 402 | `QuotaExhausted`, with `remaining()`, `required()`, `renewsAt()` | never |
| 404 | `NotFound` | no |
| 422 | `InvalidRequest`, with `fields()` | no |
| 429 | `RateLimited`, with `retryAfter()` | yes |
| 409, 5xx, network, not an answer | `ServerError` | yes |

All of them extend `ToxicFilter\Exception\ApiError`, which carries `status`, `errorCode` and
the decoded `payload`.

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

In a conversation the model is sometimes asked and deliberately not run (it reads a message
when the free detectors found something, when a lead type is half there, or every few
messages). That is a third statement, and it has its own accessor:

```php
$verdict->modelSkipped();   // 'conversation_sampling', or null when the model was not skipped
```

## Projects

An organization can moderate several sites, one project each. Name the project and the
verdict is filed there, with its own activity, review queue and webhooks; leave it out and it
goes to your default project. The keys and the credits are the organization's.

```php
$verdict = $tf->text($comment, ['project' => 'forum']);
$verdict->project();   // 'forum'

$tf->batch($items, ['project' => 'forum']);          // the whole batch, on the envelope
$tf->records(['project' => 'forum']);                // one project's queue
$tf->batches(['project' => 'forum']);                // its recent batches
```

A project that does not exist is refused with an `InvalidRequest` (`unknown_project`).

## Rules without a policy

Send the line you care about and nothing else is acted on. No stored policy is looked up,
and no default of ours is laid underneath.

```php
$verdict = $tf->text($comment, [
    'rules' => ['thresholds' => ['sexual' => ['block' => 0.7]]],
]);
```

A category you did not mention still scores and still appears in `signals`; it just does not
decide anything. A name that is not a real category, subject or lead type is a `422`: a line
that acts on nothing looks exactly like a line that works.

Send `rules` together with a `policy` and they are laid over it instead: the call wins for
what it names, the policy keeps everything else, and words are added to its lists.
`$verdict->policy()` then has `'overridden' => true`.

```php
$verdict = $tf->text($comment, [
    'policy' => 'comments',
    'rules' => ['thresholds' => ['spam' => ['block' => 0.6]]],
]);
```

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
foreach ($batch->failures() as $index => $error) { /* ... */ }   // negative keys: a failure no item owns

// A backfill: up to 1,000 items per call, queued, answered immediately, polled or webhooked.
$queued = $tf->batchAsync($aThousandComments);
$status = $tf->batchStatus($queued->id());
$status->finished();          // and status(), count(), processed(), failed(), creditsCharged()

// A backfill read back a page at a time. A cursor, not an offset: rows appear as workers
// finish them, so an offset skips whatever was inserted behind it.
$page = $tf->batchStatus($queued->id());

while ($page->hasMore()) {
    $page = $tf->batchStatus($queued->id(), ['after' => $page->nextAfter()]);
}

// The account's recent batches, newest first, for the id you did not keep.
foreach ($tf->batches(['limit' => 20]) as $batch) { $batch->id(); $batch->status(); }

// The review queue.
$queue = $tf->records(['state' => 'open']);
$tf->resolve($id, 'approved', 'ana@example.com');
$tf->feedback($id, 'false_positive');   // free, and the only honest measure we have

// And what a held verdict has had done to it. Only record(), resolve() and feedback()
// carry this: the rows of records() do not, so read one in full to see its state.
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
    $_SERVER['HTTP_X_TOXICFILTER_SIGNATURE'] ?? '',   // absent is a failed check, not a crash
    getenv('TOXICFILTER_WEBHOOK_SECRET'),
);

if ($event === null) {
    http_response_code(400);
    exit;
}
```

## Configuration

```php
$tf = new Client(
    key: getenv('TOXICFILTER_KEY'),        // tf_live_… or tf_test_… (never charged)
    baseUrl: 'https://toxicfilter.com',    // https: redirects are never followed
    retries: 2,                            // for 429 and 5xx; a 402 is never retried
    timeout: 10,                           // seconds per attempt
    maxWait: 30,                           // ceiling on the wait between attempts
);
```

## Notes

Requires PHP 8.1 and `ext-curl`. No other dependencies: a client for one small API is not
worth a dependency tree, and pinning an HTTP library is how a client gets removed from a
project. Bring your own by implementing `ToxicFilter\Transport`.

## How this is tested

```bash
composer install
composer test
```

No network and no framework: a stub transport answers like the API does. It covers what a
caller cannot see and would otherwise discover from an invoice: what is retried, what never
is, that a retry reuses its idempotency key while two calls do not, that anything but an
answer is refused, and that bytes go out as bytes.

## Author

Created by [Edu Lazaro](https://edulazaro.com) for [ToxicFilter](https://toxicfilter.com),
the moderation API this client speaks to.

## License

The ToxicFilter PHP SDK is open-sourced software licensed under the [MIT license](LICENSE).
