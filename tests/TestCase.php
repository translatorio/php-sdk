<?php

namespace Translatorio\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    /**
     * An answer shaped like the real `/v1/translate` one.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function translation(array $overrides = []): array
    {
        return array_replace([
            'id' => 'tr_01K6',
            'reference' => 'release-42',
            'source' => ['language' => 'en', 'detected' => true],
            'targets' => ['es'],
            'translation' => 'Hola mundo',
            'results' => [['index' => 0, 'translations' => ['es' => 'Hola mundo'], 'intact' => true]],
            'intact' => true,
            'usage' => [
                'characters' => 11,
                'segments' => 1,
                'cached' => 0,
                'corrected' => 0,
                'anonymized' => 0,
                'model' => ['read' => true, 'name' => 'gpt-5.6-luna', 'why' => null],
                'tokens' => ['input' => 500, 'output' => 20],
            ],
            'credits' => ['charged' => 3, 'remaining' => 1997],
            'mode' => 'live',
            'took_ms' => 840,
        ], $overrides);
    }

    /**
     * @param list<array{0: int, 1: mixed, 2?: bool, 3?: array<string, string>}> $responses
     * @param int $retries
     * @return array{0: SleeplessClient, 1: FakeTransport}
     */
    protected function client(array $responses, int $retries = 2): array
    {
        $transport = new FakeTransport($responses);

        return [
            new SleeplessClient('tr_test_key', 'https://example.test/api', $retries, $transport),
            $transport,
        ];
    }
}
