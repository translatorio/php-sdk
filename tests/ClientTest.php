<?php

namespace Translatorio\Tests;

use Translatorio\Batch;
use Translatorio\Page;

/**
 * The PHP client, against a transport that answers like the API does.
 *
 * These prove the client BEHAVES: what it sends, how it reads what comes back. Whether it
 * agrees with the API about field names and routes is settled on the other side, by the
 * application's own `PhpSdkTest`, which dispatches this client into the real thing. A stub
 * agrees with whatever it is sent.
 */
final class ClientTest extends TestCase
{
    public function test_it_reads_a_translation(): void
    {
        [$tr, $transport] = $this->client([[200, $this->translation()]]);

        $result = $tr->translate('Hello world', 'es', ['reference' => 'release-42']);

        $this->assertSame('Hola mundo', $result->translation());
        $this->assertSame('Hola mundo', $result->get('es'));
        $this->assertSame(['es' => 'Hola mundo'], $result->translations());
        $this->assertTrue($result->intact());
        $this->assertSame('en', $result->source());
        $this->assertTrue($result->detected());
        $this->assertSame('tr_01K6', $result->id());
        $this->assertSame('release-42', $result->reference());
        $this->assertSame(3, $result->charged());
        $this->assertSame(1997, $result->creditsRemaining());
        $this->assertTrue($result->modelRead());
        $this->assertSame('gpt-5.6-luna', $result->modelName());
        $this->assertFalse($result->isTest());
        $this->assertSame(840, $result->tookMs());

        $call = $transport->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://example.test/api/v1/translate', $call['url']);
        $this->assertSame(['text' => 'Hello world', 'target' => 'es', 'reference' => 'release-42'], $call['body']);
        $this->assertSame('Bearer tr_test_key', $call['headers']['Authorization']);
        $this->assertSame('translatorio-php/1.0.2', $call['headers']['User-Agent']);
        $this->assertSame('application/json', $call['headers']['Content-Type']);
    }

    public function test_many_texts_into_many_languages_go_up_as_lists(): void
    {
        [$tr, $transport] = $this->client([[200, $this->translation([
            'translation' => null,
            'targets' => ['fr', 'de'],
            'results' => [
                ['index' => 0, 'translations' => ['fr' => 'Un', 'de' => 'Eins'], 'intact' => true],
                ['index' => 1, 'translations' => ['fr' => 'Deux', 'de' => 'Zwei'], 'intact' => true],
            ],
        ])]]);

        $result = $tr->translate(['One', 'Two'], ['fr', 'de'], ['source' => 'en', 'format' => 'html']);

        $this->assertNull($result->translation(), 'Several texts have no single translation.');
        $this->assertSame('Zwei', $result->get('de', 1));
        $this->assertNull($result->get('ja', 1));
        $this->assertSame(['fr', 'de'], $result->targets());
        $this->assertSame(['texts' => ['One', 'Two'], 'targets' => ['fr', 'de'], 'source' => 'en', 'format' => 'html'], $transport->calls[0]['body']);
    }

    public function test_intact_is_false_when_the_answer_does_not_say(): void
    {
        $result = new \Translatorio\Translation(['results' => []]);

        $this->assertFalse($result->intact(), 'A missing intact must not read as safe to publish.');
    }

    public function test_effort_is_passed_only_when_given(): void
    {
        [$tr, $transport] = $this->client([[200, $this->translation()], [200, $this->translation()]]);

        $tr->translate('Hi', 'es');
        $tr->translate('Hi', 'es', ['effort' => 'high']);

        $this->assertArrayNotHasKey('effort', $transport->calls[0]['body']);
        $this->assertSame('high', $transport->calls[1]['body']['effort']);
    }

    public function test_effort_and_corrections_are_read_back(): void
    {
        [$tr] = $this->client([
            [200, $this->translation(['usage' => ['model' => ['read' => true, 'effort' => 'high', 'name' => 'gpt-5.6-terra']]])],
            [200, ['files' => [], 'usage' => ['corrected' => 2, 'model' => ['read' => false, 'effort' => 'low', 'why' => 'memory']]]],
        ]);

        $this->assertSame('high', $tr->translate('Hi', 'es', ['effort' => 'high'])->modelEffort());

        $file = $tr->translateFile('{"a":"Hi"}', 'en.json', 'es');
        $this->assertSame('low', $file->modelEffort());
        $this->assertSame(2, $file->corrected());
    }

