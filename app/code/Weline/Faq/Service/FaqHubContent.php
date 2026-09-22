<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\WidgetI18n;

/**
 * Migrated hub content formerly hardcoded in Theme layouts/faq/default.phtml.
 */
final class FaqHubContent
{
    /**
     * @return list<array{icon:string,title:string,desc:string,url:string,route?:bool,open_cs?:bool}>
     */
    public function topics(): array
    {
        $t = static fn (string $key): string => WidgetI18n::label($key);

        return [
            ['icon' => 'truck', 'title' => $t('物流配送'), 'desc' => $t('发货时效、运费与配送范围'), 'url' => 'guide/shipping', 'route' => true],
            ['icon' => 'undo', 'title' => $t('退换货'), 'desc' => $t('退货条件、流程与退款进度'), 'url' => 'guide/returns', 'route' => true],
            ['icon' => 'credit-card', 'title' => $t('支付与发票'), 'desc' => $t('支付方式、账单与发票开具'), 'url' => 'guide/payment', 'route' => true],
            ['icon' => 'box', 'title' => $t('订单问题'), 'desc' => $t('下单、改单、取消与订单状态'), 'url' => 'customer/account/index#orders', 'route' => true],
            ['icon' => 'user', 'title' => $t('账户与安全'), 'desc' => $t('登录、密码与个人信息'), 'url' => 'customer/account/index', 'route' => true],
            ['icon' => 'headset', 'title' => $t('联系客服'), 'desc' => $t('电话、邮箱与在线支持通道'), 'url' => '#contact-service', 'open_cs' => true],
        ];
    }

    /**
     * Live hub FAQ copy for the active storefront locale.
     *
     * @return list<array{q:string,a:string}>
     */
    public function faqs(): array
    {
        $locale = '';
        try {
            if (class_exists(\Weline\Framework\App\State::class)) {
                $locale = trim((string)\Weline\Framework\App\State::getLangLocal());
                if ($locale === '') {
                    $locale = trim((string)\Weline\Framework\App\State::getLang());
                }
            }
        } catch (\Throwable) {
            $locale = '';
        }

        return $this->faqsForLocale($locale !== '' ? $locale : FaqTemplateSeedService::LOCALE_ZH);
    }

    /**
     * Hub FAQ bodies: prefer persisted site rows for the exact locale, then seed catalog.
     * Catalog alone falls back to en_US for non-maintained locales (e.g. ru_RU) — DB wins when seeded.
     *
     * @return list<array{q:string,a:string}>
     */
    public function faqsForLocale(string $localeCode): array
    {
        $localeCode = trim($localeCode);
        if ($localeCode === '') {
            $localeCode = FaqTemplateSeedService::LOCALE_ZH;
        }

        $fromDb = $this->loadSiteHubFromDb($localeCode);
        if ($fromDb !== []) {
            return $fromDb;
        }

        return FaqSeedCopyCatalog::hubForLocale($localeCode);
    }

    /**
     * Exact-locale site hub rows (hub_0..hub_N), ordered by key index.
     *
     * @return list<array{q:string,a:string}>
     */
    private function loadSiteHubFromDb(string $localeCode): array
    {
        try {
            /** @var FaqService $faqs */
            $faqs = ObjectManager::getInstance(FaqService::class);
            $rows = $faqs->listForEntity(
                'site',
                SiteFaqTypeProvider::ENTITY_UUID,
                0,
                $localeCode,
                true
            );
        } catch (\Throwable) {
            return [];
        }

        $byIndex = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string)($row['locale_code'] ?? '')) !== $localeCode) {
                continue;
            }
            $key = strtolower(trim((string)($row['faq_key'] ?? '')));
            if (preg_match('/^hub_(\d+)$/', $key, $m) !== 1) {
                continue;
            }
            $q = trim((string)($row['question'] ?? ''));
            $a = trim((string)($row['answer'] ?? ''));
            if ($q === '' || $a === '') {
                continue;
            }
            $byIndex[(int)$m[1]] = ['q' => $q, 'a' => $a];
        }
        if ($byIndex === []) {
            return [];
        }
        ksort($byIndex, SORT_NUMERIC);

        return array_values($byIndex);
    }

    /**
     * @return list<array{label:string,url:string,route?:bool,open_cs?:bool}>
     */
    public function quickLinks(): array
    {
        $t = static fn (string $key): string => WidgetI18n::label($key);

        return [
            ['label' => $t('我的订单'), 'url' => 'customer/account/index#orders', 'route' => true],
            ['label' => $t('我的账户'), 'url' => 'customer/account/index', 'route' => true],
            ['label' => $t('配送说明'), 'url' => 'guide/shipping', 'route' => true],
            ['label' => $t('退换政策'), 'url' => 'guide/returns', 'route' => true],
            ['label' => $t('退款政策'), 'url' => 'refund', 'route' => true],
            ['label' => $t('支付方式'), 'url' => 'guide/payment', 'route' => true],
            ['label' => $t('隐私政策'), 'url' => 'privacy', 'route' => true],
            ['label' => $t('服务条款'), 'url' => 'terms', 'route' => true],
            ['label' => $t('Cookie 政策'), 'url' => 'cookies', 'route' => true],
            ['label' => $t('联系客服'), 'url' => '#contact-service', 'route' => false, 'open_cs' => true],
        ];
    }

    /**
     * FAQ facts for SEO FAQPage (question/answer keys).
     *
     * @return list<array{question:string,answer:string}>
     */
    public function seoFaqs(): array
    {
        $out = [];
        foreach ($this->faqs() as $row) {
            $out[] = [
                'question' => (string)($row['q'] ?? ''),
                'answer' => (string)($row['a'] ?? ''),
            ];
        }

        return $out;
    }
}
