<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 主题预览禁止任何预览账号 / auto_login 残留。
 */
final class ThemePreviewAutoLoginWiringContractTest extends TestCase
{
    private function moduleFile(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
        self::assertFileExists($path, $relative);
        $content = file_get_contents($path);
        self::assertIsString($content);

        return $content;
    }

    public function testPreviewAccountStackIsFullyRemoved(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertFileDoesNotExist($root . '/Observer/PreviewAutoLogin.php');
        self::assertFileDoesNotExist($root . '/Helper/PreviewAccountManager.php');
        self::assertFileDoesNotExist($root . '/Api/PreviewAccountProviderInterface.php');
        self::assertFileDoesNotExist(
            dirname(__DIR__, 3) . '/Customer/Integration/Theme/PreviewAccountProvider.php'
        );

        $xml = $this->moduleFile('etc/event.xml');
        self::assertStringNotContainsString('preview_auto_login', $xml);
        self::assertStringNotContainsString('PreviewAutoLogin', $xml);

        $entry = $this->moduleFile('Service/ThemePreviewEntryApplication.php');
        self::assertStringNotContainsString('PreviewAccountManager', $entry);
        self::assertStringNotContainsString('preview_auto_login', $entry);
        self::assertStringNotContainsString('auto_login', $entry);

        $exit = $this->moduleFile('Service/PreviewExitService.php');
        self::assertStringNotContainsString('PreviewAccountManager', $exit);
        self::assertStringNotContainsString('logoutPreviewUser', $exit);
        self::assertStringNotContainsString('preview_auto_login', $exit);

        $gateway = $this->moduleFile('Controller/Frontend/ThemePreview/Gateway.php');
        self::assertStringNotContainsString('auto_login', $gateway);

        $editor = $this->moduleFile('view/statics/ui/pages/weline-theme-editor.js');
        self::assertStringNotContainsString("url.searchParams.set('auto_login'", $editor);

        $parser = $this->moduleFile('Helper/ComponentMetaParser.php');
        self::assertStringNotContainsString('preview_login', $parser);
        self::assertStringNotContainsString('@preview.login', $parser);

        $customerModule = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Customer/etc/module.php'
        );
        self::assertStringNotContainsString('PreviewAccountProvider', $customerModule);
    }
}
