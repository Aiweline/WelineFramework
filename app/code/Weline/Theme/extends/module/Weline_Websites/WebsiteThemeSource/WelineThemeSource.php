<?php

declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Websites\WebsiteThemeSource;

use Weline\Theme\Extends\Module\Weline_Framework\Query\ThemeQueryProvider;
use Weline\Websites\Api\WebsiteThemeSourceInterface;

class WelineThemeSource implements WebsiteThemeSourceInterface
{
    private const DEFAULT_PAGE_TYPES = [
        'home_page',
        'about_page',
        'contact_page',
        'privacy_policy',
        'terms_of_service',
        'refund_policy',
        'cookie_policy',
        'blog_list',
        'blog_category',
        'blog_post',
        'custom_page',
    ];

    public function __construct(
        private readonly ThemeQueryProvider $themeQueryProvider
    ) {
    }

    public function getCode(): string
    {
        return 'weline_theme';
    }

    public function getName(): string
    {
        return (string)__('Weline 主题库');
    }

    public function getDescription(): string
    {
        return (string)__('从 Weline 主题库中选择主题方案，支持多种布局风格。');
    }

    public function isEnabled(): bool
    {
        return \class_exists(\Weline\Theme\Model\WelineTheme::class);
    }

    public function getSortOrder(): int
    {
        return 10;
    }

    /**
     * @inheritDoc
     */
    public function listThemes(array $context = []): array
    {
        $themes = $this->themeQueryProvider->execute('scanThemeLayoutsByType', [
            'theme_type' => 'frontend',
            'is_active' => null,
        ]);

        if (!\is_array($themes)) {
            return [];
        }

        $result = [];
        foreach ($themes as $theme) {
            if (!\is_array($theme)) {
                continue;
            }

            $themeId = (int)($theme['theme_id'] ?? $theme['id'] ?? 0);
            if ($themeId <= 0) {
                continue;
            }

            $result[] = [
                'theme_id' => $themeId,
                'theme_name' => (string)($theme['theme_name'] ?? $theme['name'] ?? 'Unknown'),
                'theme_path' => (string)($theme['theme_path'] ?? $theme['path'] ?? ''),
                'layout_count' => (int)($theme['layout_count'] ?? 0),
                'layout_types' => \is_array($theme['layout_types'] ?? null) ? $theme['layout_types'] : [],
                'preview_url' => (string)($theme['preview_url'] ?? ''),
                'thumbnail' => (string)($theme['thumbnail'] ?? ''),
                'is_active' => (bool)($theme['is_active'] ?? false),
                'source' => 'weline_theme_library',
            ];
        }

        return $result;
    }

    /**
     * Get supported page types from this theme source.
     *
     * @return list<array{code:string,label:string,description:string,is_default:bool}>
     */
    public function listPageTypes(array $context = []): array
    {
        $result = [];
        foreach (self::DEFAULT_PAGE_TYPES as $pageType) {
            $result[] = [
                'code' => $pageType,
                'label' => $this->getPageTypeLabel($pageType),
                'description' => $this->getPageTypeDescription($pageType),
                'is_default' => \in_array($pageType, ['home_page', 'about_page', 'contact_page'], true),
            ];
        }

        return $result;
    }

    /**
     * Get theme layout metadata for a specific page type.
     *
     * @return list<array{layout_code:string,layout_name:string,layout_type:string,component_slots:int,preview_url:string}>
     */
    public function getLayoutsForPageType(string $pageType, array $context = []): array
    {
        $themes = $this->listThemes($context);
        $layouts = [];

        foreach ($themes as $theme) {
            $themeId = (int)($theme['theme_id'] ?? 0);
            if ($themeId <= 0) {
                continue;
            }

            $themeLayouts = $this->themeQueryProvider->execute('scanThemeLayoutsByType', [
                'theme_id' => $themeId,
                'layout_type' => $pageType,
            ]);

            if (!\is_array($themeLayouts)) {
                continue;
            }

            foreach ($themeLayouts as $layout) {
                if (!\is_array($layout)) {
                    continue;
                }

                $layouts[] = [
                    'layout_code' => (string)($layout['layout_code'] ?? $layout['code'] ?? ''),
                    'layout_name' => (string)($layout['layout_name'] ?? $layout['name'] ?? 'Unknown'),
                    'layout_type' => $pageType,
                    'component_slots' => (int)($layout['component_slots'] ?? $layout['slots'] ?? 0),
                    'preview_url' => (string)($layout['preview_url'] ?? ''),
                    'theme_id' => $themeId,
                    'theme_name' => (string)($theme['theme_name'] ?? ''),
                ];
            }
        }

        return $layouts;
    }

    private function getPageTypeLabel(string $pageType): string
    {
        return match ($pageType) {
            'home_page' => __('首页'),
            'about_page' => __('关于我们'),
            'contact_page' => __('联系我们'),
            'privacy_policy' => __('隐私政策'),
            'terms_of_service' => __('服务条款'),
            'refund_policy' => __('退款政策'),
            'cookie_policy' => __('Cookie 政策'),
            'blog_list' => __('博客列表'),
            'blog_category' => __('博客分类'),
            'blog_post' => __('博客文章'),
            'custom_page' => __('自定义页面'),
            default => \ucwords(\str_replace(['_', '-'], ' ', $pageType)),
        };
    }

    private function getPageTypeDescription(string $pageType): string
    {
        return match ($pageType) {
            'home_page' => __('网站主页，展示品牌和核心内容'),
            'about_page' => __('介绍品牌故事、团队和价值观'),
            'contact_page' => __('提供联系方式和联系表单'),
            'privacy_policy' => __('说明隐私数据处理方式'),
            'terms_of_service' => __('明确服务使用条款'),
            'refund_policy' => __('说明退款和退货政策'),
            'cookie_policy' => __('说明 Cookie 使用方式'),
            'blog_list' => __('博客文章列表页'),
            'blog_category' => __('按分类查看博客文章'),
            'blog_post' => __('博客文章详情页'),
            'custom_page' => __('自定义单页面内容'),
            default => '',
        };
    }
}
