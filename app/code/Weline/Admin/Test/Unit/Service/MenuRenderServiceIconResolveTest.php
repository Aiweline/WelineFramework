<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Service;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
require_once BP . 'app/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Admin\Model\MenuAccessLog;
use Weline\Admin\Service\MenuRenderService;
use Weline\Backend\Setup\Ui\LegacyIconNameMap;
use Weline\Theme\Service\Ui\IconRegistry;

final class MenuRenderServiceIconResolveTest extends TestCase
{
    public function testResolveMenuIconNameMapsLegacyMdiValues(): void
    {
        $service = new class($this->createStub(MenuAccessLog::class)) extends MenuRenderService {
        };
        $method = new \ReflectionMethod(MenuRenderService::class, 'resolveMenuIconName');
        $method->setAccessible(true);

        self::assertSame('grid', $method->invoke($service, 'mdi mdi-view-dashboard'));
        self::assertSame('file', $method->invoke($service, 'mdi-file-document-outline'));
        self::assertSame('settings', $method->invoke($service, 'mdi mdi-cog'));
        self::assertSame('grid', $method->invoke($service, 'grid'));
    }

    public function testLegacyMapAndRegistryStayAlignedForCommonMenuIcons(): void
    {
        $registry = new IconRegistry();
        $legacyMap = new LegacyIconNameMap();

        foreach (['mdi mdi-cart', 'mdi mdi-account-group', 'mdi mdi-image-multiple'] as $legacyIcon) {
            $mapped = $legacyMap->map($legacyIcon);
            self::assertNotNull($mapped);
            self::assertTrue($registry->has((string)$mapped), $legacyIcon . ' -> ' . (string)$mapped);
        }
    }
}
