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


    public function testPublishedLayoutUsesInstalledIdentityWhenNoIdentityIsSupplied(): void
    {
        $installed = new \Weline\Theme\Api\Layout\LayoutIdentity(
            'compact', 'shop.main.app~test', 'product', 123, 'fr_FR',
        );

        // Structure identity keeps placement/target but drops request locale.
        self::assertSame([
            'layout_option' => 'compact',
            'scope' => 'shop.main.app~test',
            'target_type' => 'product',
            'target_id' => 123,
            'locale_code' => '',
        ], $this->readPublishedIdentity($installed));
    }

    public function testPublishedLayoutExplicitIdentityKeepsItsExistingPrecedence(): void
    {
        $installed = new \Weline\Theme\Api\Layout\LayoutIdentity(
            'compact', 'shop.main.app~test', 'product', 123, 'fr_FR',
        );

        self::assertSame([
            'layout_option' => 'wide',
            'scope' => 'another.store.web~test',
            'target_type' => 'category',
            'target_id' => 456,
            'locale_code' => '',
        ], $this->readPublishedIdentity($installed, [
            'layout_option' => 'wide',
            'scope' => 'another.store.web',
            'store_mode' => ScopeIdentity::MODE_TEST,
            'target_type' => 'category',
            'target_id' => 456,
            'locale' => 'en-US',
        ]));
    }

    public function testPublishedLayoutWithoutInstalledIdentityKeepsDefaultScope(): void
    {
        self::assertSame([
            'layout_option' => 'default',
            'scope' => 'default.default.default',
            'target_type' => 'global',
            'target_id' => 0,
            'locale_code' => '',
        ], $this->readPublishedIdentity(null));
    }

    private function readPublishedIdentity(
        ?\Weline\Theme\Api\Layout\LayoutIdentity $installed,
        array $explicit = [],
    ): array {
        \Weline\Framework\Context::enter(new \Weline\Framework\Context());
        \Weline\Framework\Runtime\RequestContext::setId('layout-identity-test-' . hrtime(true));
        if ($installed !== null) {
            \Weline\Framework\Runtime\RequestContext::set(
                \Weline\Theme\Api\Layout\LayoutIdentity::REQUEST_CONTEXT_KEY,
                $installed,
            );
        }

        // 用真实运行时身份归一化读取边界；替代发布快照的数据库依赖。
        $runtime = (new \ReflectionClass(\Weline\Theme\Service\ThemeRuntimeLayoutResolver::class))
            ->newInstanceWithoutConstructor();
        $normalizer = $this->normalizer;
        $reader = new class($runtime, $normalizer) {
            public function __construct(
                private readonly \Weline\Theme\Service\ThemeRuntimeLayoutResolver $runtime,
                private readonly ThemeLayoutScopeNormalizer $normalizer,
            ) {
            }

            public function resolveLayout(
                int $themeId,
                string $pageType,
                string $status,
                string $area,
                array $identity,
            ): array {
                $read = new \ReflectionMethod($this->runtime, 'normalizeIdentity');
                $resolved = $this->normalizer->normalize($read->invoke($this->runtime, $identity));

                return ['identity' => array_intersect_key($resolved, array_flip([
                    'layout_option', 'scope', 'target_type', 'target_id', 'locale_code',
                ]))];
            }
        };
        \Weline\Framework\Manager\ObjectManager::setInstance(
            \Weline\Theme\Service\ThemeRuntimeLayoutResolver::class,
            $reader,
        );
        $service = (new \ReflectionClass(\Weline\Theme\Service\ThemeLayoutService::class))
            ->newInstanceWithoutConstructor();
        (new \ReflectionProperty($service, 'scopeNormalizer'))->setValue($service, $normalizer);

        try {
            return $service->getPublishedLayout(
                1,
                \Weline\Theme\Model\ThemeLayout::PAGE_TYPE_PRODUCT_LIST,
                $explicit,
            )['identity'];
        } finally {
            \Weline\Framework\Manager\ObjectManager::clearCurrentRequestScope();
            \Weline\Framework\Context::leave();
        }
    }
}
