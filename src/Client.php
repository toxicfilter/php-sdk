<?php

namespace ToxicFilter;

use ToxicFilter\Exception\ApiError;
use ToxicFilter\Exception\AuthenticationError;
use ToxicFilter\Exception\InvalidRequest;
use ToxicFilter\Exception\NotFound;
use ToxicFilter\Exception\QuotaExhausted;
use ToxicFilter\Exception\RateLimited;
use ToxicFilter\Exception\ServerError;

/**
 * The ToxicFilter API, from PHP.
 *
 * Retries a 429 and a 5xx with a growing wait, and a 402 never. Every call carries an
 * `Idempotency-Key`, so a retried timeout is judged once and billed once. See the README
 * for why both of those matter.
 */
class Client
{
    public const VERSION = '1.1.1';

    private Transport $transport;

    /**
     * @param string $key Your API key: `tf_live_…` or `tf_test_…`.
     * @param string $baseUrl
     * @param int $retries How many times to ask again when it is worth asking again.
     * @param Transport|null $transport Yours, if you have opinions about HTTP.
     * @param int $timeout Seconds for the whole request. Ignored when you bring a transport,
     *                    which owns its own.
     * @param int $maxWait Ceiling on the wait between attempts. A 429 says how long to
     *                     wait, and a number on the wire must not decide how long your own
     *                     request hangs.
     */
    public function __construct(
        private readonly string $key,
        private readonly string $baseUrl = 'https://toxicfilter.com',
        private readonly int $retries = 2,
        ?Transport $transport = null,
        int $timeout = 10,
        private readonly int $maxWait = 30,
    ) {
        $this->transport = $transport ?? new CurlTransport(timeout: $timeout);
    }

    /**
     * Moderate a comment, a review, a message, a description.
     *
     * @param string $content
     * @param array<string, mixed> $options `locales`, `surface`, `ai`, `reference`, `policy`.
     * @return Verdict
     */
    public function text(string $content, array $options = []): Verdict
    {
        return new Verdict($this->post('/api/v1/text', ['content' => $content] + $options));
    }

    /**
     * Judge an email address, including a malformed one, which is the point.
     *
     * @param string $address
     * @param array<string, mixed> $options
     * @return Verdict
     */
    public function email(string $address, array $options = []): Verdict
    {
        return new Verdict($this->post('/api/v1/email', ['address' => $address] + $options));
    }

    /**
     * @param string $name
     * @param array<string, mixed> $options
     * @return Verdict
     */
    public function name(string $name, array $options = []): Verdict
    {
        return new Verdict($this->post('/api/v1/name', ['name' => $name] + $options));
    }

    /**
     * Everything a registration form has, judged together, because the combination is the
     * signal: a throwaway address is common, and a throwaway address next to a mashed name
     * next to a link in the bio is a bot.
     *
     * @param array<string, mixed> $fields `name`, `email`, `bio`, plus the usual options.
     * @return Verdict
     */
    public function signup(array $fields): Verdict
    {
        return new Verdict($this->post('/api/v1/signup', $fields));
    }

    /**
     * A picture, by address or by value. Anything that is not http or https is sent as bytes.
     *
     * @param string $url http or https. We resolve where it points before fetching it.
     * @param array<string, mixed> $options
     * @return Verdict
     */
    public function image(string $url, array $options = []): Verdict
    {
        // Not a guess: the endpoint's `url` accepts http and https and nothing else, so
        // anything that is not one of those is bytes by elimination. That is what makes
        // one argument safe here, and `imageData()` is the explicit way to say it.
        // Case-insensitively, as schemes are: `HTTPS://` sent as bytes would have the
        // service decode an address and find text where a picture should be.
        if (preg_match('#^https?://#i', $url) !== 1) {
            return $this->imageData($url, $options);
        }

        return new Verdict($this->post('/api/v1/image', ['url' => $url] + $options));
    }

