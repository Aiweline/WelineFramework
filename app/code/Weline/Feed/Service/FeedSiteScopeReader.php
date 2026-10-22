<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;

/**
 * Reads a website's own languages and currencies.
 * Country comes from the language code's region, the same way a locale bucket names its market.
 */
final class FeedSiteScopeReader
{
    /**
     * @return array{leaves:list<array{website_id:int,website_name:string,locale:string,country:string,currency:string}>,skipped_locales:list<string>}
     */
    public function scope(int $websiteId): array
    {
        $empty = ['leaves' => [], 'skipped_locales' => []];
        if ($websiteId < 0) {
            return $empty;
        }
        $website = ObjectManager::getInstance(Website::class);
        if (!$website instanceof Website) {
            return $empty;
        }
        $website->clear();
        $website->load($websiteId);
        if ((int)$website->getId() !== $websiteId && !$website->hasData(Website::schema_fields_ID)) {
            return $empty;
        }
        $name = trim($website->getName());
        $locales = $this->codes($website->getLanguageCodes());
        $currencies = [];
        foreach ($website->getCurrencyCodes() as $code) {
            $currency = strtoupper(trim((string)$code));
            if (preg_match('/^[A-Z]{3}$/', $currency) === 1) {
                $currencies[$currency] = $currency;
            }
        }
        $leaves = [];
        $skipped = [];
        foreach ($locales as $locale) {
            if (preg_match('/_([A-Z]{2})$/', $locale, $region) !== 1) {
                $skipped[] = $locale;
                continue;
            }
            foreach ($currencies as $currency) {
                $leaves[] = [
                    'website_id' => $websiteId,
                    'website_name' => $name,
                    'locale' => $locale,
                    'country' => $region[1],
                    'currency' => $currency,
                ];
            }
        }

        return [
            'leaves' => $leaves,
            'skipped_locales' => $skipped,
        ];
    }

    /**
     * @param mixed $codes
     * @return list<string>
     */
    private function codes(mixed $codes): array
    {
        if (!is_array($codes)) {
            return [];
        }
        $unique = [];
        foreach ($codes as $code) {
            $locale = trim((string)$code);
            if ($locale !== '') {
                $unique[$locale] = $locale;
            }
        }

        return array_values($unique);
    }
}
