<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\Theme\Service\ThemeLayoutScopeNormalizer;
use Weline\Theme\Service\ThemeRuntimeLayoutResolver;

final class ThemeRuntimeLayoutStoreModeTest extends TestCase
{
    public function testExplicitStoreModeReachesAuthoritativeCatalog(): void
    {
        foreach (['test', 'dev'] as $mode) {
            $scopes = new SystemConfigScopeResolver();
            $catalog = $this->createMock(ScopeIdentityCatalogInterface::class);
            $catalog->expects(self::once())->method('authoritativeIdentity')
                ->willReturnCallback(function (ScopeIdentity $identity) use ($mode): ScopeIdentity {
                    self::assertSame($mode, $identity->storeMode);
                    return $identity;
                });
            $runtime = (new \ReflectionClass(ThemeRuntimeLayoutResolver::class))->newInstanceWithoutConstructor();
            foreach (['scopeNormalizer' => new ThemeLayoutScopeNormalizer($scopes), 'scopes' => $scopes, 'catalog' => $catalog] as $field => $value) {
                (new \ReflectionProperty($runtime, $field))->setValue($runtime, $value);
            }
            $context = $runtime->buildContext(1, 'account/login', 'frontend', ['scope' => 'shop.main.app', 'store_mode' => $mode]);
            self::assertSame($mode, $context->scope->storeMode);
            self::assertSame('shop.main.app', $context->scope->storageScope);
        }
    }
}