    public function test_a_file_goes_up_as_its_content_and_name(): void
    {
        [$tr, $transport] = $this->client([[200, [
            'id' => 'tr_1',
            'format' => 'json',
            'source' => ['language' => 'en', 'detected' => true],
            'strings' => 2,
            'files' => [['language' => 'es', 'name' => 'es.json', 'content' => '{"a":"Hola"}', 'intact' => true]],
            'intact' => true,
            'usage' => ['characters' => 4, 'segments' => 2, 'cached' => 1, 'anonymized' => 0],
            'credits' => ['charged' => 2, 'remaining' => 10],
        ]]]);

        $result = $tr->translateFile('{"a":"Hello"}', 'en.json', 'es');

        $this->assertSame(['content' => '{"a":"Hello"}', 'filename' => 'en.json', 'target' => 'es'], $transport->calls[0]['body']);
        $this->assertSame('https://example.test/api/v1/translate/file', $transport->calls[0]['url']);
        $this->assertSame('json', $result->format());
        $this->assertSame(2, $result->strings());
        $this->assertSame('{"a":"Hola"}', $result->content('es'));
        $this->assertSame('es.json', $result->file('es')['name']);
        $this->assertNull($result->file('de'));
        $this->assertSame(1, $result->cached());
        $this->assertTrue($result->intact());
    }

    public function test_a_file_on_disk_is_uploaded_as_multipart_named_after_itself(): void
    {
        $path = sys_get_temp_dir() . '/translatorio-sdk-' . bin2hex(random_bytes(4)) . '-en.json';
        file_put_contents($path, '{"a":"Hello"}');

        try {
            [$tr, $transport] = $this->client([[200, ['files' => [], 'intact' => true]]]);
            $tr->translateFileAt($path, ['es', 'de'], ['format' => 'json', 'anonymize' => false, 'terms' => ['cart' => 'cesta']]);
        } finally {
            unlink($path);
        }

        $form = self::form($transport->calls[0]);

        $this->assertSame(['{"a":"Hello"}', basename($path)], $form['files']['file']);
        $this->assertSame([['targets[]', 'es'], ['targets[]', 'de'], ['format', 'json'], ['anonymize', '0'], ['terms[cart]', 'cesta']], $form['fields']);
        $this->assertNotNull($transport->calls[0]['key']);
    }

    public function test_bytes_arrive_byte_for_byte_and_a_retry_resends_the_same_body(): void
    {
        // A UTF-16 `.strings` file with its byte order mark: as JSON text it could not travel.
        $bytes = "\xFF\xFE" . mb_convert_encoding('"hello" = "Hello";' . "\n", 'UTF-16LE', 'UTF-8');

        [$tr, $transport] = $this->client([
            [503, ['error' => ['code' => 'translation_unavailable', 'message' => 'Down.']]],
            [200, ['files' => [], 'intact' => true]],
        ]);

        $tr->translateFileBytes($bytes, 'Localizable.strings', 'es', ['source' => 'en', 'idempotency_key' => 'cat-7']);

        $form = self::form($transport->calls[0]);

        $this->assertSame([$bytes, 'Localizable.strings'], $form['files']['file']);
        $this->assertSame([['target', 'es'], ['source', 'en']], $form['fields'], 'The key goes in the header, never in the form.');
        $this->assertSame('https://example.test/api/v1/translate/file', $transport->calls[0]['url']);
        $this->assertSame($transport->calls[0]['raw'], $transport->calls[1]['raw']);
        $this->assertSame(['cat-7', 'cat-7'], [$transport->calls[0]['key'], $transport->calls[1]['key']]);
    }

