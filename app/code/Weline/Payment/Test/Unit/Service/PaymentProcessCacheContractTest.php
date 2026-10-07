<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class PaymentProcessCacheContractTest extends TestCase
{
    public function testMethodManagerOwnsProcessBagAndClear(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentMethodManager.php');
        self::assertStringContainsString('$processMethodRows', $src);
        self::assertStringContainsString('function clearProcessCache', $src);
        self::assertStringContainsString('loadAllMethodRows', $src);
        self::assertStringContainsString('PaymentScopeConfigService::clearProcessCache()', $src);
        self::assertStringContainsString('ScopeSharedMemo::rememberScoped', $src);
        self::assertStringContainsString('ScopeIdentity::global()', $src);
        self::assertStringContainsString('payment.methods.public', $src);
        self::assertStringContainsString('unset($row[PaymentMethod::schema_fields_CONFIG])', $src);
    }

    public function testScopeConfigServiceOwnsOverrideProcessBag(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentScopeConfigService.php');
        self::assertStringContainsString('$processOverridesByScopeEnv', $src);
        self::assertStringContainsString('$processSystemConfigMaps', $src);
        self::assertStringContainsString('function clearProcessCache', $src);
        self::assertStringContainsString('ScopeSharedMemo::rememberScoped', $src);
        self::assertStringContainsString('scopeIdentity(', $src);
    }

    public function testProcessCacheResetterIsRegistered(): void
    {
        $module = require dirname(__DIR__, 3) . '/etc/module.php';
        self::assertSame(
            \Weline\Payment\Api\Runtime\ProcessCacheResetter::class,
            $module['provides']['process_cache_resetter.Weline_Payment'] ?? null,
        );
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Api/Runtime/ProcessCacheResetter.php');
        self::assertStringContainsString('PaymentMethodManager::clearProcessCache()', $src);
    }
}
