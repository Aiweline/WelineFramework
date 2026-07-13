<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Localization;

use Weline\Framework\App\Localization\LocalizationProviderInterface;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Model\WebsiteLanguage;

final class LocalizationProvider implements LocalizationProviderInterface
{
    public function priority(): int
    {
        return 100;
    }

    public function languageCodes(): array
    {
        $codes = WebsiteData::getLanguageCodes();
        if ($codes !== []) {
            return $codes;
        }
        $websiteId = $this->websiteId();
        return $websiteId > 0
            ? ObjectManager::getInstance(WebsiteLanguage::class)->getWebsiteLanguageCodes($websiteId)
            : [];
    }

    public function currencyCodes(): array
    {
        $codes = WebsiteData::getCurrencyCodes();
        if ($codes !== []) {
            return $codes;
        }
        $websiteId = $this->websiteId();
        return $websiteId > 0
            ? ObjectManager::getInstance(WebsiteCurrency::class)->getWebsiteCurrencyCodes($websiteId)
            : [];
    }

    public function supportsLanguage(string $code): ?bool
    {
        return null;
    }

    public function supportsCurrency(string $code): ?bool
    {
        return null;
    }

    private function websiteId(): int
    {
        $websiteId = (int)w_env('website_id', 0);
        return $websiteId > 0 ? $websiteId : (int)WelineEnv::server('WELINE_WEBSITE_ID', 0);
    }
}
