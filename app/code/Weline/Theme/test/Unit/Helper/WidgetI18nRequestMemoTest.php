<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\I18n\Api\Scope\PhraseScopeValue;
use Weline\I18n\Api\Translation\TranslationResolverInterface;
use Weline\Theme\Helper\WidgetI18n;

final class WidgetI18nRequestMemoTest extends TestCase
{
    private ?object $originalResolver = null;
    private WidgetI18nCountingResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context());
        RequestContext::init();
        RequestContext::setWelineUserLang('en_US');

        $instances = ObjectManager::getInstances();
        $this->originalResolver = $instances[TranslationResolverInterface::class] ?? null;
        $this->resolver = new WidgetI18nCountingResolver();
        ObjectManager::setInstance(TranslationResolverInterface::class, $this->resolver);
    }

    protected function tearDown(): void
    {
        ObjectManager::removeInstance(TranslationResolverInterface::class);
        if ($this->originalResolver !== null) {
            ObjectManager::setInstance(TranslationResolverInterface::class, $this->originalResolver);
        }
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }

        parent::tearDown();
    }

    public function testPathLocaleIncludesHindiAndOtherDefaultSitePacks(): void
    {
        self::assertSame('hi_IN', WidgetI18n::localeFromRequestUri('/hi_IN/product/foo?x=1'));
        self::assertSame('ar_SA', WidgetI18n::localeFromRequestUri('/ar_SA/'));
        self::assertNull(WidgetI18n::localeFromRequestUri('/product/foo'));
    }

    public function testPreferredModulesIncludeShippingForPdpFreightHint(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Helper/WidgetI18n.php');
        self::assertStringContainsString("'Weline_Shipping'", $src);
    }

    public function testRepeatedIdenticalLabelsUseTheRequestMemo(): void
    {
        self::assertSame('Color translated (red)', WidgetI18n::label('Color', '', ['red']));
        self::assertSame('Color translated (red)', WidgetI18n::label('Color', '', ['red']));
        self::assertSame(1, $this->resolver->translateCalls);
    }
}

final class WidgetI18nCountingResolver implements TranslationResolverInterface
{
    public int $translateCalls = 0;

    public function translate(string $source, string $localeCode, array $preferredModules = []): string
    {
        $this->translateCalls++;
        return $source . ' translated (%{1})';
    }

    public function reset(): void
    {
    }

    public function translateForScope(
        string $source,
        ScopeIdentity $identity,
        string $localeCode,
        array $preferredModules = [],
        ?array $localeFallbackChain = null,
    ): PhraseScopeValue {
        return new PhraseScopeValue(
            text: $source,
            source: \Weline\I18n\Api\Scope\PhraseScopeSource::fromDefault($localeCode),
            requestedScope: $identity,
            requestedLocale: $localeCode,
            fallbackStorageScopes: [],
            localeFallbackChain: $localeFallbackChain ?? [],
        );
    }
}
