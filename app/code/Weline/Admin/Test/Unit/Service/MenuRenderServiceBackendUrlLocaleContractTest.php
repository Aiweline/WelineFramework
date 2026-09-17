<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Service;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
require_once BP . 'app/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Admin\Model\MenuAccessLog;
use Weline\Admin\Service\MenuRenderService;

final class MenuRenderServiceBackendUrlLocaleContractTest extends TestCase
{
    public function testFormatMenuUrlCachedPreservesLocaleAwareBackendPrefix(): void
    {
        $service = new MenuRenderService($this->createStub(MenuAccessLog::class));
        $backend = new \ReflectionProperty(MenuRenderService::class, 'cachedBackendUrlPrefix');
        $backend->setAccessible(true);
        $backend->setValue($service, 'https://example.test/backend/en_US');

        $method = new \ReflectionMethod(MenuRenderService::class, 'formatMenuUrlCached');
        $method->setAccessible(true);
        $url = (string)$method->invoke($service, [
            'is_backend' => true,
            'route' => 'weline_product/backend/catalog/products',
        ]);

        self::assertSame(
            'https://example.test/backend/en_US/weline_product/backend/catalog/products',
            $url
        );
    }
}
