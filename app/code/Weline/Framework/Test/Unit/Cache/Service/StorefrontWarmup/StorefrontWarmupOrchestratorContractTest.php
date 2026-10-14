<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache\Service\StorefrontWarmup;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cron\StorefrontWarmup;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupExcludeGate;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupOrchestrator;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupProgressLedger;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupSitemapParser;

final class StorefrontWarmupOrchestratorContractTest extends TestCase
{
    public function testOrchestratorAndCronLiveInFrameworkCacheDomain(): void
    {
        $orchestrator = new \ReflectionClass(StorefrontWarmupOrchestrator::class);
        self::assertStringContainsString(
            'Framework/Cache/Service/StorefrontWarmup',
            \str_replace('\\', '/', $orchestrator->getFileName() ?: ''),
        );

        $cron = new \ReflectionClass(StorefrontWarmup::class);
        self::assertStringContainsString(
            'Framework/Cron',
            \str_replace('\\', '/', $cron->getFileName() ?: ''),
        );
        self::assertStringNotContainsString(
            'Framework/Cache/Cron',
            \str_replace('\\', '/', $cron->getFileName() ?: ''),
        );
        $cronSource = (string)\file_get_contents($cron->getFileName() ?: '');
        self::assertStringContainsString("'* * * * *'", $cronSource);
        self::assertStringContainsString('function locked()', $cronSource);
        self::assertStringContainsString('return true;', $cronSource);
        self::assertStringNotContainsString('Weline\\Server\\', $cronSource);
        self::assertStringNotContainsString('WlsRuntime', $cronSource);
    }

    public function testPhaseOrderConstantsAreAThenBThenCThenD(): void
    {
        self::assertSame('A', StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE);
        self::assertSame('B', StorefrontWarmupProgressLedger::PHASE_B_SITEMAP_DOCS);
        self::assertSame('C', StorefrontWarmupProgressLedger::PHASE_C_EXTRAS);
        self::assertSame('D', StorefrontWarmupProgressLedger::PHASE_D_LOCS);
    }

    public function testExcludeGateBlacklistsSearchCartCheckoutAccount(): void
    {
        $gate = new StorefrontWarmupExcludeGate();
        foreach (['/search', '/cart', '/checkout', '/account', '/customer', '/helppay/x'] as $path) {
            $result = $gate->evaluate($path);
            self::assertFalse($result['allow'], $path);
            self::assertSame('blacklist', $result['reason'], $path);
        }
    }

    public function testSitemapParserExtractsIndexShardsAndHtmlLocs(): void
    {
        $parser = new StorefrontWarmupSitemapParser();
        $index = <<<'XML'
<?xml version="1.0"?>
<sitemapindex>
  <sitemap><loc>https://example.test/sitemap-products.xml</loc></sitemap>
  <sitemap><loc>https://example.test/sitemap-blog.xml</loc></sitemap>
</sitemapindex>
XML;
        $docs = $parser->extractSitemapDocUrls($index);
        self::assertContains('https://example.test/sitemap-products.xml', $docs);
        self::assertSame('/sitemap-products.xml', $parser->locToPath($docs[0], false));

        $urlset = <<<'XML'
<?xml version="1.0"?>
<urlset>
  <url><loc>https://example.test/guide/shipping</loc></url>
  <url><loc>https://example.test/sitemap-products.xml</loc></url>
  <url><loc>https://example.test/search?q=x</loc></url>
</urlset>
XML;
        $locs = $parser->extractPageLocs($urlset);
        self::assertContains('https://example.test/guide/shipping', $locs);
        self::assertSame('/guide/shipping', $parser->locToPath('https://example.test/guide/shipping', true));
        self::assertNull($parser->locToPath('https://example.test/sitemap-products.xml', true));
    }

    public function testClearUsesOrchestratorNotThemeFqcn(): void
    {
        $clear = \dirname(__DIR__, 5) . '/Cache/Console/Cache/Clear.php';
        $source = (string)\file_get_contents($clear);
        self::assertStringContainsString('StorefrontWarmupOrchestrator', $source);
        self::assertStringContainsString('runAfterClear', $source);
        self::assertStringNotContainsString('StorefrontFpcWarmer', $source);
        self::assertStringNotContainsString('WlsRuntime', $source);
    }

    public function testWarmupHttpClientAppendsPierceStamp(): void
    {
        $path = \dirname(__DIR__, 5) . '/Cache/Service/StorefrontWarmup/StorefrontWarmupHttpClient.php';
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('appendPierceQuery', $src);
        self::assertStringContainsString('FpcPierceStamp::buildQuerySuffix', $src);
        self::assertStringContainsString('Staging warm seeds hardlinks from live', $src);
    }

    public function testWarmupHttpClientNeedBodyForcesOriginAndRefusesHit(): void
    {
        $path = \dirname(__DIR__, 5) . '/Cache/Service/StorefrontWarmup/StorefrontWarmupHttpClient.php';
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('x-wls-fpc-bypass: 1', $src);
        self::assertStringContainsString('fpc_hit_refuse_body', $src);
        self::assertStringContainsString('isFpcHit', $src);
        self::assertStringContainsString('requestTarget', $src);
        // Visitor/live assert path must allow no-pierce / no-bypass body fetch.
        self::assertStringContainsString('Post-promote visitor assert', $src);
        self::assertStringContainsString('bool $pierce = true', $src);
        self::assertStringContainsString('bool $bypass = false', $src);
    }
}
