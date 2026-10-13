<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\DeployPublicUrlMode;
use Weline\Framework\Deploy\DeployStagingSession;
use Weline\Framework\View\PublicStaticUrl;

final class PublicStaticUrlContractTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(DeployStagingSession::ENV_ROOT);
        unset($_ENV[DeployStagingSession::ENV_ROOT], $_SERVER[DeployStagingSession::ENV_ROOT]);
        parent::tearDown();
    }

    public function testFromDevWebPathIsModeAware(): void
    {
        $dev = '/Weline/Frontend/view/statics/js/weline-api-worker.js';
        if (DeployPublicUrlMode::useProduction()) {
            self::assertSame(
                '/static/Weline/Frontend/js/weline-api-worker.js',
                PublicStaticUrl::fromDevWebPath($dev),
            );
        } else {
            self::assertSame($dev, PublicStaticUrl::fromDevWebPath($dev));
        }
    }

    public function testStagingForcesFlatProductionUrl(): void
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . '/weline-public-static-' . bin2hex(random_bytes(3));
        mkdir($root . '/static', 0775, true);
        putenv(DeployStagingSession::ENV_ROOT . '=' . $root);
        $_ENV[DeployStagingSession::ENV_ROOT] = $root;

        self::assertTrue(DeployPublicUrlMode::useProduction());
        self::assertSame(
            '/static/Weline/Theme/css/widgets/mini-cart-drawer.css',
            PublicStaticUrl::fromDevWebPath('/Weline/Theme/view/statics/css/widgets/mini-cart-drawer.css'),
        );
        self::assertSame(
            '/static/Weline/Cart/css/mini-cart-drawer-qty-hit.css?v=1',
            PublicStaticUrl::fromDevWebPath('/Weline/Cart/view/statics/css/mini-cart-drawer-qty-hit.css?v=1'),
        );
        self::assertSame(
            '/static/Weline/Frontend/js/weline-api-worker.js',
            PublicStaticUrl::fromModuleSource('Weline_Frontend::js/weline-api-worker.js'),
        );
    }

    public function testSourceForbidsInventingThirdShapeInHelper(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/View/PublicStaticUrl.php');
        self::assertStringContainsString('DeployPublicUrlMode::useProduction', $src);
        self::assertStringContainsString('/view/statics/', $src);
        self::assertStringContainsString('/static/', $src);
        self::assertStringNotContainsString('PublicThemeNamespace', $src);
    }
}