    /**
     * A picture you have, rather than one you have published. Never retained by the
     * service, whatever the policy says: you already hold the file.
     *
     * @param string $bytes The raw file, a base64 string, or a `data:` URI. Raw bytes are
     *                      encoded here; anything already encoded is passed through.
     * @param array<string, mixed> $options
     * @return Verdict
     */
    public function imageData(string $bytes, array $options = []): Verdict
    {
        return new Verdict($this->post('/api/v1/image', ['data' => self::encodeImage($bytes)] + $options));
    }

    /**
     * @param string $bytes
     * @return string
     */
    private static function encodeImage(string $bytes): string
    {
        if (str_starts_with($bytes, 'data:')) {
            return $bytes;
        }

        // Already base64: encoding it again would send the alphabet of the alphabet, and
        // the service would decode one layer and find text where a picture should be. The
        // check is strict, so a raw file whose first bytes happen to look like base64
        // still fails it on the bytes that follow.
        if (base64_decode($bytes, true) !== false && preg_match('/^[A-Za-z0-9+\/\r\n]+={0,2}$/', $bytes) === 1) {
            return $bytes;
        }

        return base64_encode($bytes);
    }

    /**
     * Text on its way into YOUR model, rather than to a reader. Prompt injection, plus
     * everything a comment is checked for.
     *
     * @param string $content
     * @param array<string, mixed> $options
     * @return Verdict
     */
    public function prompt(string $content, array $options = []): Verdict
    {
        return new Verdict($this->post('/api/v1/prompt', ['content' => $content] + $options));
    }

    /**
     * One link, judged as a link: is it shaped like something pretending to be somewhere
     * else. Never fetches the address, so a clean answer means "looks like what it says",
     * not "safe".
     *
     * @param string $url
     * @param array<string, mixed> $options
     * @return Verdict
     */
    public function url(string $url, array $options = []): Verdict
    {
        return new Verdict($this->post('/api/v1/url', ['url' => $url] + $options));
    }

    /**
     * A message with what came before it. The LAST one is judged; the rest is context, and
     * it is what catches a pile-on or an approach that no single message shows.
     *
     * @param list<array{author?: string, content: string}> $messages Oldest first. Up to 50.
     * @param array<string, mixed> $options
     * @return Verdict
     */
    public function conversation(array $messages, array $options = []): Verdict
    {
        return new Verdict($this->post('/api/v1/conversation', ['messages' => array_values($messages)] + $options));
    }

    /**
     * Many things in one call, answered now.
     *
     * @param list<array<string, mixed>> $items Each with a `kind` and that kind's fields.
     * @param array<string, mixed> $options Envelope defaults: `ai`, `locales`, `surface`, `policy`.
     * @return BatchResult
     */
    public function batch(array $items, array $options = []): BatchResult
    {
        return new BatchResult($this->post('/api/v1/batch', ['items' => array_values($items)] + $options));
    }

    /**
     * The same, queued. Answers immediately; the work happens on our side.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $options
     * @return BatchResult
     */
    public function batchAsync(array $items, array $options = []): BatchResult
    {
        return $this->batch($items, ['async' => true] + $options);
    }

    /**
     * @param string $batchId
     * @param array<string, mixed> $query `limit`, `after`.
     * @return BatchResult
     */
    public function batchStatus(string $batchId, array $query = []): BatchResult
    {
        return new BatchResult($this->get('/api/v1/batches/' . rawurlencode($batchId), $query));
    }

    /**
     * The account's most recent batches, newest first, each summarised without its rows.
     *
     * Without it a caller who lost a batch id (a crashed worker, a restarted deploy) had no
     * way to find the backfill it had already paid for. Read one in full with
     * `batchStatus()`.
     *
     * @param array<string, mixed> $query `limit`, 1 to 100 (20 by default).
     * @return list<BatchResult>
     */
    public function batches(array $query = []): array
    {
        $body = $this->get('/api/v1/batches', $query);

        return array_values(array_map(
            static fn ($row) => new BatchResult((array) $row),
            (array) ($body['batches'] ?? []),
        ));
    }

