<?php

declare(strict_types=1);

namespace Weline\Ops\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class OpsProgramDefaultOffContractTest extends TestCase
{
    public function test_new_program_defaults_to_disabled(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/OpsProgramService.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('schema_fields_ENABLED, 0', $src);
        self::assertStringContainsString('新建程序必须关闭', $src);
        self::assertStringContainsString('function isEnabled', $src);
    }

    public function test_model_column_default_is_zero(): void
    {
        $path = dirname(__DIR__, 3) . '/Model/OpsWebsiteProgram.php';
        $src = (string)file_get_contents($path);
        self::assertMatchesRegularExpression(
            "/#\\[Col\\([^\\]]*default:\\s*0[^\\]]*是否启用[^\\]]*\\)\\][\\s\\S]{0,80}schema_fields_ENABLED\\s*=\\s*'enabled'/u",
            $src
        );
    }

    public function test_site_setup_assistant_provider_guides_manual_enable(): void
    {
        $provider = dirname(__DIR__, 3) . '/extends/module/Weline_SiteSetupAssistant/SetupTask/OpsSetupTaskProvider.php';
        self::assertFileExists($provider);
        $src = (string)file_get_contents($provider);
        self::assertStringContainsString('extends AbstractSetupTaskProvider', $src);
        self::assertStringContainsString('ops_ai_program_enable', $src);
        self::assertStringContainsString('ops_ai_program_policy', $src);
        self::assertStringContainsString('default_off', $src);
        self::assertStringContainsString('手动开启 AI运营', $src);
        self::assertStringContainsString('weline_ops/backend/desk/index', $src);

        $extends = (string)file_get_contents(dirname(__DIR__, 3) . '/extends.php');
        self::assertStringContainsString('SetupTaskProviderInterface::class', $extends);
        self::assertStringContainsString('OpsSetupTaskProvider::class', $extends);
    }
}
