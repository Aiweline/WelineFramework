<?php

declare(strict_types=1);

/**
 * Weline Websites - 统一建站标签
 *
 * 提供添加/编辑网站的统一 OffCanvas 入口，所有建站入口使用本标签以标准化流程。
 * 使用示例：
 * 添加：id="add_website" mode="add"
 * 编辑：mode="edit" vars="website" action-params="{id:website.website_id}"
 * PageBuilder 添加：mode="add" action 传 *\/backend/websiteManagement/add
 */

namespace Weline\Websites\Taglib;

use Weline\Component\Block\OffCanvas;
use Weline\Framework\Manager\ObjectManager;
use Weline\Taglib\TaglibInterface;

class BuildSite implements TaglibInterface
{
    public static function name(): string
    {
        return 'websites:website:build';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'id' => true,
            'mode' => false,           // add | edit，默认 add
            'action' => false,         // 覆盖默认 action，如 */backend/websiteManagement/add
            'title' => false,
            'target-button-text' => false,
            'target-button-class' => false,
            'icon' => false,
            'direction' => false,
            'class-names' => false,
            'close-button-show' => false,
            'close-button-text' => false,
            'save' => false,
            'vars' => false,
            'action-params' => false,
            'website_id' => false,     // 编辑时直接传网站 ID，避免依赖 vars 解析
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $id = $attributes['id'] ?? 'build_site';
            $mode = isset($attributes['mode']) ? strtolower(trim((string) $attributes['mode'])) : 'add';
            $action = $attributes['action'] ?? '';
            if ($action === '') {
                $action = $mode === 'edit' ? '*/admin/website/edit' : '*/admin/website/add';
            }
            $rawTitle = $attributes['title'] ?? ($mode === 'edit' ? __('编辑网站') : __('新建站点'));
            $rawTargetText = $attributes['target-button-text'] ?? ($mode === 'edit' ? __('编辑') : __('新建站点'));
            $title = __($rawTitle);
            $targetButtonText = __($rawTargetText);
            $targetButtonClass = $attributes['target-button-class'] ?? ($mode === 'edit' ? 'btn btn-sm btn-outline-info' : 'btn btn-primary');
            $icon = $attributes['icon'] ?? ($mode === 'edit' ? 'mdi mdi-pencil' : 'mdi mdi-plus');
            $direction = $attributes['direction'] ?? 'right';
            $classNames = $attributes['class-names'] ?? 'w-75 h-100';
            $closeButtonShow = $attributes['close-button-show'] ?? '1';
            $closeButtonText = $attributes['close-button-text'] ?? __('关闭');
            $save = $attributes['save'] ?? '1';
            $vars = $attributes['vars'] ?? '';
            $actionParams = $attributes['action-params'] ?? '';
            $websiteId = $attributes['website_id'] ?? '';
            if ($mode === 'edit' && $websiteId !== '' && $actionParams === '') {
                $actionParams = '{id:' . trim((string) $websiteId) . '}';
            }

            $blockData = [
                'cache' => '0',
                'id' => preg_replace('/[^\w]+/', '', $id),
                'action' => $action,
                'title' => $title,
                'target-tag' => 'a',
                'target-button-text' => $targetButtonText,
                'target-button-class' => $targetButtonClass,
                'icon' => $icon,
                'direction' => $direction,
                'class-names' => $classNames,
                'close-button-show' => $closeButtonShow,
                'close-button-text' => $closeButtonText,
                'save' => $save,
                'off-canvas-body-style' => '',
            ];
            if ($vars !== '') {
                $blockData['vars'] = $vars;
            }
            if ($actionParams !== '') {
                $blockData['action-params'] = $actionParams;
            }

            // 仅传一个命名参数 data，避免 PHP 8 将 $blockData 的键（如 cache）当作构造函数命名参数
            /** @var OffCanvas $block */
            $block = ObjectManager::getInstance(OffCanvas::class, ['data' => $blockData]);
            $block->__init();
            return $block->render();
        };
    }

    public static function tag_self_close(): bool
    {
        return true;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        return <<<DOC
## websites:website:build 统一建站标签

### 用法
添加：<w:websites:website:build id="add_website" mode="add" />
编辑：<w:websites:website:build id="edit_website" mode="edit" vars="website" action-params="{id:website.website_id}" />
自定义 action（如 PageBuilder）：<w:websites:website:build id="pb_add" mode="add" action="*/backend/websiteManagement/add" />

### 属性
- id: 必需，唯一标识
- mode: add | edit，默认 add
- action: 可选，覆盖表单提交路由
- title, target-button-text, target-button-class, icon, direction, class-names, close-button-show, close-button-text, save
- vars, action-params: 编辑时传参（如 vars="website" action-params="{id:website.website_id}"）
DOC;
    }
}
