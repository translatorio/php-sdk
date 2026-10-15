![Translatorio PHP SDK](https://raw.githubusercontent.com/translatorio/php-sdk/main/art/banner.png)

# Translatorio PHP SDK

[![tests](https://github.com/translatorio/php-sdk/actions/workflows/tests.yml/badge.svg)](https://github.com/translatorio/php-sdk/actions/workflows/tests.yml) [![Packagist version](https://img.shields.io/packagist/v/edulazaro/translatorio-sdk.svg)](https://packagist.org/packages/edulazaro/translatorio-sdk) [![Packagist downloads](https://img.shields.io/packagist/dt/edulazaro/translatorio-sdk.svg)](https://packagist.org/packages/edulazaro/translatorio-sdk) [![license](https://img.shields.io/packagist/l/edulazaro/translatorio-sdk.svg)](https://github.com/translatorio/php-sdk/blob/main/LICENSE) [![PHP version](https://img.shields.io/packagist/dependency-v/edulazaro/translatorio-sdk/php.svg)](https://packagist.org/packages/edulazaro/translatorio-sdk)

The official PHP client for [Translatorio](https://translatorio.com), the translation API for
text, HTML, Markdown, translation files and images.

```bash
composer require edulazaro/translatorio-sdk
```

```php
use Translatorio\Client;

$tr = new Client(getenv('TRANSLATORIO_KEY'));

$result = $tr->translate('Hello :name, you have {count} new messages', 'es', [
    'reference' => 'release-42',
]);

if (! $result->intact()) {
    // A placeholder, a tag or a masked value did not come back. Look before publishing.
    return hold($result->id());
}

echo $result->translation();   // Hola :name, tienes {count} mensajes nuevos
```

## Translating text

One text or many, into one language or many:

```php
$result = $tr->translate(['Add to cart', '<b>Free</b> returns'], ['es', 'de', 'pt-BR'], [
    'source' => 'en',              // leave it out, or 'auto', to detect it
    'format' => 'html',            // text, html or markdown: decides what is protected
    'formality' => 'informal',     // default, formal or informal
    'context' => 'Buttons of an online shop',
    'glossary' => 'shop',          // the slug of one of your glossaries
    'terms' => ['Acme' => 'Acme'], // glossary terms for this call only
    'anonymize' => true,           // the default: personal data is masked before the model
    'reference' => 'release-42',   // your own id, back in the answer and the history
]);

$result->get('de', 1);           // one text in one language: '<b>Kostenlose</b> Rücksendung'
$result->translations(0);        // one text in every language: ['es' => ..., 'de' => ..., 'pt-BR' => ...]
$result->results();              // every text: index, translations, intact
$result->translation();          // only for one text into one language, null otherwise
$result->intact();               // false when anything protected did not come back
$result->source();               // 'en'
$result->detected();             // whether it was detected rather than given
$result->targets();              // the targets, normalised: ['es', 'de', 'pt-BR']
```

Every option the endpoint takes passes straight through. A field it does not take is refused
with an `InvalidRequest` naming the nearest real one, never silently ignored.

### The rest of the answer

```php
$result->id();                   // 'tr_01k6...', names this call in the history
$result->reference();
$result->charged();              // credits this call cost
$result->creditsRemaining();
$result->segments();             // text and language pairs translated
$result->cached();               // pairs answered from your translation memory
$result->corrected();            // pairs answered from your corrections
$result->anonymized();           // pieces of personal data masked
$result->modelRead();            // whether the model read it
$result->modelWhy();             // 'memory' or 'test_key' when it did not
$result->modelEffort();          // 'low', 'medium' or 'high': the level it was made at
$result->isTest();               // a test key's answer is a marked copy: never publish it
$result->tookMs();
$result->raw;                    // the whole answer
```

### Choosing how hard the model works

`effort` (`default`, `low`, `medium` or `high`) can be passed as an option to `translate()`,
`translateFile()`, `translateImage()` and `batch()`. The client sends it only when you give
it, and the answer says which level was used (`modelEffort()`). Leaving it out is the same as `default`: the level Translatorio recommends at the time (medium today), which moves as the models do. Name a level to keep it fixed.

```php
$tr->translate($text, 'ja', ['effort' => 'high']);
```

## Files

A whole translation file in, one file per language out, in the same shape: JSON, PO, XLIFF,
CSV, Android, iOS, Markdown, HTML or plain text.

```php
$result = $tr->translateFileAt('lang/en.json', ['es', 'de']);

file_put_contents('lang/es.json', $result->content('es'));

$result->files();                // each: language, name ('es.json'), content, intact
$result->file('de');             // one of them
$result->format();               // 'json'
$result->strings();              // strings found in the file
$result->corrected();            // pairs answered from your corrections
$result->intact();
```

A file read from disk, or given as bytes, is uploaded as it is (`multipart/form-data`), so
nothing on the way re-encodes it:

```php
$tr->translateFileBytes($bytes, 'Localizable.strings', 'fr');
```

Text you already hold as a string goes up as JSON, with the name that tells its format or the
`format` option:

```php
$tr->translateFile($catalogue, 'messages.po', 'fr');
$tr->translateFile($strings, null, 'fr', ['format' => 'ios']);
```

## Images

Every block of text in a picture, its translation into each language, and its box:

```php
$result = $tr->translateImage('https://cdn.example.com/sign.png', ['en', 'es']);
$result = $tr->translateImage(file_get_contents('sign.png'), 'en');   // bytes work too
$result = $tr->translateImageData($base64OrDataUri, 'en');            // the explicit form

foreach ($result->blocks() as $block) {
    $block['text'];              // as written in the picture
    $block['box'];               // x, y, width, height: fractions of the image
    $block['translations'];      // ['en' => 'Exit', 'es' => 'Salida']
}

$result->texts('es');            // every block's Spanish, in order
```

Anything that is not an `http` or `https` address is sent as the picture itself: raw bytes
are base64-encoded here, base64 and `data:` URIs are passed through.

## Detecting a language

```php
$detection = $tr->detect('Obrigado pela ajuda');

$detection->code();              // 'pt'
$detection->language();          // code, name, native, rtl
$detection->confidence();        // 0 to 1
$detection->by();                // 'script' (free) or 'model'
```

## Batches

Up to ten thousand texts in the background. The call answers at once:

```php
$batch = $tr->batch($texts, ['es', 'fr'], ['glossary' => 'shop', 'reference' => 'catalogue-10']);

$batch->id();                    // 'bat_01k6...'
$batch->creditsEstimated();      // set aside now, so running batches cannot overspend

// Later, or when the batch.completed webhook arrives. The batch read back is a page of its
// results, and next() is where the next page starts (null on the last):
$cursor = null;

do {
    $page = $tr->batchStatus($batch->id(), $cursor, limit: 500);

    foreach ($page->results() as $index => $translations) {
        save($index, $translations['es'], $page->intact($index));
    }

    foreach ($page->failures() as $index => $error) {
        retryLater($index, $error['code']);
    }

    $cursor = $page->next();
} while ($cursor !== null);

$tr->batches(10);                // the recent ones, newest first (not paged)
```

A bad item is an item, not a batch: it fails in its place and the rest are kept. Results are
deleted seven days after the batch finishes (`expiresAt()`).

## Glossaries

Terms that are always translated the same way. All free.

```php
$tr->createGlossary('Shop', ['cart' => 'cesta', 'checkout' => 'caja'], ['source' => 'en', 'target' => 'es']);
$tr->glossaries();               // slug, name, source, target, number of entries
$tr->glossary('shop');           // one, with its entries as [{term, translation}]
$tr->updateGlossary('shop', ['entries' => ['cart' => 'carrito']]);   // entries REPLACE the list
$tr->deleteGlossary('shop');
```

A glossary with no target applies to every language, which is what brand names want.

## Corrections

Your own translation of one text into one language. From then on that exact text is answered
with yours and the model is never asked for it again.

```php
$tr->correct('Add to cart', 'es', 'Añadir a la cesta');
$tr->corrections(target: 'es');  // a Page, see below
$tr->deleteCorrection(42);
```

## Everything else

```php
$tr->languages();                // every language: code, name, native, rtl
$tr->usage();                    // plan, credits and renewal, the last 30 days, prices
$tr->translations(kind: 'text', limit: 50);   // the history as a Page, never the text
$tr->translationRecord('tr_01k6...');
$tr->keys();                     // active keys: id, name, mode, prefix, last use
$tr->revokeKey(12);              // there is no create: that is the panel's job
$tr->ping();                     // is the key good, is the service up
```

## Paged lists

The history and the corrections answer a `Translatorio\Page`: `items()` and `next()`. Pass
`next()` back as the method's `cursor` for the following page; null means that was the last.
The cursor is opaque, so keep it as it is.

```php
$cursor = null;

do {
    $page = $tr->translations($cursor, reference: 'doc_1');

    foreach ($page->items() as $call) {
        audit($call['id'], $call['characters']);
    }

    $cursor = $page->next();
} while ($cursor !== null);
```

A batch's results page the same way, through `batchStatus()` (above), whose answer is the
batch itself with its own `next()`.

## What it does for you

**Retries the right failures and never the wrong one.** A 429, a 5xx or a request that never
arrived is asked again with a growing wait; a `QuotaExhausted` is not, ever. Those two are
constantly confused and the correct behaviour is opposite: retry a rate limit, stop dead on a
quota.

**Makes those retries safe.** Every call that changes something carries an
`Idempotency-Key`, generated per call and reused by that call's retries, so a translation
that timed out and is asked again is run once and billed once. Pass `idempotency_key` in the
options to choose it yourself.

**Waits as long as the service asked, and no longer.** A 429 says how long in its body
(`error.retry_after`) and in `Retry-After`, and honouring it beats guessing. It is bounded by `maxWait` (30 seconds by default) all the same,
because a number on the wire should not decide how long your own request hangs.

**Never reads anything but an answer as one.** A redirect (a base URL on `http://`, which
this client deliberately does not follow), an empty body, or a proxy's HTML page is a
`ServerError`, retried like one, and never an empty translation.

Every failure is a type you can branch on:

| Status | Exception | Retried |
|---|---|---|
| 401 | `AuthenticationError` | no |
| 402 | `QuotaExhausted`, with `remaining()`, `required()`, `queued()`, `renewsAt()` | never |
| 404 | `NotFound` | no |
| 422 | `InvalidRequest`, with `fields()` | no |
| 429 | `RateLimited`, with `retryAfter()` | yes |
| 409 `idempotency_in_flight`, 5xx, network, not an answer | `ServerError` | yes |
| anything else | `ApiError` | no |

All of them extend `Translatorio\Exception\ApiError`, which carries `status`, `errorCode` and
the decoded `payload`.

```php
use Translatorio\Exception\InvalidRequest;
use Translatorio\Exception\QuotaExhausted;

try {
    $result = $tr->translate($text, 'es');
} catch (QuotaExhausted $e) {
    // Stop calling. No amount of retrying produces credits.
    alert("estimated at {$e->required()}, {$e->remaining()} left, renews {$e->renewsAt()}");
} catch (InvalidRequest $e) {
    log($e->errorCode, $e->fields());   // 'unsupported_language', ['targets' => [...]]
}
```

## Webhooks

Your endpoint URL is public. Verify before you act:

```php
use Translatorio\Webhooks;

$event = Webhooks::event(
    file_get_contents('php://input'),                   // the RAW body
    $_SERVER['HTTP_X_TRANSLATORIO_SIGNATURE'] ?? '',    // absent is a failed check, not a crash
    getenv('TRANSLATORIO_WEBHOOK_SECRET'),
);

if ($event === null) {
    http_response_code(400);
    exit;
}

if ($event['event'] === Webhooks::BATCH_COMPLETED) {
    collect($event['data']['id']);
}
```

Events: `batch.completed`, `credits.low` and `quota.exhausted`. A delivery older than five
minutes fails the check, so a captured one cannot be replayed.

## Configuration

```php
$tr = new Client(
    key: getenv('TRANSLATORIO_KEY'),           // tr_live_… or tr_test_… (free, never translated)
    baseUrl: 'https://translatorio.com/api',   // https: redirects are never followed
    retries: 2,                                // for 429, 5xx and the network; a 402 never
    timeout: 120,                              // seconds per attempt, the reading included
    maxWait: 30,                               // ceiling on the wait between attempts
);
```

A test key costs nothing and never reaches the model: it answers with a marked copy
(`isTest()` and `modelWhy()` say so), which is what an integration test wants and never
what a page should show.

## Bring your own HTTP

No dependencies beyond `ext-curl`: a client for one small API is not worth a dependency
tree. Implement `Translatorio\Transport`, one method, to send requests your own way:

```php
use Translatorio\Transport;

final class MyTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        // Send the body as it is: JSON, or a file upload already built as multipart, with
        // its boundary in the Content-Type header. Do not follow redirects. Report a request that never arrived as a
        // Translatorio\Exception\ServerError with status 0, so it is retried.
        return ['status' => 200, 'body' => '{"ok":true}', 'headers' => ['Retry-After' => '1']];
    }
}

$tr = new Client(getenv('TRANSLATORIO_KEY'), transport: new MyTransport());
```

That is also how to test code that uses this client: hand it a transport that answers what
your test needs.

## How this is tested

```bash
composer install
composer test
```

No network and no framework: a stub transport answers like the API does. It covers what a
caller cannot see and would otherwise discover from an invoice: what is retried, what never
is, that a retry reuses its idempotency key while two calls do not, that anything but an
answer is refused, that the timeout covers the reading, and that bytes go out as bytes.
Whether the client agrees with the API itself is checked on the other side, by Translatorio's
own suite, which runs this client against the real routes.

## Author

Created by [Edu Lazaro](https://edulazaro.com) for [Translatorio](https://translatorio.com),
the translation API this client speaks to.

## License

The Translatorio PHP SDK is open-sourced software licensed under the [MIT license](LICENSE).
