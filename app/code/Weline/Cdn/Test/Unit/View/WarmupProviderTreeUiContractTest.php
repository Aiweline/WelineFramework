<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class WarmupProviderTreeUiContractTest extends TestCase
{
    public function testIndexTemplateHasScopeProviderAndQueuedUrlSections(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Warmup/index.phtml';
        $src = (string)file_get_contents($path);
        $this->assertStringContainsString('cdn-warmup-management', $src);
        $this->assertStringContainsString('cdn-warmup-scope', $src);
        $this->assertStringContainsString('<w:scope', $src);
        $this->assertStringContainsString('name="target_scope"', $src);
        $this->assertStringContainsString('scope="global,website"', $src);
        $this->assertStringContainsString('target_scope', $src);
        $this->assertStringNotContainsString('<select id="cdn-warmup-scope"', $src);
        $this->assertStringNotContainsString('全部范围（全局）', $src);
        $this->assertStringContainsString('已入队 URL', $src);
        $this->assertStringContainsString('listWarmupProviders', $src);
        $this->assertStringContainsString('listWarmupUrls', $src);
        $this->assertStringContainsString('collectWarmup', $src);
        $this->assertStringContainsString('executeWarmup', $src);
        $this->assertStringContainsString('未处理任何 URL', $src);
        $this->assertStringContainsString('processed === 0', $src);
        $this->assertStringContainsString('写入 %{1} · 范围未命中过滤 %{2} · 去重跳过 %{3}', $src);
        $this->assertStringContainsString('执行本范围预热', $src);
        $this->assertStringContainsString('本 Provider×本范围暂无已入队 URL，请先收集', $src);
        $this->assertStringContainsString('范围使用官方作用范围标签', $src);
        $this->assertStringContainsString('goto-page', $src);
        $this->assertStringContainsString('w-pagination', $src);
        $this->assertStringContainsString('data-cdn-warmup-pager', $src);
        $this->assertStringNotContainsString('加载更多', $src);
        $this->assertStringNotContainsString('load-more', $src);
        $this->assertStringContainsString('confirmAction', $src);
        $this->assertStringContainsString('dialog.confirm', $src);
        $this->assertStringContainsString('UI.toast', $src);
        $this->assertStringNotContainsString('window.confirm(', $src);
        $this->assertStringNotContainsString('window.alert(', $src);
    }
}
