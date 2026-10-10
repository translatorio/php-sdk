<?php

namespace Translatorio\Tests;

use Translatorio\Exception\ServerError;

/**
 * Nothing that is not an answer from the API is ever read as one.
 *
 * A base URL on http (answered with a 301 the transport deliberately does not follow) or a
 * proxy's HTML page served with a 200 would otherwise come back as an empty translation:
 * nothing to publish, and nothing saying why.
 */
final class FailClosedTest extends TestCase
{
    public function test_a_redirect_is_never_an_answer(): void
    {
        [$tr, $transport] = $this->client([[301, '', true], [301, '', true], [301, '', true]]);

        try {
            $tr->translate('Hello', 'es');
            $this->fail('A redirect was read as an answer.');
        } catch (ServerError $e) {
            $this->assertSame(301, $e->status);
            $this->assertStringContainsString('redirect', $e->getMessage());
            $this->assertStringContainsString('https', $e->getMessage());
        }

        $this->assertCount(3, $transport->calls, 'Reported as retryable, like any other failure of the path.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function bodiesThatAreNotAnswers(): array
    {
        return [
            'a proxy page' => ['<html><body>Welcome to nginx!</body></html>'],
            'nothing at all' => [''],
            'a JSON list' => ['[]'],
            'a JSON string' => ['"ok"'],
            'JSON null' => ['null'],
        ];
    }

    /**
     * @dataProvider bodiesThatAreNotAnswers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bodiesThatAreNotAnswers')]
    public function test_a_success_that_is_not_a_json_object_is_never_an_answer(string $body): void
    {
        [$tr] = $this->client([[200, $body, true]], retries: 0);

        try {
            $tr->translate('Hello', 'es');
            $this->fail('A body that is not a JSON object was read as an answer.');
        } catch (ServerError $e) {
            $this->assertSame(200, $e->status);
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_an_error_page_that_is_not_json_still_gets_the_right_type(): void
    {
        [$tr] = $this->client([[401, '<html>Unauthorized</html>', true]]);

        $this->expectException(\Translatorio\Exception\AuthenticationError::class);

        $tr->ping();
    }
}
