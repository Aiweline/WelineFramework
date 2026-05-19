<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Head;

/**
 * 页面 SEO 主标题解析：实体名优先，否则用布局 @meta.name（可在主题后台改），再兜底 @param.title。
 * 最终由 PageSeoContextResolver 与网站名组合为「页面主标题 | 网站名称」。
 */
class HeadPageTitleResolver
{
    public const SOURCE_AUTO = 'auto';
    public const SOURCE_ENTITY = 'entity';
    public const SOURCE_LAYOUT_NAME = 'layout_name';
    public const SOURCE_PAGE_PARAM = 'page_param';

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $seo
     * @param array<string, mixed> $options
     */
    public function resolve(
        $template,
        array $meta,
        array $seo,
        mixed $product,
        mixed $category,
        mixed $page,
        array $options,
        callable $layoutNameResolver,
        callable $routeTitleResolver,
        callable $moduleNameResolver,
        callable $readTemplate,
        callable $read,
        callable $firstNonEmpty,
        callable $normalizeMetaText,
        callable $meaningfulTemplateTitle
    ): string {
        $moduleName = (string) $moduleNameResolver();
        $source = $this->normalizeSource($meta['seo_title_source'] ?? null);

        $explicit = HeadTitleRules::sanitizeTitle((string) $firstNonEmpty([
            $options['title'] ?? null,
            $read($seo, ['title', 'meta_title']),
            $readTemplate($template, 'meta_title'),
            $read($meta, ['meta_title']),
            $read($meta, ['controller_title']),
            $meaningfulTemplateTitle($template),
        ]), $moduleName);

        $entityTitle = HeadTitleRules::sanitizeTitle((string) $firstNonEmpty([
            $read($product, ['meta_name', 'meta_title', 'name', 'title']),
            $read($category, ['meta_title', 'name', 'title']),
            $read($page, ['meta_title', 'title', 'name']),
        ]), $moduleName);

        $layoutName = HeadTitleRules::sanitizeTitle(
            (string) $layoutNameResolver($template, $meta),
            $moduleName
        );
        $pageParamTitle = HeadTitleRules::sanitizeTitle(
            (string) $normalizeMetaText($meta['title'] ?? null),
            $moduleName
        );

        if ($source === self::SOURCE_ENTITY) {
            return (string) $firstNonEmpty([$explicit, $entityTitle, $pageParamTitle, $layoutName, $routeTitleResolver($template)]);
        }

        if ($source === self::SOURCE_LAYOUT_NAME) {
            return (string) $firstNonEmpty([$explicit, $layoutName, $pageParamTitle, $entityTitle, $routeTitleResolver($template)]);
        }

        if ($source === self::SOURCE_PAGE_PARAM) {
            return (string) $firstNonEmpty([$explicit, $pageParamTitle, $layoutName, $entityTitle, $routeTitleResolver($template)]);
        }

        // auto：有实体用实体名（商品/分类/CMS 页），否则用布局 meta 名（账户等可在后台改）
        if ($entityTitle !== '') {
            return (string) $firstNonEmpty([$explicit, $entityTitle, $pageParamTitle, $layoutName, $routeTitleResolver($template)]);
        }

        return (string) $firstNonEmpty([$explicit, $layoutName, $pageParamTitle, $routeTitleResolver($template)]);
    }

    private function normalizeSource(mixed $value): string
    {
        $value = strtolower(trim((string) (is_array($value) ? ($value['default'] ?? $value['value'] ?? '') : $value)));
        return match ($value) {
            self::SOURCE_ENTITY, self::SOURCE_LAYOUT_NAME, self::SOURCE_PAGE_PARAM => $value,
            default => self::SOURCE_AUTO,
        };
    }
}
