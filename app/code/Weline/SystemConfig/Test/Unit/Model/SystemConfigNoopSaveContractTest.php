<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Model;

use PHPUnit\Framework\TestCase;

/** Source contract: unchanged values must not create a version batch. */
final class SystemConfigNoopSaveContractTest extends TestCase
{
    public function testSaveScopeConfigSkipsUnchangedWrites(): void
    {
        $path = dirname(__DIR__, 3) . '/Model/SystemConfig.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('isUnchangedConfigWrite', $source);
        self::assertStringContainsString("'status' => 'noop'", $source);
        self::assertStringContainsString('没有检测到配置变更，未创建新版本。', $source);
        self::assertStringContainsString('Already inheriting (no override row)', $source);
    }

    public function testControllerWarnsOnNoop(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Backend/Config.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString("=== 'noop'", $source);
        self::assertStringContainsString('addWarning', $source);
    }

    public function testEmbedHandlesNoopStatus(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/config-embed.js';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString("data.status === 'noop'", $source);
    }
}
