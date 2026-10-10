<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Cache\TemplateCachePolicyCompiler;
use Weline\Framework\View\Cache\TemplateFragmentOutputCache;

final class TemplateFragmentOutputCacheTtlContractTest extends TestCase
{
    public function testCompilerAcceptsPerFileTtlAndFragmentBagRoundTrips(): void
    {
        $compiler = (string)file_get_contents(
            dirname(__DIR__, 4) . '/View/Cache/TemplateCachePolicyCompiler.php',
        );
        self::assertStringContainsString("array_key_exists('ttl'", $compiler);
        self::assertStringContainsString('invalid ttl', $compiler);

        TemplateFragmentOutputCache::resetProcessCaches();
        self::assertSame(300, TemplateFragmentOutputCache::resolveTtl(['ttl' => 300], 60));
        self::assertSame(60, TemplateFragmentOutputCache::resolveTtl([], 60));

        $key = 'test.widget.fragment.' . uniqid('', true);
        self::assertTrue(TemplateFragmentOutputCache::set($key, '<div data-testid="ok">x</div>', 30));
        $hit = TemplateFragmentOutputCache::get($key, 30);
        self::assertSame('fresh', $hit['status']);
        self::assertStringContainsString('data-testid="ok"', (string)$hit['html']);
        TemplateFragmentOutputCache::resetProcessCaches();
    }

    public function testHookFetchReadsDescriptorTtl(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 4) . '/View/Template.php');
        self::assertStringContainsString('TemplateFragmentOutputCache::resolveTtl', $template);
    }
}
