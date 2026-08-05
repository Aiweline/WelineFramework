<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\Theme\Service\ThemeLayoutScopeNormalizer;

/**
 * TEST-P1C-03：Theme typed Scope + store_mode 草稿隔离。
 */
final class ThemeLayoutScopeNormalizerTest extends TestCase
{
    private ThemeLayoutScopeNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ThemeLayoutScopeNormalizer(new SystemConfigScopeResolver());
    }

    public function testShortDefaultUpgradesToThreeSegment(): void
    {
        $id = $this->normalizer->normalize(['scope' => 'default']);
        self::assertSame('default.default.default', $id['storage_scope']);
        self::assertSame('default.default.default', $id['scope']);
        self::assertSame(ScopeIdentity::MODE_NORMAL, $id['store_mode']);
    }

    public function testTestModeEncodedSeparatelyFromNormal(): void
    {
        $normal = $this->normalizer->normalize([
            'scope' => 'shop.main.default',
            'store_mode' => ScopeIdentity::MODE_NORMAL,
        ]);
        $test = $this->normalizer->normalize([
            'scope' => 'shop.main.default',
            'store_mode' => ScopeIdentity::MODE_TEST,
        ]);

        self::assertSame('shop.main.default', $normal['scope']);
        self::assertSame('shop.main.default~test', $test['scope']);
        self::assertNotSame($normal['scope'], $test['scope']);
    }

    public function testNormalIdentityDoesNotSeeTestEncodedScope(): void
    {
        $normalCandidates = $this->normalizer->readCandidateScopes('shop.main.default');
        self::assertNotContains('shop.main.default~test', $normalCandidates);
        self::assertContains('shop.main.default', $normalCandidates);
    }

    public function testDecodeRoundTripKeepsMode(): void
    {
        $encoded = $this->normalizer->encodeStorageScope('shop.main.app', ScopeIdentity::MODE_TEST);
        $decoded = $this->normalizer->decodeStorageScope($encoded);
        self::assertSame('shop.main.app', $decoded['storage_scope']);
        self::assertSame(ScopeIdentity::MODE_TEST, $decoded['store_mode']);
    }
}
