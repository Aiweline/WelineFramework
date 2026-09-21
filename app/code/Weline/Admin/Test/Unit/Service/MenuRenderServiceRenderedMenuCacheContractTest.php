<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Service;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
require_once BP . 'app/bootstrap.php';

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Admin\Api\Runtime\ProcessCacheResetter;
use Weline\Admin\Model\MenuAccessLog;
use Weline\Admin\Service\MenuRenderService;
use Weline\Framework\Runtime\ProcessCacheResetContext;

final class MenuRenderServiceRenderedMenuCacheContractTest extends TestCase
{
    protected function tearDown(): void
    {
        MenuRenderService::clearProcessCache();
        parent::tearDown();
    }

    public function testClearProcessCacheEmptiesRenderedAndFrequentCaches(): void
    {
        $rendered = new ReflectionProperty(MenuRenderService::class, 'renderedMenuCache');
        $frequent = new ReflectionProperty(MenuRenderService::class, 'frequentMenusCache');
        $rendered->setValue(null, [
            'a' => ['expires' => microtime(true) + 60, 'html' => '<li>a</li>'],
        ]);
        $frequent->setValue(null, [
            '1|20|7' => ['expires' => microtime(true) + 30, 'data' => ['recentMenus' => []]],
        ]);

        MenuRenderService::clearProcessCache();

        self::assertSame([], $rendered->getValue());
        self::assertSame([], $frequent->getValue());
    }

    public function testRememberRenderedMenuRespectsMaxAndDropsExpired(): void
    {
        $max = (new ReflectionClass(MenuRenderService::class))->getConstant('RENDERED_MENU_CACHE_MAX');
        self::assertIsInt($max);
        self::assertGreaterThan(0, $max);

        $service = new MenuRenderService($this->createStub(MenuAccessLog::class));
        $remember = new ReflectionMethod(MenuRenderService::class, 'rememberRenderedMenu');
        $remember->setAccessible(true);
        $rendered = new ReflectionProperty(MenuRenderService::class, 'renderedMenuCache');

        $now = microtime(true);
        $remember->invoke($service, 'expired', '<li>old</li>', $now - 120.0);
        for ($i = 0; $i < $max + 3; $i++) {
            $remember->invoke($service, 'k' . $i, '<li>' . $i . '</li>', $now);
        }

        $cache = $rendered->getValue();
        self::assertLessThanOrEqual($max, count($cache));
        self::assertArrayNotHasKey('expired', $cache);
    }

    public function testProcessCacheResetterClearsMenuRenderProcessCaches(): void
    {
        $rendered = new ReflectionProperty(MenuRenderService::class, 'renderedMenuCache');
        $rendered->setValue(null, [
            'keep-until-reset' => ['expires' => microtime(true) + 60, 'html' => '<li>x</li>'],
        ]);

        $cleared = (new ProcessCacheResetter())->resetProcessCaches(
            new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, false)
        );

        self::assertGreaterThanOrEqual(2, $cleared);
        self::assertSame([], $rendered->getValue());
    }

    public function testCacheKeyUsesActiveFingerprintNotRawCurrentUrl(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Admin/Service/MenuRenderService.php');
        self::assertStringContainsString('buildActiveMenuFingerprint', $src);
        self::assertStringContainsString('activeFingerprint', $src);
        self::assertStringContainsString('function clearProcessCache', $src);
        self::assertStringContainsString('RENDERED_MENU_CACHE_MAX', $src);
        self::assertStringNotContainsString("\$currentUrl,\n            md5(json_encode(\$menus", $src);

        $resetter = (string)file_get_contents(BP . 'app/code/Weline/Admin/Api/Runtime/ProcessCacheResetter.php');
        self::assertStringContainsString('MenuRenderService::clearProcessCache()', $resetter);
    }

    public function testActiveFingerprintStableAcrossUrlsHittingSameLeaf(): void
    {
        $menus = [
            [
                'type' => 'menus',
                'source_id' => 'Weline_Demo::parent',
                'source_name' => 'Parent',
                'route' => '',
                'is_backend' => true,
                'nodes' => [
                    [
                        'type' => 'menus',
                        'source_id' => 'Weline_Demo::leaf',
                        'source_name' => 'Leaf',
                        'route' => 'demo/backend/leaf/index',
                        'is_backend' => true,
                        'nodes' => [],
                    ],
                ],
            ],
        ];

        $service = new MenuRenderService($this->createStub(MenuAccessLog::class));
        $prefix = new ReflectionProperty(MenuRenderService::class, 'cachedBackendUrlPrefix');
        $frontend = new ReflectionProperty(MenuRenderService::class, 'cachedFrontendUrlPrefix');
        $current = new ReflectionProperty(MenuRenderService::class, 'cachedCurrentUrl');
        $urlActive = new ReflectionProperty(MenuRenderService::class, 'menuUrlActiveCache');
        $nodeActive = new ReflectionProperty(MenuRenderService::class, 'menuNodeActiveCache');
        $prefix->setAccessible(true);
        $frontend->setAccessible(true);
        $current->setAccessible(true);
        $urlActive->setAccessible(true);
        $nodeActive->setAccessible(true);
        $backendKey = trim((string)(\Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?? 'backend'), '/');
        $prefix->setValue($service, $backendKey === '' ? '' : '/' . $backendKey);
        $frontend->setValue($service, '/');
        $build = new ReflectionMethod(MenuRenderService::class, 'buildActiveMenuFingerprint');
        $build->setAccessible(true);

        $urlActive->setValue($service, []);
        $nodeActive->setValue($service, []);
        $current->setValue($service, 'demo/backend/leaf/index');
        $fpExact = $build->invoke($service, $menus);

        $urlActive->setValue($service, []);
        $nodeActive->setValue($service, []);
        $current->setValue($service, 'demo/backend/leaf');
        $fpIndexOmitted = $build->invoke($service, $menus);

        $urlActive->setValue($service, []);
        $nodeActive->setValue($service, []);
        $current->setValue($service, 'demo/backend/other/index');
        $fpOther = $build->invoke($service, $menus);

        self::assertNotSame('none', $fpExact);
        self::assertSame($fpExact, $fpIndexOmitted);
        self::assertSame('none', $fpOther);
    }
}
