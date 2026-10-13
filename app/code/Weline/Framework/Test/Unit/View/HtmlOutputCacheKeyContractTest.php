<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * HTML output bags must use KeyBuilder::buildEnvironmentScoped — not hand-rolled
 * prefix + sha1(...|environmentHash(...)).
 */
final class HtmlOutputCacheKeyContractTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        parent::tearDown();
    }

    public function testHookOutputStyleKeysSplitByLangAndCurrency(): void
    {
        $build = static function (): string {
            return KeyBuilder::buildEnvironmentScoped(
                'hook.output',
                'fixture.phtml|1|1',
                [
                    'cache_version' => '20261013-hook-env-scoped-v2',
                    'hook_context' => 'body-end|auth:frontend-auth:0',
                    'policy_digest' => 'd',
                    'ttl' => 60,
                ],
                ['area_route' => false],
            );
        };

        $this->enterLangCurrency('zh_Hans_CN', 'CNY');
        $zh = $build();
        $this->enterLangCurrency('en_US', 'CNY');
        $en = $build();
        $this->enterLangCurrency('en_US', 'USD');
        $usd = $build();

        self::assertNotSame($zh, $en, 'lang must split hook.output keys');
        self::assertNotSame($en, $usd, 'currency must split hook.output keys');
    }

    public function testTemplateHookAndReusableHeaderUseSystemEnvironmentScopedKeys(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/View/Template.php',
        );

        self::assertStringContainsString("KeyBuilder::buildEnvironmentScoped(\n            'hook.output'", $src);
        self::assertStringContainsString("KeyBuilder::buildEnvironmentScoped(\n            'template.output.header.default'", $src);
        self::assertStringContainsString("KeyBuilder::buildEnvironmentScoped(\n                'view.hook.request'", $src);
        self::assertStringContainsString("KeyBuilder::buildEnvironmentScoped(\n                    'view.hook.aggregate'", $src);
        self::assertStringContainsString("'cache_version' => '20261013-hook-env-scoped-v2'", $src);
        self::assertStringNotContainsString("'hook.output.' . \\sha1(", $src);
        self::assertStringNotContainsString("'template.output.header.default.' . KeyBuilder::environmentHash(", $src);
        self::assertStringNotContainsString("'view.hook.aggregate.' . \\sha1(", $src);
        self::assertStringNotContainsString("'body-end:' . KeyBuilder::environmentHash(", $src);
        self::assertStringNotContainsString("'account-sidebar:' . KeyBuilder::environmentHash(", $src);
    }

    public function testLanguageSwitcherHtmlKeyUsesSystemEnvironmentScopedBuilder(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/I18n/Taglib/LanguageSwitcher.php',
        );

        self::assertStringContainsString("KeyBuilder::buildEnvironmentScoped(\n            'i18n.language_switcher.html'", $src);
        self::assertStringContainsString("'cache_version' => '20261013-lang-switcher-env-scoped-v2'", $src);
        self::assertStringNotContainsString('return \\md5(\\json_encode([', $src);
    }

    private function enterLangCurrency(string $lang, string $currency): void
    {
        if (Context::hasCurrent()) {
            RequestContext::cleanup();
            Context::leave();
        }
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        RequestContext::setId('html-output-cache-' . $lang . '-' . $currency);
        Context::current()->set('input.server.WELINE_AREA', 'frontend');
        RequestContext::installScopeIdentity(ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        ));
        RequestContext::setWelineUserLang($lang);
        RequestContext::setWelineUserCurrency($currency);
        StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(
            RequestContext::scopeIdentity(),
            $lang,
            $currency,
            \str_repeat('a', 64),
            \str_repeat('a', 64),
            true,
        ));
    }
}