    /**
     * A multipart body read back the way a server reads it.
     *
     * @param array<string, mixed> $call
     * @return array{fields: list<array{0: string, 1: string}>, files: array<string, array{0: string, 1: string}>}
     */
    private static function form(array $call): array
    {
        $type = $call['headers']['Content-Type'];
        self::assertMatchesRegularExpression('#^multipart/form-data; boundary=\S+$#', $type);
        $boundary = substr($type, strlen('multipart/form-data; boundary='));

        $parts = explode("--{$boundary}", $call['raw']);
        self::assertSame("--\r\n", array_pop($parts));
        array_shift($parts);

        $out = ['fields' => [], 'files' => []];

        foreach ($parts as $part) {
            [$head, $value] = explode("\r\n\r\n", substr($part, 2, -2), 2);
            preg_match('/name="([^"]*)"(?:; filename="([^"]*)")?/', $head, $m);

            if (isset($m[2])) {
                $out['files'][$m[1]] = [$value, $m[2]];
            } else {
                $out['fields'][] = [$m[1], $value];
            }
        }

        return $out;
    }

    public function test_a_file_that_cannot_be_read_is_refused_before_any_call(): void
    {
        [$tr, $transport] = $this->client([]);

        try {
            $tr->translateFileAt('/no/such/file.json', 'es');
            $this->fail('A missing file was sent.');
        } catch (\InvalidArgumentException) {
            $this->assertSame([], $transport->calls);
        }
    }

    public function test_it_reads_a_detection(): void
    {
        [$tr, $transport] = $this->client([[200, [
            'id' => 'tr_2',
            'language' => ['code' => 'ko', 'name' => 'Korean', 'native' => '한국어', 'rtl' => false],
            'confidence' => 0.99,
            'by' => 'script',
            'credits' => ['charged' => 0, 'remaining' => 10],
        ]]]);

        $detection = $tr->detect('안녕하세요');

        $this->assertSame(['text' => '안녕하세요'], $transport->calls[0]['body']);
        $this->assertSame('ko', $detection->code());
        $this->assertSame('한국어', $detection->language()['native']);
        $this->assertSame(0.99, $detection->confidence());
        $this->assertSame('script', $detection->by());
        $this->assertSame(0, $detection->charged());
    }

    public function test_an_image_reads_as_blocks(): void
    {
        [$tr] = $this->client([[200, [
            'source' => ['language' => 'ja'],
            'targets' => ['en', 'es'],
            'blocks' => [['text' => '出口', 'box' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.1], 'translations' => ['en' => 'Exit', 'es' => 'Salida']]],
        ]]]);

        $image = $tr->translateImage('https://cdn.test/sign.png', ['en', 'es']);

