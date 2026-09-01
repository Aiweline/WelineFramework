<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\SystemConfig\Extends\Module\Weline_Framework\Query\SystemConfigQueryProvider;

/**
 * 后台业务页可对已声明字段做单字段写入：setScopedConfig 必须对 frontend worker 开放。
 */
final class SystemConfigSetScopedConfigFrontendContractTest extends TestCase
{
    public function testSetScopedConfigIsExposedToBackendFrontendWorker(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/SystemConfigQueryProvider.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertMatchesRegularExpression(
            "/'name'\\s*=>\\s*'setScopedConfig'[\\s\\S]*?'frontend'\\s*=>\\s*true/",
            $src,
        );
        self::assertMatchesRegularExpression(
            "/'name'\\s*=>\\s*'setScopedConfig'[\\s\\S]*?Weline_SystemConfig::config_center_save/",
            $src,
        );
        self::assertMatchesRegularExpression(
            "/'name'\\s*=>\\s*'setScopedConfig'[\\s\\S]*?'mode'\\s*=>\\s*'write'/",
            $src,
        );
        self::assertStringContainsString(
            'locale: array_key_exists(\'locale\', $params)',
            $src,
        );
        self::assertStringContainsString(
            'SystemConfig::LOCALE_DEFAULT',
            $src,
        );
        self::assertMatchesRegularExpression(
            "/function commonWriteParams[\\s\\S]*?'name'\\s*=>\\s*'value_type'/",
            $src,
        );
    }
}
