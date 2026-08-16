<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Api\Localization;

\defined('BP') || \define('BP', \dirname(__DIR__, 8) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
require_once BP . 'app/code/Weline/Framework/Common/functions.php';

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Api\Localization\LocalizationProvider;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Model\WebsiteLanguage;

final class LocalizationProviderRequestCacheTest extends TestCase
{
    private ?object $originalWebsiteLanguage = null;
    private ?object $originalWebsiteCurrency = null;

    protected function setUp(): void
    {
        parent::setUp();

        Context::leave();
        Context::enter(new Context());
        RequestContext::init();
        WebsiteData::resetRequestState();
        WelineEnv::set('website_id', 0, 'localization provider request-cache test');

        $instances = ObjectManager::getInstances();
        $this->originalWebsiteLanguage = $instances[WebsiteLanguage::class] ?? null;
        $this->originalWebsiteCurrency = $instances[WebsiteCurrency::class] ?? null;
    }

    protected function tearDown(): void
    {
        ObjectManager::removeInstance(WebsiteLanguage::class);
        ObjectManager::removeInstance(WebsiteCurrency::class);
        if ($this->originalWebsiteLanguage !== null) {
            ObjectManager::setInstance(WebsiteLanguage::class, $this->originalWebsiteLanguage);
        }
        if ($this->originalWebsiteCurrency !== null) {
            ObjectManager::setInstance(WebsiteCurrency::class, $this->originalWebsiteCurrency);
        }

        WebsiteData::resetRequestState();
        Context::leave();
        parent::tearDown();
    }

    public function testFallbackLanguageAndCurrencyQueriesMemoizeEmptyResultsWithinRequest(): void
    {
        $language = new LocalizationWebsiteLanguageProbe([]);
        $currency = new LocalizationWebsiteCurrencyProbe([]);
        ObjectManager::setInstance(WebsiteLanguage::class, $language);
        ObjectManager::setInstance(WebsiteCurrency::class, $currency);
        self::assertSame($language, ObjectManager::_getInstance(WebsiteLanguage::class));
        self::assertSame($currency, ObjectManager::_getInstance(WebsiteCurrency::class));

        $provider = new LocalizationProvider();

        self::assertSame([], $provider->languageCodes());
        self::assertSame([], $provider->languageCodes());
        self::assertSame([], $provider->currencyCodes());
        self::assertSame([], $provider->currencyCodes());
        self::assertSame([0], $language->websiteIds);
        self::assertSame([0], $currency->websiteIds);
    }

    public function testFallbackLookupDoesNotCreateAProcessLevelCacheOutsideARequest(): void
    {
        RequestContext::cleanup();
        Context::current()->set('route.website_id', 0);

        $language = new LocalizationWebsiteLanguageProbe([]);
        ObjectManager::setInstance(WebsiteLanguage::class, $language);

        $provider = new LocalizationProvider();

        self::assertSame([], $provider->languageCodes());
        self::assertSame([], $provider->languageCodes());
        self::assertSame([0, 0], $language->websiteIds);
        self::assertNull(RequestContext::getId());
    }
}

final class LocalizationWebsiteLanguageProbe extends WebsiteLanguage
{
    /** @var list<int> */
    public array $websiteIds = [];

    /** @param list<string> $codes */
    public function __construct(private readonly array $codes)
    {
    }

    public function getWebsiteLanguageCodes(int $websiteId): array
    {
        $this->websiteIds[] = $websiteId;
        return $this->codes;
    }
}

final class LocalizationWebsiteCurrencyProbe extends WebsiteCurrency
{
    /** @var list<int> */
    public array $websiteIds = [];

    /** @param list<string> $codes */
    public function __construct(private readonly array $codes)
    {
    }

    public function getWebsiteCurrencyCodes(int $websiteId): array
    {
        $this->websiteIds[] = $websiteId;
        return $this->codes;
    }
}
