<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer;

final class ThemeLayoutEntityTemplateBindingTest extends TestCase
{
    public function testMaterializeReusesStructureAndRendersEachConfigurationSnapshot(): void
    {
        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/materializer-binding.php') . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($result['paths_isolated'], '不同 theme_version 必须路径隔离');
        self::assertFalse($result['same_path']);
        self::assertSame(1234567890, $result['mtime']);
        self::assertStringContainsString('old</div>', $result['old_html']);
        self::assertStringContainsString('new</div>', $result['new_html']);
        self::assertTrue($result['shell_exists']);
        self::assertTrue($result['structure_changed']);
        self::assertTrue($result['source_changed']);
    }

    public function testConfigurationAndReleaseIdentityDoNotChangeGeneratedTemplate(): void
    {
        require_once dirname(__DIR__, 3) . '/Service/SlotBoundaryMarkers.php';
        require_once dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityMaterializer.php';
        $materializer = (new \ReflectionClass(ThemeLayoutEntityMaterializer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($materializer, 'buildSlotPhtml');
        $nodes = ['content' => [['node_uid' => str_repeat('a', 32), 'config' => ['title' => 'old']]]];
        $old = $method->invoke($materializer, $nodes, 'page');
        $nodes['content'][0]['config']['title'] = 'new';
        $new = $method->invoke($materializer, $nodes, 'page');
        self::assertSame($old, $new, '发布与配置身份不能进入固化结构');
    }
}
