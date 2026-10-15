<?php

namespace Translatorio;

use InvalidArgumentException;
use Translatorio\Exception\ApiError;
use Translatorio\Exception\AuthenticationError;
use Translatorio\Exception\InvalidRequest;
use Translatorio\Exception\NotFound;
use Translatorio\Exception\QuotaExhausted;
use Translatorio\Exception\RateLimited;
use Translatorio\Exception\ServerError;

/**
 * The Translatorio API, from PHP.
 *
 * Retries a 429 and a 5xx with a growing wait, and a 402 never. Every call that changes
 * something carries an `Idempotency-Key`, so a retried timeout is translated once and billed
 * once. See the README for why both of those matter.
 */
class Client
{
    public const VERSION = '1.0.1';

    private Transport $transport;

    /**
     * @param string $key Your API key: `tr_live_…` or `tr_test_…`.
     * @param string $baseUrl Where the API lives, `/v1` excluded.
     * @param int $retries How many times to ask again when it is worth asking again.
     * @param Transport|null $transport Yours, if you have opinions about HTTP.
     * @param int $timeout Seconds for the whole request, the answer's reading included.
     *                     Ignored when you bring a transport, which owns its own.
     * @param int $maxWait Ceiling on the wait between attempts. A 429 says how long to
     *                     wait, and a number on the wire must not decide how long your own
     *                     request hangs.
     */
    public function __construct(
        private readonly string $key,
        private readonly string $baseUrl = 'https://translatorio.com/api',
        private readonly int $retries = 2,
        ?Transport $transport = null,
        int $timeout = 120,
        private readonly int $maxWait = 30,
    ) {
        $this->transport = $transport ?? new CurlTransport(timeout: $timeout);
    }

    /**
     * Translate one text or many, into one language or many.
     *
     * @param string|list<string> $text One text, or a list of them (up to 100 a call).
     * @param string|list<string> $targets One language code, or a list of them.
     * @param array<string, mixed> $options `source`, `format` (`text`, `html`, `markdown`),
     *        `formality` (`default`, `formal`, `informal`), `context`, `glossary`, `terms`,
     *        `anonymize`, `reference`, `effort`, `idempotency_key`.
     * @return Translation
     */
    public function translate(string|array $text, string|array $targets, array $options = []): Translation
    {
        $payload = (is_string($text) ? ['text' => $text] : ['texts' => array_values($text)]) + self::targets($targets);

        return new Translation($this->write('POST', '/v1/translate', $payload + $options));
    }

    /**
     * Translate a whole translation file and get one back per language, in the same shape.
     *
     * The file goes up as JSON text. For a file you hold as bytes, or one whose encoding
     * must survive exactly (a UTF-16 `.strings`), use `translateFileBytes()` or
     * `translateFileAt()`, which upload it as it is.
     *
     * @param string $content The file's text.
     * @param string|null $filename Its name. Tells the format by its extension and names
     *                              the results (`en.json` comes back as `es.json`). Null
     *                              when `format` is in the options.
     * @param string|list<string> $targets
     * @param array<string, mixed> $options `format` (`json`, `po`, `xliff`, `csv`,
     *        `android`, `ios`, `markdown`, `html`, `text`), `source`, `formality`, `context`,
     *        `glossary`, `terms`, `anonymize`, `reference`, `effort`, `idempotency_key`.
     * @return FileTranslation
     */
    public function translateFile(string $content, ?string $filename, string|array $targets, array $options = []): FileTranslation
    {
        $payload = ['content' => $content] + ($filename === null ? [] : ['filename' => $filename]) + self::targets($targets);

        return new FileTranslation($this->write('POST', '/v1/translate/file', $payload + $options));
    }

    /**
     * The same, for a file you hold as bytes: uploaded as `multipart/form-data`, byte for
     * byte, so nothing on the way re-encodes it.
     *
     * @param string $bytes The file, exactly as it is on disk.
     * @param string $filename Its name. Tells the format by its extension (unless `format`
     *                         is in the options) and names the results.
     * @param string|list<string> $targets
     * @param array<string, mixed> $options As `translateFile()`.
     * @return FileTranslation
     */
    public function translateFileBytes(string $bytes, string $filename, string|array $targets, array $options = []): FileTranslation
    {
        return new FileTranslation($this->upload('/v1/translate/file', 'file', $bytes, $filename, self::targets($targets) + $options));
    }

