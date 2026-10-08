<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\App\State;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\View\Template;
use Weline\Framework\View\TemplateCompileService;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteLanguage;
use Weline\Widget\Api\WidgetRegistryInterface;

/**
 * Formal Theme layout publish gate: Taglib-compile entity sources → com_*.phtml.
 * This is Template/Taglib compilation (per locale cache files), not layout/widget
 * relationship solidify and not i18n dictionary compile. Failures throw (caller
 * rolls back entity bytes). Draft identities are skipped.
 *
 * Before compile, pins Website/RequestContext from the identity storage scope so
 * literal @url / <url> bake carries the publish-site mount (not default-site).
 */
final class ThemeLayoutEntityFormalLocaleCompileService
{
    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ThemeContextService $themeContext,
        private readonly TemplateCompileService $compiler,
        private readonly Printing $printing,
    ) {
    }

    /** @param array<string,?string> $candidates completed publish candidates */
    public function compileAfterPromote(ThemeVersionIdentity $identity, array $candidates): void
    {
        if ($identity->area !== 'frontend' || $identity->mode !== ThemeVersionIdentity::MODE_FORMAL) {
            return;
        }
        $pages = $this->extractPublishedPageLayouts($candidates);
        if ($pages === []) {
            return;
        }
        $locales = $this->resolveDefaultWebsiteLocales();
        $currency = State::resolveWebsiteDefaultCurrency();
        $theme = $this->themeContext->resolveTheme('frontend', null, false);
        if (!$theme instanceof WelineTheme) {
            throw new \RuntimeException('theme_layout_formal_compile_theme_missing');
        }

        $this->runPinnedToIdentityWebsite($identity, function () use ($identity, $pages, $locales, $currency): void {
            $template = Template::getInstance();
            foreach ($pages as $page) {
                $snapshot = ThemeLayoutSourceSnapshot::capture(
                    $this->paths,
                    $identity,
                    $page['layout_type'],
                    $page['layout_option'],
                    $page['target_type'],
                    $page['target_id'],
                );
                $pagePath = $snapshot->pagePath();
                if ($pagePath === null) {
                    throw new \RuntimeException(
                        'theme_layout_formal_compile_page_missing:' . $page['layout_type'] . '/' . $page['layout_option']
                    );
                }
                $snapshot->install($template);
                $pinned = $this->buildPinnedCompileSet($snapshot, $pagePath);
                $pageSource = $snapshot->source($pagePath);
                $widgets = $pageSource !== null
                    ? $this->resolveInlineWidgetTemplates((string)$pageSource['bytes'], $identity->area)
                    : [];
                try {
                    $this->compiler->compilePinnedSources(
                        $pinned,
                        $locales,
                        $currency,
                        $widgets,
                        function (int $done, int $total, string $locale, string $label) use ($page): void {
                            $this->printing->note(sprintf(
                                '%s [%d/%d] locale=%s · %s · %s/%s',
                                (string)__('主题布局 Taglib 编译'),
                                $done,
                                $total,
                                $locale,
                                $label,
                                $page['layout_type'],
                                $page['layout_option'],
                            ));
                        },
                    );
                } catch (\Throwable $error) {
                    $this->printing->error(sprintf(
                        '%s locale_batch_failed layout=%s/%s: %s',
                        (string)__('主题布局 Taglib 编译'),
                        $page['layout_type'],
                        $page['layout_option'],
                        $error->getMessage(),
                    ));
                    throw $error;
                }
            }
        });
    }

    /**
     * Pin WebsiteData + RequestContext + Url parser to the publish-site before
     * Taglib bake so literal url mounts match identity scope (not default site).
     */
    private function runPinnedToIdentityWebsite(ThemeVersionIdentity $identity, callable $fn): void
    {
        $website = $this->resolveWebsiteForCanonicalScope($identity->canonicalScope);
        if ($website === null) {
            $fn();

            return;
        }

        $websiteId = (int)$website->getWebsiteId();
        $websiteCode = trim((string)$website->getCode());
        if ($websiteCode === '') {
            $websiteCode = 'default';
        }
        $websiteUrl = trim((string)$website->getUrl());
        $scopeIdentity = $this->buildPinnedScopeIdentity($identity->canonicalScope, $websiteId, $websiteCode);

        $prevParser = [
            'id' => Url::$parserServer['WELINE_WEBSITE_ID'] ?? null,
            'code' => Url::$parserServer['WELINE_WEBSITE_CODE'] ?? null,
            'url' => Url::$parserServer['WELINE_WEBSITE_URL'] ?? null,
            'area' => Url::$parserServer['WELINE_AREA'] ?? null,
        ];

        RequestContext::runWithCompileTimeWebsitePin($scopeIdentity, $websiteUrl, function () use (
            $website,
            $websiteId,
            $websiteCode,
            $websiteUrl,
            $prevParser,
            $fn,
        ): void {
            WebsiteData::setWebsite($website);
            Url::$parserServer['WELINE_WEBSITE_ID'] = (string)$websiteId;
            Url::$parserServer['WELINE_WEBSITE_CODE'] = $websiteCode;
            Url::$parserServer['WELINE_WEBSITE_URL'] = $websiteUrl;
            Url::$parserServer['WELINE_AREA'] = 'frontend';
            try {
                $fn();
            } finally {
                if ($prevParser['id'] === null) {
                    unset(Url::$parserServer['WELINE_WEBSITE_ID']);
                } else {
                    Url::$parserServer['WELINE_WEBSITE_ID'] = $prevParser['id'];
                }
                if ($prevParser['code'] === null) {
                    unset(Url::$parserServer['WELINE_WEBSITE_CODE']);
                } else {
                    Url::$parserServer['WELINE_WEBSITE_CODE'] = $prevParser['code'];
                }
                if ($prevParser['url'] === null) {
                    unset(Url::$parserServer['WELINE_WEBSITE_URL']);
                } else {
                    Url::$parserServer['WELINE_WEBSITE_URL'] = $prevParser['url'];
                }
                if ($prevParser['area'] === null) {
                    unset(Url::$parserServer['WELINE_AREA']);
                } else {
                    Url::$parserServer['WELINE_AREA'] = $prevParser['area'];
                }
            }
        });
    }

    private function resolveWebsiteForCanonicalScope(string $canonicalScope): ?Website
    {
        $code = $this->websiteCodeFromCanonicalScope($canonicalScope);
        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            $website->clear()->clearQuery()->load(Website::schema_fields_CODE, $code, true);
            if ($website->hasData(Website::schema_fields_ID)
                || ($code === Website::CODE_DEFAULT && (int)$website->getWebsiteId() === Website::ID_DEFAULT)
            ) {
                if (!$website->hasData(Website::schema_fields_CODE)) {
                    $website->setCode($code);
                    $website->setData(Website::schema_fields_ID, Website::ID_DEFAULT);
                }

                return $website;
            }
            if ($code === Website::CODE_DEFAULT) {
                $website->clear()->clearQuery()->load(Website::ID_DEFAULT, null, true);
                if (!$website->hasData(Website::schema_fields_CODE)) {
                    $website->setCode(Website::CODE_DEFAULT);
                    $website->setData(Website::schema_fields_ID, Website::ID_DEFAULT);
                }

                return $website;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function websiteCodeFromCanonicalScope(string $canonicalScope): string
    {
        $canonicalScope = strtolower(trim($canonicalScope));
        if ($canonicalScope === '' || $canonicalScope === 'global') {
            return Website::CODE_DEFAULT;
        }
        try {
            $parsed = ObjectManager::getInstance(ScopeHierarchyInterface::class)
                ->fromStorageScope($canonicalScope, true);
            if ($parsed instanceof ScopeIdentity && is_string($parsed->websiteCode) && $parsed->websiteCode !== '') {
                return $parsed->websiteCode;
            }
        } catch (\Throwable) {
        }
        $parts = explode('.', $canonicalScope);

        return trim((string)($parts[0] ?? '')) !== ''
            ? trim((string)$parts[0])
            : Website::CODE_DEFAULT;
    }

    private function buildPinnedScopeIdentity(
        string $canonicalScope,
        int $websiteId,
        string $websiteCode,
    ): ScopeIdentity {
        try {
            $parsed = ObjectManager::getInstance(ScopeHierarchyInterface::class)
                ->fromStorageScope($canonicalScope, true);
            if ($parsed instanceof ScopeIdentity && !$parsed->isGlobal()) {
                return match ($parsed->scopeKind) {
                    ScopeIdentity::KIND_CHANNEL => ScopeIdentity::channel(
                        $websiteId,
                        $websiteCode,
                        (string)($parsed->storeCode ?? 'default'),
                        (string)($parsed->channelCode ?? 'default'),
                        (string)($parsed->storeMode ?? ScopeIdentity::MODE_NORMAL),
                    ),
                    ScopeIdentity::KIND_STORE => ScopeIdentity::store(
                        $websiteId,
                        $websiteCode,
                        (string)($parsed->storeCode ?? 'default'),
                        (string)($parsed->storeMode ?? ScopeIdentity::MODE_NORMAL),
                    ),
                    default => ScopeIdentity::website($websiteId, $websiteCode),
                };
            }
        } catch (\Throwable) {
        }

        return ScopeIdentity::website($websiteId, $websiteCode);
    }

    /**
     * @return array<string, array{bytes:string, origin:string, context_key:string, label:string}>
     */
    private function buildPinnedCompileSet(ThemeLayoutSourceSnapshot $snapshot, string $pagePath): array
    {
        $pinned = [];
        $pageSource = $snapshot->source($pagePath);
        if ($pageSource === null) {
            throw new \RuntimeException('theme_layout_formal_compile_page_source_missing');
        }
        $pinned[$pagePath] = [
            'bytes' => $pageSource['bytes'],
            'origin' => $pageSource['origin'],
            'context_key' => $snapshot->fingerprint(),
            'label' => 'page:' . basename($pagePath),
        ];
        foreach (['header', 'footer'] as $partialType) {
            $partialPath = $snapshot->selectedPartialPath($partialType);
            if ($partialPath === null) {
                continue;
            }
            $partialSource = $snapshot->source($partialPath);
            if ($partialSource === null) {
                continue;
            }
            $pinned[$partialPath] = [
                'bytes' => $partialSource['bytes'],
                'origin' => $partialSource['origin'],
                'context_key' => $snapshot->fingerprint(),
                'label' => 'partial:' . $partialType,
            ];
        }

        return $pinned;
    }

    /** @param array<string,?string> $candidates
     * @return list<array{layout_type:string,layout_option:string,target_type:string,target_id:int}>
     */
    private function extractPublishedPageLayouts(array $candidates): array
    {
        $pages = [];
        $seen = [];
        foreach ($candidates as $path => $bytes) {
            if (!is_string($path) || !is_string($bytes)) {
                continue;
            }
            $normalized = str_replace('\\', '/', $path);
            if (!str_contains($normalized, '/pages/layouts/') || str_contains($normalized, '/sources/')) {
                continue;
            }
            $metadata = ThemeLayoutSourceSnapshot::metadata($bytes);
            if (!is_array($metadata)) {
                continue;
            }
            $layoutType = trim((string)($metadata['layout_type'] ?? ''));
            if ($layoutType === '') {
                continue;
            }
            $entry = [
                'layout_type' => $layoutType,
                'layout_option' => (string)($metadata['layout_option'] ?? 'default'),
                'target_type' => (string)($metadata['target_type'] ?? 'global'),
                'target_id' => max(0, (int)($metadata['target_id'] ?? 0)),
            ];
            $key = implode('|', $entry);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pages[] = $entry;
        }

        return $pages;
    }

    /** @return list<string> */
    private function resolveDefaultWebsiteLocales(): array
    {
        try {
            $codes = ObjectManager::getInstance(WebsiteLanguage::class)->getWebsiteLanguageCodes(Website::ID_DEFAULT);
        } catch (\Throwable) {
            $codes = [];
        }
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
        foreach (['zh_Hans_CN', 'en_US'] as $baseline) {
            if (!in_array($baseline, $codes, true)) {
                $codes[] = $baseline;
            }
        }
        if ($codes === []) {
            throw new \RuntimeException('theme_layout_formal_compile_locales_empty');
        }

        return $codes;
    }

    /** @return list<string> */
    private function resolveInlineWidgetTemplates(string $html, string $area): array
    {
        if (!preg_match_all('~<w:widget\b[^>]*\bcode="([^"]+)"~i', $html, $matches)) {
            return [];
        }
        $registry = ObjectManager::getInstance(WidgetRegistryInterface::class)->getRegistry();
        $templates = [];
        foreach (array_unique($matches[1]) as $code) {
            $code = trim((string)$code);
            if ($code === '') {
                continue;
            }
            $widget = $this->findWidgetByCode($registry, $code, $area);
            if ($widget === null) {
                continue;
            }
            $templateRef = trim((string)($widget['template'] ?? ''));
            if ($templateRef === '') {
                $module = (string)($widget['module'] ?? 'Weline_Theme');
                $templateRef = $module . '::templates/' . $area . '/widgets/' . $code . '.phtml';
            }
            $templates[] = $templateRef;
        }

        return array_values(array_unique($templates));
    }

    private function findWidgetByCode(array $registry, string $code, string $area): ?array
    {
        foreach ($registry as $widgets) {
            if (!is_array($widgets)) {
                continue;
            }
            foreach ($widgets as $widget) {
                if (!is_array($widget)) {
                    continue;
                }
                $widgetCode = (string)($widget['code'] ?? '');
                $widgetArea = (string)($widget['area'] ?? $area);
                if ($widgetCode === $code && ($widgetArea === '' || $widgetArea === $area)) {
                    return $widget;
                }
            }
        }

        return null;
    }
}
