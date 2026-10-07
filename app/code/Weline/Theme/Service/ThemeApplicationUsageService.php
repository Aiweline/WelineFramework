<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Service\BackendThemeApplicationService;
use Weline\Theme\Api\DefaultThemeInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Websites\Model\ThemeApplication;
use Weline\Websites\Model\Website;

/**
 * 只读：按 websites_theme_application / backend_theme_application 汇总主题使用，
 * 供主题列表与 theme:upgrade 无 -t 目标解析。不写激活标记。
 */
final class ThemeApplicationUsageService
{
    public function __construct(
        private readonly ThemeApplication $applications,
        private readonly BackendThemeApplicationService $backendApplications,
        private readonly DefaultThemeInterface $defaultTheme,
        private readonly WelineTheme $themes,
    ) {
    }

    /**
     * 网站本级（scope_kind=website）frontend own 引用：theme_id → website codes。
     *
     * @return array<int, list<string>>
     */
    public function frontendWebsiteUsageByThemeId(): array
    {
        $usage = [];
        foreach ($this->frontendWebsiteBindingsByThemeId() as $themeId => $bindings) {
            $codes = [];
            foreach ($bindings as $binding) {
                $codes[] = $binding['website_code'];
            }
            $usage[$themeId] = $codes;
        }

        return $usage;
    }

    /**
     * 网站本级 frontend own 引用：theme_id → [{website_id, website_code}, ...]（按 code 排序）。
     *
     * @return array<int, list<array{website_id:int,website_code:string}>>
     */
    public function frontendWebsiteBindingsByThemeId(): array
    {
        $usage = [];
        foreach ($this->listOwnApplicationRows('frontend') as $row) {
            $themeId = $row['theme_id'];
            if ($themeId < 1) {
                continue;
            }
            $website = $this->websiteFromScopeKey($row['scope_key']);
            if ($website === null) {
                continue;
            }
            $usage[$themeId] ??= [];
            foreach ($usage[$themeId] as $existing) {
                if ($existing['website_id'] === $website['website_id']
                    && $existing['website_code'] === $website['website_code']) {
                    continue 2;
                }
            }
            $usage[$themeId][] = $website;
        }
        foreach ($usage as &$bindings) {
            \usort(
                $bindings,
                static fn(array $a, array $b): int => \strcmp($a['website_code'], $b['website_code'])
            );
        }
        unset($bindings);

        return $usage;
    }

    /**
     * 主题列表「编辑」入口用的网站范围：有绑定时取首个绑定站；否则默认站 0 / default。
     *
     * @return array{website_id:int,website_code:string}
     */
    public function resolveEditWebsiteForTheme(int $themeId): array
    {
        if ($themeId > 0) {
            $bindings = $this->frontendWebsiteBindingsByThemeId()[$themeId] ?? [];
            if ($bindings !== []) {
                return $bindings[0];
            }
        }

        return [
            'website_id' => Website::ID_DEFAULT,
            'website_code' => Website::CODE_DEFAULT,
        ];
    }

