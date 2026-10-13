<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Policy::index must recover layout_type from WELINE_ORIGIN_REQUEST_URI when
 * FPC path-only canonicalize wiped router-injected GET params.
 */
final class PolicyHydrateLayoutFromOriginPathContractTest extends TestCase
{
    public function testIndexHydratesFromOriginPathBeforeDefaultPolicyLayout(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Frontend/Policy.php'
        );

        self::assertStringContainsString('hydrateLayoutParamsFromPublicPath', $source);
        self::assertStringContainsString('resolveVisitorPublicPath', $source);
        self::assertStringContainsString('WELINE_ORIGIN_REQUEST_URI', $source);
        self::assertStringContainsString('resolveFromPath', $source);
        self::assertMatchesRegularExpression(
            '/function index\(\)[\s\S]*hydrateLayoutParamsFromPublicPath\(\)/',
            $source
        );
        self::assertStringNotContainsString("'url' => '@url'", $source);
    }
}
