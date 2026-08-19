<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Console\Theme;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Console\Theme\Upgrade as ThemeUpgradeCommand;

if (!class_exists(ThemeUpgradeCommand::class, false)) {
    require_once dirname(__DIR__, 4) . '/Console/Theme/Upgrade.php';
}

final class ThemeUpgradeCommandContractTest extends TestCase
{
    public function testNamedThemeLookupUsesTheDeclaredNameFieldConstant(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/Console/Theme/Upgrade.php');

        self::assertIsString($source);
        self::assertStringContainsString('WelineTheme::schema_fields_NAME', $source);
        self::assertStringNotContainsString('WelineTheme::filed_NAME', $source);
    }

    public function testCliMetadataIsNotTreatedAsAModuleFilter(): void
    {
        [$themeName, $modules] = ThemeUpgradeCommand::parseArguments([
            0 => 'theme:upgrade',
            1 => '-t',
            2 => 'weshop-motor',
            'command' => 'theme:upgrade',
            't' => 'weshop-motor',
        ]);

        self::assertSame('weshop-motor', $themeName);
        self::assertSame([], $modules);
    }

    public function testPublisherCreatesMissingDestinationDirectories(): void
    {
        $workspace = sys_get_temp_dir() . '/weline-theme-upgrade-' . bin2hex(random_bytes(6));
        $source = $workspace . '/source.css';
        $destination = $workspace . '/nested/assets/css';
        mkdir($workspace, 0755, true);
        file_put_contents($source, '.motor-header { color: white; }');

        try {
            $command = (new \ReflectionClass(ThemeUpgradeCommand::class))->newInstanceWithoutConstructor();
            $method = new \ReflectionMethod(ThemeUpgradeCommand::class, 'copyThemeFile');
            $method->invoke($command, $source, $destination);

            self::assertSame(
                '.motor-header { color: white; }',
                file_get_contents($destination . '/source.css')
            );
        } finally {
            if (is_file($destination . '/source.css')) {
                unlink($destination . '/source.css');
            }
            foreach ([$destination, dirname($destination), dirname($destination, 2), $workspace] as $directory) {
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }
        }
    }

    public function testAbsoluteCoreThemePathPublishesInsideTheStaticThemeNamespace(): void
    {
        $sourceRoot = '/Users/example/project/app/code/Weline/Theme/view/theme';
        $sourceFile = $sourceRoot . '/frontend/assets/css/theme.css';

        self::assertSame(
            '/Users/example/project/pub/static/Weline/default/frontend/assets/css',
            ThemeUpgradeCommand::buildDestinationDirectory(
                $sourceRoot,
                $sourceFile,
                'Weline/default',
                '/Users/example/project/pub/static'
            )
        );
    }

    public function testPublisherRejectsAFileOutsideTheThemeRoot(): void
    {
        self::assertNull(ThemeUpgradeCommand::buildDestinationDirectory(
            '/Users/example/project/app/design/WeShop/motor',
            '/Users/example/project/app/code/Weline/Theme/view/theme/frontend/assets/css/theme.css',
            'WeShop/motor',
            '/Users/example/project/pub/static'
        ));
    }
}
