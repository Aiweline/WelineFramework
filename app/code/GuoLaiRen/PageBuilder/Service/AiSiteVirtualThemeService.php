<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\VirtualTheme;
use GuoLaiRen\PageBuilder\Model\VirtualThemeComponent;
use GuoLaiRen\PageBuilder\Model\VirtualThemeComponentVersion;
use GuoLaiRen\PageBuilder\Model\VirtualThemeLayout;
use Weline\Framework\Manager\ObjectManager;

/**
 * PageBuilder AI 建站虚拟主题服务
 * 所有数据存储在 PageBuilder 自有的表中，不依赖 Weline\Theme 模块
 */
class AiSiteVirtualThemeService
{
    public function __construct(
        private readonly ?AiSitePageBlueprintService $pageBlueprintService = null,
    ) {
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param list<string> $pageTypes
     * @param array<string, array<string, mixed>> $pageTypeLayouts
     * @return array{virtual_theme_id:int,page_type_layouts:array<string, array<string, mixed>>,theme:VirtualTheme}
     */
    public function ensureVirtualTheme(
        array $scope,
        array $websiteProfile,
        array $pageTypes,
        array|int $pageTypeLayouts,
        int $sessionId = 0
    ): array {
        if (\is_int($pageTypeLayouts)) {
            $sessionId = $pageTypeLayouts;
            $pageTypeLayouts = [];
        }

        $pageBlueprintService = $this->pageBlueprintService ?? ObjectManager::getInstance(AiSitePageBlueprintService::class);
        $siteDisplayName = $pageBlueprintService->resolveSiteDisplayName($websiteProfile, $scope);
        $theme = $this->loadOrCreateTheme((int)($scope['virtual_theme_id'] ?? 0), $websiteProfile, $sessionId);
        if (!$theme->getId()) {
            $theme->save();
        }

        $themeId = (int)$theme->getId();
        $headerCode = 'header/ai-site-header';
        $footerCode = 'footer/ai-site-footer';

        $this->saveThemeComponent(
            $themeId,
            $headerCode,
            VirtualThemeComponent::AREA_FRONTEND,
            VirtualThemeComponent::CATEGORY_HEADER,
            'AI Site Header',
            $this->buildHeaderTemplate(),
            [
                'site_title' => $siteDisplayName,
                'site_tagline' => (string)($websiteProfile['site_tagline'] ?? ''),
                'logo' => (string)($websiteProfile['logo'] ?? ''),
                'nav_hint' => (string)__('Home | Pages | Contact'),
            ],
            ['position' => ['header'], 'page_layouts' => ['*'], 'sort_order' => 10]
        );

        $this->saveThemeComponent(
            $themeId,
            $footerCode,
            VirtualThemeComponent::AREA_FRONTEND,
            VirtualThemeComponent::CATEGORY_FOOTER,
            'AI Site Footer',
            $this->buildFooterTemplate(),
            [
                'site_title' => $siteDisplayName,
                'brief_description' => (string)($websiteProfile['brief_description'] ?? ''),
                'target_domain' => (string)($websiteProfile['target_domain'] ?? ''),
            ],
            ['position' => ['footer'], 'page_layouts' => ['*'], 'sort_order' => 20]
        );

        $resolvedLayouts = [];
        foreach ($pageTypes as $pageType) {
            $blueprint = $pageBlueprintService->buildPageBlueprint($pageType, $scope, $websiteProfile);
            $generatedContent = [];

            foreach ($blueprint['sections'] as $section) {
                $generatedContent[] = [
                    'code' => (string)$section['code'],
                    'enabled' => true,
                    'config' => [],
                    'instance_id' => '',
                    'sort_order' => (int)($section['sort_order'] ?? 0),
                ];

                $this->saveThemeComponent(
                    $themeId,
                    (string)$section['code'],
                    VirtualThemeComponent::AREA_FRONTEND,
                    VirtualThemeComponent::CATEGORY_CONTENT,
                    (string)($section['name'] ?? $blueprint['page_label']),
                    $this->resolveContentTemplate((string)($section['template'] ?? 'hero')),
                    \is_array($section['config'] ?? null) ? $section['config'] : [],
                    [
                        'position' => ['content'],
                        'page_layouts' => [$pageType],
                        'sort_order' => (int)($section['sort_order'] ?? 100),
                        'section_key' => (string)($section['key'] ?? ''),
                    ]
                );
            }

            $layout = \is_array($pageTypeLayouts[$pageType] ?? null) ? $pageTypeLayouts[$pageType] : [];
            $layout['header'] = \is_array($layout['header'] ?? null) ? $layout['header'] : ['component' => '', 'config' => []];
            $layout['footer'] = \is_array($layout['footer'] ?? null) ? $layout['footer'] : ['component' => '', 'config' => []];
            $layout['content'] = \is_array($layout['content'] ?? null) ? $layout['content'] : [];

            if (\trim((string)($layout['header']['component'] ?? '')) === '') {
                $layout['header'] = ['component' => $headerCode, 'config' => []];
            }
            if (\trim((string)($layout['footer']['component'] ?? '')) === '') {
                $layout['footer'] = ['component' => $footerCode, 'config' => []];
            }

            if ($this->shouldInjectGeneratedContent($layout['content'])) {
                $layout['content'] = $generatedContent;
            }

            $layout['version'] = '1.0';
            $layout['page_id'] = (int)($layout['page_id'] ?? 0);
            $layout['use_original_template'] = false;
            $resolvedLayouts[$pageType] = $layout;

            $this->saveThemeLayout($themeId, $pageType, $layout);
        }

        $config = $theme->getConfig();
        $config['source'] = VirtualTheme::SOURCE_PAGEBUILDER_AI;
        $config['scope_session_id'] = $sessionId;
        $config['website_profile'] = $websiteProfile;
        $config['selected_page_types'] = $pageTypes;
        $config['virtual_page_layouts'] = $resolvedLayouts;
        $theme->setConfig($config);
        $theme->save();

        return [
            'virtual_theme_id' => $themeId,
            'page_type_layouts' => $resolvedLayouts,
            'theme' => $theme,
        ];
    }

    /**
     * @param array<string, mixed> $websiteProfile
     */
    private function loadOrCreateTheme(int $themeId, array $websiteProfile, int $sessionId): VirtualTheme
    {
        /** @var VirtualTheme $theme */
        $theme = clone ObjectManager::getInstance(VirtualTheme::class);
        $theme->clearData()->clearQuery();
        if ($themeId > 0) {
            $theme->load($themeId);
        }

        if ($theme->getId()) {
            return $theme;
        }

        $name = \trim((string)($websiteProfile['site_title'] ?? ''));
        if ($name === '') {
            $name = 'PageBuilder AI Draft';
        }

        $slug = $this->slugify($name);
        $theme->setName($name . ' Theme')
            ->setSessionId($sessionId)
            ->setPath('ai/pagebuilder-' . $slug . '-' . ($sessionId > 0 ? $sessionId : \substr(\md5((string)\microtime(true)), 0, 8)))
            ->setSource(VirtualTheme::SOURCE_PAGEBUILDER_AI)
            ->setIsActive(false)
            ->setConfig([
                'source' => VirtualTheme::SOURCE_PAGEBUILDER_AI,
                'website_profile' => $websiteProfile,
            ]);

        return $theme;
    }

    private function saveThemeComponent(
        int $themeId,
        string $componentCode,
        string $area,
        string $category,
        string $name,
        string $templateContent,
        array $defaultConfig,
        array $meta
    ): void {
        /** @var VirtualThemeComponent $component */
        $component = clone ObjectManager::getInstance(VirtualThemeComponent::class);
        $component->clearData()->clearQuery();
        $component->where(VirtualThemeComponent::schema_fields_VIRTUAL_THEME_ID, $themeId)
            ->where(VirtualThemeComponent::schema_fields_COMPONENT_CODE, $componentCode)
            ->where(VirtualThemeComponent::schema_fields_AREA, $area)
            ->find();

        if (!$component->getId()) {
            $component->setVirtualThemeId($themeId)
                ->setComponentCode($componentCode)
                ->setArea($area)
                ->setCategory($category)
                ->setName($name)
                ->setTemplateContent($templateContent)
                ->setDefaultConfig($defaultConfig)
                ->setMeta(\array_merge($meta, [
                    'source_type' => VirtualThemeComponent::SOURCE_TYPE_VIRTUAL,
                ]))
                ->setIsAiGenerated(true)
                ->setIsActive(true)
                ->save();

            $this->saveComponentVersion($component, $templateContent, $defaultConfig, $meta);
        } else {
            $component->setTemplateContent($templateContent)
                ->setDefaultConfig($defaultConfig)
                ->setMeta(\array_merge($meta, [
                    'source_type' => VirtualThemeComponent::SOURCE_TYPE_VIRTUAL,
                ]))
                ->setIsAiGenerated(true)
                ->save();

            $this->saveComponentVersion($component, $templateContent, $defaultConfig, $meta);
        }
    }

    private function saveComponentVersion(
        VirtualThemeComponent $component,
        string $templateContent,
        array $defaultConfig,
        array $meta
    ): void {
        $lastVersionNo = 1;
        /** @var VirtualThemeComponentVersion $lastVersion */
        $lastVersion = clone ObjectManager::getInstance(VirtualThemeComponentVersion::class);
        $lastVersion->clearData()->clearQuery();
        $lastVersion->where(VirtualThemeComponentVersion::schema_fields_COMPONENT_ID, $component->getId())
            ->order(VirtualThemeComponentVersion::schema_fields_VERSION_NO, 'DESC')
            ->find();
        if ($lastVersion->getId()) {
            $lastVersionNo = $lastVersion->getVersionNo() + 1;
        }

        /** @var VirtualThemeComponentVersion $version */
        $version = clone ObjectManager::getInstance(VirtualThemeComponentVersion::class);
        $version->setComponentId($component->getId())
            ->setVersionNo($lastVersionNo)
            ->setStatus(VirtualThemeComponentVersion::STATUS_PUBLISHED)
            ->setTemplateContent($templateContent)
            ->setDefaultConfig($defaultConfig)
            ->setMeta($meta)
            ->save();

        $component->setPublishedVersionId($version->getId());
        $component->save();
    }

    private function saveThemeLayout(int $themeId, string $pageType, array $layout): void
    {
        /** @var VirtualThemeLayout $themeLayout */
        $themeLayout = clone ObjectManager::getInstance(VirtualThemeLayout::class);
        $themeLayout->clearData()->clearQuery();
        $themeLayout->where(VirtualThemeLayout::schema_fields_VIRTUAL_THEME_ID, $themeId)
            ->where(VirtualThemeLayout::schema_fields_PAGE_TYPE, $pageType)
            ->where(VirtualThemeLayout::schema_fields_AREA, 'frontend')
            ->find();

        $themeLayout->setVirtualThemeId($themeId)
            ->setPageType($pageType)
            ->setArea('frontend')
            ->setConfig($layout)
            ->setVersion((string)($layout['version'] ?? '1.0'))
            ->setUseOriginalTemplate((bool)($layout['use_original_template'] ?? false))
            ->setPageId((int)($layout['page_id'] ?? 0))
            ->save();
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function shouldInjectGeneratedContent(array $content): bool
    {
        if ($content === []) {
            return true;
        }

        foreach ($content as $component) {
            if (!\is_array($component)) {
                continue;
            }
            $code = \trim((string)($component['code'] ?? $component['component'] ?? ''));
            if ($code === '') {
                continue;
            }
            if (!\in_array($code, ['content/ai-generated-section', 'ai-generated-section', 'content-ai-generated-section'], true)) {
                return false;
            }
        }

        return true;
    }

    private function buildHeaderTemplate(): string
    {
        return <<<'PHTML'
<header class="pb-ai-theme-header" style="padding:24px 32px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;gap:16px;background:#ffffff;">
    <div style="display:flex;align-items:center;gap:12px;min-width:0;">
        <?php if (!empty($logo)): ?>
            <img src="<?= htmlspecialchars((string)$logo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($site_title ?? ''), ENT_QUOTES, 'UTF-8') ?>" style="height:40px;width:auto;display:block;">
        <?php endif; ?>
        <div style="min-width:0;">
            <div style="font-size:20px;font-weight:700;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars((string)($site_title ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            <?php if (!empty($site_tagline)): ?>
                <div style="font-size:13px;color:#64748b;"><?= htmlspecialchars((string)$site_tagline, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
    </div>
    <nav style="font-size:14px;color:#334155;"><?= htmlspecialchars((string)($nav_hint ?? ''), ENT_QUOTES, 'UTF-8') ?></nav>
</header>
PHTML;
    }

    private function buildFooterTemplate(): string
    {
        return <<<'PHTML'
<footer class="pb-ai-theme-footer" style="padding:28px 32px;border-top:1px solid #e5e7eb;background:#f8fafc;color:#334155;">
    <div style="max-width:960px;margin:0 auto;display:grid;gap:8px;">
        <strong style="font-size:16px;color:#0f172a;"><?= htmlspecialchars((string)($site_title ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
        <?php if (!empty($brief_description)): ?>
            <p style="margin:0;line-height:1.6;"><?= htmlspecialchars((string)$brief_description, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if (!empty($target_domain)): ?>
            <span style="font-size:12px;color:#64748b;"><?= htmlspecialchars((string)$target_domain, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </div>
</footer>
PHTML;
    }

    private function resolveContentTemplate(string $template): string
    {
        return match ($template) {
            'cards' => $this->buildCardsTemplate(),
            'checklist' => $this->buildChecklistTemplate(),
            'cta' => $this->buildCtaTemplate(),
            default => $this->buildHeroTemplate(),
        };
    }

    private function buildHeroTemplate(): string
    {
        return <<<'PHTML'
<section class="pb-ai-generated-section pb-ai-generated-section-hero" style="padding:64px 32px 48px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);">
    <div style="max-width:1080px;margin:0 auto;display:grid;gap:18px;">
        <?php if (!empty($eyebrow)): ?>
            <span style="font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#2563eb;"><?= htmlspecialchars((string)$eyebrow, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
        <h1 style="margin:0;font-size:42px;line-height:1.08;color:#0f172a;max-width:900px;"><?= htmlspecialchars((string)($headline ?? $site_title ?? ''), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if (!empty($description)): ?>
            <p style="margin:0;max-width:760px;font-size:18px;line-height:1.7;color:#475569;white-space:pre-line;"><?= htmlspecialchars((string)$description, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if (!empty($chips) && is_array($chips)): ?>
            <div style="display:flex;flex-wrap:wrap;gap:10px;">
                <?php foreach ($chips as $chip): ?>
                    <?php if (!is_scalar($chip) || trim((string)$chip) === '') { continue; } ?>
                    <span style="display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;"><?= htmlspecialchars((string)$chip, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
            <?php if (!empty($primary_cta)): ?>
                <span style="display:inline-flex;align-items:center;padding:12px 20px;border-radius:999px;background:#0f172a;color:#ffffff;font-size:14px;font-weight:700;"><?= htmlspecialchars((string)$primary_cta, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <?php if (!empty($secondary_note)): ?>
                <span style="font-size:13px;color:#64748b;"><?= htmlspecialchars((string)$secondary_note, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </div>
    </div>
</section>
PHTML;
    }

    private function buildCardsTemplate(): string
    {
        return <<<'PHTML'
<section class="pb-ai-generated-section pb-ai-generated-section-cards" style="padding:20px 32px 40px;background:#ffffff;">
    <div style="max-width:1080px;margin:0 auto;display:grid;gap:18px;">
        <?php if (!empty($section_title)): ?>
            <h2 style="margin:0;font-size:28px;line-height:1.2;color:#0f172a;"><?= htmlspecialchars((string)$section_title, ENT_QUOTES, 'UTF-8') ?></h2>
        <?php endif; ?>
        <?php if (!empty($section_intro)): ?>
            <p style="margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;white-space:pre-line;"><?= htmlspecialchars((string)$section_intro, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if (!empty($items) && is_array($items)): ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;">
                <?php foreach ($items as $item): ?>
                    <?php if (!is_array($item)) { continue; } ?>
                    <article style="display:grid;gap:10px;padding:22px;border-radius:22px;background:#f8fafc;border:1px solid #e5e7eb;">
                        <?php if (!empty($item['eyebrow'])): ?>
                            <span style="font-size:11px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#2563eb;"><?= htmlspecialchars((string)$item['eyebrow'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                        <h3 style="margin:0;font-size:20px;line-height:1.2;color:#0f172a;"><?= htmlspecialchars((string)($item['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                        <?php if (!empty($item['description'])): ?>
                            <p style="margin:0;font-size:15px;line-height:1.7;color:#475569;white-space:pre-line;"><?= htmlspecialchars((string)$item['description'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
PHTML;
    }

    private function buildChecklistTemplate(): string
    {
        return <<<'PHTML'
<section class="pb-ai-generated-section pb-ai-generated-section-checklist" style="padding:8px 32px 40px;background:#ffffff;">
    <div style="max-width:1080px;margin:0 auto;display:grid;gap:18px;">
        <?php if (!empty($section_title)): ?>
            <h2 style="margin:0;font-size:26px;line-height:1.25;color:#0f172a;"><?= htmlspecialchars((string)$section_title, ENT_QUOTES, 'UTF-8') ?></h2>
        <?php endif; ?>
        <?php if (!empty($section_intro)): ?>
            <p style="margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;white-space:pre-line;"><?= htmlspecialchars((string)$section_intro, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if (!empty($points) && is_array($points)): ?>
            <div style="display:grid;gap:12px;">
                <?php foreach ($points as $index => $point): ?>
                    <?php if (!is_scalar($point) || trim((string)$point) === '') { continue; } ?>
                    <div style="display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;">
                        <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#ffffff;font-size:12px;font-weight:700;"><?= (int)$index + 1 ?></span>
                        <p style="margin:0;font-size:15px;line-height:1.7;color:#334155;white-space:pre-line;"><?= htmlspecialchars((string)$point, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
PHTML;
    }

    private function buildCtaTemplate(): string
    {
        return <<<'PHTML'
<section class="pb-ai-generated-section pb-ai-generated-section-cta" style="padding:16px 32px 64px;background:linear-gradient(180deg,#ffffff 0%,#eef2ff 100%);">
    <div style="max-width:1080px;margin:0 auto;padding:28px;border-radius:28px;background:#0f172a;color:#ffffff;display:grid;gap:14px;">
        <?php if (!empty($section_title)): ?>
            <h2 style="margin:0;font-size:28px;line-height:1.2;color:#ffffff;"><?= htmlspecialchars((string)$section_title, ENT_QUOTES, 'UTF-8') ?></h2>
        <?php endif; ?>
        <?php if (!empty($section_text)): ?>
            <p style="margin:0;max-width:760px;font-size:16px;line-height:1.7;color:rgba(255,255,255,0.82);white-space:pre-line;"><?= htmlspecialchars((string)$section_text, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
            <?php if (!empty($button_label)): ?>
                <span style="display:inline-flex;align-items:center;padding:12px 20px;border-radius:999px;background:#ffffff;color:#0f172a;font-size:14px;font-weight:700;"><?= htmlspecialchars((string)$button_label, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <?php if (!empty($assist_text)): ?>
                <span style="font-size:13px;color:rgba(255,255,255,0.72);"><?= htmlspecialchars((string)$assist_text, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </div>
    </div>
</section>
PHTML;
    }

    private function slugify(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = (string)\preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = \trim($value, '-');

        return $value !== '' ? $value : 'component';
    }
}
