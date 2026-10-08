<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\State;

/**
 * State must take website defaults from LocalizationProviderRegistry only —
 * never hardcode WebsiteData (sites supply via providers; process bag keys by website scope).
 */
final class StateLocalizationProviderDefaultsContractTest extends TestCase
{
    public function testResolveWebsiteDefaultsDoNotReferenceWebsiteData(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/App/State.php'
        );
        self::assertStringNotContainsString(
            'WebsiteData::getDefaultLanguage',
            $src,
        );
        self::assertStringNotContainsString(
            'WebsiteData::getDefaultCurrency',
            $src,
        );
        self::assertStringNotContainsString(
            'Weline\\Websites\\Data\\WebsiteData',
            $src,
        );
        self::assertStringContainsString('preferredDefaultLanguage', $src);
        self::assertStringContainsString('preferredDefaultCurrency', $src);
        self::assertStringContainsString('LocalizationProviderRegistry', $src);
        self::assertTrue(\method_exists(State::class, 'resolveWebsiteDefaultLanguage'));
        self::assertTrue(\method_exists(State::class, 'resolveWebsiteDefaultCurrency'));
        self::assertTrue(\method_exists(State::class, 'clearProcessLocalizationCaches'));
    }

    public function testProcessDefaultBagsClearTogether(): void
    {
        State::clearProcessLocalizationCaches();
        $lang = new \ReflectionProperty(State::class, 'websiteDefaultLanguageByScope');
        $currency = new \ReflectionProperty(State::class, 'websiteDefaultCurrencyByScope');
        $lang->setValue(null, ['id:0' => 'zh_Hans_CN']);
        $currency->setValue(null, ['id:0' => 'CNY']);
        self::assertNotSame([], $lang->getValue(null));
        State::clearProcessLocalizationCaches();
        self::assertSame([], $lang->getValue(null));
        self::assertSame([], $currency->getValue(null));
    }
}
