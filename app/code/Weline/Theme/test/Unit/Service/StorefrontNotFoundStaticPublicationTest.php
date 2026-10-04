<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\StorefrontNotFoundStaticPage;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Service\SetupSourceFingerprint;
use Weline\Framework\View\Template;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\StorefrontNotFoundStaticGenerator;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\WidgetDefaultInjectionService;

final class StorefrontNotFoundStaticPublicationTest extends TestCase
{
    public function testChangedRenderedDependenciesArePublishedAndIdenticalOutputIsNotRewritten(): void
    {
        $code = 'e2e-phtml-404-' . bin2hex(random_bytes(5));
        $path = StorefrontNotFoundStaticPage::staticFilePath('en_US', $code);
        $theme = clone ObjectManager::getInstance(WelineTheme::class);
        $theme->clearData()->clearQuery()->load(1);
        $context = $this->createMock(ThemeContextService::class);
        $context->method('resolveThemeForScope')->willReturn($theme);
        $context->method('resolveTheme')->willReturn($theme);
        $output = '<!doctype html><html><body>dependency A</body></html>';
        $template = $this->createMock(Template::class);
        $template->method('fetchModuleThemeHtml')->willReturnCallback(static function () use (&$output): string { return $output; });
        $slots = $this->createMock(SlotRendererService::class);
        $slots->method('processSlots')->willReturnCallback(static fn(string $html): string => $html);
        $generator = new StorefrontNotFoundStaticGenerator($context, $slots, $template,
            ObjectManager::getInstance(Request::class), $this->createMock(WidgetDefaultInjectionService::class),
            ObjectManager::getInstance(Printing::class));
        try {
            self::assertTrue($generator->publishOne($code, 'en_US', 0)['ok']);
            self::assertStringContainsString('dependency A', file_get_contents($path));
            $output = '<!doctype html><html><body>dependency B</body></html>';
            self::assertTrue($generator->publishOne($code, 'en_US', 0)['ok']);
            self::assertStringContainsString('dependency B', file_get_contents($path));
            clearstatcache(true, $path);
            $before = [fileinode($path), filemtime($path), hash_file('sha256', $path)];
            self::assertTrue($generator->publishOne($code, 'en_US', 0)['ok']);
            clearstatcache(true, $path);
            self::assertSame($before, [fileinode($path), filemtime($path), hash_file('sha256', $path)]);
        } finally {
            foreach ([$path, $path . '.hash'] as $file) { if (is_file($file)) { unlink($file); } }
            if (is_dir(dirname($path))) { rmdir(dirname($path)); }
            (new SetupSourceFingerprint())->forgetByPrefix('static404:' . $code . ':');
        }
    }
}
