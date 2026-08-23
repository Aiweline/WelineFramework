<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Compilation\ModuleRegistryCompiler;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\SystemConfig\Service\GlobalOnlyScopeIdentityCatalog;

final class GlobalOnlyScopeIdentityCatalogTest extends TestCase
{
    /** @var list<string> */
    private array $registryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->registryFiles as $file) {
            @\unlink($file);
        }
    }

    public function testKeepsGlobalOnlyBehaviorWithoutContributor(): void
    {
        $catalog = new GlobalOnlyScopeIdentityCatalog($this->registry([]));

        self::assertSame([], $catalog->options());
        self::assertTrue($catalog->authoritativeIdentity(ScopeIdentity::global())->isGlobal());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('system_config_website_scope_not_available');
        $catalog->websiteIdForCode('default');
    }

    public function testDelegatesToSingleAuthoritativeContributor(): void
    {
        $catalog = new GlobalOnlyScopeIdentityCatalog($this->registry([
            ScopeIdentityCatalogInterface::CONTRIBUTOR_CAPABILITY_PREFIX . 'Test'
                => ProviderRegistryTestScopeIdentityCatalog::class,
        ]));

        self::assertSame(73, $catalog->websiteIdForCode('shop'));
        self::assertSame([['code' => 'shop']], $catalog->options());
        self::assertSame(
            'shop',
            $catalog->authoritativeIdentity(ScopeIdentity::website(73, 'shop'))->websiteCode,
        );
    }

    public function testRejectsAmbiguousContributors(): void
    {
        $catalog = new GlobalOnlyScopeIdentityCatalog($this->registry([
            ScopeIdentityCatalogInterface::CONTRIBUTOR_CAPABILITY_PREFIX . 'One'
                => ProviderRegistryTestScopeIdentityCatalog::class,
            ScopeIdentityCatalogInterface::CONTRIBUTOR_CAPABILITY_PREFIX . 'Two'
                => SecondProviderRegistryTestScopeIdentityCatalog::class,
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('system_config_scope_identity_catalog_contributor_ambiguous');
        $catalog->options();
    }

    public function testRejectsInvalidContributorType(): void
    {
        $catalog = new GlobalOnlyScopeIdentityCatalog($this->registry([
            ScopeIdentityCatalogInterface::CONTRIBUTOR_CAPABILITY_PREFIX . 'Invalid'
                => \stdClass::class,
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('system_config_scope_identity_catalog_contributor_invalid');
        $catalog->options();
    }

    /** @param array<string, class-string> $providers */
    private function registry(array $providers): ServiceProviderRegistry
    {
        $file = \tempnam(\sys_get_temp_dir(), 'weline-scope-provider-');
        if ($file === false) {
            self::fail('Unable to allocate temporary provider registry.');
        }
        $this->registryFiles[] = $file;
        \file_put_contents($file, '<?php return ' . \var_export([
            'format' => ModuleRegistryCompiler::FORMAT_VERSION,
            'order' => ['Test_Module'],
            'modules' => [
                'Test_Module' => ['provides' => $providers],
            ],
        ], true) . ';');

        return new ServiceProviderRegistry($file);
    }
}

class ProviderRegistryTestScopeIdentityCatalog implements ScopeIdentityCatalogInterface
{
    public function websiteIdForCode(string $websiteCode): int
    {
        return 73;
    }

    public function authoritativeIdentity(ScopeIdentity $candidate): ScopeIdentity
    {
        return $candidate;
    }

    public function options(): array
    {
        return [['code' => 'shop']];
    }
}

final class SecondProviderRegistryTestScopeIdentityCatalog extends ProviderRegistryTestScopeIdentityCatalog
{
}
