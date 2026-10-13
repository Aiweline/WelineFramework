<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;

/**
 * Path-only FPC must key off visitor pretty paths (e.g. /destinations),
 * not rewritten controller paths (/guide?view=…), or distinct pages collide.
 */
final class FullPageCacheGuideOriginPrettyPathContractTest extends TestCase
{
    public function testCoordinatorPrefersWelineOriginRequestUriForRawFullUri(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php'
        );

        self::assertStringContainsString('function getRawFullUri', $source);
        self::assertStringContainsString('WELINE_ORIGIN_REQUEST_URI', $source);
        self::assertStringContainsString('visitor-facing pretty path', $source);
        self::assertStringContainsString('/destinations', $source);
        self::assertStringContainsString('/guide?view=', $source);
        self::assertLessThan(
            strpos($source, "WelineEnv::server('WELINE_ORIGIN_REQUEST_URI'") ?: PHP_INT_MAX,
            strpos($source, 'function getRawFullUri') ?: PHP_INT_MAX,
        );
    }

    public function testUrlMatrixExpanderDocumentsPathLocaleNotQueryIdentity(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Framework/Event/Changed/UrlMatrixExpander.php'
        );

        self::assertStringContainsString('path 语种，非 query 标记', $source);
        self::assertStringContainsString('withPathVariant', $source);
    }
}
