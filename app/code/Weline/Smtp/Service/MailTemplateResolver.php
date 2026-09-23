<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Model\SmtpMailTemplate;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\Websites\Model\Website;

/**
 * 就近向上解析邮件模板：typed scope 链 ×（目标 locale / 站默认语）；
 * 通知域 channel 回退到 Weline_Backend::notification_email。
 */
class MailTemplateResolver
{
    public const NOTIFICATION_FALLBACK_CHANNEL = 'Weline_Backend::notification_email';

    public function __construct(
        private readonly ?ScopeHierarchyInterface $scopeHierarchy = null,
    ) {
    }

    /**
     * @return array{
     *   template: SmtpMailTemplate,
     *   channel_code: string,
     *   resolved_channel: string,
     *   storage_scope: string,
     *   locale: string
     * }|null
     */
    public function resolve(
        string $channelCode,
        string $storageScope,
        string $locale,
        ?string $websiteDefaultLocale = null,
    ): ?array {
        $channelCode = trim($channelCode);
        $storageScope = trim($storageScope) !== '' ? trim($storageScope) : SystemConfig::SCOPE_GLOBAL;
        $locale = trim($locale);
        if ($channelCode === '' || $locale === '' || $locale === 'default') {
            return null;
        }

        $channels = [$channelCode];
        if ($this->isNotifyChannel($channelCode) && $channelCode !== self::NOTIFICATION_FALLBACK_CHANNEL) {
            $channels[] = self::NOTIFICATION_FALLBACK_CHANNEL;
        }

        $scopeChain = $this->buildScopeChain($storageScope);
        $siteDefault = trim((string)$websiteDefaultLocale);
        if ($siteDefault === '') {
            $siteDefault = $this->resolveWebsiteDefaultLanguage($storageScope);
        }

        foreach ($channels as $tryChannel) {
            foreach ($scopeChain as $scope) {
                foreach (self::localeFallbackChain($locale, $siteDefault) as $tryLocale) {
                    $row = $this->loadRow($tryChannel, $scope, $tryLocale);
                    if ($row !== null) {
                        return [
                            'template' => $row,
                            'channel_code' => $channelCode,
                            'resolved_channel' => $tryChannel,
                            'storage_scope' => $scope,
                            'locale' => $tryLocale,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * 模板行 locale 尝试顺序（同 scope 内）。
     * 非中文：locale → en_US → siteDefault → zh_Hans_CN（去重）；禁止非中文直接落到 zh。
     * 中文：locale → siteDefault（若不同）。
     *
     * @return list<string>
     */
    public static function localeFallbackChain(string $locale, string $siteDefault = ''): array
    {
        $locale = trim($locale);
        $siteDefault = trim($siteDefault);
        if ($locale === '' || $locale === 'default') {
            return [];
        }

        $chain = [$locale];
        $isChinese = self::isChineseLocale($locale);
        if (!$isChinese) {
            $en = MailTemplateSeedCopyCatalog::LOCALE_EN;
            if ($locale !== $en) {
                $chain[] = $en;
            }
        }
        if ($siteDefault !== '' && $siteDefault !== 'default' && !in_array($siteDefault, $chain, true)) {
            $chain[] = $siteDefault;
        }
        if (!$isChinese) {
            $zh = MailTemplateSeedCopyCatalog::LOCALE_ZH;
            if (!in_array($zh, $chain, true)) {
                $chain[] = $zh;
            }
        }

        return $chain;
    }

    private static function isChineseLocale(string $locale): bool
    {
        $normalized = strtolower(str_replace('_', '-', trim($locale)));

        return $normalized === '' || str_starts_with($normalized, 'zh');
    }

    /**
     * Listing badge：当前 scope 无行时，返回将继承到的父 scope（若有）。
     */
    public function findInheritFrom(
        string $channelCode,
        string $storageScope,
        string $locale,
        ?string $websiteDefaultLocale = null,
    ): ?array {
        $hit = $this->resolve($channelCode, $storageScope, $locale, $websiteDefaultLocale);
        if ($hit === null) {
            return null;
        }
        if ($hit['storage_scope'] === $storageScope && $hit['locale'] === $locale
            && $hit['resolved_channel'] === $channelCode) {
            return null;
        }
        return $hit;
    }

    private function isNotifyChannel(string $channelCode): bool
    {
        return (bool)preg_match('/::notify_/', $channelCode)
            || $channelCode === self::NOTIFICATION_FALLBACK_CHANNEL;
    }

    /** @return list<string> */
    private function buildScopeChain(string $storageScope): array
    {
        $hierarchy = $this->scopeHierarchy
            ?? ObjectManager::getInstance(ScopeHierarchyInterface::class);
        if ($hierarchy instanceof ScopeHierarchyInterface) {
            $identity = $hierarchy->fromStorageScope($storageScope, true);
            if ($identity !== null) {
                return $hierarchy->chainFromIdentity($identity);
            }
        }
        /** @var SystemConfig $config */
        $config = ObjectManager::getInstance(SystemConfig::class);
        return $config->getFallbackScopes($storageScope);
    }

    private function resolveWebsiteDefaultLanguage(string $storageScope): string
    {
        $parts = explode('.', $storageScope);
        $websiteCode = trim((string)($parts[0] ?? ''));
        if ($websiteCode !== '' && $websiteCode !== 'default') {
            try {
                /** @var Website $website */
                $website = ObjectManager::getInstance(Website::class);
                $row = $website->clear()->where(Website::schema_fields_CODE, $websiteCode)->find()->fetch();
                if ($row && $row->getId()) {
                    $lang = trim((string)($row->getDefaultLanguage() ?? ''));
                    if ($lang !== '' && $lang !== 'default') {
                        return $lang;
                    }
                }
            } catch (\Throwable) {
            }
        }

        // Global / 未绑定网站：回退平台默认语（与发信 Resolver「未配置语言 → 网站/默认语模板」一致）
        return \Weline\Framework\App\Env::default_LANGUAGE_CODE;
    }

    private function loadRow(string $channel, string $scope, string $locale): ?SmtpMailTemplate
    {
        /** @var SmtpMailTemplate $model */
        $model = ObjectManager::getInstance(SmtpMailTemplate::class);
        $row = $model->clear()
            ->where(SmtpMailTemplate::schema_fields_CHANNEL_CODE, $channel)
            ->where(SmtpMailTemplate::schema_fields_STORAGE_SCOPE, $scope)
            ->where(SmtpMailTemplate::schema_fields_LOCALE, $locale)
            ->find()
            ->fetch();
        if (!$row || !$row->getId()) {
            return null;
        }
        // Detach: OM singleton is reused; callers must not see later loads overwrite data.
        /** @var SmtpMailTemplate $copy */
        $copy = clone $row;

        return $copy;
    }
}
