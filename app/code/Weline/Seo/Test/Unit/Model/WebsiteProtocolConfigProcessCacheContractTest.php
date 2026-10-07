<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Model;

use PHPUnit\Framework\TestCase;

final class WebsiteProtocolConfigProcessCacheContractTest extends TestCase
{
    public function testProtocolConfigOwnsProcessBagAndClearOnSave(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/WebsiteProtocolConfig.php');
        self::assertStringContainsString('$processRowsByWebsiteId', $src);
        self::assertStringContainsString('function clearProcessCache', $src);
        self::assertMatchesRegularExpression(
            '/function\s+saveForWebsite[\s\S]*invalidateSharedCache/',
            $src,
        );
        self::assertStringContainsString('ScopeSharedMemo::rememberScoped', $src);
        self::assertStringContainsString('ScopeIdentity::websiteById', $src);
    }

    public function testProcessCacheResetterIsRegistered(): void
    {
        $module = require dirname(__DIR__, 3) . '/etc/module.php';
        self::assertSame(
            \Weline\Seo\Api\Runtime\ProcessCacheResetter::class,
            $module['provides']['process_cache_resetter.Weline_Seo'] ?? null,
        );
    }
}
