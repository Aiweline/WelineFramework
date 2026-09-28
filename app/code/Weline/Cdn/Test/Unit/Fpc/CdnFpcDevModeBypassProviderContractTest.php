<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Fpc;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Extends\Module\Weline_Framework\Fpc\Bypass\CdnFpcDevModeBypassProvider;

final class CdnFpcDevModeBypassProviderContractTest extends TestCase
{
    public function testProviderDeclaresCdnFpcDevModeEnvFlag(): void
    {
        $rules = (new CdnFpcDevModeBypassProvider())->rules();
        self::assertCount(1, $rules);
        self::assertSame('cdn.fpc_dev_mode', $rules[0]['id']);
        self::assertSame(['cdn_fpc_dev_mode'], $rules[0]['match']['env_flags']);
        self::assertSame('bypass_serve_and_publish', $rules[0]['effect']);
    }

    public function testSystemConfigTemplateDeclaresScopedDevModeKey(): void
    {
        $path = \dirname(__DIR__, 3) . '/extends/module/Weline_SystemConfig/Config/backend/cdn-fpc-dev-mode.phtml';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('cdn/fpc/dev_mode', $src);
        self::assertStringContainsString('website,store,channel', $src);
        self::assertStringContainsString('@Extra type=fpc', $src);
    }

    public function testModuleProvidesFlagResolverInterface(): void
    {
        $module = require \dirname(__DIR__, 3) . '/etc/module.php';
        self::assertArrayHasKey(
            \Weline\Framework\Http\Fpc\CdnFpcDevModeFlagResolverInterface::class,
            $module['provides']
        );
    }
}
