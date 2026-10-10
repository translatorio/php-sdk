<?php

namespace Translatorio\Tests;

/**
 * A picture goes up as an address, or as bytes the API can decode once.
 */
final class ImageEncodingTest extends TestCase
{
    /** A 1x1 transparent PNG, base64. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /**
     * @param string $image
     * @return array<string, mixed>
     */
    private function sent(string $image): array
    {
        [$tr, $transport] = $this->client([[200, ['blocks' => []]]]);
        $tr->translateImage($image, 'en', ['source' => 'ja']);

        $this->assertSame('https://example.test/api/v1/translate/image', $transport->calls[0]['url']);

        return $transport->calls[0]['body'];
    }

    public function test_an_address_goes_as_url_whatever_the_case_of_its_scheme(): void
    {
        $this->assertSame(['url' => 'https://cdn.test/a.png', 'target' => 'en', 'source' => 'ja'], $this->sent('https://cdn.test/a.png'));
        $this->assertSame('HTTPS://cdn.test/a.png', $this->sent('HTTPS://cdn.test/a.png')['url']);
    }

    public function test_raw_bytes_are_encoded_once(): void
    {
        $body = $this->sent(base64_decode(self::PNG));

        $this->assertSame(self::PNG, $body['data']);
        $this->assertArrayNotHasKey('url', $body);
    }

    public function test_base64_and_data_uris_pass_through_untouched(): void
    {
        $this->assertSame(self::PNG, $this->sent(self::PNG)['data']);
        $this->assertSame('data:image/png;base64,' . self::PNG, $this->sent('data:image/png;base64,' . self::PNG)['data']);
    }

    public function test_base64_with_line_breaks_is_not_encoded_again(): void
    {
        $wrapped = chunk_split(self::PNG, 20, "\n");

        $this->assertSame($wrapped, $this->sent($wrapped)['data']);
    }

    public function test_the_explicit_form_always_sends_bytes(): void
    {
        [$tr, $transport] = $this->client([[200, ['blocks' => []]]]);
        $tr->translateImageData(base64_decode(self::PNG), ['en', 'es']);

        $this->assertSame(['data' => self::PNG, 'targets' => ['en', 'es']], $transport->calls[0]['body']);
    }
}
