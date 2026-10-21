<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 前端 4 个启动依赖（runtime/dom-observer/weline-api-business/url-frontend）
 * 生产环境合并为一个 weline-core.js；DEV 保持逐文件以便调试。
 */
final class FrontendCoreBundleContractTest extends TestCase
{
    public function testProductionHeaderLoadsSingleCoreBundleWithDevFallback(): void
    {
        $src = (string)\file_get_contents(BP . 'app/code/Weline/Frontend/view/blocks/header/base.phtml');
        self::assertStringContainsString('weline-core.js', $src);
        self::assertStringContainsString('$welineCoreProd', $src);
        // DEV 回退仍然逐文件
        self::assertStringContainsString('<js defer>Weline_Frontend::js/runtime.js</js>', $src);
        self::assertStringContainsString('<js defer>Weline_Framework::js/dom-observer.js</js>', $src);
        self::assertStringContainsString('<js defer>Weline_Frontend::js/weline-api-business.js</js>', $src);
        self::assertStringContainsString('<js defer>Weline_Framework::js/url-frontend.js</js>', $src);
    }

    public function testDeployPublishesCoreBundle(): void
    {
        $src = (string)\file_get_contents(
            BP . 'app/code/Weline/Framework/Console/Console/Deploy/Upgrade.php',
        );
        self::assertStringContainsString('WelineCoreBundle::publish', $src);

        $bundle = (string)\file_get_contents(
            BP . 'app/code/Weline/Framework/Deploy/WelineCoreBundle.php',
        );
        foreach (['runtime.js', 'dom-observer.js', 'weline-api-business.js', 'url-frontend.js'] as $source) {
            self::assertStringContainsString($source, $bundle);
        }
    }
}