    /**
     * The same, reading the file from disk, naming it after itself and uploading its bytes.
     *
     * @param string $path
     * @param string|list<string> $targets
     * @param array<string, mixed> $options As `translateFile()`.
     * @return FileTranslation
     * @throws InvalidArgumentException When the file cannot be read.
     */
    public function translateFileAt(string $path, string|array $targets, array $options = []): FileTranslation
    {
        $bytes = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($bytes === false) {
            throw new InvalidArgumentException("Cannot read the file at {$path}.");
        }

        return $this->translateFileBytes($bytes, basename($path), $targets, $options);
    }

    /**
     * Every block of text in a picture, translated, with its box. By address or by value:
     * anything that is not http or https is sent as bytes.
     *
     * @param string $image An http or https URL, or the picture itself (see `translateImageData()`).
     * @param string|list<string> $targets
     * @param array<string, mixed> $options `source`, `formality`, `context`, `glossary`,
     *        `terms`, `reference`, `effort`, `idempotency_key`.
     * @return ImageTranslation
     */
    public function translateImage(string $image, string|array $targets, array $options = []): ImageTranslation
    {
        // Not a guess: the endpoint's `url` accepts http and https and nothing else, so
        // anything that is not one of those is bytes by elimination. Case-insensitively, as
        // schemes are: `HTTPS://` sent as bytes would be decoded as base64 and refused.
        if (preg_match('#^https?://#i', $image) !== 1) {
            return $this->translateImageData($image, $targets, $options);
        }

        return new ImageTranslation($this->write('POST', '/v1/translate/image', ['url' => $image] + self::targets($targets) + $options));
    }

    /**
     * A picture you have, rather than one you have published. Never stored by the service.
     *
     * @param string $bytes The raw file, a base64 string, or a `data:image/…;base64,` URI.
     *                      Raw bytes are encoded here; anything already encoded is passed
     *                      through. PNG, JPEG, WebP or GIF, up to 8 MB.
     * @param string|list<string> $targets
     * @param array<string, mixed> $options As `translateImage()`.
     * @return ImageTranslation
     */
    public function translateImageData(string $bytes, string|array $targets, array $options = []): ImageTranslation
    {
        return new ImageTranslation($this->write('POST', '/v1/translate/image', ['data' => self::encodeImage($bytes)] + self::targets($targets) + $options));
    }

    /**
     * Which language a text is in. Free when the alphabet belongs to one language.
     *
     * @param string $text
     * @param array<string, mixed> $options `reference`, `idempotency_key`.
     * @return Detection
     */
    public function detect(string $text, array $options = []): Detection
    {
        return new Detection($this->write('POST', '/v1/detect', ['text' => $text] + $options));
    }

    /**
     * Up to ten thousand texts translated in the background. Answers at once; read it back
     * with `batchStatus()`, or let the `batch.completed` webhook say when it is done.
     *
     * @param list<string> $texts
     * @param string|list<string> $targets
     * @param array<string, mixed> $options Every option of `translate()`.
     * @return Batch
     */
    public function batch(array $texts, string|array $targets, array $options = []): Batch
    {
        return new Batch($this->write('POST', '/v1/batches', ['texts' => array_values($texts)] + self::targets($targets) + $options));
    }

    /**
     * The account's most recent batches, newest first, each summarised without its results.
     * Not paged: the API answers the latest ones and no more.
     *
     * @param int|null $limit 1 to 100 (25 by default).
     * @return list<Batch>
     */
    public function batches(?int $limit = null): array
    {
        return array_map(
            static fn ($row) => new Batch((array) $row),
            $this->data($this->get('/v1/batches', self::given(['limit' => $limit]))),
        );
    }

    /**
     * One batch, with a page of its results. The batch is the page: its `results()` and
     * `failures()` are the items, and its `next()` is where the next page starts.
     *
     * @param string $id
     * @param string|null $cursor The `next()` of the previous page; null for the first.
     * @param int|null $limit Results per page, 1 to 500 (100 by default).
     * @return Batch
     */
    public function batchStatus(string $id, ?string $cursor = null, ?int $limit = null): Batch
    {
        return new Batch($this->get('/v1/batches/' . rawurlencode($id), self::given(['after' => $cursor, 'limit' => $limit])));
    }

    /**
     * Every glossary of the account: `slug`, `name`, `source`, `target`, the number of
     * `entries` and `updated_at`.
     *
     * @return list<array<string, mixed>>
     */
    public function glossaries(): array
    {
        return $this->data($this->get('/v1/glossaries'));
    }