        $this->assertSame('ja', $image->source());
        $this->assertSame(['Salida'], $image->texts('es'));
        $this->assertSame(0.3, $image->blocks()[0]['box']['width']);
    }

    public function test_a_batch_is_read_in_two_halves(): void
    {
        [$tr, $transport] = $this->client([
            [202, ['id' => 'bat_1', 'status' => 'queued', 'total' => 3, 'done' => 0, 'failed' => 0, 'credits' => ['estimated' => 30, 'charged' => 0]]],
            [200, [
                'id' => 'bat_1', 'status' => 'completed', 'total' => 3, 'done' => 2, 'failed' => 1,
                'credits' => ['estimated' => 30, 'charged' => 12],
                'results' => [
                    ['index' => 0, 'translations' => ['es' => 'Uno'], 'intact' => true],
                    ['index' => 1, 'error' => ['code' => 'translation_unavailable', 'message' => 'Nope.']],
                    ['index' => 2, 'translations' => ['es' => 'Tres'], 'intact' => false],
                ],
                'next_after' => null,
            ]],
        ]);

        $batch = $tr->batch(['One', 'Two', 'Three'], 'es', ['reference' => 'cat-1']);

        $this->assertSame('bat_1', $batch->id());
        $this->assertFalse($batch->finished());
        $this->assertSame(30, $batch->creditsEstimated());
        $this->assertSame(['texts' => ['One', 'Two', 'Three'], 'target' => 'es', 'reference' => 'cat-1'], $transport->calls[0]['body']);

        $read = $tr->batchStatus('bat_1', '-1', 100);

        $this->assertSame('https://example.test/api/v1/batches/bat_1?after=-1&limit=100', $transport->calls[1]['url']);
        $this->assertTrue($read->finished());
        $this->assertSame([0 => ['es' => 'Uno'], 2 => ['es' => 'Tres']], $read->results());
        $this->assertSame('translation_unavailable', $read->failures()[1]['code']);
        $this->assertTrue($read->intact(0));
        $this->assertFalse($read->intact(2));
        $this->assertNull($read->next());
        $this->assertNull($transport->calls[1]['key'], 'A read carries no idempotency key.');
    }

    public function test_the_lists_unwrap_their_data(): void
    {
        [$tr, $transport] = $this->client([
            [200, ['data' => [['id' => 'bat_1', 'status' => 'running']]]],
            [200, ['data' => [['code' => 'es', 'name' => 'Spanish', 'native' => 'Español', 'rtl' => false]]]],
            [200, ['data' => [['id' => 3, 'mode' => 'live']]]],
            [200, ['data' => [['slug' => 'shop', 'entries' => 2]]]],
            [200, ['data' => [['id' => 'tr_1']], 'next_cursor' => 'abc']],
            [200, ['data' => [['id' => 9]], 'next_cursor' => null]],
        ]);

        $batches = $tr->batches(5);
        $this->assertInstanceOf(Batch::class, $batches[0]);
        $this->assertSame('running', $batches[0]->status());
        $this->assertSame('es', $tr->languages()[0]['code']);
        $this->assertSame(3, $tr->keys()[0]['id']);
        $this->assertSame('shop', $tr->glossaries()[0]['slug']);
        $history = $tr->translations(kind: 'text');
        $this->assertInstanceOf(Page::class, $history);
        $this->assertSame([['id' => 'tr_1']], $history->items());
        $this->assertSame('abc', $history->next());

        $corrections = $tr->corrections('abc', target: 'es');
        $this->assertSame([['id' => 9]], $corrections->items());
        $this->assertNull($corrections->next(), 'Null is the last page.');

        $this->assertSame('https://example.test/api/v1/batches?limit=5', $transport->calls[0]['url']);
        $this->assertSame('https://example.test/api/v1/translations?kind=text', $transport->calls[4]['url']);
        $this->assertSame('https://example.test/api/v1/corrections?target=es&cursor=abc', $transport->calls[5]['url']);
    }

    public function test_glossaries_corrections_and_keys_use_the_right_methods(): void
    {
        [$tr, $transport] = $this->client(array_fill(0, 8, [200, ['ok' => true]]));

        $tr->createGlossary('Shop', ['cart' => 'cesta'], ['source' => 'en', 'target' => 'es']);
        $tr->glossary('shop');
        $tr->updateGlossary('shop', ['name' => 'Store']);
        $tr->deleteGlossary('shop');
        $tr->correct('Add to cart', 'es', 'Añadir a la cesta');
        $tr->deleteCorrection(42);
        $tr->revokeKey(7);
        $tr->translationRecord('tr_1');

        $seen = array_map(fn ($c) => $c['method'] . ' ' . substr($c['url'], strlen('https://example.test/api')), $transport->calls);

        $this->assertSame([
            'POST /v1/glossaries',
            'GET /v1/glossaries/shop',
            'PUT /v1/glossaries/shop',
            'DELETE /v1/glossaries/shop',
            'PUT /v1/corrections',
            'DELETE /v1/corrections/42',
            'DELETE /v1/keys/7',
            'GET /v1/translations/tr_1',
        ], $seen);

        $this->assertSame(['name' => 'Shop', 'entries' => ['cart' => 'cesta'], 'source' => 'en', 'target' => 'es'], $transport->calls[0]['body']);
        $this->assertSame(['text' => 'Add to cart', 'target' => 'es', 'translation' => 'Añadir a la cesta'], $transport->calls[4]['body']);
        $this->assertNull($transport->calls[3]['body'], 'A DELETE has no body.');
        $this->assertArrayNotHasKey('Content-Type', $transport->calls[3]['headers']);

        foreach ([0, 2, 3, 4, 5, 6] as $write) {
            $this->assertNotNull($transport->calls[$write]['key'], "{$seen[$write]} carries no idempotency key");
        }

        foreach ([1, 7] as $read) {
            $this->assertNull($transport->calls[$read]['key']);
        }
    }

    public function test_ping_and_usage_answer_their_bodies(): void
    {
        [$tr] = $this->client([
            [200, ['ok' => true, 'mode' => 'test', 'key' => 'tr_test_abcd']],
            [200, ['plan' => 'free', 'credits' => ['remaining' => 2000, 'renews_at' => null]]],
        ]);

        $this->assertSame('test', $tr->ping()['mode']);
        $this->assertSame(2000, $tr->usage()['credits']['remaining']);
    }
}
