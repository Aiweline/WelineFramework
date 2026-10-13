<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Test\ProjectRoot;

/**
 * Scope hole-punch must not eager-start the customer session jar.
 */
final class ScopeBootstrapSessionDeferContractTest extends TestCase
{
    public function testAppDefersEagerSessionOnScopeBootstrap(): void
    {
        $src = (string)\file_get_contents(ProjectRoot::path() . 'app/code/Weline/Framework/App.php');
        self::assertStringContainsString('isFrontendScopeBootstrapRequestUri', $src);
        self::assertStringContainsString("'/api/frontend/scope-bootstrap'", $src);
        self::assertStringContainsString(
            'eager-mint/reassert WELINE_CUSTOMER_SESSID',
            $src,
        );
        self::assertMatchesRegularExpression(
            '/if \(\$this->isFrontendScopeBootstrapRequestUri\(\$requestUri\)\) \{\s*return;/s',
            $src,
        );
    }
}