    /**
     * Create a glossary. Its slug, made from the name, is how a call names it.
     *
     * @param string $name
     * @param array<string, string>|list<array{term: string, translation: string}> $entries
     *        `{"cart": "cesta"}`, or the list a read returns.
     * @param array<string, mixed> $options `source` and `target`: tie it to one language
     *        each way. Without a target it applies to every language, as brand names want.
     * @return array<string, mixed> The glossary, with its entries.
     */
    public function createGlossary(string $name, array $entries = [], array $options = []): array
    {
        return $this->write('POST', '/v1/glossaries', ['name' => $name] + ($entries === [] ? [] : ['entries' => $entries]) + $options);
    }

    /**
     * @param string $slug
     * @return array<string, mixed> The glossary, with its entries as `{term, translation}`.
     */
    public function glossary(string $slug): array
    {
        return $this->get('/v1/glossaries/' . rawurlencode($slug));
    }

    /**
     * Change any of `name`, `source`, `target` and `entries`. Entries, when sent, REPLACE
     * the list; what is not sent is left as it was.
     *
     * @param string $slug
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function updateGlossary(string $slug, array $fields): array
    {
        return $this->write('PUT', '/v1/glossaries/' . rawurlencode($slug), $fields);
    }

    /**
     * @param string $slug
     * @return array<string, mixed> `{"deleted": true}`.
     */
    public function deleteGlossary(string $slug): array
    {
        return $this->write('DELETE', '/v1/glossaries/' . rawurlencode($slug));
    }

    /**
     * The account's corrections, newest first, a page at a time. Each item is `id`, `text`,
     * `target`, `translation` and `updated_at`.
     *
     * @param string|null $cursor The `next()` of the previous page; null for the first.
     * @param string|null $target Only the corrections into this language.
     * @param int|null $limit 1 to 200 (50 by default).
     * @return Page<array<string, mixed>>
     */
    public function corrections(?string $cursor = null, ?string $target = null, ?int $limit = null): Page
    {
        return $this->page($this->get('/v1/corrections', self::given(['target' => $target, 'limit' => $limit, 'cursor' => $cursor])));
    }

    /**
     * Your own translation of one text into one language. From then on that exact text is
     * answered with yours, and the model is never asked for it again. Saving the same text
     * and language again replaces it.
     *
     * @param string $text
     * @param string $target
     * @param string $translation
     * @return array<string, mixed> `id`, `text`, `target`, `translation`, `updated_at`.
     */
    public function correct(string $text, string $target, string $translation): array
    {
        return $this->write('PUT', '/v1/corrections', [
            'text' => $text,
            'target' => $target,
            'translation' => $translation,
        ]);
    }

    /**
     * @param int|string $id
     * @return array<string, mixed> `{"deleted": true}`.
     */
    public function deleteCorrection(int|string $id): array
    {
        return $this->write('DELETE', '/v1/corrections/' . rawurlencode((string) $id));
    }

    /**
     * Every language and its code: `code`, `name`, `native` and `rtl`.
     *
     * @return list<array<string, mixed>>
     */
    public function languages(): array
    {
        return $this->data($this->get('/v1/languages'));
    }

    /**
     * Plan, credits left and when they renew, the last 30 days, and prices. Free, and it
     * answers even when the allowance is gone, which is exactly when you want to ask.
     *
     * @return array<string, mixed>
     */
    public function usage(): array
    {
        return $this->get('/v1/usage');
    }

    /**
     * The account's calls, newest first, a page at a time. Never the text: it is not kept.
     *
     * @param string|null $cursor The `next()` of the previous page; null for the first.
     * @param string|null $kind `text`, `image` or `detect`.
     * @param string|null $reference Only the calls made with this reference.
     * @param int|null $limit 1 to 100 (25 by default).
     * @return Page<array<string, mixed>>
     */
    public function translations(?string $cursor = null, ?string $kind = null, ?string $reference = null, ?int $limit = null): Page
    {
        return $this->page($this->get('/v1/translations', self::given(['kind' => $kind, 'reference' => $reference, 'limit' => $limit, 'cursor' => $cursor])));
    }

    /**
     * One call from the history, by the id its answer carried.
     *
     * @param string $id
     * @return array<string, mixed>
     */
    public function translationRecord(string $id): array
    {
        return $this->get('/v1/translations/' . rawurlencode($id));
    }