    /**
     * Absolute storefront base from Website (scheme://host[:port][/mount]).
     * Bound themes use their website; unbound / Website::ID_DEFAULT use site 0.
     */
    public function resolveStorefrontOriginForWebsite(int $websiteId, string $websiteCode = ''): ?string
    {
        if ($websiteId < Website::ID_DEFAULT) {
            return null;
        }

        $websiteCode = \strtolower(\trim($websiteCode));
        try {
            if ($websiteCode !== '') {
                /** @var Website $probe */
                $probe = ObjectManager::getInstance(Website::class);
                $row = (clone $probe)->clearData()->clearQuery()->load($websiteId);
                $loadedId = $row->hasData(Website::schema_fields_ID)
                    ? (int)$row->getData(Website::schema_fields_ID)
                    : -1;
                if ($loadedId === $websiteId) {
                    $code = \strtolower(\trim($row->getCode()));
                    if ($code !== '' && $code !== $websiteCode) {
                        return null;
                    }
                }
            }

            return Website::resolveStorefrontBaseUrl($websiteId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Headless preview capture base for a theme's bound website (install-local Host).
     */
    public function resolveCaptureBaseUrlForTheme(int $themeId): ?string
    {
        if ($themeId < 1) {
            return null;
        }
        $edit = $this->resolveEditWebsiteForTheme($themeId);

        try {
            /** @var InstallLocalStorefrontBaseResolver $resolver */
            $resolver = ObjectManager::getInstance(InstallLocalStorefrontBaseResolver::class);

            return $resolver->resolveForWebsite(
                (int)$edit['website_id'],
                (string)$edit['website_code'],
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 所有 frontend own 引用中的正 theme_id（含 global / store / channel）。
     *
     * @return list<int>
     */
    public function frontendBoundThemeIds(): array
    {
        $ids = [];
        foreach ($this->listOwnApplicationRows('frontend') as $row) {
            if ($row['theme_id'] >= 1) {
                $ids[$row['theme_id']] = true;
            }
        }

        return \array_map('intval', \array_keys($ids));
    }

    /**
     * 后台应用引用 theme_id；0 表示包默认 / 未配置回落 Default。
     */
    public function backendApplicationThemeId(): int
    {
        try {
            return (int)$this->backendApplications->buildContext()->themeId;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * theme:upgrade 无 -t：各站 frontend 应用 + 后台应用 + 注册 Default（去重）。
     *
     * @return list<WelineTheme>
     */
    public function themesForDefaultUpgrade(): array
    {
        $ids = [];
        foreach ($this->frontendBoundThemeIds() as $id) {
            $ids[$id] = true;
        }
        $backendId = $this->backendApplicationThemeId();
        if ($backendId >= 1) {
            $ids[$backendId] = true;
        }
        $defaultId = (int)($this->defaultTheme->getRegisteredDefault('frontend')['id']
            ?? $this->defaultTheme->getRegisteredDefault('backend')['id']
            ?? 0);
        if ($defaultId >= 1) {
            $ids[$defaultId] = true;
        }

        $themes = [];
        foreach (\array_keys($ids) as $id) {
            $theme = clone $this->themes;
            $theme->clearData()->clearQuery()->load((int)$id);
            if ((int)$theme->getId() === (int)$id) {
                $themes[] = $theme;
            }
        }

        return $themes;
    }

    /**
     * @return list<array{scope_key:string,theme_id:int}>
     */
    private function listOwnApplicationRows(string $area): array
    {
        try {
            $rows = (clone $this->applications)->clear()
                ->where(ThemeApplication::schema_fields_AREA, $area)
                ->where(ThemeApplication::schema_fields_STORE_MODE, ScopeIdentity::MODE_NORMAL)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }
        if (!\is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $raw = $row[ThemeApplication::schema_fields_REFERENCE_JSON] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            try {
                $data = \json_decode((string)$raw, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            if (!\is_array($data)) {
                continue;
            }
            $themeId = (int)($data['theme_id'] ?? 0);
            $scopeKey = (string)($row[ThemeApplication::schema_fields_SCOPE_KEY] ?? '');
            if ($scopeKey === '') {
                continue;
            }
            $out[] = [
                'scope_key' => $scopeKey,
                'theme_id' => $themeId,
            ];
        }

        return $out;
    }

    /**
     * @return array{website_id:int,website_code:string}|null
     */
    private function websiteFromScopeKey(string $scopeKey): ?array
    {
        $parts = \explode('|', $scopeKey);
        if (($parts[0] ?? '') !== ScopeIdentity::KIND_WEBSITE) {
            return null;
        }
        $websiteId = (int)($parts[1] ?? -1);
        if ($websiteId < 0) {
            return null;
        }
        $code = \strtolower(\trim((string)($parts[2] ?? '')));
        if ($code === '' || \preg_match('/^[a-z0-9][a-z0-9_-]{0,254}$/D', $code) !== 1) {
            return null;
        }

        return [
            'website_id' => $websiteId,
            'website_code' => $code,
        ];
    }
}
