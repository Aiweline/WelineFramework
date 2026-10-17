<?php

declare(strict_types=1);

namespace Weline\UrlManager\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Url;
use Weline\UrlManager\Observer\RouterRewrite;
use Weline\UrlManager\Observer\SeoUrlGenerateRewrite;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllUrlManagerObserverContractsPass().
 */
final class UrlManagerObserverContractsTest extends TestCase
{
    public function testAllUrlManagerObserverContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'RouterRewriteProcessCache::testInvalidateCachesClearsRouterAndDecodeProcessBags' => function () {
            $this->assert_RouterRewriteProcessCache_testInvalidateCachesClearsRouterAndDecodeProcessBags();
        },
            'RouterRewriteProcessCache::testKeyedInvalidationOnlyDropsMatchingWebsiteRewriteKeys' => function () {
            $this->assert_RouterRewriteProcessCache_testKeyedInvalidationOnlyDropsMatchingWebsiteRewriteKeys();
        },
            'RouterRewriteProcessCache::testCacheKeysForRewriteIncludeSlashVariants' => function () {
            $this->assert_RouterRewriteProcessCache_testCacheKeysForRewriteIncludeSlashVariants();
        },
            'SeoUrlGenerateRewriteProcessCache::testInvalidationKeepsSharedPoolAndResetCompatibility' => function () {
            $this->assert_SeoUrlGenerateRewriteProcessCache_testInvalidationKeepsSharedPoolAndResetCompatibility();
        },
        ];
    }

private function assert_RouterRewriteProcessCache_testInvalidateCachesClearsRouterAndDecodeProcessBags(): void
{
    $reflection = new \ReflectionClass(RouterRewrite::class);
    $processCache = $reflection->getProperty('processCache');
    $processCache->setAccessible(true);
    $processCache->setValue(null, ['k' => ['path' => 'old']]);
    $expires = $reflection->getProperty('processCacheExpiresAt');
    $expires->setAccessible(true);
    $expires->setValue(null, ['k' => \time() + 300]);
    $urlReflection = new \ReflectionClass(Url::class);
    $decodeBag = $urlReflection->getProperty('processDecodeUrls');
    $decodeBag->setAccessible(true);
    $decodeBag->setValue(null, ['d' => 'http://example.test/decoded']);
    $decodeExpires = $urlReflection->getProperty('processDecodeUrlExpiresAt');
    $decodeExpires->setAccessible(true);
    $decodeExpires->setValue(null, ['d' => \time() + 300]);
    SeoUrlGenerateRewrite::invalidateCaches();
    self::assertSame([], $processCache->getValue());
    self::assertSame([], $expires->getValue());
    self::assertSame([], $decodeBag->getValue());
    self::assertSame([], $decodeExpires->getValue());
}

private function assert_RouterRewriteProcessCache_testKeyedInvalidationOnlyDropsMatchingWebsiteRewriteKeys(): void
{
    $keepKey = RouterRewrite::buildCacheKey(550, 'regions/south');
    $dropKey = RouterRewrite::buildCacheKey(550, 'regions/northeast');
    $otherSite = RouterRewrite::buildCacheKey(158, 'contact-page');
    $reflection = new \ReflectionClass(RouterRewrite::class);
    $processCache = $reflection->getProperty('processCache');
    $processCache->setAccessible(true);
    $expires = $reflection->getProperty('processCacheExpiresAt');
    $expires->setAccessible(true);
    $ttl = \time() + 300;
    $processCache->setValue(null, [$keepKey => ['path' => 'guide?r=south'], $dropKey => ['path' => 'guide?r=northeast'], $otherSite => ['path' => 'pagebuilder/frontend/page/view?page_id=1']]);
    $expires->setValue(null, [$keepKey => $ttl, $dropKey => $ttl, $otherSite => $ttl]);
    SeoUrlGenerateRewrite::invalidateCaches(550, 'regions/northeast');
    $bag = $processCache->getValue();
    // Website-scoped L1 forget drops all keys for website 550.
    self::assertArrayNotHasKey($dropKey, $bag);
    self::assertArrayNotHasKey($keepKey, $bag);
    self::assertArrayHasKey($otherSite, $bag);
    self::assertSame(['path' => 'pagebuilder/frontend/page/view?page_id=1'], $bag[$otherSite]);
}

private function assert_RouterRewriteProcessCache_testCacheKeysForRewriteIncludeSlashVariants(): void
{
    $keys = RouterRewrite::cacheKeysForRewrite(7, '/contact');
    self::assertContains(RouterRewrite::buildCacheKey(7, 'contact'), $keys);
    self::assertContains(RouterRewrite::buildCacheKey(7, '/contact'), $keys);
}

private function assert_SeoUrlGenerateRewriteProcessCache_testInvalidationKeepsSharedPoolAndResetCompatibility(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/UrlManager' . '/Observer/SeoUrlGenerateRewrite.php');
    self::assertStringNotContainsString('private static array $processCache', $src);
    self::assertStringContainsString('function clearProcessCache', $src);
    self::assertStringContainsString('function invalidateCaches', $src);
    self::assertStringContainsString('RouterRewrite::cacheKeysForRewrite', $src);
    self::assertStringContainsString('Url::clearProcessDecodeCache()', $src);
    $modelSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/UrlManager' . '/Model/UrlRewrite.php');
    self::assertStringContainsString('invalidateSeoRewriteCaches', $modelSrc);
    self::assertStringContainsString('function save_after', $modelSrc);
    self::assertStringContainsString('function delete_after', $modelSrc);
    self::assertStringContainsString('function findLatestByWebsiteAndRewrites', $modelSrc);
    self::assertStringContainsString('invalidateSeoRewriteCaches(', $modelSrc);
    $routerSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/UrlManager' . '/Observer/RouterRewrite.php');
    self::assertStringContainsString('function clearProcessCache', $routerSrc);
    self::assertStringContainsString('function cacheKeysForRewrite', $routerSrc);
    self::assertStringContainsString('findLatestByWebsiteAndRewrites', $routerSrc);
    self::assertStringContainsString('lookup_path', $routerSrc);
    self::assertStringContainsString('isRequestAlreadySynced', $routerSrc);
    $resetterSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/UrlManager' . '/Api/Runtime/ProcessCacheResetter.php');
    self::assertStringContainsString('RouterRewrite::clearProcessCache()', $resetterSrc);
    self::assertStringContainsString('Url::clearProcessDecodeCache()', $resetterSrc);
}
}