    /**
     * The account's active keys: `id`, `name`, `mode`, `prefix`, `last_used_at`,
     * `created_at`. Never a secret.
     *
     * @return list<array<string, mixed>>
     */
    public function keys(): array
    {
        return $this->data($this->get('/v1/keys'));
    }

    /**
     * Revokes one, including the one you are calling with. There is no create: a key that
     * can mint keys makes a leak permanent.
     *
     * @param int|string $id
     * @return array<string, mixed> `{"revoked": true}`.
     */
    public function revokeKey(int|string $id): array
    {
        return $this->write('DELETE', '/v1/keys/' . rawurlencode((string) $id));
    }

    /**
     * Is the key good, is the service up. Costs nothing; point a health check at it.
     *
     * @return array<string, mixed> `ok`, `mode` and the key's `prefix`.
     */
    public function ping(): array
    {
        return $this->get('/v1/ping');
    }

    /**
     * @param string|list<string> $targets
     * @return array<string, mixed>
     */
    private static function targets(string|array $targets): array
    {
        return is_string($targets) ? ['target' => $targets] : ['targets' => array_values($targets)];
    }

    /**
     * The query fields that were given, without the ones left null.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function given(array $fields): array
    {
        return array_filter($fields, static fn ($value) => $value !== null);
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
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    private function data(array $body): array
    {
        return array_values(array_map(static fn ($row) => (array) $row, (array) ($body['data'] ?? [])));
    }

    /**
     * @param array<string, mixed> $body
     * @return Page<array<string, mixed>>
     */
    private function page(array $body): Page
    {
        $cursor = $body['next_cursor'] ?? null;

        return new Page($this->data($body), is_string($cursor) ? $cursor : null, $body);
    }

    /**
     * A request that changes something, with its idempotency key.
     *
     * @param string $method
     * @param string $path
     * @param array<string, mixed>|null $payload Null for a DELETE, which has no body.
     * @return array<string, mixed>
     */
    private function write(string $method, string $path, ?array $payload = null): array
    {
        // One key per call, reused by every retry of that call. That is what makes the
        // retrying safe: the same key means the same request, and the API answers it once
        // however many times the network makes us ask.
        $idempotencyKey = $payload['idempotency_key'] ?? $this->newIdempotencyKey();

        if ($payload === null) {
            return $this->send($method, $path, [], null, null, (string) $idempotencyKey);
        }

        unset($payload['idempotency_key']);

        return $this->send($method, $path, [], (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json', (string) $idempotencyKey);
    }

    /**
     * A POST that uploads a file as `multipart/form-data`, with its idempotency key.
     *
     * The body is built once, here, so every retry resends exactly these bytes under the
     * same key, and the transport needs to know nothing about multipart.
     *
     * @param string $path
     * @param string $name The file part's name.
     * @param string $bytes
     * @param string $filename
     * @param array<string, mixed> $fields Every other field, sent as form fields.
     * @return array<string, mixed>
     */
    private function upload(string $path, string $name, string $bytes, string $filename, array $fields): array
    {
        $idempotencyKey = (string) ($fields['idempotency_key'] ?? $this->newIdempotencyKey());
        unset($fields['idempotency_key']);

        $boundary = 'translatorio-' . bin2hex(random_bytes(16));
        $body = '';

        foreach (self::formFields($fields) as [$field, $value]) {
            $body .= "--{$boundary}\r\n"
                . 'Content-Disposition: form-data; name="' . self::quoted($field) . "\"\r\n\r\n"
                . $value . "\r\n";
        }

        $body .= "--{$boundary}\r\n"
            . 'Content-Disposition: form-data; name="' . self::quoted($name) . '"; filename="' . self::quoted($filename) . "\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $bytes . "\r\n"
            . "--{$boundary}--\r\n";

        return $this->send('POST', $path, [], $body, 'multipart/form-data; boundary=' . $boundary, $idempotencyKey);
    }

    /**
     * Options flattened into form fields the way the API reads a form: a list as
     * `targets[]`, a map as `terms[cart]`, a boolean as `1` or `0`. Nulls are left out.
     *
     * @param array<string, mixed> $fields
     * @param string|null $prefix
     * @return list<array{0: string, 1: string}>
     */
    private static function formFields(array $fields, ?string $prefix = null): array
    {
        $out = [];

        foreach ($fields as $key => $value) {
            $name = $prefix === null ? (string) $key : $prefix . (array_is_list($fields) ? '[]' : '[' . $key . ']');

            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                array_push($out, ...self::formFields($value, $name));

                continue;
            }

            $out[] = [$name, is_bool($value) ? ($value ? '1' : '0') : (string) $value];
        }

        return $out;
    }

    /**
     * A name or filename made safe inside a quoted `Content-Disposition` parameter.
     *
     * @param string $value
     * @return string
     */
    private static function quoted(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $value);
    }

