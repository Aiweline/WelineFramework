<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class SeoOptimizationControlCenterFrontendContractTest extends TestCase
{
    public function testOptimizationConsoleUsesItsAclScopedDirectoryProjection(): void
    {
        $template = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/Backend/Optimization/index.phtml'
        );

        self::assertStringContainsString(
            "resource('seo_optimization_control')",
            $template
        );
        self::assertStringContainsString(
            'state.directoryApi.optimizationControlCenterSnapshot({})',
            $template
        );
        self::assertStringContainsString(
            'Array.isArray(response.sites)?response.sites:items(response)',
            $template
        );
        self::assertStringNotContainsString("resource('websites')", $template);
        self::assertStringNotContainsString(
            'state.websitesApi.getWebsiteList({})',
            $template
        );
    }
}