    /**
     * The review queue: what is waiting for a person.
     *
     * @param array<string, mixed> $query `state`, `decision`, `reference`, `limit`, `before`, …
     * @return array{records: list<Verdict>, next_before: string|null}
     */
    public function records(array $query = []): array
    {
        $body = $this->get('/api/v1/records', $query);

        return [
            'records' => array_map(
                static fn (array $row) => new Verdict($row),
                (array) ($body['records'] ?? []),
            ),
            'next_before' => $body['next_before'] ?? null,
        ];
    }

    /**
     * @param string $id
     * @return Verdict
     */
    public function record(string $id): Verdict
    {
        return new Verdict($this->get('/api/v1/records/' . rawurlencode($id)));
    }

    /**
     * A person decided.
     *
     * @param string $id
     * @param string $action `approved` or `rejected`.
     * @param string|null $moderator Your own name for whoever did it.
     * @param string|null $note
     * @return Verdict
     */
    public function resolve(string $id, string $action, ?string $moderator = null, ?string $note = null): Verdict
    {
        return new Verdict($this->post('/api/v1/records/' . rawurlencode($id) . '/resolve', array_filter([
            'action' => $action,
            'moderator' => $moderator,
            'note' => $note,
        ], static fn ($v) => $v !== null)));
    }

    /**
     * Tell us the verdict was wrong. It costs nothing and it is the only honest measure of
     * whether the thresholds are set well.
     *
     * @param string $id
     * @param string $verdict `correct`, `false_positive` or `false_negative`.
     * @param string|null $note
     * @return Verdict
     */
    public function feedback(string $id, string $verdict, ?string $note = null): Verdict
    {
        return new Verdict($this->post('/api/v1/records/' . rawurlencode($id) . '/feedback', array_filter([
            'verdict' => $verdict,
            'note' => $note,
        ], static fn ($v) => $v !== null)));
    }

    /**
     * Your keys: prefixes, modes and last use. Never a secret, since the rows hold hashes.
     *
     * @return array<string, mixed>
     */
    public function keys(): array
    {
        return $this->get('/api/v1/keys');
    }

    /**
     * Revokes one, including the one you are calling with, which is the point at three in
     * the morning. There is no create: a key that can mint keys makes a leak permanent.
     *
     * @param int|string $id
     * @return array<string, mixed>
     */
    public function revokeKey(int|string $id): array
    {
        return $this->post('/api/v1/keys/' . rawurlencode((string) $id) . '/revoke', []);
    }

    /**
     * Credits, windows, prices and what has been spent on what. Free, and it answers even
     * when the allowance is gone, which is exactly when you want to ask.
     *
     * @return array<string, mixed>
     */
    public function usage(): array
    {
        return $this->get('/api/v1/usage');
    }

    /**
     * Is the key good, is the service up. Costs nothing; point a health check at it.
     *
     * @return array<string, mixed>
     */
    public function ping(): array
    {
        return $this->get('/api/v1/ping');
    }

