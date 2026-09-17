<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Model\SmtpMailTemplate;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * 建站检查：渠道模板是否存在、站点语种是否覆盖。
 */
class MailTemplateSetupCoverage
{
    public function __construct(
        private readonly ?MailChannelCollector $collector = null,
    ) {
    }

    private function collector(): MailChannelCollector
    {
        return $this->collector ?? ObjectManager::getInstance(MailChannelCollector::class);
    }

    /**
     * @return list<string> 尚无任何可解析模板行的渠道 code
     */
    public function channelsMissingTemplates(string $storageScope): array
    {
        $scopeLocales = $this->localesByChannel($storageScope);
        $missing = [];
        foreach ($this->collector()->collect() as $channel) {
            $code = (string)($channel['code'] ?? '');
            if ($code === '') {
                continue;
            }
            if (($scopeLocales[$code] ?? []) === []) {
                $missing[] = $code;
            }
        }

        return $missing;
    }

    /**
     * @return array{missing_pairs:list<array{channel:string,locale:string}>,locales:list<string>}
     */
    public function localeGaps(string $storageScope, int $websiteId = Website::ID_DEFAULT): array
    {
        $locales = $this->websiteLocales($websiteId);
        $scopeLocales = $this->localesByChannel($storageScope);
        $missing = [];
        foreach ($this->collector()->collect() as $channel) {
            $code = (string)($channel['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $have = $scopeLocales[$code] ?? [];
            foreach ($locales as $locale) {
                if (!isset($have[$locale])) {
                    $missing[] = ['channel' => $code, 'locale' => $locale];
                }
            }
        }

        return [
            'missing_pairs' => $missing,
            'locales' => $locales,
        ];
    }

    /**
     * @return list<string>
     */
    public function websiteLocales(int $websiteId = Website::ID_DEFAULT): array
    {
        $codes = [];
        try {
            /** @var WebsiteLanguage $model */
            $model = ObjectManager::getInstance(WebsiteLanguage::class);
            $websiteCodes = $model->getWebsiteLanguageCodes($websiteId);
            if (is_array($websiteCodes)) {
                foreach ($websiteCodes as $code) {
                    $code = trim((string)$code);
                    if ($code !== '') {
                        $codes[] = $code;
                    }
                }
            }
        } catch (\Throwable) {
        }
        if ($codes === []) {
            $codes = MailTemplateSeedCopyCatalog::baselineLocales();
        }
        $seen = [];
        $out = [];
        foreach ($codes as $code) {
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $out[] = $code;
        }

        return $out;
    }

    /**
     * 当前 scope 模板 ∪ Global 继承（与发信就近向上一致的最小集）。
     *
     * @return array<string, array<string, true>> channel => locale => true
     */
    private function localesByChannel(string $storageScope): array
    {
        $scopes = array_values(array_unique(array_filter([
            trim($storageScope),
            SystemConfig::SCOPE_GLOBAL,
            ConfigReader::SCOPE_GLOBAL,
        ])));
        $map = [];
        try {
            /** @var SmtpMailTemplate $model */
            $model = ObjectManager::getInstance(SmtpMailTemplate::class);
            $rows = $model->clear()
                ->where(SmtpMailTemplate::schema_fields_STORAGE_SCOPE, $scopes, 'IN')
                ->select()
                ->fetch()
                ->getItems();
            foreach ($rows as $row) {
                $channel = trim((string)$row->getData(SmtpMailTemplate::schema_fields_CHANNEL_CODE));
                $locale = trim((string)$row->getData(SmtpMailTemplate::schema_fields_LOCALE));
                if ($channel === '' || $locale === '' || $locale === 'default') {
                    continue;
                }
                $map[$channel][$locale] = true;
            }
        } catch (\Throwable) {
            return [];
        }

        return $map;
    }
}
