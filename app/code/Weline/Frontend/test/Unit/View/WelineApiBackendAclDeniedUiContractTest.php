<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class WelineApiBackendAclDeniedUiContractTest extends TestCase
{
    public function testFrontendApiSplitsAclDeniedUiByEnvironment(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Frontend/view/statics/js/weline-api.js'
        );

        self::assertStringContainsString("backend_acl_denied", $source);
        self::assertStringContainsString('FrontendWorkerBackendAuthorizationException', $source);
        self::assertStringContainsString('isBackendAclDeniedError', $source);
        self::assertStringContainsString('showBackendAclDeniedUi', $source);
        self::assertStringContainsString('dialog.alert', $source);
        self::assertStringContainsString('isDevMode()', $source);
        self::assertStringContainsString('toast.error', $source);
        self::assertStringContainsString('_welineBackendAclUiShown', $source);
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*isBackendAclDeniedError\(error\)\s*\)\s*\{[\s\S]*?showBackendAclDeniedUi\(error\)/',
            $source
        );
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*isDevMode\(\)\s*\)\s*\{[\s\S]*?dialog\.alert/',
            $source
        );
    }
}
