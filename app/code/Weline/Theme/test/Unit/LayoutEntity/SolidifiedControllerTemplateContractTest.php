<?php

declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

final class SolidifiedControllerTemplateContractTest extends TestCase
{
    public function testSourceSelectionContractInAppCode(): void
    {
        // Prefer app/code path assertions: PHPUnit may load vendor/weline/module-theme first.
        $source = file_get_contents(dirname(__DIR__, 3) . '/Service/LayoutEntity/SolidifiedControllerTemplateResolver.php');
        self::assertIsString($source);
        self::assertStringContainsString('function resolveForIdentity', $source, 'Selection must use the complete version and resource identity.');
        self::assertStringContainsString('versionOwnerScope', $source, 'Published layout selection must honor ThemeApplicationContext version owner scope.');
        self::assertStringContainsString('ThemeApplicationContext', $source);
        self::assertStringContainsString('function resolveExecutableLayoutPath', $source);
        self::assertStringContainsString(
            'resolvePublishedVersionAlongThemeChain',
            $source,
            'Child package_defaults themes must inherit ancestor published solidified layouts (Filters default_injections).',
        );
        self::assertStringContainsString(
            'themeProvidesLayoutOverride',
            $source,
            'Parent solidification must not clobber child design layout overrides (homepage brand content).',
        );
        self::assertStringContainsString('getParentId', $source, 'Theme inheritance walk must use parent_id chain.');
        self::assertMatchesRegularExpression(
            '/use\s+Weline\\\\Theme\\\\Model\\\\ThemeScopeVersion\s*;/',
            $source,
            'ThemeScopeVersion return type must import Model namespace (not LayoutEntity).',
        );
    }
}
