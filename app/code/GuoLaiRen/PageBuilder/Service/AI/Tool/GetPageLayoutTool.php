<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Tool;

use Weline\Ai\Interface\ToolInterface;
use GuoLaiRen\PageBuilder\Service\LayoutAssembler;
use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Manager\ObjectManager;

/**
 * 获取页面布局结构工具
 * 
 * 获取当前页面的布局结构（header/content/footer 区域组件列表），
 * 帮助智能体理解页面上下文
 */
class GetPageLayoutTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_page_layout';
    }

    public function getDescription(): string
    {
        return 'Get the layout structure of a specific page, including the list of components in each region (header, content, footer). Use this to understand the page context when generating components.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page_id' => [
                    'type' => 'integer',
                    'description' => 'The page ID to get layout for',
                ],
                'style_code' => [
                    'type' => 'string',
                    'description' => 'The template style code (alternative to page_id)',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $args): mixed
    {
        $pageId = $args['page_id'] ?? 0;
        $styleCode = $args['style_code'] ?? '';

        /** @var LayoutAssembler $layoutAssembler */
        $layoutAssembler = ObjectManager::getInstance(LayoutAssembler::class);

        // 通过 page_id 获取
        if ($pageId > 0) {
            /** @var Page $pageModel */
            $pageModel = ObjectManager::getInstance(Page::class);
            $page = $pageModel->reset()
                ->where('page_id', $pageId)
                ->find()
                ->fetch();

            if (!$page || !$page->getId()) {
                return ['error' => __('页面不存在：%{1}', [$pageId])];
            }

            try {
                $layoutConfig = $layoutAssembler->getFullLayoutConfig($page);
                return [
                    'page_id' => $pageId,
                    'layout' => $this->simplifyLayout($layoutConfig),
                ];
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        }

        // 通过 style_code 获取组件列表
        if (!empty($styleCode)) {
            try {
                $filesMap = $layoutAssembler->getComponentFilesMap($styleCode);
                return [
                    'style_code' => $styleCode,
                    'component_files' => array_map(fn($path) => basename($path), $filesMap),
                ];
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        }

        return ['error' => 'Please provide either page_id or style_code'];
    }

    /**
     * 简化布局配置（去除大型字段，保留结构信息）
     */
    private function simplifyLayout(array $layoutConfig): array
    {
        $simplified = [];
        foreach ($layoutConfig as $region => $components) {
            if (!is_array($components)) {
                continue;
            }
            $simplified[$region] = [];
            foreach ($components as $comp) {
                if (!is_array($comp)) {
                    continue;
                }
                $simplified[$region][] = [
                    'code' => $comp['code'] ?? $comp['component_code'] ?? '',
                    'name' => $comp['name'] ?? '',
                    'sort_order' => $comp['sort_order'] ?? 0,
                ];
            }
        }
        return $simplified;
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
