<?php

namespace ToxicFilter\Tests;

use PHPUnit\Framework\TestCase;

/**
 * What the package promises about itself, rather than what the client does.
 *
 * Every failure here is invisible to whoever hits it: an `export-ignore` that forgets the
 * suite ships megabytes nobody wants, a missing LICENSE makes the README link to nothing,
 * and a PHP floor the CI never runs is a promise no test would otherwise check.
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

        // And the licence deliberately does NOT: the README links to it.
        $this->assertStringNotContainsString('LICENSE', $attributes);
        $this->assertFileExists(self::ROOT . '/LICENSE');
    }

    public function test_it_depends_on_php_and_two_extensions_and_nothing_more(): void
    {
        // The whole argument for this client: no Guzzle, nothing to conflict with whatever
        // the host application already pins.
        $this->assertSame(['php', 'ext-curl', 'ext-json'], array_keys($this->manifest()['require']));
    }

    public function test_the_php_floor_is_a_version_the_ci_runs(): void
    {
        preg_match('/\^(\d+\.\d+)/', $this->manifest()['require']['php'], $declared);
        preg_match_all("/'(\d+\.\d+)'/", file_get_contents(self::ROOT . '/.github/workflows/tests.yml'), $tested);

        $versions = $tested[1];
        usort($versions, static fn ($a, $b) => version_compare($a, $b));

        $this->assertSame($declared[1], $versions[0], 'the manifest and the CI matrix disagree');
    }

    public function test_it_says_who_wrote_it_and_where_to_look(): void
    {
        $manifest = $this->manifest();

        $this->assertNotEmpty($manifest['authors']);
        $this->assertNotEmpty($manifest['keywords']);
        $this->assertSame('MIT', $manifest['license']);
        $this->assertArrayHasKey('source', $manifest['support']);
    }

    public function test_the_art_exists_and_the_readme_shows_it(): void
    {
        foreach (['banner.svg', 'banner.png', 'og.svg', 'og.png'] as $name) {
            $this->assertFileExists(self::ROOT . "/art/$name");
        }

        // Absolute, because Packagist rewrites a relative path but PyPI does not, and the
        // three clients use one form rather than three.
        $this->assertStringStartsWith(
            '![ToxicFilter PHP SDK](https://raw.githubusercontent.com/toxicfilter/php-sdk/main/art/banner.png)',
            file_get_contents(self::ROOT . '/README.md'),
        );
    }

    public function test_the_version_is_the_one_being_released(): void
    {
        $this->assertSame('1.4.0', \ToxicFilter\Client::VERSION);
    }

    /**
     * `curl_close()` has been a no-op since PHP 8.0 and is deprecated in 8.5, where every
     * call would print a notice into the host application's log.
     */
    public function test_it_calls_nothing_php_deprecates_and_the_ci_runs_the_newest_php(): void
    {
        $this->assertStringNotContainsString('curl_close(', file_get_contents(self::ROOT . '/src/CurlTransport.php'));
        $this->assertStringContainsString("'8.5'", file_get_contents(self::ROOT . '/.github/workflows/tests.yml'));
    }

    /**
     * The README's webhook handler is copied verbatim. Without a fallback, a request with
     * no signature header hands `null` to a `string` parameter: a TypeError and a 500
     * instead of the 400 the snippet goes on to send.
     */
    public function test_the_webhook_example_survives_a_missing_header(): void
    {
        $this->assertStringContainsString(
            "\$_SERVER['HTTP_X_TOXICFILTER_SIGNATURE'] ?? ''",
            file_get_contents(self::ROOT . '/README.md'),
        );
    }

    public function test_the_dotfiles_speak_english(): void
    {
        foreach (['.gitattributes', '.gitignore'] as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(que|lo|una|aquí|solo|por|para|cada|el)\b/iu',
                file_get_contents(self::ROOT . "/$file"),
                "$file still has Spanish comments",
            );
        }
    }

    /**
     * Billing moved from fixed prices per call (12 credits for a model reading, 40 for a
     * picture) to one credit a check plus the model's tokens, rounded up. A client that
     * repeats the old figures is quoting a price nobody charges.
     */
    public function test_nothing_quotes_the_old_fixed_prices(): void
    {
        $files = [self::ROOT . '/README.md', ...glob(self::ROOT . '/src/*.php'), ...glob(self::ROOT . '/src/Exception/*.php')];

        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/12 credits|40 credits|forty|moderate_ai|moderate_image/i',
                file_get_contents($file),
                basename($file) . ' quotes a price that no longer exists',
            );
        }
    }
}
