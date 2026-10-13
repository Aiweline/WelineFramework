<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;

final class DeployModeSetStagingContractTest extends TestCase
{
    public function testProdUsesStagingPipelineWithoutLiveWipeFirst(): void
    {
        $path = dirname(__DIR__, 3) . '/Console/Console/Deploy/Mode/Set.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('DeployStagingSession', $src);
        self::assertStringContainsString('deployProdViaStaging', $src);
        self::assertStringContainsString('commitSwap', $src);
        self::assertStringContainsString('purgePrevAndResidue', $src);
        self::assertStringContainsString('abort', $src);
        // Prod path must not wipe live trees before staging pipeline.
        // Scoop only until the next case (prod ends with return, not break).
        self::assertSame(1, preg_match(
            '/case\s+[\'"]prod[\'"]\s*:\s*(.*?)(?=\n\s*case\s+)/s',
            $src,
            $prodCase,
        ));
        $prodBody = (string)($prodCase[1] ?? '');
        self::assertStringContainsString('deployProdViaStaging', $prodBody);
        self::assertMatchesRegularExpression(
            '/persistDeployMode\(\s*[\'"]prod[\'"]\s*\).*deployProdViaStaging/s',
            $prodBody,
        );
        self::assertStringNotContainsString('cleanTplComDir', $prodBody);
        self::assertStringNotContainsString('clearGeneratedComplicateDir', $prodBody);
        self::assertStringNotContainsString('cleanThemeDir', $prodBody);
        // Dev still cleans compile dirs.
        self::assertSame(1, preg_match(
            '/case\s+[\'"]dev[\'"]\s*:\s*(.*?)break;/s',
            $src,
            $devCase,
        ));
        self::assertStringContainsString('cleanTplComDir', (string)($devCase[1] ?? ''));
    }

    public function testUpgradeAndTraitTemplateRespectStaging(): void
    {
        $upgrade = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Console/Console/Deploy/Upgrade.php',
        );
        self::assertStringContainsString('DeployStagingSession::staticRoot()', $upgrade);
        self::assertStringNotContainsString(
            "resolveFlatStaticModuleRoot(\$moduleName, PUB . 'static')",
            $upgrade,
        );

        $trait = (string)file_get_contents(
            dirname(__DIR__, 3) . '/View/TraitTemplate.php',
        );
        self::assertStringContainsString('DeployStagingSession::isActive()', $trait);
        self::assertStringContainsString('DeployStagingSession::complicateRoot()', $trait);
        self::assertStringContainsString('DeployPublicUrlMode::useProduction', $trait);
        self::assertStringContainsString('useProductionStaticPublicUrls', $trait);
        self::assertStringContainsString('deploy_public_urls', $trait);
        self::assertStringContainsString('context-env-v10-module-static-flat', $trait);
        $publicUrlMode = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Deploy/DeployPublicUrlMode.php',
        );
        self::assertStringContainsString('forcesProductionPublicUrls', $publicUrlMode);
        self::assertStringContainsString("Env::system('deploy')", $publicUrlMode);

        $session = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Deploy/DeployStagingSession.php',
        );
        self::assertStringContainsString('rollbackSwap', $session);
        self::assertStringContainsString('deploy_staging_static_empty', $session);
        self::assertStringContainsString('WELINE_DEPLOY_STAGING_ROOT', $session);
        self::assertStringContainsString('removeEmptyStagingParent', $session);
        self::assertStringContainsString('forcesProductionPublicUrls', $session);
        $modeSet = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Console/Console/Deploy/Mode/Set.php',
        );
        // deactivate before purgePrev (env cleared before deleting staging dirs).
        self::assertMatchesRegularExpression(
            '/deactivate\(\);\s*\$session->purgePrevAndResidue\(\);/m',
            $modeSet,
        );
        self::assertStringContainsString('reloadPersistentWorkersAfterModeSet', $modeSet);
        self::assertStringContainsString('server:reload', $modeSet);
    }
}
