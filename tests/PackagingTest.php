<?php

namespace Translatorio\Tests;

use PHPUnit\Framework\TestCase;

/**
 * What the package promises about itself, rather than what the client does.
 */
class PackagingTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return json_decode(file_get_contents(self::ROOT . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_installed_package_is_the_source_and_nothing_else(): void
    {
        $attributes = file_get_contents(self::ROOT . '/.gitattributes');

        foreach (['/tests', '/phpunit.xml', '/.github', '/art'] as $path) {
            $this->assertStringContainsString($path, $attributes, "$path still travels in the dist");
        }

        $this->assertStringNotContainsString('LICENSE', $attributes);
        $this->assertFileExists(self::ROOT . '/LICENSE');
    }

    public function test_it_depends_on_php_and_two_extensions_and_nothing_more(): void
    {
        $this->assertSame(['php', 'ext-curl', 'ext-json'], array_keys($this->manifest()['require']));
    }

    public function test_the_php_floor_is_a_version_the_ci_runs(): void
    {
        preg_match('/\^(\d+\.\d+)/', $this->manifest()['require']['php'], $declared);
        preg_match_all("/'(\d+\.\d+)'/", file_get_contents(self::ROOT . '/.github/workflows/tests.yml'), $tested);

        $versions = $tested[1];
        usort($versions, static fn ($a, $b) => version_compare($a, $b));

        $this->assertSame($declared[1], $versions[0]);
        $this->assertContains('8.5', $versions);
    }

    public function test_it_says_who_wrote_it_and_where_to_look(): void
    {
        $manifest = $this->manifest();

        $this->assertSame('edulazaro/translatorio-sdk', $manifest['name']);
        $this->assertSame('MIT', $manifest['license']);
        $this->assertSame('https://github.com/translatorio/php-sdk', $manifest['support']['source']);
        $this->assertStringContainsString('Edu Lazaro', file_get_contents(self::ROOT . '/LICENSE'));
    }

    public function test_the_readme_shows_the_banner_by_absolute_address(): void
    {
        $this->assertStringStartsWith(
            '![Translatorio PHP SDK](https://raw.githubusercontent.com/translatorio/php-sdk/main/art/banner.png)',
            file_get_contents(self::ROOT . '/README.md'),
        );
    }

    public function test_the_version_is_the_one_being_released(): void
    {
        $this->assertSame('1.0.0', \Translatorio\Client::VERSION);
    }

    /**
     * The README's webhook handler is copied verbatim; without a fallback, a request with no
     * signature header hands `null` to a `string` parameter and answers 500 instead of 400.
     */
    public function test_the_webhook_example_survives_a_missing_header(): void
    {
        $this->assertStringContainsString(
            "\$_SERVER['HTTP_X_TRANSLATORIO_SIGNATURE'] ?? ''",
            file_get_contents(self::ROOT . '/README.md'),
        );
    }

    public function test_no_em_dash_anywhere_a_reader_sees(): void
    {
        foreach ([self::ROOT . '/README.md', ...glob(self::ROOT . '/src/*.php'), ...glob(self::ROOT . '/src/Exception/*.php')] as $file) {
            $this->assertStringNotContainsString('—', file_get_contents($file), basename($file));
        }
    }
}