    /**
     * @param string $path
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        // One key per call, reused by every retry of that call. That is what makes the
        // retrying above safe: the same key means the same request, and the API answers it
        // once however many times the network makes us ask.
        $idempotencyKey = $payload['idempotency_key'] ?? $this->newIdempotencyKey();
        unset($payload['idempotency_key']);

        return $this->send('POST', $path, [], $payload, (string) $idempotencyKey);
    }

    /**
     * @param string $path
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query, null, null);
    }

    /**
     * @param string $method
     * @param string $path
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $payload
     * @param string|null $idempotencyKey
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $query, ?array $payload, ?string $idempotencyKey): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->key,
            'Accept' => 'application/json',
            'User-Agent' => 'toxicfilter-php/' . self::VERSION,
        ];

        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $body = $payload === null ? null : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $attempt = 0;

        while (true) {
            try {
                $response = $this->transport->send($method, $url, $headers, $body);
                $status = $response['status'];

                // Only a 2xx carrying a JSON object is an answer. Anything else reaching
                // this point used to be decoded to an empty array and returned, and an
                // empty verdict read as `allow`: an http base URL (a 301 the transport
                // rightly does not follow) or a proxy's HTML page published everything.
                if ($status >= 200 && $status < 300) {
                    return $this->answer($status, $response['body']);
                }

                if ($status >= 300 && $status < 400) {
                    throw new ServerError(sprintf(
                        'The API answered with a redirect (%d) instead of a verdict. Check that the base URL is right and uses https (%s).',
                        $status,
                        $this->baseUrl,
                    ), $status);
                }

                throw $this->error($status, $this->decode($response['body']));
            } catch (ApiError $e) {
                if (! $e->isRetryable() || $attempt >= $this->retries) {
                    throw $e;
                }

                $attempt++;

                // Growing, because a service that just said "too many" is not helped by
                // being asked again immediately, and a server that is failing needs longer
                // than the client's patience suggests.
                //
                // A rate limit says how long, and that beats guessing: the service knows
                // when its own window turns over. Bounded by `maxWait` all the same, so a
                // wrong or hostile number cannot pin this process for as long as it likes.
                $this->wait(min(
                    $this->maxWait,
                    $e instanceof RateLimited ? $e->retryAfter() : 2 ** $attempt,
                ));
            }
        }
    }

    /**
     * @param int $seconds
     * @return void
     */
    protected function wait(int $seconds): void
    {
        sleep(max(1, $seconds));
    }

    /**
     * The body of a successful response, or an error saying why it is not one.
     *
     * Checked for an OBJECT rather than for anything that decodes: `[]`, `null` and `"ok"`
     * are all valid JSON and none of them is something the API sends, and treating them as
     * an empty answer is exactly how a missing verdict turns into a permissive one.
     * Retryable, because what produces these (a proxy mid-deploy, a gateway page) tends to
     * pass.
     *
     * @param int $status
     * @param string $body
     * @return array<string, mixed>
     * @throws ServerError When the body is empty, not JSON, or not a JSON object.
     */
    private function answer(int $status, string $body): array
    {
        $decoded = json_decode($body);

        if (! $decoded instanceof \stdClass) {
            throw new ServerError(sprintf(
                'The API answered %d with %s instead of a JSON object. Something between you and it (a proxy, a gateway, the wrong base URL) answered in its place.',
                $status,
                trim($body) === '' ? 'an empty body' : 'a body that is not one',
            ), $status);
        }

        return (array) json_decode($body, true);
    }

    /**
     * An error body, decoded as far as it goes.
     *
     * Lenient on purpose, unlike `answer()`: the status already says what happened, and an
     * error page that is not JSON must still become the right exception for its status.
     *
     * @param string $body
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The right exception for the status, so a caller can branch on the type instead of
     * reading a message.
     *
     * @param int $status
     * @param array<string, mixed> $payload
     * @return ApiError
     */
    private function error(int $status, array $payload): ApiError
    {
        $code = $payload['error']['code'] ?? null;
        $message = (string) ($payload['error']['message'] ?? $payload['message'] ?? 'The request failed.');

        return match (true) {
            $status === 401 => new AuthenticationError($message, $status, $code, $payload),
            $status === 402 => new QuotaExhausted($message, $status, $code, $payload),
            $status === 404 => new NotFound($message, $status, $code, $payload),
            $status === 422 => new InvalidRequest($message, $status, $code, $payload),
            $status === 429 => new RateLimited($message, $status, $code, $payload),
            // 409 is `idempotency_in_flight`: the earlier attempt at this very call is
            // still running. Waiting and asking again is exactly right.
            $status === 409, $status >= 500 => new ServerError($message, $status, $code, $payload),
            default => new ApiError($message, $status, $code, $payload),
        };
    }

    /**
     * @return string
     */
    private function newIdempotencyKey(): string
    {
        return 'php-' . bin2hex(random_bytes(16));
    }
}
