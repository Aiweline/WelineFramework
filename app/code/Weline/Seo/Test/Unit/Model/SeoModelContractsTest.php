<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Model;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSeoModelContractsPass().
 */
final class SeoModelContractsTest extends TestCase
{
    public function testAllSeoModelContractsPass(): void
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
            'WebsiteProtocolConfigProcessCache::testProtocolConfigOwnsProcessBagAndClearOnSave' => function () {
            $this->assert_WebsiteProtocolConfigProcessCache_testProtocolConfigOwnsProcessBagAndClearOnSave();
        },
            'WebsiteProtocolConfigProcessCache::testProcessCacheResetterIsRegistered' => function () {
            $this->assert_WebsiteProtocolConfigProcessCache_testProcessCacheResetterIsRegistered();
        },
        ];
    }

private function assert_WebsiteProtocolConfigProcessCache_testProtocolConfigOwnsProcessBagAndClearOnSave(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Model/WebsiteProtocolConfig.php');
    self::assertStringContainsString('$processRowsByWebsiteId', $src);
    self::assertStringContainsString('function clearProcessCache', $src);
    self::assertMatchesRegularExpression('/function\s+saveForWebsite[\s\S]*invalidateSharedCache/', $src);
    self::assertStringContainsString('ScopeSharedMemo::rememberScoped', $src);
    self::assertStringContainsString('ScopeIdentity::websiteById', $src);
}

private function assert_WebsiteProtocolConfigProcessCache_testProcessCacheResetterIsRegistered(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/etc/module.php';
    self::assertSame(\Weline\Seo\Api\Runtime\ProcessCacheResetter::class, $module['provides']['process_cache_resetter.Weline_Seo'] ?? null);
}
}
