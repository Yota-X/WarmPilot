<?php
declare(strict_types=1);

namespace WarmPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BuildManifestTest extends TestCase {
    public function testPluginNameAndTextDomainProduceTheWarmpilotSlug(): void {
        $source = file_get_contents(dirname(__DIR__, 2) . '/warmpilot.php');
        self::assertIsString($source);
        self::assertMatchesRegularExpression('/^\s*\*\s*Plugin Name:\s*WarmPilot\s*$/m', $source);
        self::assertMatchesRegularExpression('/^\s*\*\s*Text Domain:\s*warmpilot\s*$/m', $source);
    }

    public function testBuildUsesProductionComposerAutoloadBeforePackaging(): void {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $build = $composer['scripts']['build'] ?? [];
        $productionInstall = array_search(
            '@composer install --no-dev --classmap-authoritative --no-interaction --no-progress',
            $build,
            true
        );
        $package = array_search('@php build/build.php', $build, true);
        $developmentRestore = array_search(
            '@composer install --no-interaction --no-progress',
            $build,
            true
        );

        self::assertIsInt($productionInstall);
        self::assertIsInt($package);
        self::assertIsInt($developmentRestore);
        self::assertLessThan($package, $productionInstall);
        self::assertLessThan($developmentRestore, $package);
    }

    public function testManifestContainsExactlyTheRuntimePackage(): void {
        self::assertSame([
            'LICENSE.txt',
            'THIRD-PARTY-NOTICES.txt',
            'readme.txt',
            'uninstall.php',
            'warmpilot.php',
            'admin',
            'assets',
            'composer.json',
            'includes',
            'licenses',
            'resources',
            'vendor/autoload.php',
            'vendor/composer',
            'vendor/jeremykendall/php-domain-parser',
            'vendor/symfony/polyfill-intl-idn',
            'vendor/symfony/polyfill-intl-normalizer',
        ], require dirname(__DIR__, 2) . '/build/manifest.php');
    }

    /**
     * @dataProvider developmentPathProvider
     */
    public function testDevelopmentPathsAreExcluded(string $path): void {
        $manifest = require dirname(__DIR__, 2) . '/build/manifest.php';
        self::assertNotContains($path, $manifest);
    }

    public function developmentPathProvider(): array {
        return [
            ['test'],
            ['build'],
            ['composer.lock'],
            ['phpunit.xml'],
            ['dist'],
            ['.gitignore'],
        ];
    }
}
