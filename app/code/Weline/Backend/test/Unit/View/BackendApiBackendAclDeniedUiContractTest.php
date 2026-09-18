<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendApiBackendAclDeniedUiContractTest extends TestCase
{
    public function testBackendApiSplitsAclDeniedUiByEnvironment(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Backend/view/statics/js/weline-api.js'
        );

        self::assertStringContainsString("backend_acl_denied", $source);
        self::assertStringContainsString('FrontendWorkerBackendAuthorizationException', $source);
        self::assertStringContainsString('isBackendAclDeniedError', $source);
        self::assertStringContainsString('notifyBackendAclDeniedUi', $source);
        self::assertStringContainsString('showBackendAclDeniedUi', $source);
        self::assertStringContainsString('dialog.alert', $source);
        self::assertStringContainsString('isDevMode()', $source);
        self::assertStringContainsString('toast.error', $source);
        self::assertStringContainsString('notifyBackendAclDeniedUi(error)', $source);
        self::assertStringContainsString('notifyBackendAclDeniedUi(businessError)', $source);
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*isDevMode\(\)\s*\)\s*\{[\s\S]*?dialog\.alert/',
            $source
        );
    }
}
