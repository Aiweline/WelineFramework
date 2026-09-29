<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\WarmupProvider;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\WarmupProvider\FpcExtraDeclaredUrls;

final class FpcExtraDeclaredUrlsContractTest extends TestCase
{
    public function testSkipsProductPatternsInSource(): void
    {
        $path = dirname(__DIR__, 3) . '/WarmupProvider/FpcExtraDeclaredUrls.php';
        $src = (string)file_get_contents($path);
        $this->assertStringContainsString('isProductPattern', $src);
        $this->assertStringContainsString('/product', $src);
        $this->assertStringContainsString('BlogSitemapUrlProvider', $src);
        $this->assertStringContainsString('FaqSitemapUrlProvider', $src);
        $this->assertStringContainsString('WarmupLocaleUrlExpander', $src);
        $this->assertStringContainsString('SOURCE_MODULE', $src);
        $this->assertStringContainsString('覆盖站点已启用语种', $src);
    }

    public function testExecuteMethodExists(): void
    {
        $this->assertTrue(method_exists(FpcExtraDeclaredUrls::class, 'execute'));
        $this->assertSame('静态文档（FPC 公开页）', FpcExtraDeclaredUrls::UI_LABEL);
    }
}
