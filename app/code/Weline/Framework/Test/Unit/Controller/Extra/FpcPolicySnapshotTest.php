<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Controller\Extra;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Controller\Extra\FpcPolicySnapshot;
use Weline\Framework\Runtime\ScopeIdentity;

final class FpcPolicySnapshotTest extends TestCase
{
    public function testIndependentInheritanceAndModeIsolation(): void
    {
        $scope = ScopeIdentity::channel(0, 'default', 'main', 'web', 'normal');
        $store = ScopeIdentity::store(0, 'default', 'main', 'normal');
        $snapshot = $this->snapshot();
        $snapshot['overrides']['normal'][$scope->canonicalKey()]['a'] = ['enabled' => null, 'ttl' => 30];
        $snapshot['overrides']['normal'][$store->canonicalKey()]['a'] = ['enabled' => false, 'ttl' => 90];
        $result = FpcPolicySnapshot::resolve($snapshot, '/products/coat', $scope);
        self::assertFalse($result['enabled']);
        self::assertSame(30, $result['ttl']);
        self::assertSame($store->canonicalKey(), $result['enabled_source']);
        $test = FpcPolicySnapshot::resolve($snapshot, '/products/coat', ScopeIdentity::channel(0, 'default', 'main', 'web', 'test'));
        self::assertTrue($test['enabled']);
        self::assertSame(600, $test['ttl']);
    }

    public function testCodeProhibitionAndConservativeOverlap(): void
    {
        $scope = ScopeIdentity::channel(0, 'default', 'main', 'web', 'normal');
        $snapshot = $this->snapshot();
        $snapshot['declarations']['b'] = $snapshot['declarations']['a'];
        $snapshot['declarations']['b']['declaration_id'] = 'b';
        $snapshot['declarations']['b']['attrs']['enabled'] = false;
        $snapshot['overrides']['normal'][$scope->canonicalKey()]['b'] = ['enabled' => true, 'ttl' => 90];
        self::assertFalse(FpcPolicySnapshot::resolve($snapshot, '/products/coat', $scope)['enabled']);
        self::assertNull(FpcPolicySnapshot::resolve($snapshot, '/unlisted', $scope));
        $snapshot['declarations']['c'] = $snapshot['declarations']['a'];
        $snapshot['declarations']['c']['declaration_id'] = 'c';
        $snapshot['declarations']['c']['path_pattern'] = '/products/coat';
        self::assertSame('c', FpcPolicySnapshot::resolve($snapshot, '/products/coat', $scope)['declaration_id']);
    }

    public function testFingerprintIgnoresOtherScopesButTracksEffectivePolicy(): void
    {
        $scope = ScopeIdentity::channel(0, 'default', 'main', 'web', 'normal');
        $snapshot = $this->snapshot();
        $before = FpcPolicySnapshot::resolve($snapshot, '/products/coat', $scope)['policy_fingerprint'];
        $snapshot['source_version'] = 2;
        $snapshot['revision'] = 'different';
        $snapshot['overrides']['test'][$scope->canonicalKey()]['a'] = ['ttl' => 1];
        self::assertSame($before, FpcPolicySnapshot::resolve($snapshot, '/products/coat', $scope)['policy_fingerprint']);
        $snapshot['overrides']['normal'][$scope->canonicalKey()]['a'] = ['ttl' => 5];
        self::assertNotSame($before, FpcPolicySnapshot::resolve($snapshot, '/products/coat', $scope)['policy_fingerprint']);
    }

    public function testExplicitModeIsolatesGlobalAndWebsiteInheritanceAndFingerprint(): void
    {
        foreach ([ScopeIdentity::global(), ScopeIdentity::website(0, 'default')] as $scope) {
            $snapshot = $this->snapshot();
            $key = $scope->canonicalKey();
            $snapshot['overrides']['normal'][$key]['a'] = ['enabled' => false, 'ttl' => 90];
            $snapshot['overrides']['test'][$key]['a'] = ['enabled' => true, 'ttl' => 17];
            $normal = FpcPolicySnapshot::resolve($snapshot, '/products/coat', $scope);
            $test = FpcPolicySnapshot::resolve($snapshot, '/products/coat', $scope, 'test');
            self::assertFalse($normal['enabled']);
            self::assertSame(90, $normal['ttl']);
            self::assertTrue($test['enabled']);
            self::assertSame(17, $test['ttl']);
            $snapshot['overrides']['test'][$key]['a'] = $snapshot['overrides']['normal'][$key]['a'];
            self::assertNotSame($normal['policy_fingerprint'],
                FpcPolicySnapshot::resolve($snapshot, '/products/coat', $scope, 'test')['policy_fingerprint']);
        }
        $scope = ScopeIdentity::channel(0, 'default', 'main', 'web', 'normal');
        $snapshot = $this->snapshot();
        self::assertSame(FpcPolicySnapshot::resolve($snapshot, '/products/coat', $scope),
            FpcPolicySnapshot::resolve($snapshot, '/products/coat', $scope, 'test'));
    }

    public function testPublicPathNormalization(): void
    {
        self::assertSame('/products/coat', FpcPolicySnapshot::normalizePath('https://shop.test/store/en_US/USD/products/coat?q=1', 'https://shop.test/store/'));
    }

    private function snapshot(): array
    {
        return ['schema_version' => 'fpc-policy-snapshot.v1', 'revision' => 'one', 'source_version' => 1,
            'declarations' => ['a' => ['declaration_id' => 'a', 'path_pattern' => '/products/*', 'public_path_patterns' => [],
                'attrs' => ['enabled' => true, 'ttl' => 600], 'namespaces' => ['website/default/catalog']]],
            'overrides' => [], 'scope_chains' => []];
    }
}
