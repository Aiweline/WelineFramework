<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;

/**
 * Deploy generation mismatch on QueryBin handshake must force client reload.
 */
final class QueryBinDeployGenerationContractTest extends TestCase
{
    public function testHandshakeAssertsClientDeployGeneration(): void
    {
        $src = (string)\file_get_contents(
            BP . 'app/code/Weline/Frontend/Controller/Api/QueryBin.php'
        );
        self::assertStringContainsString('assertClientDeployGenerationCurrent', $src);
        self::assertStringContainsString("'deploy_reload_required'", $src);
        self::assertStringContainsString('Storefront deploy generation changed', $src);
        self::assertStringContainsString('currentServerDeployVersion', $src);
    }

    public function testApiJsComparesHolePunchDeployVersion(): void
    {
        $script = (string)\file_get_contents(
            BP . 'app/code/Weline/Frontend/view/statics/js/weline-api.js'
        );
        self::assertStringContainsString('payload.deploy_version', $script);
        self::assertStringContainsString("error.code = 'deploy_reload_required'", $script);
        self::assertStringContainsString('deploy_reload_required:', $script);
    }

    public function testCoordinatorEmitsDeployHeaderAndMustRevalidate(): void
    {
        $src = (string)\file_get_contents(
            BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php'
        );
        self::assertStringContainsString('applyDeployGenerationResponseHeaders', $src);
        self::assertStringContainsString('X-Weline-Deploy-Version', $src);
        self::assertStringContainsString("', must-revalidate'", $src);
    }
}
