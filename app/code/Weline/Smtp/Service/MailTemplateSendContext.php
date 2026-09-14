<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Smtp\Helper\Data;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\Websites\Model\Website;

/**
 * 解析 send 用的 storage_scope + locale（G10 / R3）。
 */
class MailTemplateSendContext
{
    /**
     * @param array<string, mixed> $params
     * @return array{ok:bool,storage_scope:string,locale:string,website_default:string,message:string,explicit:bool}
     */
    public function resolve(array $params): array
    {
        /** @var Data $data */
        $data = ObjectManager::getInstance(Data::class);
        $explicitScope = trim((string)($params['scope'] ?? $params['target_scope'] ?? $params['storage_scope'] ?? ''));
        $websiteCode = trim((string)($params['website_code'] ?? ''));
        $explicitLocale = trim((string)($params['locale'] ?? ''));

        $hasHttpScope = false;
        try {
            $identity = RequestContext::scopeIdentity();
            $hasHttpScope = $identity instanceof ScopeIdentity && !$identity->isGlobal();
        } catch (\Throwable) {
            $hasHttpScope = false;
        }

        $explicit = $explicitScope !== '' || $websiteCode !== '' || $explicitLocale !== '';

        if ($explicitScope === '' && $websiteCode !== '') {
            $explicitScope = $websiteCode . '.default.default';
        }

        if ($explicitScope === '') {
            $explicitScope = $data->resolveScope(null);
        } else {
            $explicitScope = $data->resolveScope($explicitScope);
        }

        $websiteDefault = $this->websiteDefaultForScope($explicitScope, $websiteCode);
        $locale = $explicitLocale;
        if ($locale === '' || $locale === 'default') {
            if ($hasHttpScope || $explicit) {
                try {
                    $lang = (string)RequestContext::getWelineUserLang();
                    if ($lang !== '') {
                        $locale = $lang;
                    }
                } catch (\Throwable) {
                }
            }
            if ($locale === '' || $locale === 'default') {
                $locale = $websiteDefault !== '' ? $websiteDefault : 'zh_Hans_CN';
            }
        }

        $isCliLike = !$hasHttpScope && !$this->seemsHttpRequest();
        if ($isCliLike && trim((string)($params['scope'] ?? $params['target_scope'] ?? $params['website_code'] ?? '')) === '') {
            return [
                'ok' => false,
                'storage_scope' => $explicitScope,
                'locale' => $locale,
                'website_default' => $websiteDefault,
                'message' => (string)__('异步/CLI 发信必须显式传入 scope 或 website_code'),
                'explicit' => false,
            ];
        }
        if ($isCliLike && trim((string)($params['locale'] ?? '')) === '') {
            // allow website default when scope explicit
            if ($websiteDefault === '') {
                return [
                    'ok' => false,
                    'storage_scope' => $explicitScope,
                    'locale' => $locale,
                    'website_default' => $websiteDefault,
                    'message' => (string)__('异步/CLI 发信必须显式传入 locale，或确保网站有默认语言'),
                    'explicit' => false,
                ];
            }
        }

        return [
            'ok' => true,
            'storage_scope' => $explicitScope !== '' ? $explicitScope : SystemConfig::SCOPE_GLOBAL,
            'locale' => $locale,
            'website_default' => $websiteDefault,
            'message' => '',
            'explicit' => $explicit,
        ];
    }

    private function seemsHttpRequest(): bool
    {
        return PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg';
    }

    private function websiteDefaultForScope(string $storageScope, string $websiteCode): string
    {
        $code = $websiteCode;
        if ($code === '') {
            $parts = explode('.', $storageScope);
            $code = trim((string)($parts[0] ?? ''));
        }
        if ($code === '' || $code === 'default') {
            try {
                $identity = null;
                /** @var ScopeHierarchyInterface $hierarchy */
                $hierarchy = ObjectManager::getInstance(ScopeHierarchyInterface::class);
                $identity = $hierarchy->fromStorageScope($storageScope, true);
                if ($identity instanceof ScopeIdentity && $identity->websiteCode) {
                    $code = (string)$identity->websiteCode;
                }
            } catch (\Throwable) {
            }
        }
        if ($code === '' || $code === 'default') {
            return '';
        }
        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            $row = $website->clear()->where(Website::schema_fields_CODE, $code)->find()->fetch();
            if ($row && $row->getId()) {
                return trim((string)($row->getDefaultLanguage() ?? ''));
            }
        } catch (\Throwable) {
        }
        return '';
    }
}
