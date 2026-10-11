<?php

declare(strict_types=1);

namespace Weline\EditorManager\test\Unit\EditorManager;

use PHPUnit\Framework\TestCase;

/**
 * 契约：Local 说明须标明「占位回退」，禁止再暗示为可用本地编辑器。
 */
class LocalPlaceholderDescriptionContractTest extends TestCase
{
    public function testLocalSourceClarifiesPlaceholderNotLocalInstalledEditor(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/EditorManager/Local.php');

        self::assertStringContainsString('占位回退', $source);
        self::assertStringContainsString('非可用富文本', $source);
        self::assertStringContainsString('不是本机已安装的本地编辑器', $source);
        self::assertStringNotContainsString('暂无标记器', $source);
    }
}