    /**
     * @param string $path
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query, null, null, null);
    }

    /**
     * @param string $method
     * @param string $path
     * @param array<string, mixed> $query
     * @param string|null $body Already encoded; null for a request without one.
     * @param string|null $contentType The body's type, when there is a body.
     * @param string|null $idempotencyKey
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $query, ?string $body, ?string $contentType, ?string $idempotencyKey): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->key,
            'Accept' => 'application/json',
            'User-Agent' => 'translatorio-php/' . self::VERSION,
        ];

        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $attempt = 0;

        while (true) {
            try {
                $response = $this->transport->send($method, $url, $headers, $body);
                $status = $response['status'];

                // Only a 2xx carrying a JSON object is an answer. Anything else read as one
                // would be an empty result: no translation, and nothing saying why.
                if ($status >= 200 && $status < 300) {
                    return $this->answer($status, $response['body']);
                }

                if ($status >= 300 && $status < 400) {
                    throw new ServerError(sprintf(
                        'The API answered with a redirect (%d) instead of an answer. Check that the base URL is right and uses https (%s).',
                        $status,
                        $this->baseUrl,
                    ), $status);
                }

                $payload = $this->decode($response['body']);

                throw $this->error($status, $payload, self::retryAfter($payload, $response['headers'] ?? []));
            } catch (ApiError $e) {
                if (! $e->isRetryable() || $attempt >= $this->retries) {
                    throw $e;
                }

                $attempt++;

                // Growing, because a service that just said "too many" is not helped by
                // being asked again immediately, and a server that is failing needs longer
                // than the client's patience suggests. When the answer says how long
                // (`Retry-After`), that beats guessing, bounded by `maxWait` all the same, so
                // a wrong or hostile number cannot pin this process for as long as it likes.
                $this->wait(min($this->maxWait, $e->retryAfterSeconds ?? 2 ** $attempt));
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
     * The seconds the API asked to wait: `error.retry_after` in the body, else the
     * `Retry-After` header (whatever the case of its name), which plain HTTP always has.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @return int|null Null when neither says a number of seconds.
     */
    private static function retryAfter(array $payload, array $headers): ?int
    {
        $inBody = $payload['error']['retry_after'] ?? null;

        if (is_numeric($inBody)) {
            return max(1, (int) $inBody);
        }

        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'retry-after' && is_numeric(trim((string) $value))) {
                return max(1, (int) trim((string) $value));
            }
        }

        return null;
    }

    /**
     * The body of a successful response, or an error saying why it is not one.
     *
     * Checked for an OBJECT rather than for anything that decodes: `[]`, `null` and `"ok"`
     * are all valid JSON and none of them is something the API sends. Retryable, because
     * what produces these (a proxy mid-deploy, a gateway page) tends to pass.
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
     * @param int|null $retryAfter
     * @return ApiError
     */
    private function error(int $status, array $payload, ?int $retryAfter): ApiError
    {
        $code = $payload['error']['code'] ?? null;
        $code = is_string($code) ? $code : null;
        $message = (string) ($payload['error']['message'] ?? $payload['message'] ?? 'The request failed.');

        return match (true) {
            $status === 401 => new AuthenticationError($message, $status, $code, $payload, $retryAfter),
            $status === 402 => new QuotaExhausted($message, $status, $code, $payload, $retryAfter),
            $status === 404 => new NotFound($message, $status, $code, $payload, $retryAfter),
            $status === 422 => new InvalidRequest($message, $status, $code, $payload, $retryAfter),
            $status === 429 => new RateLimited($message, $status, $code, $payload, $retryAfter),
            // A 409 `idempotency_in_flight` is the earlier attempt at this very call still
            // running, and waiting and asking again is exactly right.
            $status === 409 && $code === 'idempotency_in_flight', $status >= 500 => new ServerError($message, $status, $code, $payload, $retryAfter),
            default => new ApiError($message, $status, $code, $payload, $retryAfter),
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
