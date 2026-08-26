<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Api\Localization\LocaleCatalogInterface;
use Weline\Theme\Service\AllMenu\AllMenuCandidateService;
use Weline\Theme\Service\AllMenu\MenuTreeNormalizer;
use Weline\Theme\Service\AllMenu\PageCandidateService;

/**
 * Nested navigation tree editor (depth <= 3): page / category / custom nodes.
 */
class NavTreeType extends AbstractParamType
{
    public function getTypeCode(): string
    {
        return 'nav_tree';
    }

    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $fieldId = $this->generateFieldId($key, $layoutId);
        $maxDepth = (int)($param['max_depth'] ?? MenuTreeNormalizer::MAX_DEPTH);
        $maxDepth = max(1, min(MenuTreeNormalizer::MAX_DEPTH, $maxDepth));
        $items = $this->normalizeTree($value ?? $this->getDefaultValue($param) ?? []);
        if ($items === []) {
            $items = $this->defaultSeed($param);
        }

        [$pageCandidates, $categoryCandidates] = $this->resolveCandidates($param);

        $labels = [
            'title' => (string)__('导航树'),
            'pages' => (string)__('页面'),
            'categories' => (string)__('分类'),
            'add_custom' => (string)__('添加自定义'),
            'detail' => (string)__('编辑'),
            'remove' => (string)__('删除'),
            'empty' => (string)__('暂无节点：点击左侧页面/分类添加，或拖入右侧、添加自定义'),
            'name' => (string)__('名称'),
            'url' => (string)__('链接'),
            'tag_page' => (string)__('页面'),
            'tag_category' => (string)__('分类'),
            'tag_custom' => (string)__('自定义'),
            'description' => (string)__('描述'),
            'image' => (string)__('图片'),
            'ref' => (string)__('引用'),
            'save_detail' => (string)__('保存'),
            'cancel' => (string)__('取消'),
            'indent_hint' => (string)__('拖拽行调整顺序与层级：行上缘=前、中间=子项、下缘=后（最多三级）'),
            'add_to_tree' => (string)__('点击添加到菜单树'),
            'drop_child_empty' => (string)__('拖入成为子项'),
            'drop_append' => (string)__('拖放到此处追加到末尾'),
            'i18n' => (string)__('多语言'),
            'i18n_name' => (string)__('名称翻译'),
            'i18n_description' => (string)__('描述翻译'),
            'image_pick' => (string)__('从媒体库选择'),
            'has_description' => (string)__('已填描述'),
            'has_image' => (string)__('已设图片'),
        ];

        $locales = [];
        try {
            /** @var LocaleCatalogInterface $catalog */
            $catalog = ObjectManager::getInstance(LocaleCatalogInterface::class);
            $lang = \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN';
            foreach ($catalog->installed($lang) as $locale) {
                if (!is_array($locale)) {
                    continue;
                }
                $code = trim((string)($locale['code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $locales[] = [
                    'code' => $code,
                    'name' => trim((string)($locale['name'] ?? $code)),
                ];
            }
        } catch (\Throwable) {
            $locales = [];
        }
        if ($locales === []) {
            $locales = [
                ['code' => 'zh_Hans_CN', 'name' => '简体中文'],
                ['code' => 'en_US', 'name' => 'English'],
            ];
        }

        $payload = [
            'tree' => $items,
            'page_candidates' => $pageCandidates,
            'category_candidates' => $categoryCandidates,
            'max_depth' => $maxDepth,
            'labels' => $labels,
            'item_schema' => $param['item_schema'] ?? [],
            'locales' => $locales,
        ];
        $payloadJson = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '{}';

        $html = '<div class="w-param-nav-tree" data-w-component="nav-tree" data-field-id="'
            . htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') . '" data-key="'
            . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" data-max-depth="'
            . $maxDepth . '">';
        $html .= '<div class="w-nav-tree-editor" id="' . htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') . '_editor"></div>';
        $html .= '<input type="hidden" id="' . htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') . '" name="'
            . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" value="'
            . htmlspecialchars(json_encode($items, JSON_UNESCAPED_UNICODE) ?: '[]', ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<textarea class="w-nav-tree-boot-data" id="' . htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8')
            . '_nav_tree_boot" hidden readonly aria-hidden="true">'
            . $payloadJson
            . '</textarea>';
        $html .= '</div>';

        return $this->wrapField($key, $param, $html, $layoutId);
    }

    public function validate(mixed $value, array $param): bool
    {
        if (!parent::validate($value, $param)) {
            return false;
        }
        if ($value === null || $value === '' || $value === '[]') {
            return true;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded);
        }

        return is_array($value);
    }

    public function processValue(mixed $value, array $param): mixed
    {
        $maxDepth = (int)($param['max_depth'] ?? MenuTreeNormalizer::MAX_DEPTH);
        $normalizer = new MenuTreeNormalizer();

        return $normalizer->normalize($value, $maxDepth);
    }

    public function getDefaultValue(array $param): mixed
    {
        if (isset($param['default'])) {
            return $this->normalizeTree($param['default']);
        }

        return $this->defaultSeed($param);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeTree(mixed $value): array
    {
        return (new MenuTreeNormalizer())->normalize($value);
    }

    /**
     * @param array<string, mixed> $param
     * @return list<array<string, mixed>>
     */
    private function defaultSeed(array $param): array
    {
        if (!empty($param['seed_pages']) && is_array($param['seed_pages'])) {
            return $this->normalizeTree($param['seed_pages']);
        }
        try {
            /** @var PageCandidateService $pages */
            $pages = ObjectManager::getInstance(PageCandidateService::class);

            return $pages->defaultSeedTree();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $param
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function resolveCandidates(array $param): array
    {
        $pages = is_array($param['page_candidates'] ?? null) ? $param['page_candidates'] : null;
        $cats = is_array($param['category_candidates'] ?? null) ? $param['category_candidates'] : null;
        if ($pages !== null && $cats !== null) {
            return [$this->normalizeTree($pages), $this->normalizeTree($cats)];
        }
        try {
            /** @var AllMenuCandidateService $svc */
            $svc = ObjectManager::getInstance(AllMenuCandidateService::class);

            return [
                $pages !== null ? $this->normalizeTree($pages) : $svc->pageCandidates(),
                $cats !== null ? $this->normalizeTree($cats) : $svc->categoryCandidates(),
            ];
        } catch (\Throwable) {
            return [
                $pages !== null ? $this->normalizeTree($pages) : [],
                $cats !== null ? $this->normalizeTree($cats) : [],
            ];
        }
    }
}
