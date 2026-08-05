<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\I18n\Api\Scope\PhraseScopeSource;
use Weline\I18n\Service\PhraseScopeResolver;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;

/**
 * TEST-P1C-04：phrase Scope + locale 父级回落。
 */
final class PhraseScopeResolverTest extends TestCase
{
    private PhraseScopeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PhraseScopeResolver(new SystemConfigScopeResolver());
    }

    public function testTwoStoresKeepDistinctScopedPhrases(): void
    {
        $dict = [
            'Hello|scope:shop.main.default' . "\0" . 'en_US' => 'Hello Main',
            'Hello|scope:shop.outlet.default' . "\0" . 'en_US' => 'Hello Outlet',
        ];
        $lookup = static function (string $word, string $locale) use ($dict): ?string {
            return $dict[$word . "\0" . $locale] ?? null;
        };

        $main = $this->resolver->resolve(
            'Hello',
            ScopeIdentity::store(0, 'shop', 'main', ScopeIdentity::MODE_NORMAL),
            'en_US',
            $lookup,
            ['en_US'],
        );
        $outlet = $this->resolver->resolve(
            'Hello',
            ScopeIdentity::store(0, 'shop', 'outlet', ScopeIdentity::MODE_NORMAL),
            'en_US',
            $lookup,
            ['en_US'],
        );

        self::assertSame('Hello Main', $main->text);
        self::assertSame('Hello Outlet', $outlet->text);
        self::assertSame(PhraseScopeSource::KIND_EXACT, $main->source->sourceKind);
        self::assertSame(PhraseScopeSource::KIND_EXACT, $outlet->source->sourceKind);
    }

    public function testFallsBackToWebsiteThenUnscopedDefault(): void
    {
        $dict = [
            'Hello|scope:shop.default.default' . "\0" . 'en_US' => 'Hello Website',
        ];
        $lookup = static function (string $word, string $locale) use ($dict): ?string {
            return $dict[$word . "\0" . $locale] ?? null;
        };

        $result = $this->resolver->resolve(
            'Hello',
            ScopeIdentity::store(0, 'shop', 'main', ScopeIdentity::MODE_NORMAL),
            'en_US',
            $lookup,
            ['en_US'],
            static fn(string $s, string $l): string => 'Hello Default',
        );

        self::assertSame('Hello Website', $result->text);
        self::assertSame(PhraseScopeSource::KIND_FALLBACK, $result->source->sourceKind);
        self::assertSame(ScopeIdentity::KIND_WEBSITE, $result->source->scopeKind);
    }

    public function testLocaleChainPrefersRequestedThenZh(): void
    {
        $dict = [
            'Hi|scope:default.default.default' . "\0" . 'zh_Hans_CN' => '你好',
        ];
        $lookup = static function (string $word, string $locale) use ($dict): ?string {
            return $dict[$word . "\0" . $locale] ?? null;
        };

        $result = $this->resolver->resolve(
            'Hi',
            ScopeIdentity::global(),
            'en_US',
            $lookup,
        );

        self::assertSame('你好', $result->text);
        self::assertSame('zh_Hans_CN', $result->source->locale);
    }
}
