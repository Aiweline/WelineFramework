<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Service\NotificationOriginContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

class NotificationOriginContextTest extends TestCase
{
    public function testEnrichAppendsOriginFooterOnce(): void
    {
        $svc = new NotificationOriginContext();
        $first = $svc->enrich([
            'title' => '标题',
            'content' => '正文',
            'topic' => 'system_info',
        ]);

        $this->assertTrue($first['metadata']['origin']['enriched'] ?? false);
        $this->assertStringContainsString(NotificationOriginContext::FOOTER_MARKER, $first['content']);
        $this->assertStringContainsString('运行模式', $first['content']);
        $this->assertStringContainsString('项目路径', $first['content']);
        $this->assertNotSame('', trim((string)($first['metadata']['origin']['project_root'] ?? '')));
        $this->assertSame(Runtime::getMode(), $first['metadata']['origin']['runtime_mode']);
        $this->assertContains(
            $first['metadata']['origin']['area'],
            ['cli', 'frontend', 'backend', 'rest_frontend', 'rest_backend', 'none'],
        );

        $second = $svc->enrich($first);
        $this->assertSame(1, substr_count($second['content'], NotificationOriginContext::FOOTER_MARKER));
    }

    public function testCaptureIncludesCliModeWhenRunningUnderCli(): void
    {
        if (!Runtime::isCli() && Runtime::getMode() !== RuntimeInterface::MODE_CLI) {
            $this->markTestSkipped('requires CLI-like runtime');
        }
        $origin = (new NotificationOriginContext())->capture();
        $this->assertSame(RuntimeInterface::MODE_CLI, $origin['runtime_mode']);
        $this->assertSame('cli', $origin['area']);
        $this->assertNotSame('', $origin['sapi']);
    }
}
